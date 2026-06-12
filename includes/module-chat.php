<?php
/**
 * BSC Office Hub Integration – Modul: Seifensieder-Chat im Shop.
 *
 * Floating-Chat-Widget für eingeloggte Kunden UND Gäste (gleicher Thread wie
 * das Kundenportal: crm_interactions ref_type=portal_chat – Mitarbeiter
 * antworten wie gewohnt im CRM-Chat-Overlay). Gäste bekommen eine Token-Session
 * (localStorage), die im Hub einem Kunden zugeordnet werden kann.
 * Alle Requests laufen server-seitig über admin-ajax (Nonce) zum Hub –
 * kein Secret und kein CORS im Browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── AJAX-Proxy ───────────────────────────────────────────────────────────────

/**
 * Liefert den Hub-Pfad-Prefix für den aktuellen Chat-Teilnehmer:
 * eingeloggt + im Hub bekannt → /customer/{id}, sonst Gast-Token → /guest/{token}.
 */
function bschi_chat_target( bool $json_errors = true ): string {
    check_ajax_referer( 'bschi_chat', 'nonce' );
    if ( ! bschi_feature_enabled( 'chat' ) ) {
        if ( $json_errors ) {
            wp_send_json_error( [ 'message' => 'Chat nicht verfügbar' ], 403 );
        }
        wp_die( 'Chat nicht verfügbar.', '', [ 'response' => 403 ] );
    }
    if ( is_user_logged_in() ) {
        $resolved = bschi_resolve_current_customer();
        if ( ! empty( $resolved['found'] ) ) {
            return '/api/v1/shop/customer/' . (int) $resolved['customer_id'];
        }
    }
    $tok = strtolower( sanitize_text_field( wp_unslash( $_REQUEST['guest'] ?? '' ) ) );
    if ( ! preg_match( '/^[a-f0-9]{32}$/', $tok ) ) {
        if ( $json_errors ) {
            wp_send_json_error( [ 'message' => 'Keine Chat-Session' ], 401 );
        }
        wp_die( 'Keine Chat-Session.', '', [ 'response' => 401 ] );
    }
    return '/api/v1/shop/guest/' . $tok;
}

/** Registriert einen AJAX-Handler für eingeloggte UND nicht eingeloggte Besucher. */
function bschi_chat_ajax( string $action, callable $fn ): void {
    add_action( 'wp_ajax_' . $action, $fn );
    add_action( 'wp_ajax_nopriv_' . $action, $fn );
}

bschi_chat_ajax( 'bschi_chat_guest_session', function () {
    check_ajax_referer( 'bschi_chat', 'nonce' );
    if ( ! bschi_feature_enabled( 'chat' ) ) {
        wp_send_json_error( [ 'message' => 'Chat nicht verfügbar' ], 403 );
    }
    $tok      = strtolower( sanitize_text_field( wp_unslash( $_POST['guest'] ?? '' ) ) );
    $endpoint = bschi_hub_url( '/api/v1/shop/guest/chat/session' );
    if ( ! $endpoint ) {
        wp_send_json_error( [ 'message' => 'Hub nicht konfiguriert' ], 500 );
    }
    $response = wp_remote_post( $endpoint, [
        'timeout' => 10,
        'headers' => bschi_hub_headers(),
        'body'    => wp_json_encode( [ 'token' => preg_match( '/^[a-f0-9]{32}$/', $tok ) ? $tok : null ] ),
    ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
        wp_send_json_error( [ 'message' => 'Chat nicht erreichbar' ], 502 );
    }
    wp_send_json_success( json_decode( wp_remote_retrieve_body( $response ), true ) );
} );

bschi_chat_ajax( 'bschi_chat_history', function () {
    $base = bschi_chat_target();
    $data = bschi_hub_get( $base . '/chat' );
    if ( $data === null ) {
        wp_send_json_error( [ 'message' => 'Chat nicht erreichbar' ], 502 );
    }
    wp_send_json_success( $data );
} );

bschi_chat_ajax( 'bschi_chat_send', function () {
    $base = bschi_chat_target();
    $msg  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

    // Anhänge (max 5 x 15 MB) als Base64 zum Hub relayen
    $files = [];
    if ( ! empty( $_FILES['files'] ) && is_array( $_FILES['files']['name'] ?? null ) ) {
        $count = min( count( $_FILES['files']['name'] ), 5 );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( ( $_FILES['files']['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
                continue;
            }
            if ( ( $_FILES['files']['size'][ $i ] ?? 0 ) > 15 * 1024 * 1024 ) {
                wp_send_json_error( [ 'message' => 'Datei zu groß (max. 15 MB)' ], 400 );
            }
            $tmp = $_FILES['files']['tmp_name'][ $i ];
            if ( ! is_uploaded_file( $tmp ) ) {
                continue;
            }
            $files[] = [
                'name'        => sanitize_file_name( $_FILES['files']['name'][ $i ] ),
                'content_b64' => base64_encode( (string) file_get_contents( $tmp ) ),
                'mime'        => sanitize_text_field( $_FILES['files']['type'][ $i ] ?? '' ),
            ];
        }
    }

    if ( $msg === '' && ! $files ) {
        wp_send_json_error( [ 'message' => 'Leere Nachricht' ], 400 );
    }

    $email = '';
    if ( is_user_logged_in() ) {
        $user  = wp_get_current_user();
        $email = $user->billing_email ?: $user->user_email;
    }

    $endpoint = bschi_hub_url( $base . '/chat' );
    if ( ! $endpoint ) {
        wp_send_json_error( [ 'message' => 'Hub nicht konfiguriert' ], 500 );
    }
    $response = wp_remote_post( $endpoint, [
        'timeout' => 25,
        'headers' => bschi_hub_headers(),
        'body'    => wp_json_encode( [
            'message' => $msg,
            'email'   => $email,
            'files'   => $files,
        ] ),
    ] );
    $code = wp_remote_retrieve_response_code( $response );
    if ( is_wp_error( $response ) || $code >= 400 ) {
        $detail = json_decode( wp_remote_retrieve_body( $response ), true )['detail'] ?? '';
        $msg_out = $code === 403 && str_contains( (string) $detail, 'gesperrt' )
            ? 'Der Chat ist für dich derzeit gesperrt.'
            : 'Senden fehlgeschlagen';
        wp_send_json_error( [ 'message' => $msg_out, 'blocked' => $code === 403 ], $code >= 400 ? $code : 502 );
    }
    wp_send_json_success( json_decode( wp_remote_retrieve_body( $response ), true ) );
} );

bschi_chat_ajax( 'bschi_chat_typing', function () {
    $base = bschi_chat_target();
    bschi_hub_post( $base . '/chat/typing', [] );
    wp_send_json_success();
} );

bschi_chat_ajax( 'bschi_chat_state', function () {
    $base = bschi_chat_target();
    $data = bschi_hub_get( $base . '/chat/state' );
    wp_send_json_success( $data ?: [ 'agent_typing' => false ] );
} );

bschi_chat_ajax( 'bschi_chat_attachment', function () {
    $base = bschi_chat_target( false );
    $mid  = (int) ( $_GET['message_id'] ?? 0 );
    $idx  = (int) ( $_GET['idx'] ?? 0 );
    $bin  = bschi_hub_get_binary( $base . '/chat/attachment/' . $mid . '/' . $idx );
    if ( ! $bin ) {
        wp_die( 'Anhang nicht gefunden.', '', [ 'response' => 404 ] );
    }
    nocache_headers();
    // Hub-gelieferten Content-Type nie ungefiltert durchreichen (Stored XSS,
    // z.B. text/html im Shop-Origin) – nur harmlose Typen inline, Rest Download.
    $type        = strtolower( trim( strtok( (string) $bin['content_type'], ';' ) ) );
    $safe_inline = [ 'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain' ];
    $name        = '';
    if ( $bin['disposition'] && preg_match( '/filename="?([^";]+)"?/i', $bin['disposition'], $m ) ) {
        $name = sanitize_file_name( $m[1] );
    }
    if ( $name === '' ) {
        $name = 'anhang-' . $mid . '-' . $idx;
    }
    if ( in_array( $type, $safe_inline, true ) ) {
        header( 'Content-Type: ' . $type );
        header( 'Content-Disposition: inline; filename="' . $name . '"' );
    } else {
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
    }
    header( "Content-Security-Policy: sandbox; default-src 'none'" );
    echo $bin['body']; // phpcs:ignore -- binärer Datei-Stream
    exit;
} );

// Profilbild eines Bearbeiters (immer als Bild ausliefern, 1h Browser-Cache)
bschi_chat_ajax( 'bschi_chat_avatar', function () {
    check_ajax_referer( 'bschi_chat', 'nonce' );
    if ( ! bschi_feature_enabled( 'chat' ) ) {
        wp_die( '', '', [ 'response' => 403 ] );
    }
    $uid = (int) ( $_GET['user_id'] ?? 0 );
    $bin = bschi_hub_get_binary( '/api/v1/shop/chat/agent-avatar/' . $uid );
    if ( ! $bin ) {
        wp_die( '', '', [ 'response' => 404 ] );
    }
    $type = strtolower( trim( strtok( (string) $bin['content_type'], ';' ) ) );
    if ( ! str_starts_with( $type, 'image/' ) ) {
        $type = 'image/webp';
    }
    header( 'Content-Type: ' . $type );
    header( 'Cache-Control: private, max-age=3600' );
    header( "Content-Security-Policy: sandbox; default-src 'none'" );
    echo $bin['body']; // phpcs:ignore -- binärer Bild-Stream
    exit;
} );

// Profilkarte (Name, Position, Bio) eines Bearbeiters
bschi_chat_ajax( 'bschi_chat_agent', function () {
    check_ajax_referer( 'bschi_chat', 'nonce' );
    if ( ! bschi_feature_enabled( 'chat' ) ) {
        wp_send_json_error( [ 'message' => 'Nicht verfügbar' ], 403 );
    }
    $uid  = (int) ( $_GET['user_id'] ?? 0 );
    $data = bschi_hub_get( '/api/v1/shop/chat/agent/' . $uid );
    if ( $data === null ) {
        wp_send_json_error( [ 'message' => 'Nicht gefunden' ], 404 );
    }
    wp_send_json_success( $data );
} );

// ─── Widget (Frontend) ────────────────────────────────────────────────────────

add_action( 'wp_footer', function () {
    if ( ! bschi_feature_enabled( 'chat' ) || is_admin() ) {
        return;
    }
    $mode = 'guest';
    if ( is_user_logged_in() ) {
        $resolved = bschi_resolve_current_customer();
        if ( ! empty( $resolved['found'] ) ) {
            $mode = 'customer';
        }
    }
    $ajax  = admin_url( 'admin-ajax.php' );
    $nonce = wp_create_nonce( 'bschi_chat' );
    $logo  = get_site_icon_url( 64 ) ?: '';
    ?>
    <style>
    /* Trusted-Shops-Badge nach unten links verbannen – der Chat wohnt unten rechts.
       !important im Stylesheet überstimmt die Inline-Styles des TS-Scripts. */
    div[id^='trustbadge-container']{left:18px!important;right:auto!important}
    #bschi-chat-btn{position:fixed;right:18px;bottom:18px;z-index:99998;width:58px;height:58px;border-radius:50%;background:#4b5a42;color:#fff;border:none;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;transition:opacity .25s ease,transform .25s ease}
    #bschi-chat-btn svg{width:28px;height:28px;display:block}
    #bschi-chat-btn.bschi-scroll-hide{opacity:0;transform:translateY(90px);pointer-events:none}
    #bschi-chat-btn .bschi-unread{position:absolute;top:-4px;right:-4px;background:#b54343;color:#fff;border-radius:10px;min-width:20px;height:20px;font-size:12px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 5px}
    #bschi-chat-panel{position:fixed;right:18px;bottom:88px;z-index:99999;width:360px;max-width:calc(100vw - 36px);height:520px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.3);display:none;flex-direction:column;overflow:hidden;font-family:inherit}
    #bschi-chat-panel.open{display:flex}
    .bschi-chat-head{background:#4b5a42;color:#fff;padding:12px 16px;font-weight:700;display:flex;justify-content:space-between;align-items:center;gap:10px}
    .bschi-chat-head .bschi-htitle{display:flex;align-items:center;gap:10px;min-width:0}
    /* Logo: transparent, in Weiß eingefärbt, ohne Rahmen/Kasten */
    .bschi-chat-head img.bschi-logo{width:36px;height:36px;flex:none;object-fit:contain;background:transparent;border:none;border-radius:0;filter:brightness(0) invert(1)}
    .bschi-chat-head small{display:block;font-weight:400;font-size:11px;opacity:.85}
    .bschi-chat-close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:4px 8px;flex:none}
    .bschi-chat-msgs{flex:1;overflow-y:auto;padding:14px;background:#faf9f6;display:flex;flex-direction:column;gap:8px}
    .bschi-row{display:flex;gap:8px;align-items:flex-end}
    .bschi-row.in{justify-content:flex-end}
    .bschi-av{width:30px;height:30px;border-radius:50%;flex:none;object-fit:cover;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;cursor:pointer;background:#4b5a42;border:none;padding:0}
    .bschi-msg{max-width:82%;padding:8px 12px;border-radius:12px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
    .bschi-msg.in{background:#dde7d2;border-bottom-right-radius:4px}
    .bschi-msg.out{background:#fff;border:1px solid #e4e0d8;border-bottom-left-radius:4px}
    .bschi-msg .bschi-meta{font-size:10px;color:#999;margin-top:4px;text-align:right}
    .bschi-msg .bschi-agent{font-size:11px;font-weight:700;color:#4b5a42;margin-bottom:2px}
    .bschi-msg a{color:#4b5a42;text-decoration:underline}
    .bschi-typing{font-size:11px;color:#999;padding:0 16px 6px;display:none}
    .bschi-blocked{display:none;font-size:12px;color:#b54343;background:#fdf0f0;padding:9px 14px;border-top:1px solid #f2dada;text-align:center}
    .bschi-offline{display:none;font-size:11px;color:#7a6f5d;background:#f6f1e7;padding:7px 14px;border-bottom:1px solid #ece4d4;text-align:center}
    .bschi-chat-input{display:flex;gap:8px;padding:10px;border-top:1px solid #e4e0d8;background:#fff;align-items:flex-end}
    /* min/max mit !important gegen Theme-Styles (Flatsome gibt textareas grosse min-height) */
    #bschi-chat-panel .bschi-chat-input textarea{flex:1;border:1px solid #ddd;border-radius:10px;padding:8px 11px!important;font-size:13px;resize:none;height:38px;min-height:38px!important;max-height:110px!important;overflow-y:auto;font-family:inherit;line-height:1.4;box-sizing:border-box;margin:0}
    .bschi-chat-input button{background:#4b5a42;color:#fff;border:none;border-radius:10px;min-width:44px;height:38px;cursor:pointer;font-size:16px;flex:none}
    .bschi-chat-input .bschi-attach{background:#f0efe9;color:#4b5a42}
    .bschi-files-hint{font-size:11px;color:#777;padding:0 12px 8px;background:#fff;display:none}
    #bschi-agcard{position:absolute;inset:0;background:rgba(0,0,0,.45);z-index:5;display:none;align-items:center;justify-content:center;padding:24px}
    #bschi-agcard .bschi-agbox{background:#fff;border-radius:14px;padding:22px;max-width:280px;width:100%;text-align:center;position:relative;max-height:100%;overflow-y:auto}
    #bschi-agcard .bschi-agx{position:absolute;top:8px;right:10px;background:none;border:none;font-size:18px;cursor:pointer;color:#999}
    #bschi-agcard .bschi-agphoto{width:84px;height:84px;border-radius:50%;object-fit:cover;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:26px;font-weight:700;background:#4b5a42}
    #bschi-agcard .bschi-agname{font-size:16px;font-weight:700}
    #bschi-agcard .bschi-agpos{font-size:12px;color:#4b5a42;font-weight:600;margin-top:2px}
    #bschi-agcard .bschi-agbio{font-size:12px;color:#666;margin-top:10px;line-height:1.5;text-align:left;white-space:pre-wrap}
    </style>
    <button id="bschi-chat-btn" type="button" aria-label="Chat öffnen">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
        <circle cx="8.5" cy="11.5" r=".8" fill="#fff" stroke="none"/><circle cx="12.5" cy="11.5" r=".8" fill="#fff" stroke="none"/><circle cx="16.5" cy="11.5" r=".8" fill="#fff" stroke="none"/>
      </svg>
      <span class="bschi-unread" id="bschi-chat-unread"></span></button>
    <div id="bschi-chat-panel" role="dialog" aria-label="Seifensieder Chat">
      <div class="bschi-chat-head">
        <div class="bschi-htitle">
          <?php if ( $logo ) : ?><img class="bschi-logo" src="<?= esc_url( $logo ); ?>" alt=""><?php endif; ?>
          <div>Seifensieder Chat<small>Wir antworten so schnell wie möglich</small></div>
        </div>
        <button class="bschi-chat-close" type="button" aria-label="Schließen">&times;</button>
      </div>
      <div class="bschi-offline" id="bschi-chat-offline">Im Moment ist niemand von uns online – schreib uns trotzdem, wir melden uns, sobald wir wieder da sind.</div>
      <div class="bschi-chat-msgs" id="bschi-chat-msgs"><div style="text-align:center;color:#999;font-size:12px;padding:24px">Lade Nachrichten…</div></div>
      <div class="bschi-typing" id="bschi-chat-typing">Bearbeiter tippt…</div>
      <div class="bschi-blocked" id="bschi-chat-blocked">Der Chat ist für dich derzeit nicht verfügbar.</div>
      <div class="bschi-files-hint" id="bschi-files-hint"></div>
      <div class="bschi-chat-input" id="bschi-chat-inputbar">
        <button class="bschi-attach" type="button" id="bschi-chat-attach" aria-label="Anhang"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
        <input type="file" id="bschi-chat-files" multiple style="display:none" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip,.heic">
        <textarea id="bschi-chat-text" placeholder="Nachricht schreiben…" rows="1"></textarea>
        <button type="button" id="bschi-chat-send" aria-label="Senden"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
      </div>
      <div id="bschi-agcard"><div class="bschi-agbox"><button class="bschi-agx" type="button">&times;</button><div id="bschi-agbody"></div></div></div>
    </div>
    <script>
    (function(){
      var AJAX=<?= wp_json_encode( $ajax ); ?>,NONCE=<?= wp_json_encode( $nonce ); ?>,MODE=<?= wp_json_encode( $mode ); ?>;
      var btn=document.getElementById('bschi-chat-btn'),panel=document.getElementById('bschi-chat-panel'),
          msgs=document.getElementById('bschi-chat-msgs'),txt=document.getElementById('bschi-chat-text'),
          send=document.getElementById('bschi-chat-send'),attach=document.getElementById('bschi-chat-attach'),
          fileInput=document.getElementById('bschi-chat-files'),hint=document.getElementById('bschi-files-hint'),
          typing=document.getElementById('bschi-chat-typing'),unread=document.getElementById('bschi-chat-unread'),
          blockedNote=document.getElementById('bschi-chat-blocked'),inputBar=document.getElementById('bschi-chat-inputbar'),
          offlineNote=document.getElementById('bschi-chat-offline'),
          agcard=document.getElementById('bschi-agcard'),agbody=document.getElementById('bschi-agbody');
      var open=false,histTimer=null,stateTimer=null,lastCount=parseInt(localStorage.getItem('bschiChatSeen')||'0',10),
          lastTyping=0,guestTok=localStorage.getItem('bschiChatGuest')||'',sessionReady=(MODE==='customer'),isBlocked=false;

      function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
      // Farbe kommt vom Hub – nur valide Hex-Farben in style-Attribute lassen
      function safeColor(c){return /^#[0-9a-fA-F]{3,8}$/.test(c||'')?c:'#4b5a42';}
      function fmtTime(iso){if(!iso)return'';try{var d=new Date(iso);return d.toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit'})+' '+d.toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'});}catch(e){return'';}}
      function guestParam(){return (MODE==='guest'&&guestTok)?'&guest='+encodeURIComponent(guestTok):'';}

      // Gast-Session sicherstellen (Token in localStorage, Hub legt Gast-Kunden an)
      function ensureSession(){
        if(sessionReady)return Promise.resolve();
        var fd=new FormData();fd.append('action','bschi_chat_guest_session');fd.append('nonce',NONCE);
        if(guestTok)fd.append('guest',guestTok);
        return fetch(AJAX,{method:'POST',body:fd,credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){
            if(j&&j.success&&j.data&&j.data.token){
              guestTok=j.data.token;localStorage.setItem('bschiChatGuest',guestTok);sessionReady=true;
              if(j.data.blocked)setBlocked(true);
            }else{throw new Error('session');}
          });
      }

      function setBlocked(v){
        isBlocked=v;
        blockedNote.style.display=v?'block':'none';
        inputBar.style.display=v?'none':'flex';
      }
      function setStaffOnline(v){offlineNote.style.display=(v===false)?'block':'none';}

      function avatarHtml(m){
        var uid=m.agent_user_id||0;
        if(m.agent_has_avatar&&uid){
          return '<img class="bschi-av" data-uid="'+uid+'" src="'+AJAX+'?action=bschi_chat_avatar&nonce='+NONCE+'&user_id='+uid+'" alt="">';
        }
        var ini=String(m.agent_name||'W').split(/\s+/).map(function(x){return x[0]||'';}).join('').slice(0,2);
        return '<button type="button" class="bschi-av" data-uid="'+(parseInt(uid,10)||0)+'" style="background:'+safeColor(m.agent_color)+'">'+esc(ini)+'</button>';
      }

      function render(list){
        if(!list.length){msgs.innerHTML='<div style="text-align:center;color:#999;font-size:12px;padding:24px">Noch keine Nachrichten - schreib uns!</div>';return;}
        msgs.innerHTML=list.map(function(m){
          var att=(m.attachments||[]).map(function(a){
            var u=AJAX+'?action=bschi_chat_attachment&nonce='+NONCE+'&message_id='+m.id+'&idx='+a.idx+guestParam();
            return '<div><a href="'+u+'" target="_blank" rel="noopener">'+esc(a.name)+'</a></div>';
          }).join('');
          var agent=m.dir==='out'?'<div class="bschi-agent">'+esc(m.agent_name||'Team')+'</div>':'';
          var ticks=m.dir==='in'?(m.read?' &#10003;&#10003;':' &#10003;'):'';
          var bubble='<div class="bschi-msg '+m.dir+'">'+agent+esc(m.text)+att
            +'<div class="bschi-meta">'+fmtTime(m.at)+(m.edited?' (bearbeitet)':'')+ticks+'</div></div>';
          var av=m.dir==='out'?avatarHtml(m):'';
          return '<div class="bschi-row '+m.dir+'">'+av+bubble+'</div>';
        }).join('');
        msgs.scrollTop=msgs.scrollHeight;
      }

      // Profilkarte eines Bearbeiters (Klick auf Avatar)
      msgs.addEventListener('click',function(e){
        var el=e.target.closest('.bschi-av');
        if(!el)return;
        var uid=parseInt(el.getAttribute('data-uid')||'0',10);
        if(!uid)return;
        agcard.style.display='flex';
        agbody.innerHTML='<div style="padding:14px;color:#999;font-size:12px">Lädt…</div>';
        fetch(AJAX+'?action=bschi_chat_agent&nonce='+NONCE+'&user_id='+uid,{credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){
            if(!j||!j.success){throw new Error();}
            var d=j.data,ini=String(d.name||'').split(/\s+/).map(function(x){return x[0]||'';}).join('').slice(0,2);
            var pic=d.has_avatar
              ?'<img class="bschi-agphoto" src="'+AJAX+'?action=bschi_chat_avatar&nonce='+NONCE+'&user_id='+uid+'" alt="">'
              :'<div class="bschi-agphoto" style="background:'+safeColor(d.color)+'">'+esc(ini)+'</div>';
            agbody.innerHTML=pic+'<div class="bschi-agname">'+esc(d.name)+'</div>'
              +'<div class="bschi-agpos">'+esc(d.position||'')+'</div>'
              +(d.bio?'<div class="bschi-agbio">'+esc(d.bio)+'</div>':'');
          })
          .catch(function(){agbody.innerHTML='<div style="padding:14px;color:#b54343;font-size:12px">Profil nicht verfügbar.</div>';});
      });
      agcard.addEventListener('click',function(e){if(e.target===agcard||e.target.classList.contains('bschi-agx'))agcard.style.display='none';});

      function loadHistory(){
        if(MODE==='guest'&&!guestTok)return;
        fetch(AJAX+'?action=bschi_chat_history&nonce='+NONCE+guestParam(),{credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){
            if(!j||!j.success)return;
            setBlocked(!!(j.data&&j.data.blocked));
            if(j.data&&'staff_online' in j.data)setStaffOnline(j.data.staff_online);
            var list=(j.data&&j.data.messages)||[];
            if(open){render(list);lastCount=list.length;localStorage.setItem('bschiChatSeen',String(lastCount));unread.style.display='none';}
            else{
              var n=list.length-lastCount;
              if(n>0){unread.textContent=n;unread.style.display='flex';}
            }
          }).catch(function(){});
      }

      function pollState(){
        if(MODE==='guest'&&!guestTok)return;
        fetch(AJAX+'?action=bschi_chat_state&nonce='+NONCE+guestParam(),{credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){
            typing.style.display=(j&&j.success&&j.data&&j.data.agent_typing)?'block':'none';
            if(j&&j.success&&j.data&&'staff_online' in j.data)setStaffOnline(j.data.staff_online);
          })
          .catch(function(){});
      }

      function setOpen(v){
        open=v;panel.classList.toggle('open',v);
        if(v){
          var p=(MODE==='guest'&&!guestTok)?Promise.resolve():ensureSession().catch(function(){});
          p.then(function(){
            loadHistory();
            histTimer=setInterval(loadHistory,10000);
            stateTimer=setInterval(pollState,4000);
          });
          setTimeout(function(){txt.focus();},150);
        }else{
          clearInterval(histTimer);clearInterval(stateTimer);
        }
      }
      btn.addEventListener('click',function(){setOpen(!open);});
      panel.querySelector('.bschi-chat-close').addEventListener('click',function(){setOpen(false);});

      // Eingabefeld: einzeilig starten, beim Tippen/Überlaufen mitwachsen
      function autoGrow(){
        txt.style.height='38px';
        if(txt.scrollHeight>42){txt.style.height=Math.min(txt.scrollHeight,110)+'px';}
      }
      txt.addEventListener('input',function(){
        autoGrow();
        var now=Date.now();
        if(now-lastTyping>2500&&sessionReady){
          lastTyping=now;
          var fd=new FormData();fd.append('action','bschi_chat_typing');fd.append('nonce',NONCE);
          if(MODE==='guest')fd.append('guest',guestTok);
          fetch(AJAX,{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
        }
      });
      txt.addEventListener('keydown',function(e){
        if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();doSend();}
      });

      attach.addEventListener('click',function(){fileInput.click();});
      fileInput.addEventListener('change',function(){
        var n=fileInput.files.length;
        hint.style.display=n?'block':'none';
        hint.textContent=n?(n+' Datei(en) ausgewählt (max. 5 x 15 MB)'):'';
      });

      function doSend(){
        var message=txt.value.trim();
        if(!message&&!fileInput.files.length)return;
        if(isBlocked)return;
        var sendIcon=send.innerHTML;
        send.disabled=true;send.style.opacity='.55';
        ensureSession().then(function(){
          var fd=new FormData();
          fd.append('action','bschi_chat_send');fd.append('nonce',NONCE);fd.append('message',message);
          if(MODE==='guest')fd.append('guest',guestTok);
          for(var i=0;i<Math.min(fileInput.files.length,5);i++){fd.append('files[]',fileInput.files[i]);}
          return fetch(AJAX,{method:'POST',body:fd,credentials:'same-origin'});
        })
          .then(function(r){return r.json();})
          .then(function(j){
            if(j&&j.success){txt.value='';autoGrow();fileInput.value='';hint.style.display='none';loadHistory();}
            else{
              if(j&&j.data&&j.data.blocked)setBlocked(true);
              alert((j&&j.data&&j.data.message)||'Senden fehlgeschlagen');
            }
          })
          .catch(function(){alert('Senden fehlgeschlagen');})
          .finally(function(){send.disabled=false;send.style.opacity='';send.innerHTML=sendIcon;});
      }
      send.addEventListener('click',doSend);

      // Ungelesen-Badge auch bei geschlossenem Panel aktualisieren (alle 60s)
      // (Gäste ohne bestehende Session erzeugen dabei KEINE Hub-Anfrage)
      loadHistory();
      setInterval(function(){if(!open)loadHistory();},60000);

      // Mobil: Button beim Scrollen ausblenden, bei Scroll-Ruhe wieder einblenden
      if(window.matchMedia('(max-width:782px)').matches){
        var scrollIdle=null;
        window.addEventListener('scroll',function(){
          if(!open)btn.classList.add('bschi-scroll-hide');
          clearTimeout(scrollIdle);
          scrollIdle=setTimeout(function(){btn.classList.remove('bschi-scroll-hide');},450);
        },{passive:true});
      }
    })();
    </script>
    <?php
} );
