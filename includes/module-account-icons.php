<?php
/**
 * My-Account-Navigation: SVG-Icons vor den Menüpunkten.
 *
 * Reine CSS-Lösung: je Menüpunkt (woocommerce-MyAccount-navigation-link--{slug})
 * ein ::before mit mask-image (Data-URI-SVG) + background-color:currentColor –
 * die Icons erben so automatisch Text- und Hoverfarbe des Themes.
 * Deckt auch Endpoints fremder Plugins ab (z.B. meine-entwuerfe vom Seifen-Konfigurator).
 */

defined( 'ABSPATH' ) || exit;

function bschi_account_icons(): array {
    // 24x24, stroke:black (Farbe egal – wird als Maske genutzt), Feather-Stil
    $s = "fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'";
    return [
        'dashboard'       => "<path d='M3 11l9-8 9 8' $s/><path d='M5 9.5V21h14V9.5' $s/>",
        'orders'          => "<path d='M21 8l-9-5-9 5v8l9 5 9-5V8z' $s/><path d='M3 8l9 5 9-5' $s/><path d='M12 13v8' $s/>",
        'downloads'       => "<path d='M12 3v11' $s/><path d='M7 10l5 5 5-5' $s/><path d='M4 21h16' $s/>",
        'edit-address'    => "<path d='M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11z' $s/><circle cx='12' cy='10' r='2.5' $s/>",
        'edit-account'    => "<circle cx='12' cy='8' r='4' $s/><path d='M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5' $s/>",
        'customer-logout' => "<path d='M14 4h6v16h-6' $s/><path d='M3 12h11' $s/><path d='M10 8l4 4-4 4' $s/>",
        'guthaben'        => "<rect x='3' y='6' width='18' height='13' rx='2' $s/><path d='M3 10h18' $s/><path d='M7 15h4' $s/>",
        'gutscheine'      => "<rect x='3' y='8' width='18' height='4' $s/><path d='M5 12v8h14v-8' $s/><path d='M12 8v12' $s/><path d='M12 8c-1.6-3.2-6-3-6-.6C6 9.4 9.5 8.6 12 8z' $s/><path d='M12 8c1.6-3.2 6-3 6-.6 0 2-3.5 1.2-6 .6z' $s/>",
        'hub-dokumente'   => "<path d='M6 3h9l5 5v13H6z' $s/><path d='M14 3v6h6' $s/>",
        'hub-preisliste'  => "<path d='M20 12l-8 8-9-9V4h7l10 8z' $s/><circle cx='7.5' cy='7.5' r='1.5' $s/>",
        'meine-entwuerfe' => "<path d='M12 20h9' $s/><path d='M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z' $s/>",
    ];
}

add_action( 'wp_head', function () {
    if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
        return;
    }
    $icons = bschi_account_icons();
    $css   = '';
    foreach ( $icons as $slug => $svg ) {
        $uri = 'data:image/svg+xml,' . rawurlencode( "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'>$svg</svg>" );
        $css .= ".woocommerce-MyAccount-navigation-link--$slug a::before{"
              . "-webkit-mask-image:url(\"$uri\");mask-image:url(\"$uri\");}\n";
    }
    // Basis-Regel NUR fuer Slugs mit Icon – sonst bekaemen fremde Menuepunkte
    // ohne mask-image ein volles currentColor-Quadrat.
    $sel = implode( ',', array_map(
        fn( $slug ) => ".woocommerce-MyAccount-navigation-link--$slug a::before",
        array_keys( $icons )
    ) );
    echo '<style id="bschi-account-icons">'
       . $sel . '{content:"";display:inline-block;'
       . 'width:17px;height:17px;margin-right:9px;vertical-align:-3px;background-color:currentColor;'
       . '-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:center;mask-position:center;'
       . '-webkit-mask-size:contain;mask-size:contain;}'
       . "\n" . $css . '</style>';
} );
