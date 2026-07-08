<?php
/**
 * BSC Office Hub Integration – Modul: Veranstaltungs-Banner.
 *
 * Shortcode [bsc_hub_event] bewirbt kommende Führungen/Veranstaltungen aus dem
 * Office Hub (GET /api/v1/shop/events/upcoming) als Karten-Grid.
 * Kombiniert konkrete Termine (mit Datum) und buchbare Dauer-Angebote.
 *
 * Attribute: [bsc_hub_event limit="6" cta_url="/kontakt" cta_text="Jetzt anfragen"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BSCHI_EVENT_CACHE_TTL', 600 ); // 10 Minuten

// ─── AJAX: Veranstaltungs-Detail (für „Mehr Infos"-Modal) ──────────────────────
add_action( 'wp_ajax_bschi_event_detail', 'bschi_event_detail_ajax' );
add_action( 'wp_ajax_nopriv_bschi_event_detail', 'bschi_event_detail_ajax' );
function bschi_event_detail_ajax() {
    if ( ! bschi_feature_enabled( 'event_banner' ) ) {
        wp_send_json_error( [ 'message' => 'Nicht verfügbar' ], 403 );
    }
    $id = (int) ( $_GET['id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( [ 'message' => 'Ungültig' ], 400 );
    }
    $data = bschi_hub_get( '/api/v1/shop/events/' . $id, 120 );
    if ( $data === null ) {
        wp_send_json_error( [ 'message' => 'Veranstaltung nicht erreichbar' ], 502 );
    }
    wp_send_json_success( $data );
}

// ─── AJAX: Anmeldung (Proxy zum Hub, Secret bleibt am Server) ──────────────────
add_action( 'wp_ajax_bschi_event_signup', 'bschi_event_signup_ajax' );
add_action( 'wp_ajax_nopriv_bschi_event_signup', 'bschi_event_signup_ajax' );
function bschi_event_signup_ajax() {
    if ( ! bschi_feature_enabled( 'event_banner' ) ) {
        wp_send_json_error( [ 'message' => 'Nicht verfügbar' ], 403 );
    }
    $id = (int) ( $_POST['id'] ?? 0 );
    if ( ! $id ) {
        wp_send_json_error( [ 'message' => 'Ungültig' ], 400 );
    }
    $endpoint = bschi_hub_url( '/api/v1/shop/events/' . $id . '/signup' );
    if ( ! $endpoint ) {
        wp_send_json_error( [ 'message' => 'Hub nicht konfiguriert' ], 500 );
    }
    $payload = [
        'name'    => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
        'email'   => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
        'phone'   => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
        'persons' => (int) ( $_POST['persons'] ?? 1 ),
        'note'    => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
        'hp'      => sanitize_text_field( wp_unslash( $_POST['hp'] ?? '' ) ),  // Honeypot
    ];
    $response = wp_remote_post( $endpoint, [
        'timeout' => 15,
        'headers' => bschi_hub_headers(),
        'body'    => wp_json_encode( $payload ),
    ] );
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => 'Anmeldung gerade nicht möglich' ], 502 );
    }
    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $code >= 400 ) {
        $msg = ( is_array( $body ) && ! empty( $body['detail'] ) && is_string( $body['detail'] ) )
            ? $body['detail'] : 'Anmeldung fehlgeschlagen';
        wp_send_json_error( [ 'message' => $msg ], $code );
    }
    wp_send_json_success( is_array( $body ) ? $body : [ 'ok' => true ] );
}

add_shortcode( 'bsc_hub_event', function ( $atts ): string {
    bschi_report_link( 'events' );
    if ( ! bschi_feature_enabled( 'event_banner' ) ) {
        return '';
    }
    $s = bschi_get_settings();
    $a = shortcode_atts( [
        'limit'     => 6,
        'cta_url'   => '',
        'cta_text'  => 'Jetzt anfragen',
        'layout'    => '',   // cards | list  (leer = Admin-Default)
        'lead_days' => '',   // Vorlaufzeit in Tagen (leer = Admin-Default; 0 = ohne Begrenzung)
    ], $atts, 'bsc_hub_event' );

    $limit  = max( 1, min( 30, (int) $a['limit'] ) );
    $layout = in_array( $a['layout'], [ 'cards', 'list' ], true ) ? $a['layout'] : ( $s['event_layout'] ?? 'cards' );
    $lead   = ( $a['lead_days'] === '' ) ? (int) ( $s['event_lead_days'] ?? 0 ) : (int) $a['lead_days'];
    $lead   = max( 0, min( 365, $lead ) );

    $url    = '/api/v1/shop/events/upcoming?limit=' . $limit . ( $lead > 0 ? '&within_days=' . $lead : '' );
    $data   = bschi_hub_get( $url, BSCHI_EVENT_CACHE_TTL );
    $events = $data['events'] ?? null;
    bschi_event_set_hub_css( $data['design_css'] ?? '' );
    if ( empty( $events ) ) {
        return '';
    }
    $cta_url  = $a['cta_url'] ?: home_url( '/kontakt' );
    return $layout === 'list'
        ? bschi_event_render_list( $events, $cta_url, $a['cta_text'] )
        : bschi_event_render( $events, $cta_url, $a['cta_text'] );
} );

/**
 * Header-Banner: nur die HEUTIGEN Veranstaltungen, kompakt + „Mehr Infos" (öffnet das Modal).
 * Zeigt nichts, wenn heute keine Veranstaltung ist.  [bsc_hub_event_today]
 */
add_shortcode( 'bsc_hub_event_today', function ( $atts ): string {
    if ( ! bschi_feature_enabled( 'event_banner' ) ) {
        return '';
    }
    $s = bschi_get_settings();
    $a = shortcode_atts( [ 'cta_text' => 'Mehr Infos', 'hours' => '' ], $atts, 'bsc_hub_event_today' );
    $hours = ( $a['hours'] === '' ) ? (int) ( $s['event_today_hours'] ?? 0 ) : (int) $a['hours'];
    $hours = max( 0, min( 168, $hours ) );

    $now        = (int) current_time( 'timestamp' );
    $window_end = $hours > 0 ? ( $now + $hours * 3600 ) : strtotime( date( 'Y-m-d 23:59:59', $now ) );
    $wd         = $hours > 0 ? max( 1, (int) ceil( $hours / 24 ) ) : 1;
    $data   = bschi_hub_get( '/api/v1/shop/events/upcoming?limit=20&within_days=' . $wd . '&since_midnight=1', 180 );
    $events = $data['events'] ?? [];
    bschi_event_set_hub_css( $data['design_css'] ?? '' );
    $list   = [];
    foreach ( $events as $e ) {
        if ( ( $e['kind'] ?? '' ) !== 'termin' || empty( $e['scheduled_at'] ) ) {
            continue;
        }
        $ts  = strtotime( $e['scheduled_at'] );
        $end = ! empty( $e['end_at'] ) ? strtotime( $e['end_at'] ) : $ts;
        // im Fenster (Start bis window_end) UND noch nicht vorbei
        if ( $ts <= $window_end && $end >= $now ) {
            $list[] = $e;
        }
    }
    if ( ! $list ) {
        return '';
    }
    ob_start();
    echo bschi_event_styles();
    foreach ( $list as $e ) :
        $ts = strtotime( $e['scheduled_at'] );
        ?>
        <div class="bschi-evt" style="<?= bschi_event_today_bg( (string) ( $e['image_url'] ?? '' ) ); ?>">
            <span class="bschi-evt__lbl"><?= esc_html( bschi_event_when_label( strtotime( $e['scheduled_at'] ) ) ); ?></span>
            <div class="bschi-evt__main">
                <div class="bschi-evt__title"><?= esc_html( $e['title'] ?? 'Veranstaltung' ); ?>
                    <?php if ( ! empty( $e['cancelled'] ) ) : ?> <span class="bschi-evt__state bschi-evt__state--cancel">Abgesagt</span>
                    <?php elseif ( ! empty( $e['sold_out'] ) ) : ?> <span class="bschi-evt__state bschi-evt__state--full">Ausgebucht</span><?php endif; ?>
                </div>
                <div class="bschi-evt__meta">
                    <?php if ( ! empty( $e['time_label'] ) ) : ?><span>&#128337; <?= esc_html( $e['time_label'] ); ?></span><?php endif; ?>
                    <?php if ( ! empty( $e['location'] ) ) : ?><span>&#128205; <?= esc_html( $e['location'] ); ?></span><?php endif; ?>
                    <?php if ( ! empty( $e['hinweis'] ) ) : ?><span style="font-weight:700;color:#b56b43">&#9432; <?= esc_html( $e['hinweis'] ); ?></span><?php endif; ?>
                </div>
                <?php if ( ! empty( $e['id'] ) ) : ?>
                    <a href="#" class="bschi-evt__link bschi-ev__more" data-ev="<?= (int) $e['id']; ?>"><?= esc_html( $a['cta_text'] ); ?> &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    endforeach;
    echo bschi_event_modal_once();
    return (string) ob_get_clean();
} );

/**
 * Hintergrund-CSS für ein Event-Bild.
 * Wichtig: data:-URLs (Upload aus dem Hub) NICHT durch esc_url() schicken – das entfernt sie.
 * Base64 enthält keine Anführungszeichen; wir beschränken auf erlaubte Zeichen und betten in url('') ein.
 */
function bschi_event_bg( string $image ): string {
    $grad = 'linear-gradient(rgba(0,0,0,.05),rgba(0,0,0,.18))';
    if ( $image === '' ) {
        return 'background:linear-gradient(135deg,#4b5a42,#3a4633)';
    }
    if ( strncmp( $image, 'data:image/', 11 ) === 0 ) {
        $safe = preg_replace( '#[^a-zA-Z0-9:/;,+=._-]#', '', $image );
        return "background-image:$grad,url('$safe')";
    }
    return "background-image:$grad,url(" . esc_url( $image ) . ')';
}

/**
 * Relativer Tages-Hinweis: Heute / Morgen / Übermorgen (sonst leer). WP-Zeitzone.
 */
function bschi_event_daypill( int $ts ): string {
    if ( ! $ts ) {
        return '';
    }
    $now  = (int) current_time( 'timestamp' );
    $diff = (int) round( ( strtotime( date( 'Y-m-d', $ts ) ) - strtotime( date( 'Y-m-d', $now ) ) ) / 86400 );
    return [ 0 => 'Heute', 1 => 'Morgen', 2 => 'Übermorgen' ][ $diff ] ?? '';
}

/**
 * Hintergrund des Heute-Banners: grün; falls ein Event-Bild da ist, dezent dahinter einblenden
 * (kräftiger grüner Overlay, damit der weiße Text lesbar bleibt).
 */
function bschi_event_today_bg( string $image ): string {
    $ov = 'linear-gradient(rgba(58,70,51,.86),rgba(46,56,40,.93))';
    if ( $image === '' ) {
        return 'background:linear-gradient(135deg,#4b5a42,#3a4633)';
    }
    if ( strncmp( $image, 'data:image/', 11 ) === 0 ) {
        $safe = preg_replace( '#[^a-zA-Z0-9:/;,+=._-]#', '', $image );
        return "background-image:$ov,url('$safe');background-size:cover;background-position:center";
    }
    return "background-image:$ov,url(" . esc_url( $image ) . ');background-size:cover;background-position:center';
}

/**
 * Dynamisches Label fürs Heute-Banner: Jetzt / in X Std. / Heute / Morgen / in X Tagen.
 */
function bschi_event_when_label( int $ts ): string {
    if ( ! $ts ) {
        return 'Heute';
    }
    $now  = (int) current_time( 'timestamp' );
    $secs = $ts - $now;
    if ( $secs <= 0 ) {
        return 'Jetzt';
    }
    $days = (int) round( ( strtotime( date( 'Y-m-d', $ts ) ) - strtotime( date( 'Y-m-d', $now ) ) ) / 86400 );
    if ( $days === 0 ) {
        if ( $secs <= 6 * 3600 ) {
            return 'in ' . max( 1, (int) ceil( $secs / 3600 ) ) . ' Std.';
        }
        return 'Heute';
    }
    if ( $days === 1 ) {
        // morgen früh und nah dran → lieber Stunden
        return ( $secs <= 12 * 3600 ) ? ( 'in ' . (int) ceil( $secs / 3600 ) . ' Std.' ) : 'Morgen';
    }
    return 'in ' . $days . ' Tagen';
}

/**
 * Veranstaltungs-Karten rendern.
 */
function bschi_event_render( array $events, string $cta_url, string $cta_text ): string {
    ob_start();
    echo bschi_event_styles();
    ?>
    <div class="bschi-ev">
        <?php foreach ( $events as $e ) :
            $title    = trim( (string) ( $e['title'] ?? 'Veranstaltung' ) );
            $teaser   = trim( (string) ( $e['teaser'] ?? '' ) );
            $hinweis  = trim( (string) ( $e['hinweis'] ?? '' ) );
            $location = trim( (string) ( $e['location'] ?? '' ) );
            $duration = trim( (string) ( $e['duration'] ?? '' ) );
            $image    = trim( (string) ( $e['image_url'] ?? '' ) );
            $is_termin = ( ( $e['kind'] ?? '' ) === 'termin' );
            $ts        = ! empty( $e['scheduled_at'] ) ? strtotime( $e['scheduled_at'] ) : 0;
            $price     = isset( $e['price'] ) && $e['price'] !== null ? (float) $e['price'] : null;

            $img_style = bschi_event_bg( $image );
            ?>
            <?php $eid = (int) ( $e['id'] ?? 0 ); ?>
            <div class="bschi-ev__card<?= ! empty( $e['cancelled'] ) ? ' bschi-ev__card--cancel' : ''; ?>">
                <div class="bschi-ev__img" style="<?= $img_style; ?>">
                    <?php if ( $is_termin && $ts ) : ?>
                        <div class="bschi-ev__date"><b><?= esc_html( date_i18n( 'd', $ts ) ); ?></b><span><?= esc_html( date_i18n( 'M', $ts ) ); ?></span></div>
                    <?php endif; ?>
                    <span class="bschi-ev__tag"><?= $is_termin ? 'Termin' : 'Dauer-Angebot'; ?></span>
                    <?php if ( ! empty( $e['cancelled'] ) ) : ?>
                        <span class="bschi-ev__badge bschi-ev__badge--cancel">Abgesagt</span>
                    <?php elseif ( ! empty( $e['sold_out'] ) ) : ?>
                        <span class="bschi-ev__badge bschi-ev__badge--full">Ausgebucht</span>
                    <?php endif; ?>
                </div>
                <div class="bschi-ev__body">
                    <?php $pill = ( $is_termin && $ts ) ? bschi_event_daypill( $ts ) : ''; ?>
                    <?php if ( $pill ) : ?><span class="bschi-ev__pill<?= $pill === 'Heute' ? ' bschi-ev__pill--today' : ''; ?>"><?= esc_html( $pill ); ?></span><?php endif; ?>
                    <h3 class="bschi-ev__title"><?= esc_html( $title ); ?></h3>
                    <div class="bschi-ev__meta">
                        <?php if ( $is_termin && $ts ) : ?><span>&#128197; <?= esc_html( date_i18n( 'l, d.m.Y', $ts ) ); ?><?php if ( ! empty( $e['time_label'] ) ) : ?>, <?= esc_html( $e['time_label'] ); ?><?php endif; ?></span><?php endif; ?>
                        <?php if ( $location ) : ?><span>&#128205; <?= esc_html( $location ); ?></span><?php endif; ?>
                        <?php if ( $duration ) : ?><span>&#9201; <?= esc_html( $duration ); ?></span><?php endif; ?>
                    </div>
                    <?php if ( $teaser ) : ?><p class="bschi-ev__teaser"><?= esc_html( $teaser ); ?></p><?php endif; ?>
                    <?php if ( $hinweis ) : ?><p class="bschi-ev__hint" style="display:inline-block;background:#b56b43;color:#fff;font-weight:700;border-radius:8px;padding:6px 12px;margin:8px 0 0;font-size:.9em;line-height:1.35"><?= esc_html( $hinweis ); ?></p><?php endif; ?>
                    <?php if ( $price !== null ) : ?><div class="bschi-ev__price"><?= esc_html( number_format( $price, 2, ',', '.' ) ); ?>&nbsp;€ p.&nbsp;P.</div><?php endif; ?>
                    <?php if ( $eid ) : ?>
                        <button type="button" class="bschi-ev__cta bschi-ev__more" data-ev="<?= $eid; ?>">Mehr Infos</button>
                    <?php else : ?>
                        <a class="bschi-ev__cta" href="<?= esc_url( $cta_url ); ?>"><?= esc_html( $cta_text ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    echo bschi_event_modal_once();
    return (string) ob_get_clean();
}

/**
 * Übersichts-Ansicht: kompakte Liste/Agenda mehrerer Veranstaltungen (eine Zeile je Event).
 */
function bschi_event_render_list( array $events, string $cta_url, string $cta_text ): string {
    ob_start();
    echo bschi_event_styles();
    ?>
    <div class="bschi-evl">
        <?php foreach ( $events as $e ) :
            $title    = trim( (string) ( $e['title'] ?? 'Veranstaltung' ) );
            $teaser   = trim( (string) ( $e['teaser'] ?? '' ) );
            $hinweis  = trim( (string) ( $e['hinweis'] ?? '' ) );
            $location = trim( (string) ( $e['location'] ?? '' ) );
            $duration = trim( (string) ( $e['duration'] ?? '' ) );
            $is_termin = ( ( $e['kind'] ?? '' ) === 'termin' );
            $ts        = ! empty( $e['scheduled_at'] ) ? strtotime( $e['scheduled_at'] ) : 0;
            $price     = isset( $e['price'] ) && $e['price'] !== null ? (float) $e['price'] : null;
            ?>
            <div class="bschi-evl__row">
                <div class="bschi-evl__date">
                    <?php if ( $is_termin && $ts ) : ?>
                        <b><?= esc_html( date_i18n( 'd', $ts ) ); ?></b><span><?= esc_html( date_i18n( 'M', $ts ) ); ?></span>
                    <?php else : ?>
                        <b>&#8734;</b><span>Termin n.&nbsp;V.</span>
                    <?php endif; ?>
                </div>
                <div class="bschi-evl__main">
                    <?php $pill = ( $is_termin && $ts ) ? bschi_event_daypill( $ts ) : ''; ?>
                    <div class="bschi-evl__title"><?php if ( $pill ) : ?><span class="bschi-ev__pill<?= $pill === 'Heute' ? ' bschi-ev__pill--today' : ''; ?>" style="margin-right:7px"><?= esc_html( $pill ); ?></span><?php endif; ?><?= esc_html( $title ); ?></div>
                    <div class="bschi-evl__meta">
                        <?php if ( $is_termin && $ts ) : ?><span><?= esc_html( date_i18n( 'l, d.m.Y', $ts ) ); ?><?php if ( ! empty( $e['time_label'] ) ) : ?>, <?= esc_html( $e['time_label'] ); ?><?php endif; ?></span><?php endif; ?>
                        <?php if ( $location ) : ?><span>&#128205; <?= esc_html( $location ); ?></span><?php endif; ?>
                        <?php if ( $duration ) : ?><span>&#9201; <?= esc_html( $duration ); ?></span><?php endif; ?>
                    </div>
                    <?php if ( $teaser ) : ?><div class="bschi-evl__teaser"><?= esc_html( $teaser ); ?></div><?php endif; ?>
                    <?php if ( $hinweis ) : ?><div class="bschi-evl__hint" style="display:inline-block;background:#b56b43;color:#fff;font-weight:700;border-radius:8px;padding:5px 11px;margin-top:6px;font-size:13px;line-height:1.35"><?= esc_html( $hinweis ); ?></div><?php endif; ?>
                    <?php if ( ! empty( $e['cancelled'] ) ) : ?><span class="bschi-evl__state bschi-evl__state--cancel">Abgesagt</span>
                    <?php elseif ( ! empty( $e['sold_out'] ) ) : ?><span class="bschi-evl__state bschi-evl__state--full">Ausgebucht</span><?php endif; ?>
                </div>
                <div class="bschi-evl__right">
                    <?php if ( $price !== null ) : ?><div class="bschi-evl__price"><?= esc_html( number_format( $price, 2, ',', '.' ) ); ?>&nbsp;€</div><?php endif; ?>
                    <?php $eid = (int) ( $e['id'] ?? 0 ); ?>
                    <?php if ( $eid ) : ?>
                        <button type="button" class="bschi-evl__cta bschi-ev__more" data-ev="<?= $eid; ?>">Mehr Infos</button>
                    <?php else : ?>
                        <a class="bschi-evl__cta" href="<?= esc_url( $cta_url ); ?>"><?= esc_html( $cta_text ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    echo bschi_event_modal_once();
    return (string) ob_get_clean();
}

/**
 * Modal + JS für „Mehr Infos" / Anmeldung – nur einmal pro Seite ausgeben.
 */
function bschi_event_modal_once(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    $ajax = esc_js( admin_url( 'admin-ajax.php' ) );
    ob_start();
    ?>
    <div class="bschi-evm" id="bschi-evm" aria-hidden="true">
        <div class="bschi-evm__box" role="dialog" aria-modal="true">
            <button type="button" class="bschi-evm__close" aria-label="Schließen">&times;</button>
            <div class="bschi-evm__content" id="bschi-evm-content"></div>
        </div>
    </div>
    <script>
    (function(){
        var AJAX = '<?= $ajax; ?>';
        var ov = document.getElementById('bschi-evm');
        var content = document.getElementById('bschi-evm-content');
        if(!ov || ov.dataset.bound) return; ov.dataset.bound = '1';
        function esc(s){ var d=document.createElement('div'); d.textContent = (s==null?'':String(s)); return d.innerHTML; }
        function close(){ ov.classList.remove('open'); ov.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }
        function open(){ ov.classList.add('open'); ov.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
        function safeUrl(u){ u=String(u||''); return (/^https?:\/\//.test(u) || /^data:image\//.test(u)) ? u : ''; }

        function render(d){
            var img = safeUrl(d.image_url);
            var when = '';
            if(d.scheduled_at){ try{ var dt=new Date(d.scheduled_at);
                when = dt.toLocaleDateString('de-DE',{weekday:'long',day:'2-digit',month:'long',year:'numeric'}) + (d.all_day?'':(', '+(d.time_label||(dt.toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'})+' Uhr')))); }catch(e){} }
            var h = '';
            if(img) h += '<div class="bschi-evm__img" style="background-image:url(\''+img.replace(/[^a-zA-Z0-9:/;,+=._\-]/g,'')+'\')"></div>';
            h += '<h3 class="bschi-evm__title">'+esc(d.title||'Veranstaltung')+'</h3>';
            h += '<div class="bschi-evm__meta">';
            if(when) h += '<div>&#128197; '+esc(when)+'</div>';
            if(d.location) h += '<div>&#128205; '+esc(d.location)+'</div>';
            h += '</div>';
            if(d.cancelled){
                h += '<div class="bschi-evm__note bschi-evm__note--cancel">Diese Veranstaltung wurde leider <b>abgesagt</b> und findet nicht statt.</div>';
            }
            if(d.description) h += '<div class="bschi-evm__desc">'+esc(d.description).replace(/\n/g,'<br>')+'</div>';

            var links='';
            var pu=safeUrl(d.post_url); var su=safeUrl(d.social_url);
            if(pu) links += '<a href="'+esc(pu)+'" target="_blank" rel="noopener" class="bschi-evm__link">Mehr Infos zum Beitrag &rarr;</a>';
            if(su) links += '<a href="'+esc(su)+'" target="_blank" rel="noopener" class="bschi-evm__link bschi-evm__link--social">Auf Social Media ansehen &rarr;</a>';
            if(links) h += '<div class="bschi-evm__links">'+links+'</div>';

            if(!d.cancelled){
                if(d.registration_enabled){
                    if(d.sold_out){
                        h += '<div class="bschi-evm__note bschi-evm__note--full">Diese Veranstaltung ist leider <b>ausgebucht</b>.</div>';
                    } else {
                        var left = (d.spots_left!=null) ? '<div class="bschi-evm__left">Noch '+esc(d.spots_left)+' Plätze frei</div>' : '';
                        h += left + '<form class="bschi-evm__form" data-ev="'+esc(d.id)+'">'
                          + '<div class="bschi-evm__row"><label>Name*<input name="name" required></label>'
                          + '<label>Personen<input name="persons" type="number" min="1" max="20" value="1"></label></div>'
                          + '<div class="bschi-evm__row"><label>E-Mail<input name="email" type="email"></label>'
                          + '<label>Telefon<input name="phone"></label></div>'
                          + '<label>Anmerkung<textarea name="note" rows="2"></textarea></label>'
                          + '<input type="text" name="hp" class="bschi-evm__hp" tabindex="-1" autocomplete="off" aria-hidden="true">'
                          + '<button type="submit" class="bschi-evm__submit">Jetzt anmelden</button>'
                          + '<div class="bschi-evm__msg"></div></form>';
                    }
                } else {
                    h += '<div class="bschi-evm__note bschi-evm__note--info">Für diese Veranstaltung ist <b>keine Anmeldung erforderlich</b> – einfach vorbeikommen!</div>';
                }
            }
            content.innerHTML = h;
            var form = content.querySelector('.bschi-evm__form');
            if(form) form.addEventListener('submit', onSubmit);
        }

        function onSubmit(e){
            e.preventDefault();
            var form = e.target, msg = form.querySelector('.bschi-evm__msg');
            var btn = form.querySelector('.bschi-evm__submit');
            if(form.hp && form.hp.value){ return; } // Bot
            btn.disabled = true; btn.textContent = 'Senden…'; msg.textContent='';
            var fd = new FormData();
            fd.append('action','bschi_event_signup');
            fd.append('id', form.getAttribute('data-ev'));
            ['name','email','phone','persons','note','hp'].forEach(function(k){ if(form[k]) fd.append(k, form[k].value); });
            fetch(AJAX, {method:'POST', body:fd, credentials:'same-origin'})
              .then(function(r){return r.json();})
              .then(function(j){
                  if(j && j.success){
                      content.innerHTML = '<div class="bschi-evm__note bschi-evm__note--ok"><b>Danke für deine Anmeldung!</b><br>Wir haben sie erhalten und melden uns bei Rückfragen.</div>';
                  } else {
                      var m = (j && j.data && j.data.message) ? j.data.message : 'Anmeldung fehlgeschlagen.';
                      msg.textContent = m; btn.disabled=false; btn.textContent='Jetzt anmelden';
                  }
              })
              .catch(function(){ msg.textContent='Verbindung fehlgeschlagen.'; btn.disabled=false; btn.textContent='Jetzt anmelden'; });
        }

        document.addEventListener('click', function(e){
            var more = e.target.closest('.bschi-ev__more');
            if(more){ e.preventDefault();
                content.innerHTML = '<div class="bschi-evm__loading">Lädt…</div>'; open();
                fetch(AJAX+'?action=bschi_event_detail&id='+encodeURIComponent(more.getAttribute('data-ev')), {credentials:'same-origin'})
                  .then(function(r){return r.json();})
                  .then(function(j){ if(j && j.success){ render(j.data); } else { content.innerHTML='<div class="bschi-evm__note bschi-evm__note--full">Details konnten nicht geladen werden.</div>'; } })
                  .catch(function(){ content.innerHTML='<div class="bschi-evm__note bschi-evm__note--full">Details konnten nicht geladen werden.</div>'; });
                return;
            }
            if(e.target.closest('.bschi-evm__close') || e.target === ov){ close(); }
        });
        document.addEventListener('keydown', function(e){ if(e.key==='Escape' && ov.classList.contains('open')) close(); });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

/**
 * Einmalig das Karten-CSS ausgeben.
 */
/**
 * Vom Office Hub geliefertes, aus der Generalvorlage generiertes Event-CSS merken
 * (ersetzt das hartkodierte CSS unten; leer = Fallback bleibt aktiv).
 */
function bschi_event_set_hub_css( string $css ): void {
    if ( $css !== '' ) {
        $GLOBALS['bschi_event_hub_css'] = $css;
    }
}

function bschi_event_styles(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    $hub = $GLOBALS['bschi_event_hub_css'] ?? '';
    if ( $hub !== '' ) {
        return $hub;   // zentrale Generalvorlage aus dem Hub
    }
    return '<style id="bschi-event-css">
    /* Kacheln: zentriert; ab >3 horizontal scrollbar (Slider), NIE umbrechen */
    .bschi-ev{display:flex;flex-wrap:nowrap;gap:18px;margin:0 0 24px;font-family:inherit;overflow-x:auto;padding:4px 2px 10px;justify-content:safe center;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
    .bschi-ev::-webkit-scrollbar{height:8px}.bschi-ev::-webkit-scrollbar-thumb{background:rgba(0,0,0,.18);border-radius:4px}
    .bschi-ev__card{flex:0 0 clamp(250px,80vw,290px);scroll-snap-align:center;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 8px 28px rgba(0,0,0,.1);display:flex;flex-direction:column;border:1px solid #ededeb}
    .bschi-ev__img{position:relative;aspect-ratio:16/10;background-size:cover;background-position:center;background-repeat:no-repeat}
    .bschi-ev__date{position:absolute;top:10px;left:10px;background:#fff;border-radius:11px;padding:6px 11px;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,.2);line-height:1}
    .bschi-ev__date b{display:block;font-size:19px;font-weight:900;color:#b56b43}
    .bschi-ev__date span{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#666;font-weight:700}
    .bschi-ev__tag{position:absolute;top:10px;right:10px;background:rgba(75,90,66,.94);color:#fff;font-size:10px;font-weight:700;letter-spacing:.5px;border-radius:7px;padding:4px 9px}
    .bschi-ev__body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
    .bschi-ev__title{font-size:17px;font-weight:500;line-height:1.22;margin:0;color:#2c2a25}
    .bschi-ev__meta{font-size:12.5px;color:#6b6257;display:flex;flex-direction:column;gap:3px}
    .bschi-ev__teaser{font-size:13.5px;color:#5a544c;line-height:1.5;margin:0;flex:1}
    .bschi-ev__price{font-weight:800;color:#4b5a42;font-size:14.5px}
    .bschi-ev__cta{margin-top:6px;text-align:center;background:#4b5a42;color:#fff;font-weight:700;text-decoration:none;border-radius:10px;padding:11px 16px;font-size:14px;transition:background .15s}
    .bschi-ev__cta:hover{background:#3a4633}
    /* Übersichts-/Listen-Ansicht */
    .bschi-evl{display:flex;flex-direction:column;gap:10px;margin:0 0 24px;font-family:inherit}
    .bschi-evl__row{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #ededeb;border-radius:14px;padding:12px 16px;box-shadow:0 4px 16px rgba(0,0,0,.06)}
    .bschi-evl__date{flex:none;width:54px;text-align:center;background:#f6f4ef;border-radius:10px;padding:7px 4px;line-height:1}
    .bschi-evl__date b{display:block;font-size:20px;font-weight:900;color:#b56b43}
    .bschi-evl__date span{font-size:9.5px;text-transform:uppercase;letter-spacing:.6px;color:#777;font-weight:700}
    .bschi-evl__main{flex:1;min-width:0}
    .bschi-evl__title{font-size:16px;font-weight:500;color:#2c2a25;line-height:1.25}
    .bschi-evl__meta{font-size:12.5px;color:#6b6257;display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:3px}
    .bschi-evl__teaser{font-size:13px;color:#5a544c;line-height:1.45;margin-top:4px}
    .bschi-evl__right{flex:none;display:flex;flex-direction:column;align-items:flex-end;gap:6px}
    .bschi-evl__price{font-weight:800;color:#4b5a42;font-size:14px;white-space:nowrap}
    .bschi-evl__cta{background:#4b5a42;color:#fff;font-weight:700;text-decoration:none;border-radius:9px;padding:9px 14px;font-size:13px;white-space:nowrap;transition:background .15s}
    .bschi-evl__cta:hover{background:#3a4633}
    @media(max-width:560px){.bschi-evl__row{flex-wrap:wrap}.bschi-evl__right{width:100%;flex-direction:row;justify-content:space-between;align-items:center}}
    /* Tages-Pill (Heute/Morgen/Übermorgen) */
    .bschi-ev__pill{align-self:flex-start;display:inline-block;background:#b56b43;color:#fff;font-size:11px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;border-radius:7px;padding:3px 10px}
    .bschi-ev__pill--today{background:#c0392b;box-shadow:0 0 0 3px rgba(192,57,43,.18)}
    /* Modal: Weiterführende Links */
    .bschi-evm__links{display:flex;flex-direction:column;gap:8px;margin:12px 0}
    .bschi-evm__link{display:inline-block;background:#f0efe9;color:#4b5a42;font-weight:700;text-decoration:none;border-radius:9px;padding:11px 14px;font-size:14px;text-align:center}
    .bschi-evm__link--social{background:#eef0fb;color:#4b3f8a}
    /* Header-Banner „Veranstaltung heute" – kompakt, zentriert, gerade Ecken */
    .bschi-evt{display:flex;align-items:center;justify-content:center;gap:11px;flex-wrap:wrap;background:#3a4633;color:#fff;border-radius:0;padding:8px 16px;margin:0 0 16px;font-family:inherit;box-shadow:0 4px 16px rgba(0,0,0,.16);text-align:center}
    .bschi-evt__lbl{background:#e07856;color:#fff;font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;border-radius:0;padding:4px 11px;flex:none}
    .bschi-evt__main{min-width:0;display:flex;flex-direction:column;align-items:center;gap:2px}
    .bschi-evt__title{font-size:15px;font-weight:800;line-height:1.22}
    .bschi-evt__meta{font-size:12px;opacity:.92;display:flex;flex-wrap:wrap;gap:2px 12px;justify-content:center}
    .bschi-evt__state{font-size:10.5px;font-weight:800;border-radius:0;padding:2px 8px;text-transform:uppercase}
    .bschi-evt__state--cancel{background:#c0392b}.bschi-evt__state--full{background:#6b6257}
    .bschi-evt__link{display:inline-block;margin-top:3px;color:#fff;font-weight:700;font-size:13.5px;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
    .bschi-evt__link:hover{opacity:.85}
    .bschi-evt + .bschi-evt{margin-top:-8px}
    /* Status-Badges */
    .bschi-ev__badge{position:absolute;left:10px;bottom:10px;font-size:11px;font-weight:800;letter-spacing:.4px;border-radius:7px;padding:5px 10px;color:#fff;text-transform:uppercase}
    .bschi-ev__badge--cancel{background:#c0392b}
    .bschi-ev__badge--full{background:#6b6257}
    .bschi-ev__card--cancel .bschi-ev__img{filter:grayscale(.6) opacity(.85)}
    .bschi-ev__card--cancel .bschi-ev__title{text-decoration:line-through;text-decoration-color:#c0392b}
    .bschi-evl__state{display:inline-block;margin-top:5px;font-size:11px;font-weight:800;border-radius:6px;padding:2px 8px;color:#fff;text-transform:uppercase}
    .bschi-evl__state--cancel{background:#c0392b}
    .bschi-evl__state--full{background:#6b6257}
    /* Modal */
    .bschi-evm{position:fixed;inset:0;z-index:99999;display:none;align-items:flex-start;justify-content:center;background:rgba(0,0,0,.55);padding:300px 14px 40px;overflow-y:auto}
    .bschi-evm.open{display:flex}
    .bschi-evm__box{position:relative;background:#fff;border-radius:18px;max-width:540px;width:100%;box-shadow:0 24px 70px rgba(0,0,0,.4);padding:0;overflow:hidden}
    .bschi-evm__close{position:absolute;top:10px;right:12px;z-index:2;background:rgba(0,0,0,.5);color:#fff;border:0;border-radius:50%;width:38px;height:38px;min-width:38px;max-width:38px;min-height:38px;box-sizing:border-box;aspect-ratio:1/1;font-size:22px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;flex:none}
    .bschi-evm__content{padding:0 22px 22px}
    .bschi-evm__loading{padding:40px;text-align:center;color:#888}
    .bschi-evm__img{height:200px;background-size:cover;background-position:center;margin:0 -22px 16px;border-bottom:1px solid #eee}
    .bschi-evm__title{font-size:21px;font-weight:800;color:#2c2a25;margin:18px 0 8px;line-height:1.2}
    .bschi-evm__meta{font-size:13.5px;color:#6b6257;display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
    .bschi-evm__desc{font-size:14.5px;line-height:1.6;color:#4a443c;margin-bottom:16px;white-space:normal}
    .bschi-evm__left{font-size:13px;font-weight:700;color:#4b5a42;margin-bottom:8px}
    .bschi-evm__note{border-radius:12px;padding:13px 15px;font-size:14px;line-height:1.5;margin:10px 0}
    .bschi-evm__note--cancel{background:#fdecea;color:#a5281b;border:1px solid #f3c2bc}
    .bschi-evm__note--full{background:#f1efe9;color:#5a544c;border:1px solid #e2ded3}
    .bschi-evm__note--info{background:#eef5ee;color:#3a4633;border:1px solid #cfe0cf}
    .bschi-evm__note--ok{background:#e7f5ec;color:#1d6b34;border:1px solid #b9e2c6}
    .bschi-evm__form{display:flex;flex-direction:column;gap:11px;margin-top:6px}
    .bschi-evm__row{display:flex;gap:11px}
    .bschi-evm__form label{flex:1;display:flex;flex-direction:column;font-size:12.5px;font-weight:600;color:#5a544c;gap:4px}
    .bschi-evm__form input,.bschi-evm__form textarea{border:1px solid #d8d3c8;border-radius:9px;padding:10px 11px;font-size:14px;font-family:inherit;background:#fff;color:#2c2a25}
    .bschi-evm__hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;opacity:0!important}
    .bschi-evm__submit{background:#4b5a42;color:#fff;border:0;border-radius:10px;padding:13px;font-size:15px;font-weight:700;cursor:pointer;margin-top:4px}
    .bschi-evm__submit:disabled{opacity:.6;cursor:default}
    .bschi-evm__msg{font-size:13px;color:#c0392b;min-height:16px}
    @media(max-width:480px){.bschi-evm__row{flex-direction:column;gap:11px}}
    </style>';
}
