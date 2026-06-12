<?php
/**
 * BSC Office Hub Integration – Modul: Sale-Banner-Shortcode.
 *
 * Shortcode [bsc_hub_sale] zeigt die aktuelle Sale-Kampagne bzw. das aktive
 * Shop-Banner aus dem Office Hub an (GET /api/v1/shop/sale/current, 5 Min. Cache).
 * Rendert nichts, wenn das Modul deaktiviert ist oder nichts aktiv ist –
 * der Shortcode kann daher dauerhaft auf der Startseite eingebunden bleiben.
 *
 * Bevorzugt Flatsome-Markup ([section][ux_banner][text_box]), Fallback: eigenes HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BSCHI_SALE_CACHE_TTL', 300 ); // 5 Minuten

/**
 * Aktuelle Sale-/Banner-Daten vom Hub (gecacht).
 */
function bschi_sale_get_current( bool $skip_cache = false ): ?array {
    if ( $skip_cache ) {
        delete_transient( 'bschi_get_' . md5( '/api/v1/shop/sale/current' ) );
    }
    return bschi_hub_get( '/api/v1/shop/sale/current', BSCHI_SALE_CACHE_TTL );
}

/**
 * Shortcode [bsc_hub_sale]
 */
add_shortcode( 'bsc_hub_sale', function ( $atts ): string {
    if ( ! bschi_feature_enabled( 'sale_banner' ) ) {
        return '';
    }
    $data = bschi_sale_get_current();
    if ( ! $data || empty( $data['active'] ) ) {
        return '';
    }
    return bschi_sale_render_banner( $data );
} );

/**
 * Banner-HTML aus Hub-Daten bauen.
 */
function bschi_sale_render_banner( array $d ): string {
    $bg       = $d['bg_color'] ?: '#87a590';
    $fg       = $d['text_color'] ?: '#ffffff';
    $headline = trim( (string) ( $d['headline'] ?? '' ) );
    $subline  = trim( (string) ( $d['subline'] ?? '' ) );
    $text     = trim( (string) ( $d['text'] ?? '' ) );
    $coupon   = trim( (string) ( $d['coupon_code'] ?? '' ) );
    $rabatt   = $d['rabatt_pct'] ?? null;
    $cta_text = trim( (string) ( $d['cta_text'] ?? '' ) );
    $cta_url  = trim( (string) ( $d['cta_url'] ?? '' ) );
    $image    = trim( (string) ( $d['image_url'] ?? '' ) );

    if ( ! $headline && ! $text && ! $coupon ) {
        return ''; // keine Inhalte gepflegt → nichts rendern
    }

    // ── Innerer Content (für beide Render-Wege identisch) ────────────────────
    $inner = '';
    if ( $headline ) {
        $inner .= '<h2 style="color:inherit;margin:0 0 8px"><strong>' . esc_html( $headline ) . '</strong></h2>';
    }
    if ( $subline ) {
        $inner .= '<h4 style="color:inherit;margin:0 0 10px">' . esc_html( $subline ) . '</h4>';
    }
    if ( $text ) {
        $inner .= '<p style="color:inherit;margin:0 0 10px">' . nl2br( esc_html( $text ) ) . '</p>';
    }
    if ( $rabatt ) {
        $inner .= '<h3 style="color:inherit;margin:0 0 6px"><strong>' . esc_html( rtrim( rtrim( number_format( (float) $rabatt, 1, ',', '.' ), '0' ), ',' ) ) . '% RABATT</strong></h3>';
    }
    if ( $coupon ) {
        $inner .= '<h2 style="color:inherit;letter-spacing:1px;font-family:monospace;margin:0 0 12px"><strong>' . esc_html( $coupon ) . '</strong></h2>';
    }

    // Countdown (eigenes leichtgewichtiges JS, Flatsome-unabhängig)
    $countdown_html = '';
    if ( ! empty( $d['countdown_ts'] ) ) {
        $ts = strtotime( $d['countdown_ts'] );
        if ( $ts && $ts > time() ) {
            $cid            = 'bschi-cd-' . substr( md5( (string) $ts ), 0, 8 );
            $countdown_html = '<div id="' . esc_attr( $cid ) . '" style="font-size:1.1em;font-weight:600;margin:0 0 12px;color:inherit"></div>'
                . '<script>(function(){var end=' . (int) $ts . '*1000,el=document.getElementById(' . wp_json_encode( $cid ) . ');'
                . 'function p(n){return n<10?"0"+n:n}'
                . 'function t(){var d=end-Date.now();if(d<=0){el.textContent="";return}'
                . 'var s=Math.floor(d/1000),tg=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60),sec=s%60;'
                . 'el.textContent="Noch "+(tg>0?tg+" Tag"+(tg!==1?"e":"")+", ":"")+p(h)+":"+p(m)+":"+p(sec);'
                . 'setTimeout(t,1000)}t()})();</script>';
        }
    }

    $button_html = '';
    if ( $cta_text ) {
        $url          = $cta_url ?: home_url( '/shop' );
        $button_html  = '<a href="' . esc_url( $url ) . '" class="button secondary" '
            . 'style="border-radius:10px;margin-top:4px">'
            . esc_html( $cta_text ) . '</a>';
    }

    $content = $inner . $countdown_html . $button_html;

    // ── Variante 1: Flatsome aktiv → natives ux_banner-Markup ────────────────
    if ( shortcode_exists( 'ux_banner' ) && shortcode_exists( 'text_box' ) ) {
        $bg_attr = $image ? ' bg="' . esc_url( $image ) . '" bg_overlay="rgba(0,0,0,0.31)"' : ' bg_color="' . esc_attr( $bg ) . '"';
        $sc = '[section label="BSC Hub Sale" padding="0px"]'
            . '[ux_banner height="300px"' . $bg_attr . ']'
            . '[text_box width="60" width__sm="90" position_x="50" position_y="50"]'
            . '<div style="color:' . esc_attr( $fg ) . '">' . $content . '</div>'
            . '[/text_box][/ux_banner][/section]';
        return do_shortcode( $sc );
    }

    // ── Variante 2: Fallback ohne Flatsome ────────────────────────────────────
    $style = 'border-radius:8px;padding:48px 24px;text-align:center;margin:0 0 24px;'
        . 'color:' . esc_attr( $fg ) . ';';
    if ( $image ) {
        $style .= 'background:linear-gradient(rgba(0,0,0,.31),rgba(0,0,0,.31)),url(' . esc_url( $image ) . ') center/cover;';
    } else {
        $style .= 'background:' . esc_attr( $bg ) . ';';
    }
    return '<div class="bschi-sale-banner" style="' . $style . '">' . $content . '</div>';
}
