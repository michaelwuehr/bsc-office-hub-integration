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
