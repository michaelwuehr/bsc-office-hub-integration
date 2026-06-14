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

add_shortcode( 'bsc_hub_event', function ( $atts ): string {
    if ( ! bschi_feature_enabled( 'event_banner' ) ) {
        return '';
    }
    $a = shortcode_atts( [
        'limit'    => 6,
        'cta_url'  => '',
        'cta_text' => 'Jetzt anfragen',
    ], $atts, 'bsc_hub_event' );

    $limit = max( 1, min( 30, (int) $a['limit'] ) );
    $data  = bschi_hub_get( '/api/v1/shop/events/upcoming?limit=' . $limit, BSCHI_EVENT_CACHE_TTL );
    $events = $data['events'] ?? null;
    if ( empty( $events ) ) {
        return '';
    }
    return bschi_event_render( $events, $a['cta_url'] ?: home_url( '/kontakt' ), $a['cta_text'] );
} );

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
            $location = trim( (string) ( $e['location'] ?? '' ) );
            $duration = trim( (string) ( $e['duration'] ?? '' ) );
            $image    = trim( (string) ( $e['image_url'] ?? '' ) );
            $is_termin = ( ( $e['kind'] ?? '' ) === 'termin' );
            $ts        = ! empty( $e['scheduled_at'] ) ? strtotime( $e['scheduled_at'] ) : 0;
            $price     = isset( $e['price'] ) && $e['price'] !== null ? (float) $e['price'] : null;

            $img_style = $image
                ? 'background-image:linear-gradient(rgba(0,0,0,.05),rgba(0,0,0,.18)),url(' . esc_url( $image ) . ')'
                : 'background:linear-gradient(135deg,#4b5a42,#3a4633)';
            ?>
            <div class="bschi-ev__card">
                <div class="bschi-ev__img" style="<?= $img_style; ?>">
                    <?php if ( $is_termin && $ts ) : ?>
                        <div class="bschi-ev__date"><b><?= esc_html( date_i18n( 'd', $ts ) ); ?></b><span><?= esc_html( date_i18n( 'M', $ts ) ); ?></span></div>
                    <?php endif; ?>
                    <span class="bschi-ev__tag"><?= $is_termin ? 'Termin' : 'Dauer-Angebot'; ?></span>
                </div>
                <div class="bschi-ev__body">
                    <h3 class="bschi-ev__title"><?= esc_html( $title ); ?></h3>
                    <div class="bschi-ev__meta">
                        <?php if ( $is_termin && $ts ) : ?><span>&#128197; <?= esc_html( date_i18n( 'l, d.m.Y', $ts ) ); ?><?php if ( ! empty( $e['time_label'] ) ) : ?>, <?= esc_html( $e['time_label'] ); ?><?php endif; ?></span><?php endif; ?>
                        <?php if ( $location ) : ?><span>&#128205; <?= esc_html( $location ); ?></span><?php endif; ?>
                        <?php if ( $duration ) : ?><span>&#9201; <?= esc_html( $duration ); ?></span><?php endif; ?>
                    </div>
                    <?php if ( $teaser ) : ?><p class="bschi-ev__teaser"><?= esc_html( $teaser ); ?></p><?php endif; ?>
                    <?php if ( $price !== null ) : ?><div class="bschi-ev__price"><?= esc_html( number_format( $price, 2, ',', '.' ) ); ?>&nbsp;€ p.&nbsp;P.</div><?php endif; ?>
                    <a class="bschi-ev__cta" href="<?= esc_url( $cta_url ); ?>"><?= esc_html( $cta_text ); ?></a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Einmalig das Karten-CSS ausgeben.
 */
function bschi_event_styles(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="bschi-event-css">
    .bschi-ev{display:grid;grid-template-columns:repeat(auto-fill,minmax(258px,1fr));gap:18px;margin:0 0 24px;font-family:inherit}
    .bschi-ev__card{border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 8px 28px rgba(0,0,0,.1);display:flex;flex-direction:column;border:1px solid #ededeb}
    .bschi-ev__img{position:relative;aspect-ratio:16/10;background-size:cover;background-position:center;background-repeat:no-repeat}
    .bschi-ev__date{position:absolute;top:10px;left:10px;background:#fff;border-radius:11px;padding:6px 11px;text-align:center;box-shadow:0 4px 12px rgba(0,0,0,.2);line-height:1}
    .bschi-ev__date b{display:block;font-size:19px;font-weight:900;color:#b56b43}
    .bschi-ev__date span{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#666;font-weight:700}
    .bschi-ev__tag{position:absolute;top:10px;right:10px;background:rgba(75,90,66,.94);color:#fff;font-size:10px;font-weight:700;letter-spacing:.5px;border-radius:7px;padding:4px 9px}
    .bschi-ev__body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
    .bschi-ev__title{font-size:17px;font-weight:800;line-height:1.22;margin:0;color:#2c2a25}
    .bschi-ev__meta{font-size:12.5px;color:#6b6257;display:flex;flex-direction:column;gap:3px}
    .bschi-ev__teaser{font-size:13.5px;color:#5a544c;line-height:1.5;margin:0;flex:1}
    .bschi-ev__price{font-weight:800;color:#4b5a42;font-size:14.5px}
    .bschi-ev__cta{margin-top:6px;text-align:center;background:#4b5a42;color:#fff;font-weight:700;text-decoration:none;border-radius:10px;padding:11px 16px;font-size:14px;transition:background .15s}
    .bschi-ev__cta:hover{background:#3a4633}
    </style>';
}
