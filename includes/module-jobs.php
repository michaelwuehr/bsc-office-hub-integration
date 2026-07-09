<?php
/**
 * BSC Office Hub Integration – Modul: Offene Stellen / Jobs.
 *
 * Shortcode [bsc_hub_jobs] zeigt die im Office Hub gepflegten offenen Stellen
 * (GET /api/v1/shop/jobs) als Stellenanzeigen mit Einleitung, „Das wartet auf dich",
 * „Das bringst du mit", „Das bieten wir dir", Bewerbung/Kontakt sowie Initiativbewerbung
 * und einem AGG-/Gleichbehandlungs-Hinweis. Datenpflege erfolgt komplett im Hub.
 *
 * Beispiel: eine WP-Seite (z. B. /jobs/) anlegen und nur den Shortcode einfügen:
 *   [bsc_hub_jobs]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bschi_jobs_bullets( $text ): string {
    $items = '';
    foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
        $l = trim( ltrim( trim( $line ), "-•*\t " ) );
        if ( $l !== '' ) {
            $items .= '<li>' . esc_html( $l ) . '</li>';
        }
    }
    return $items ? '<ul>' . $items . '</ul>' : '';
}

function bschi_jobs_styles(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style>
    .bschi-jobs{max-width:820px;margin:0 auto}
    .bschi-jobs__intro{font-size:1.05rem;line-height:1.6;color:#3a3228;margin:0 0 1.4em}
    .bschi-jobs__empty{padding:18px;border:1px solid #e0d8c8;border-radius:12px;background:#f8f5f0;color:#5a5045}
    .bschi-job{border:1px solid #e0d8c8;border-radius:16px;padding:22px 24px;margin:0 0 18px;background:#fff;box-shadow:0 3px 14px rgba(0,0,0,.05)}
    .bschi-job__title{color:#4b5a42;font-size:1.5rem;margin:0 0 .25em;line-height:1.15}
    .bschi-job__meta{color:#b56b43;font-weight:700;letter-spacing:.3px;font-size:.95rem;margin-bottom:1em}
    .bschi-job__intro{line-height:1.6;color:#3a3228;margin:0 0 1em}
    .bschi-job h4{color:#4b5a42;font-size:1.05rem;margin:1.1em 0 .4em}
    .bschi-job ul{margin:.2em 0 .6em;padding-left:1.3em}
    .bschi-job li{margin:.3em 0;line-height:1.5}
    .bschi-job__salary{margin:1em 0 0}
    .bschi-job__contact{margin-top:1.3em;border-top:2px solid #c9a84c;padding-top:1em;line-height:1.6}
    .bschi-jobs__init{border:1px dashed #c9a84c;border-radius:14px;padding:18px 22px;margin:8px 0 18px;background:#faf7f0}
    .bschi-jobs__init h3{color:#4b5a42;margin:0 0 .3em}
    .bschi-jobs__agg{margin-top:1.6em;color:#7a7060;font-size:.82rem;line-height:1.5}
    /* Kachel-/Grid-Ansicht (per Plugin-Settings umschaltbar) */
    .bschi-jobs--grid{max-width:1180px}
    .bschi-jobs--grid .bschi-jobs__list{display:grid;gap:18px;align-items:start;grid-template-columns:repeat(var(--bschi-cols,3),minmax(0,1fr))}
    .bschi-jobs--grid .bschi-job{margin:0;display:flex;flex-direction:column}
    @media(max-width:960px){.bschi-jobs--grid .bschi-jobs__list{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:640px){.bschi-jobs--grid .bschi-jobs__list{grid-template-columns:1fr}}
    /* Kompakte Kacheln: Kurzintro gekürzt, Details im Aufklapper */
    .bschi-jobs--compact .bschi-job__intro{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:.6em}
    .bschi-job__more{margin-top:auto;padding-top:.7em}
    .bschi-job__more>summary{cursor:pointer;color:#b56b43;font-weight:700;letter-spacing:.2px;list-style:none;display:inline-block}
    .bschi-job__more>summary::-webkit-details-marker{display:none}
    .bschi-job__more>summary::after{content:" \25B8"}
    .bschi-job__more[open]>summary::after{content:" \25BE"}
    .bschi-job__more[open]>summary{margin-bottom:.6em}
    /* "Jetzt bewerben"-Button */
    .bschi-job__apply{display:inline-block;margin-top:1em;background:#4b5a42;color:#fff!important;text-decoration:none;font-weight:700;letter-spacing:.2px;padding:11px 22px;border-radius:10px;line-height:1;transition:background .15s}
    .bschi-job__apply:hover{background:#3c4835;color:#fff!important}
    .bschi-jobs--grid .bschi-job__foot{margin-top:auto;display:flex;flex-direction:column;align-items:flex-start}
    /* Slider / Karussell: horizontal wischbare Karten (Scroll-Snap, ohne JS) */
    .bschi-jobs--slider{max-width:1180px}
    .bschi-jobs--slider .bschi-jobs__list{display:flex;gap:18px;overflow-x:auto;scroll-snap-type:x proximity;padding:2px 2px 14px;-webkit-overflow-scrolling:touch;scrollbar-width:thin}
    .bschi-jobs--slider .bschi-jobs__list::-webkit-scrollbar{height:8px}
    .bschi-jobs--slider .bschi-jobs__list::-webkit-scrollbar-thumb{background:rgba(0,0,0,.18);border-radius:4px}
    .bschi-jobs--slider .bschi-job{flex:0 0 clamp(280px,82vw,344px);scroll-snap-align:start;margin:0;display:flex;flex-direction:column}
    .bschi-jobs--slider .bschi-job__foot{margin-top:auto;display:flex;flex-direction:column;align-items:flex-start}
    </style>';
}

add_shortcode( 'bsc_hub_jobs', function ( $atts ): string {
    bschi_report_link( 'jobs' );
    $data = bschi_hub_get( '/api/v1/shop/jobs', 300 );
    $meta = ( is_array( $data ) && isset( $data['meta'] ) ) ? $data['meta'] : [];
    $jobs = ( is_array( $data ) && isset( $data['jobs'] ) ) ? $data['jobs'] : [];

    // Darstellung: Liste (untereinander), Kacheln (vollständig) oder Kacheln (kompakt, mit Aufklapper)
    // – aus Plugin-Settings, pro Shortcode übersteuerbar.
    $s   = bschi_get_settings();
    $a   = shortcode_atts( [ 'layout' => '', 'columns' => '' ], is_array( $atts ) ? $atts : [] );
    $layout  = in_array( $a['layout'], [ 'list', 'grid', 'compact', 'slider' ], true ) ? $a['layout'] : ( $s['jobs_layout'] ?? 'compact' );
    $cols    = (int) ( $a['columns'] !== '' ? $a['columns'] : ( $s['jobs_columns'] ?? 3 ) );
    $cols    = max( 2, min( 4, $cols ) );
    $grid    = in_array( $layout, [ 'grid', 'compact' ], true );
    $slider  = ( $layout === 'slider' );
    $compact = ( $layout === 'compact' || $slider );   // Slider nutzt kompakte, gleich hohe Karten
    $apply_url   = trim( (string) ( $s['jobs_apply_url'] ?? '' ) );
    $apply_label = trim( (string) ( $s['jobs_apply_label'] ?? '' ) ) ?: 'Jetzt bewerben';

    ob_start();
    echo bschi_jobs_styles();
    $wrap_cls = 'bschi-jobs' . ( $grid ? ' bschi-jobs--grid' : '' ) . ( $slider ? ' bschi-jobs--slider' : '' ) . ( $compact ? ' bschi-jobs--compact' : '' );
    ?>
    <div class="<?php echo esc_attr( $wrap_cls ); ?>"<?php echo $grid ? ' style="--bschi-cols:' . (int) $cols . '"' : ''; ?>>
        <?php if ( ! empty( $meta['jobs_page_intro'] ) ) : ?>
            <p class="bschi-jobs__intro"><?php echo esc_html( $meta['jobs_page_intro'] ); ?></p>
        <?php endif; ?>

        <?php if ( empty( $jobs ) ) : ?>
            <div class="bschi-jobs__empty">
                Aktuell sind keine Stellen ausgeschrieben.
                <?php if ( ! empty( $meta['jobs_init_text'] ) ) : ?><br><?php echo esc_html( $meta['jobs_init_text'] ); ?><?php endif; ?>
            </div>
        <?php else : ?>
            <div class="bschi-jobs__list">
            <?php foreach ( $jobs as $j ) :
                $meta_line = implode( ' · ', array_filter( [
                    $j['employment_type'] ?? '', $j['location'] ?? '', $j['start_text'] ?? '',
                ] ) );
                $contact = ! empty( $j['contact'] ) ? $j['contact'] : ( $meta['jobs_contact'] ?? '' );
                // "Jetzt bewerben"-Ziel: konfigurierte URL (z. B. Formular) > E-Mail aus dem Kontakttext (mailto)
                $apply_href = ''; $apply_ext = false;
                if ( $apply_url !== '' ) {
                    $apply_href = $apply_url; $apply_ext = true;
                } elseif ( $contact && preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $contact, $mm ) ) {
                    $apply_href = 'mailto:' . $mm[0] . '?subject=' . rawurlencode( 'Bewerbung als ' . ( $j['title'] ?? '' ) );
                }
                ?>
                <article class="bschi-job">
                    <h3 class="bschi-job__title"><?php echo esc_html( $j['title'] ); ?></h3>
                    <?php if ( $meta_line ) : ?><div class="bschi-job__meta"><?php echo esc_html( $meta_line ); ?></div><?php endif; ?>
                    <?php if ( ! empty( $j['intro'] ) ) : ?><p class="bschi-job__intro"><?php echo esc_html( $j['intro'] ); ?></p><?php endif; ?>
                    <?php if ( $compact ) : ?><div class="bschi-job__foot"><details class="bschi-job__more"><summary>Mehr erfahren</summary><?php endif; ?>
                    <?php if ( ! empty( $j['tasks'] ) ) : ?><h4>Das wartet auf dich</h4><?php echo bschi_jobs_bullets( $j['tasks'] ); ?><?php endif; ?>
                    <?php if ( ! empty( $j['profile'] ) ) : ?><h4>Das bringst du mit</h4><?php echo bschi_jobs_bullets( $j['profile'] ); ?><?php endif; ?>
                    <?php if ( ! empty( $j['offer'] ) ) : ?><h4>Das bieten wir dir</h4><?php echo bschi_jobs_bullets( $j['offer'] ); ?><?php endif; ?>
                    <?php if ( ! empty( $j['salary_text'] ) ) : ?><p class="bschi-job__salary"><strong>Vergütung:</strong> <?php echo esc_html( $j['salary_text'] ); ?></p><?php endif; ?>
                    <?php if ( $contact ) : ?><div class="bschi-job__contact"><strong>Deine Bewerbung</strong><br><?php echo nl2br( esc_html( $contact ) ); ?></div><?php endif; ?>
                    <?php if ( $compact ) : ?></details><?php endif; ?>
                    <?php if ( $apply_href ) : ?><a class="bschi-job__apply" href="<?php echo esc_url( $apply_href, [ 'http', 'https', 'mailto' ] ); ?>"<?php echo $apply_ext ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $apply_label ); ?></a><?php endif; ?>
                    <?php if ( $compact ) : ?></div><?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $meta['jobs_init_text'] ) ) : ?>
                <div class="bschi-jobs__init">
                    <h3>Initiativbewerbung</h3>
                    <p><?php echo esc_html( $meta['jobs_init_text'] ); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( ! empty( $meta['jobs_agg_footer'] ) ) : ?>
            <p class="bschi-jobs__agg"><?php echo esc_html( $meta['jobs_agg_footer'] ); ?></p>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
} );


/**
 * Direktbewerbungs-Formular [bsc_hub_bewerbung] – legt über den Office Hub eine Bewerbung an
 * (source „website"), optional mit Lebenslauf-Upload. Läuft server-seitig über admin-ajax
 * (Hub-Secret bleibt im Server, nicht im Browser). Honeypot als Spam-Schutz.
 * Attribute: job_id (Stelle vorauswählen), title.
 */
add_action( 'wp_ajax_bschi_job_apply', 'bschi_job_apply_ajax' );
add_action( 'wp_ajax_nopriv_bschi_job_apply', 'bschi_job_apply_ajax' );
function bschi_job_apply_ajax() {
    $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
    if ( $name === '' ) {
        wp_send_json_error( [ 'message' => 'Bitte einen Namen angeben.' ], 400 );
    }
    $endpoint = bschi_hub_url( '/api/v1/shop/jobs/apply' );
    if ( ! $endpoint ) {
        wp_send_json_error( [ 'message' => 'Bewerbung derzeit nicht möglich.' ], 500 );
    }
    $cv_b64 = $cv_name = $cv_mime = null;
    if ( ! empty( $_FILES['cv']['tmp_name'] ) && is_uploaded_file( $_FILES['cv']['tmp_name'] ) ) {
        $sz   = (int) ( $_FILES['cv']['size'] ?? 0 );
        $mime = (string) ( $_FILES['cv']['type'] ?? '' );
        $allowed = [ 'application/pdf', 'image/jpeg', 'image/png', 'application/msword',
                     'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ];
        if ( $sz > 0 && $sz <= 8 * 1024 * 1024 && in_array( $mime, $allowed, true ) ) {
            $bytes = file_get_contents( $_FILES['cv']['tmp_name'] );
            if ( $bytes !== false ) {
                $cv_b64  = base64_encode( $bytes );
                $cv_name = sanitize_file_name( (string) ( $_FILES['cv']['name'] ?? 'lebenslauf' ) );
                $cv_mime = $mime;
            }
        } elseif ( $sz > 8 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => 'Der Lebenslauf ist zu groß (max. 8 MB).' ], 400 );
        }
    }
    $payload = [
        'job_id'  => ( (string) ( $_POST['job_id'] ?? '' ) !== '' ) ? (int) $_POST['job_id'] : null,
        'name'    => $name,
        'email'   => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
        'phone'   => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
        'message' => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
        'cv_b64'  => $cv_b64, 'cv_name' => $cv_name, 'cv_mime' => $cv_mime,
        'hp'      => sanitize_text_field( wp_unslash( $_POST['hp'] ?? '' ) ),
    ];
    $response = wp_remote_post( $endpoint, [
        'timeout' => 25, 'headers' => bschi_hub_headers(), 'body' => wp_json_encode( $payload ),
    ] );
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => 'Übermittlung gerade nicht möglich. Bitte später erneut versuchen.' ], 502 );
    }
    $code = wp_remote_retrieve_response_code( $response );
    if ( $code >= 400 ) {
        $b = json_decode( wp_remote_retrieve_body( $response ), true );
        $msg = ( is_array( $b ) && ! empty( $b['detail'] ) && is_string( $b['detail'] ) ) ? $b['detail'] : 'Bewerbung fehlgeschlagen.';
        wp_send_json_error( [ 'message' => $msg ], $code );
    }
    wp_send_json_success( [ 'ok' => true ] );
}

add_shortcode( 'bsc_hub_bewerbung', function ( $atts ): string {
    $a = shortcode_atts( [ 'job_id' => '', 'title' => 'Jetzt bewerben' ], is_array( $atts ) ? $atts : [] );
    $data = bschi_hub_get( '/api/v1/shop/jobs', 300 );
    $jobs = ( is_array( $data ) && isset( $data['jobs'] ) ) ? $data['jobs'] : [];
    $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
    $uid  = 'bschiapp_' . wp_rand( 1000, 9999 );
    $presel = (int) $a['job_id'];
    ob_start();
    ?>
    <style>
    .bschi-appf{max-width:640px;margin:0 auto;font-family:inherit}
    .bschi-appf__row{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
    .bschi-appf__row label{font-weight:700;font-size:.92rem;color:#3a3228}
    .bschi-appf input,.bschi-appf select,.bschi-appf textarea{width:100%;box-sizing:border-box;padding:11px 13px;border:1px solid #d9d0be;border-radius:10px;font:inherit;font-size:1rem;background:#fff;color:#2c2a25}
    .bschi-appf input:focus,.bschi-appf select:focus,.bschi-appf textarea:focus{outline:none;border-color:#4b5a42;box-shadow:0 0 0 3px rgba(75,90,66,.14)}
    .bschi-appf textarea{min-height:110px;resize:vertical}
    .bschi-appf__hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}
    .bschi-appf__file{font-size:.9rem}
    .bschi-appf__hint{font-size:.8rem;color:#7a7060;margin-top:3px}
    .bschi-appf__btn{background:#4b5a42;color:#fff;border:0;font-weight:700;letter-spacing:.2px;padding:13px 26px;border-radius:11px;font-size:1rem;cursor:pointer;transition:background .15s}
    .bschi-appf__btn:hover{background:#3c4835}.bschi-appf__btn:disabled{opacity:.6;cursor:default}
    .bschi-appf__msg{margin-top:12px;padding:12px 14px;border-radius:10px;font-size:.95rem;display:none}
    .bschi-appf__msg--ok{display:block;background:#eaf3e6;border:1px solid #bcd8b0;color:#33562a}
    .bschi-appf__msg--err{display:block;background:#f8e8e5;border:1px solid #e2b6ad;color:#8a3226}
    </style>
    <form id="<?php echo esc_attr( $uid ); ?>" class="bschi-appf" enctype="multipart/form-data" novalidate>
        <?php if ( ! empty( $jobs ) ) : ?>
        <div class="bschi-appf__row">
            <label for="<?php echo esc_attr( $uid ); ?>_job">Stelle</label>
            <select id="<?php echo esc_attr( $uid ); ?>_job" name="job_id">
                <option value="">Initiativbewerbung</option>
                <?php foreach ( $jobs as $j ) : ?>
                    <option value="<?php echo (int) $j['id']; ?>" <?php selected( $presel, (int) $j['id'] ); ?>><?php echo esc_html( $j['title'] ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="bschi-appf__row"><label>Name *</label><input type="text" name="name" required autocomplete="name"></div>
        <div class="bschi-appf__row"><label>E-Mail *</label><input type="email" name="email" required autocomplete="email"></div>
        <div class="bschi-appf__row"><label>Telefon</label><input type="tel" name="phone" autocomplete="tel"></div>
        <div class="bschi-appf__row"><label>Nachricht / Anschreiben</label><textarea name="message" placeholder="Erzähl uns kurz, warum du zu uns passt."></textarea></div>
        <div class="bschi-appf__row"><label>Lebenslauf (optional)</label>
            <input class="bschi-appf__file" type="file" name="cv" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            <div class="bschi-appf__hint">PDF, Word oder Bild, max. 8 MB.</div></div>
        <div class="bschi-appf__hp"><label>Bitte frei lassen<input type="text" name="hp" tabindex="-1" autocomplete="off"></label></div>
        <button type="submit" class="bschi-appf__btn"><?php echo esc_html( $a['title'] ); ?></button>
        <div class="bschi-appf__msg" id="<?php echo esc_attr( $uid ); ?>_msg"></div>
    </form>
    <script>
    (function(){
      var f=document.getElementById('<?php echo esc_js( $uid ); ?>');
      if(!f||f.dataset.bound)return; f.dataset.bound='1';
      var msg=document.getElementById('<?php echo esc_js( $uid ); ?>_msg');
      var AJAX='<?php echo $ajax; ?>';
      f.addEventListener('submit',function(e){
        e.preventDefault();
        var btn=f.querySelector('.bschi-appf__btn');
        msg.className='bschi-appf__msg';
        var fd=new FormData(f); fd.append('action','bschi_job_apply');
        btn.disabled=true; var ot=btn.textContent; btn.textContent='Wird gesendet …';
        fetch(AJAX,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
          if(d&&d.success){
            f.reset();
            msg.className='bschi-appf__msg bschi-appf__msg--ok';
            msg.textContent='Vielen Dank! Deine Bewerbung ist bei uns eingegangen – wir melden uns.';
          }else{
            msg.className='bschi-appf__msg bschi-appf__msg--err';
            msg.textContent=(d&&d.data&&d.data.message)?d.data.message:'Es ist ein Fehler aufgetreten. Bitte versuche es erneut.';
          }
        }).catch(function(){
          msg.className='bschi-appf__msg bschi-appf__msg--err';
          msg.textContent='Verbindung fehlgeschlagen. Bitte später erneut versuchen.';
        }).finally(function(){ btn.disabled=false; btn.textContent=ot; });
      });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
} );
