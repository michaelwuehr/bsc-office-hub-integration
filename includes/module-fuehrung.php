<?php
/**
 * BSC Office Hub Integration – Modul: Führungs-Buchungsbestätigung.
 *
 * Shortcode [bsc_hub_fuehrung_confirm] bettet die Bestätigungs-Seite des Office Hubs
 * direkt auf woidsiederei.de ein. Das Bestätigungs-Token kommt aus der Seiten-URL
 * (Standard-Parameter ?token=...), sodass der Veranstalter den Link
 *   https://woidsiederei.de/fuehrung-bestaetigung/?token=XYZ
 * statt der Hub-URL erhalten kann. Die eingebettete Seite meldet ihre Höhe per
 * postMessage zurück, damit der Rahmen ohne Scrollbalken automatisch mitwächst.
 *
 * Beispiel-Seite in WordPress: nur den Shortcode einfügen:
 *   [bsc_hub_fuehrung_confirm]
 * Optionale Attribute: param="token" (URL-Parametername), min_height="900".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'bsc_hub_fuehrung_confirm', 'bschi_fuehrung_confirm_shortcode' );
function bschi_fuehrung_confirm_shortcode( $atts ) {
    $atts = shortcode_atts(
        [
            'param'      => 'token',
            'min_height' => '900',
        ],
        $atts,
        'bsc_hub_fuehrung_confirm'
    );

    $param = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $atts['param'] );
    $token = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
    $token = preg_replace( '/[^A-Za-z0-9_\-]/', '', $token );

    if ( $token === '' ) {
        return '<div style="max-width:640px;margin:24px auto;padding:18px;border:1px solid #e0d8c8;'
            . 'border-radius:12px;background:#f7f5f0;color:#5a5045;font-family:sans-serif;line-height:1.5">'
            . 'Kein Bestätigungs-Link erkannt. Bitte öffnen Sie den persönlichen Link aus Ihrer E-Mail.</div>';
    }

    $src = esc_url( bschi_hub_url( '/fuehrung-bestaetigung/' . rawurlencode( $token ) ) );
    $mh  = max( 300, (int) $atts['min_height'] );
    $uid = 'bschifc_' . wp_rand( 1000, 9999 );

    ob_start();
    ?>
    <iframe id="<?php echo esc_attr( $uid ); ?>" src="<?php echo $src; ?>"
            style="width:100%;border:0;min-height:<?php echo (int) $mh; ?>px;overflow:hidden"
            scrolling="no" loading="lazy" title="Buchungsbestätigung Woidsiederei"></iframe>
    <script>
    (function () {
      var f = document.getElementById('<?php echo esc_js( $uid ); ?>');
      if (!f) { return; }
      window.addEventListener('message', function (e) {
        var d = e && e.data;
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch (x) { return; } }
        if (d && d.bschiConfirmHeight) {
          f.style.height = (parseInt(d.bschiConfirmHeight, 10) + 30) + 'px';
        }
      });
    })();
    </script>
    <?php
    return ob_get_clean();
}


/**
 * Shortcode [bsc_hub_fuehrungen] – das Führungs-ANGEBOT (buchbare Pakete) als hochwertige
 * Karten-Galerie. NICHT die datierten Veranstaltungen ([bsc_hub_event]) – hier stehen die
 * Pakete aus dem Modul Führungen (je Paket im Hub unter „Im Führungs-Angebot zeigen" aktiviert).
 * Quelle: GET /api/v1/shop/fuehrungen/pakete (10 Min. Cache).
 * Attribute: cta_url (Anfrage-Ziel), cta_text.
 */
define( 'BSCHI_FP_CACHE_TTL', 600 );

add_shortcode( 'bsc_hub_fuehrungen', function ( $atts ): string {
    $a = shortcode_atts( [
        'cta_url'  => '',
        'cta_text' => 'Jetzt anfragen',
    ], $atts, 'bsc_hub_fuehrungen' );
    $data   = bschi_hub_get( '/api/v1/shop/fuehrungen/pakete', BSCHI_FP_CACHE_TTL );
    $pakete = $data['pakete'] ?? null;
    if ( empty( $pakete ) ) {
        return '';
    }
    $cta_url = $a['cta_url'] ?: home_url( '/kontakt' );
    return bschi_fuehrungen_render( $pakete, $cta_url, $a['cta_text'] );
} );

/**
 * Preis-Label: „ab X,XX € p. P." bzw. Grundpreis; sonst „auf Anfrage".
 */
function bschi_fp_price( array $p ): string {
    if ( isset( $p['price_per_person'] ) && $p['price_per_person'] !== null && (float) $p['price_per_person'] > 0 ) {
        return 'ab ' . number_format( (float) $p['price_per_person'], 2, ',', '.' ) . '&nbsp;€ p.&nbsp;P.';
    }
    if ( isset( $p['base_price'] ) && $p['base_price'] !== null && (float) $p['base_price'] > 0 ) {
        return number_format( (float) $p['base_price'], 2, ',', '.' ) . '&nbsp;€';
    }
    return 'auf Anfrage';
}

function bschi_fuehrungen_render( array $pakete, string $cta_url, string $cta_text ): string {
    ob_start();
    ?>
    <style id="bschi-fp-css">
    .bschi-fp{display:grid;grid-template-columns:repeat(auto-fill,minmax(288px,1fr));gap:26px;margin:0 0 24px;font-family:inherit}
    .bschi-fp__card{display:flex;flex-direction:column;background:#fff;border:1px solid #ececea;border-radius:18px;overflow:hidden;box-shadow:0 10px 34px rgba(0,0,0,.09);transition:transform .18s,box-shadow .18s}
    .bschi-fp__card:hover{transform:translateY(-4px);box-shadow:0 16px 46px rgba(0,0,0,.14)}
    .bschi-fp__img{aspect-ratio:16/10;background:linear-gradient(135deg,#4b5a42,#3a4633);background-size:cover;background-position:center;background-repeat:no-repeat;position:relative}
    .bschi-fp__price{position:absolute;bottom:12px;left:12px;background:#fff;color:#4b5a42;font-weight:800;font-size:15px;border-radius:10px;padding:6px 12px;box-shadow:0 4px 14px rgba(0,0,0,.2)}
    .bschi-fp__body{display:flex;flex-direction:column;gap:11px;padding:20px 22px 22px;flex:1}
    .bschi-fp__title{font-size:20px;font-weight:800;line-height:1.2;color:#2c2a25;margin:0}
    .bschi-fp__teaser{font-size:14px;line-height:1.55;color:#5a544c;margin:0;flex:1}
    .bschi-fp__meta{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12.5px;color:#6b6257;border-top:1px solid #efedea;padding-top:12px}
    .bschi-fp__meta span{display:inline-flex;align-items:center;gap:5px}
    .bschi-fp__cta{margin-top:4px;text-align:center;background:#4b5a42;color:#fff;font-weight:700;text-decoration:none;border-radius:11px;padding:12px 18px;font-size:14.5px;transition:background .15s}
    .bschi-fp__cta:hover{background:#3a4633;color:#fff}
    </style>
    <div class="bschi-fp">
        <?php foreach ( $pakete as $p ) :
            $title = trim( (string) ( $p['title'] ?? 'Führung' ) );
            $teaser = trim( (string) ( $p['teaser'] ?? '' ) );
            if ( $teaser === '' ) {
                $teaser = trim( (string) ( $p['programme'] ?? '' ) );
                if ( mb_strlen( $teaser ) > 180 ) {
                    $teaser = mb_substr( $teaser, 0, 180 ) . '…';
                }
            }
            $img = trim( (string) ( $p['image_url'] ?? '' ) );
            $img_style = $img ? "background-image:linear-gradient(rgba(0,0,0,.04),rgba(0,0,0,.14)),url(" . esc_url( $img ) . ")" : '';
            $dur = trim( (string) ( $p['duration'] ?? '' ) );
            $loc = trim( (string) ( $p['location'] ?? '' ) );
            $minp = $p['min_participants'] ?? null;
            $maxp = $p['max_participants'] ?? null;
            $ppl = ( $minp && $maxp ) ? ( (int) $minp . '–' . (int) $maxp . ' Pers.' ) : ( $maxp ? ( 'bis ' . (int) $maxp . ' Pers.' ) : '' );
            ?>
            <div class="bschi-fp__card">
                <div class="bschi-fp__img" style="<?php echo $img_style; ?>">
                    <span class="bschi-fp__price"><?php echo bschi_fp_price( $p ); ?></span>
                </div>
                <div class="bschi-fp__body">
                    <h3 class="bschi-fp__title"><?php echo esc_html( $title ); ?></h3>
                    <?php if ( $teaser ) : ?><p class="bschi-fp__teaser"><?php echo esc_html( $teaser ); ?></p><?php endif; ?>
                    <?php if ( $dur || $ppl || $loc ) : ?>
                        <div class="bschi-fp__meta">
                            <?php if ( $dur ) : ?><span>&#9201; <?php echo esc_html( $dur ); ?></span><?php endif; ?>
                            <?php if ( $ppl ) : ?><span>&#128101; <?php echo esc_html( $ppl ); ?></span><?php endif; ?>
                            <?php if ( $loc ) : ?><span>&#128205; <?php echo esc_html( $loc ); ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <a class="bschi-fp__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_text ); ?></a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
