<?php
/**
 * BSC Office Hub Integration – Modul: Woidsiederei-Chat im Shop.
 *
 * Floating-Chat-Widget für eingeloggte Kunden (gleicher Thread wie das
 * Kundenportal: crm_interactions ref_type=portal_chat – Mitarbeiter antworten
 * wie gewohnt im CRM-Chat-Overlay). Alle Requests laufen server-seitig über
 * admin-ajax (Nonce) zum Hub – kein Secret und kein CORS im Browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── AJAX-Proxy ───────────────────────────────────────────────────────────────

function bschi_chat_guard(): int {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Nicht angemeldet' ], 401 );
    }
    check_ajax_referer( 'bschi_chat', 'nonce' );
    if ( ! bschi_feature_enabled( 'chat' ) ) {
        wp_send_json_error( [ 'message' => 'Chat nicht verfügbar' ], 403 );
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) ) {
        wp_send_json_error( [ 'message' => 'Kein Kundenkonto gefunden' ], 404 );
    }
    return (int) $resolved['customer_id'];
}

add_action( 'wp_ajax_bschi_chat_history', function () {
    $cid  = bschi_chat_guard();
    $data = bschi_hub_get( '/api/v1/shop/customer/' . $cid . '/chat' );
    if ( $data === null ) {
        wp_send_json_error( [ 'message' => 'Chat nicht erreichbar' ], 502 );
    }
    wp_send_json_success( $data );
} );

add_action( 'wp_ajax_bschi_chat_send', function () {
    $cid  = bschi_chat_guard();
    $user = wp_get_current_user();
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

    $endpoint = bschi_hub_url( '/api/v1/shop/customer/' . $cid . '/chat' );
    if ( ! $endpoint ) {
        wp_send_json_error( [ 'message' => 'Hub nicht konfiguriert' ], 500 );
    }
    $response = wp_remote_post( $endpoint, [
        'timeout' => 25,
        'headers' => bschi_hub_headers(),
        'body'    => wp_json_encode( [
            'message' => $msg,
            'email'   => $user->billing_email ?: $user->user_email,
            'files'   => $files,
        ] ),
    ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
        wp_send_json_error( [ 'message' => 'Senden fehlgeschlagen' ], 502 );
    }
    wp_send_json_success( json_decode( wp_remote_retrieve_body( $response ), true ) );
} );

add_action( 'wp_ajax_bschi_chat_typing', function () {
    $cid = bschi_chat_guard();
    bschi_hub_post( '/api/v1/shop/customer/' . $cid . '/chat/typing', [] );
    wp_send_json_success();
} );

add_action( 'wp_ajax_bschi_chat_state', function () {
    $cid  = bschi_chat_guard();
    $data = bschi_hub_get( '/api/v1/shop/customer/' . $cid . '/chat/state' );
    wp_send_json_success( $data ?: [ 'agent_typing' => false ] );
} );

add_action( 'wp_ajax_bschi_chat_attachment', function () {
    $cid = bschi_chat_guard();
    $mid = (int) ( $_GET['message_id'] ?? 0 );
    $idx = (int) ( $_GET['idx'] ?? 0 );
    $bin = bschi_hub_get_binary( '/api/v1/shop/customer/' . $cid . '/chat/attachment/' . $mid . '/' . $idx );
    if ( ! $bin ) {
        wp_die( 'Anhang nicht gefunden.', '', [ 'response' => 404 ] );
    }
    nocache_headers();
    header( 'Content-Type: ' . $bin['content_type'] );
    if ( $bin['disposition'] ) {
        header( 'Content-Disposition: ' . $bin['disposition'] );
    }
    echo $bin['body']; // phpcs:ignore -- binärer Datei-Stream
    exit;
} );

// ─── Widget (Frontend) ────────────────────────────────────────────────────────

add_action( 'wp_footer', function () {
    if ( ! bschi_feature_enabled( 'chat' ) || ! is_user_logged_in() || is_admin() ) {
        return;
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) ) {
        return;
    }
    $ajax  = admin_url( 'admin-ajax.php' );
    $nonce = wp_create_nonce( 'bschi_chat' );
    ?>
    <style>
    #bschi-chat-btn{position:fixed;right:18px;bottom:18px;z-index:99998;width:58px;height:58px;border-radius:50%;background:#4b5a42;color:#fff;border:none;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.25);font-size:24px;line-height:1}
    #bschi-chat-btn .bschi-unread{position:absolute;top:-4px;right:-4px;background:#b54343;color:#fff;border-radius:10px;min-width:20px;height:20px;font-size:12px;font-weight:700;display:none;align-items:center;justify-content:center;padding:0 5px}
    #bschi-chat-panel{position:fixed;right:18px;bottom:88px;z-index:99999;width:360px;max-width:calc(100vw - 36px);height:520px;max-height:calc(100vh - 120px);background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.3);display:none;flex-direction:column;overflow:hidden;font-family:inherit}
    #bschi-chat-panel.open{display:flex}
    .bschi-chat-head{background:#4b5a42;color:#fff;padding:14px 16px;font-weight:700;display:flex;justify-content:space-between;align-items:center}
    .bschi-chat-head small{display:block;font-weight:400;font-size:11px;opacity:.85}
    .bschi-chat-close{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:4px 8px}
    .bschi-chat-msgs{flex:1;overflow-y:auto;padding:14px;background:#faf9f6;display:flex;flex-direction:column;gap:8px}
    .bschi-msg{max-width:82%;padding:8px 12px;border-radius:12px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
    .bschi-msg.in{align-self:flex-end;background:#dde7d2;border-bottom-right-radius:4px}
    .bschi-msg.out{align-self:flex-start;background:#fff;border:1px solid #e4e0d8;border-bottom-left-radius:4px}
    .bschi-msg .bschi-meta{font-size:10px;color:#999;margin-top:4px;text-align:right}
    .bschi-msg .bschi-agent{font-size:11px;font-weight:700;color:#4b5a42;margin-bottom:2px}
    .bschi-msg a{color:#4b5a42;text-decoration:underline}
    .bschi-typing{font-size:11px;color:#999;padding:0 16px 6px;display:none}
    .bschi-chat-input{display:flex;gap:8px;padding:10px;border-top:1px solid #e4e0d8;background:#fff;align-items:flex-end}
    .bschi-chat-input textarea{flex:1;border:1px solid #ddd;border-radius:10px;padding:9px 11px;font-size:13px;resize:none;height:42px;max-height:110px;font-family:inherit}
    .bschi-chat-input button{background:#4b5a42;color:#fff;border:none;border-radius:10px;min-width:44px;height:42px;cursor:pointer;font-size:16px}
    .bschi-chat-input .bschi-attach{background:#f0efe9;color:#4b5a42}
    .bschi-files-hint{font-size:11px;color:#777;padding:0 12px 8px;background:#fff;display:none}
    </style>
    <button id="bschi-chat-btn" type="button" aria-label="Chat öffnen">&#128172;<span class="bschi-unread" id="bschi-chat-unread"></span></button>
    <div id="bschi-chat-panel" role="dialog" aria-label="Woidsiederei Chat">
      <div class="bschi-chat-head">
        <div>Woidsiederei Chat<small>Wir antworten so schnell wie möglich</small></div>
        <button class="bschi-chat-close" type="button" aria-label="Schließen">&times;</button>
      </div>
      <div class="bschi-chat-msgs" id="bschi-chat-msgs"><div style="text-align:center;color:#999;font-size:12px;padding:24px">Lade Nachrichten…</div></div>
      <div class="bschi-typing" id="bschi-chat-typing">Bearbeiter tippt…</div>
      <div class="bschi-files-hint" id="bschi-files-hint"></div>
      <div class="bschi-chat-input">
        <button class="bschi-attach" type="button" id="bschi-chat-attach" aria-label="Anhang">&#128206;</button>
        <input type="file" id="bschi-chat-files" multiple style="display:none" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip,.heic">
        <textarea id="bschi-chat-text" placeholder="Nachricht schreiben…" rows="1"></textarea>
        <button type="button" id="bschi-chat-send" aria-label="Senden">&#10148;</button>
      </div>
    </div>
    <script>
    (function(){
      var AJAX=<?= wp_json_encode( $ajax ); ?>,NONCE=<?= wp_json_encode( $nonce ); ?>;
      var btn=document.getElementById('bschi-chat-btn'),panel=document.getElementById('bschi-chat-panel'),
          msgs=document.getElementById('bschi-chat-msgs'),txt=document.getElementById('bschi-chat-text'),
          send=document.getElementById('bschi-chat-send'),attach=document.getElementById('bschi-chat-attach'),
          fileInput=document.getElementById('bschi-chat-files'),hint=document.getElementById('bschi-files-hint'),
          typing=document.getElementById('bschi-chat-typing'),unread=document.getElementById('bschi-chat-unread');
      var open=false,histTimer=null,stateTimer=null,lastCount=parseInt(localStorage.getItem('bschiChatSeen')||'0',10),lastTyping=0;

      function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
      function fmtTime(iso){if(!iso)return'';try{var d=new Date(iso);return d.toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit'})+' '+d.toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'});}catch(e){return'';}}

      function render(list){
        if(!list.length){msgs.innerHTML='<div style="text-align:center;color:#999;font-size:12px;padding:24px">Noch keine Nachrichten - schreib uns!</div>';return;}
        msgs.innerHTML=list.map(function(m){
          var att=(m.attachments||[]).map(function(a){
            var u=AJAX+'?action=bschi_chat_attachment&nonce='+NONCE+'&message_id='+m.id+'&idx='+a.idx;
            return '<div><a href="'+u+'" target="_blank" rel="noopener">'+esc(a.name)+'</a></div>';
          }).join('');
          var agent=m.dir==='out'?'<div class="bschi-agent">'+esc(m.agent_name||'Team')+'</div>':'';
          var ticks=m.dir==='in'?(m.read?' &#10003;&#10003;':' &#10003;'):'';
          return '<div class="bschi-msg '+m.dir+'">'+agent+esc(m.text)+att
            +'<div class="bschi-meta">'+fmtTime(m.at)+(m.edited?' (bearbeitet)':'')+ticks+'</div></div>';
        }).join('');
        msgs.scrollTop=msgs.scrollHeight;
      }

      function loadHistory(){
        fetch(AJAX+'?action=bschi_chat_history&nonce='+NONCE,{credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){
            if(!j||!j.success)return;
            var list=(j.data&&j.data.messages)||[];
            if(open){render(list);lastCount=list.length;localStorage.setItem('bschiChatSeen',String(lastCount));unread.style.display='none';}
            else{
              var n=list.length-lastCount;
              if(n>0){unread.textContent=n;unread.style.display='flex';}
            }
          }).catch(function(){});
      }

      function pollState(){
        fetch(AJAX+'?action=bschi_chat_state&nonce='+NONCE,{credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){typing.style.display=(j&&j.success&&j.data&&j.data.agent_typing)?'block':'none';})
          .catch(function(){});
      }

      function setOpen(v){
        open=v;panel.classList.toggle('open',v);
        if(v){
          loadHistory();
          histTimer=setInterval(loadHistory,10000);
          stateTimer=setInterval(pollState,4000);
          setTimeout(function(){txt.focus();},150);
        }else{
          clearInterval(histTimer);clearInterval(stateTimer);
        }
      }
      btn.addEventListener('click',function(){setOpen(!open);});
      panel.querySelector('.bschi-chat-close').addEventListener('click',function(){setOpen(false);});

      txt.addEventListener('input',function(){
        var now=Date.now();
        if(now-lastTyping>2500){
          lastTyping=now;
          var fd=new FormData();fd.append('action','bschi_chat_typing');fd.append('nonce',NONCE);
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
        send.disabled=true;send.innerHTML='&#8987;';
        var fd=new FormData();
        fd.append('action','bschi_chat_send');fd.append('nonce',NONCE);fd.append('message',message);
        for(var i=0;i<Math.min(fileInput.files.length,5);i++){fd.append('files[]',fileInput.files[i]);}
        fetch(AJAX,{method:'POST',body:fd,credentials:'same-origin'})
          .then(function(r){return r.json();})
          .then(function(j){
            if(j&&j.success){txt.value='';fileInput.value='';hint.style.display='none';loadHistory();}
            else{alert((j&&j.data&&j.data.message)||'Senden fehlgeschlagen');}
          })
          .catch(function(){alert('Senden fehlgeschlagen');})
          .finally(function(){send.disabled=false;send.innerHTML='&#10148;';});
      }
      send.addEventListener('click',doSend);

      // Ungelesen-Badge auch bei geschlossenem Panel aktualisieren (alle 60s)
      loadHistory();
      setInterval(function(){if(!open)loadHistory();},60000);
    })();
    </script>
    <?php
} );
