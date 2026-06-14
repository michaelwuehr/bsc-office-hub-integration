<?php
/**
 * BSC Office Hub Integration – Modul: Sale-Banner-Shortcode.
 *
 * Shortcode [bsc_hub_sale] zeigt die aktuelle Sale-Kampagne bzw. das aktive
 * Shop-Banner (Typ "sale") aus dem Office Hub an
 * (GET /api/v1/shop/sale/current, 5 Min. Cache).
 *
 * Modernes, eigenständiges Hero-Banner (scoped .bschi-sb-*, theme-unabhängig,
 * responsiv). Rendert nichts, wenn das Modul deaktiviert ist oder nichts aktiv
 * ist – der Shortcode kann dauerhaft auf der Startseite eingebunden bleiben.
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
 * Hex-Farbe abdunkeln/aufhellen. $amt z.B. -0.2 = 20% dunkler.
 */
function bschi_shade_color( string $hex, float $amt ): string {
    $hex = ltrim( trim( $hex ), '#' );
    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
        return '#' . $hex;
    }
    $out = '#';
    for ( $i = 0; $i < 3; $i++ ) {
        $c = hexdec( substr( $hex, $i * 2, 2 ) );
        $c = (int) round( $c + ( $amt >= 0 ? ( 255 - $c ) * $amt : $c * $amt ) );
        $out .= str_pad( dechex( max( 0, min( 255, $c ) ) ), 2, '0', STR_PAD_LEFT );
    }
    return $out;
}

/**
 * Einmalig (pro Seitenaufbau) das gemeinsame Banner-CSS ausgeben.
 */
function bschi_banner_styles(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="bschi-banner-css">
    .bschi-sb{position:relative;overflow:hidden;border-radius:18px;margin:0 0 24px;padding:clamp(30px,6vw,58px) clamp(20px,5vw,44px);text-align:center;display:flex;flex-direction:column;align-items:center;gap:clamp(10px,2vw,16px);box-shadow:0 12px 44px rgba(0,0,0,.14);isolation:isolate;font-family:inherit}
    .bschi-sb::before{content:"";position:absolute;inset:0;z-index:-1;background:radial-gradient(circle at 28% 18%,rgba(255,255,255,.16),transparent 60%)}
    .bschi-sb__badge{position:absolute;top:14px;right:14px;width:clamp(72px,14vw,98px);height:clamp(72px,14vw,98px);border-radius:50%;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;transform:rotate(-9deg);box-shadow:0 6px 18px rgba(0,0,0,.22);line-height:1}
    .bschi-sb__badge b{font-size:clamp(20px,4.4vw,30px);font-weight:900}
    .bschi-sb__badge span{font-size:10px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;margin-top:2px;opacity:.8}
    .bschi-sb__eyebrow{font-size:12px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;opacity:.85}
    .bschi-sb__headline{font-size:clamp(27px,5.2vw,46px);font-weight:900;line-height:1.08;margin:0;max-width:760px;text-wrap:balance}
    .bschi-sb__subline{font-size:clamp(15px,2.6vw,21px);font-weight:600;margin:0;opacity:.95;max-width:680px}
    .bschi-sb__text{font-size:clamp(14px,2vw,16.5px);margin:0;opacity:.92;max-width:620px;line-height:1.55}
    .bschi-sb__coupon{display:inline-flex;align-items:center;gap:10px;background:rgba(255,255,255,.16);border:2px dashed rgba(255,255,255,.65);border-radius:12px;padding:9px 10px 9px 16px;backdrop-filter:blur(2px)}
    .bschi-sb__coupon .lbl{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;opacity:.8;margin-right:2px}
    .bschi-sb__coupon code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:clamp(16px,3vw,21px);font-weight:800;letter-spacing:2px}
    .bschi-sb__copy{border:none;border-radius:8px;background:rgba(255,255,255,.92);color:#222;font-weight:700;font-size:12px;padding:8px 12px;cursor:pointer;white-space:nowrap;transition:transform .15s,background .15s}
    .bschi-sb__copy:active{transform:scale(.94)}
    .bschi-sb__cd{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}
    .bschi-sb__cd div{min-width:54px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.22);border-radius:10px;padding:8px 6px}
    .bschi-sb__cd b{display:block;font-size:clamp(18px,3.4vw,24px);font-weight:800;line-height:1}
    .bschi-sb__cd small{font-size:9.5px;letter-spacing:1px;text-transform:uppercase;opacity:.78}
    .bschi-sb__cta{display:inline-block;margin-top:4px;background:#fff;font-weight:800;font-size:clamp(14px,2.2vw,16px);text-decoration:none;border-radius:12px;padding:13px 30px;box-shadow:0 6px 18px rgba(0,0,0,.2);transition:transform .15s,box-shadow .15s}
    .bschi-sb__cta:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(0,0,0,.26)}
    /* Aktions-Leiste (schlank) – Phase 2 nutzt dieselbe Stylesheet-Ausgabe */
    .bschi-akt{display:flex;align-items:center;justify-content:center;gap:clamp(8px,2vw,16px);flex-wrap:wrap;border-radius:14px;margin:0 0 18px;padding:13px clamp(14px,3vw,24px);text-align:center;font-family:inherit;box-shadow:0 6px 20px rgba(0,0,0,.12)}
    .bschi-akt__txt{font-size:clamp(14px,2.3vw,17px);font-weight:700;line-height:1.3}
    .bschi-akt__code{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.18);border:2px dashed rgba(255,255,255,.6);border-radius:10px;padding:6px 8px 6px 13px}
    .bschi-akt__code code{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:800;letter-spacing:1.5px;font-size:clamp(14px,2.4vw,17px)}
    .bschi-akt__copy{border:none;border-radius:7px;background:rgba(255,255,255,.92);color:#222;font-weight:700;font-size:11px;padding:6px 10px;cursor:pointer}
    .bschi-akt__cta{background:#fff;font-weight:800;font-size:13px;text-decoration:none;border-radius:9px;padding:9px 18px;white-space:nowrap}
    @media(max-width:520px){.bschi-sb__badge{position:static;transform:none;margin-bottom:4px}}
    </style>';
}

/**
 * Mini-JS für "Code kopieren" (einmalig).
 */
function bschi_banner_copy_js(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<script>(function(){document.addEventListener("click",function(e){var b=e.target.closest("[data-bschi-copy]");if(!b)return;e.preventDefault();var t=b.getAttribute("data-bschi-copy");function ok(){var o=b.textContent;b.textContent="Kopiert!";setTimeout(function(){b.textContent=o;},1600);}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(ok,ok);}else{var ta=document.createElement("textarea");ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand("copy");}catch(_){}document.body.removeChild(ta);ok();}});})();</script>';
}

/**
 * Countdown-HTML (Boxen Tage/Std/Min/Sek) für eine Zielzeit (ISO oder Timestamp-String).
 */
function bschi_banner_countdown( ?string $iso ): string {
    if ( ! $iso ) {
        return '';
    }
    $ts = strtotime( $iso );
    if ( ! $ts || $ts <= time() ) {
        return '';
    }
    $cid = 'bschi-cd-' . substr( md5( (string) $ts . wp_rand() ), 0, 8 );
    $html  = '<div class="bschi-sb__cd" id="' . esc_attr( $cid ) . '">'
        . '<div><b data-d>--</b><small>Tage</small></div><div><b data-h>--</b><small>Std</small></div>'
        . '<div><b data-m>--</b><small>Min</small></div><div><b data-s>--</b><small>Sek</small></div></div>';
    $html .= '<script>(function(){var end=' . (int) $ts . '*1000,el=document.getElementById(' . wp_json_encode( $cid ) . ');if(!el)return;'
        . 'function p(n){return n<10?"0"+n:""+n}function t(){var d=end-Date.now();if(d<=0){el.style.display="none";return}'
        . 'var s=Math.floor(d/1000);el.querySelector("[data-d]").textContent=Math.floor(s/86400);'
        . 'el.querySelector("[data-h]").textContent=p(Math.floor(s%86400/3600));'
        . 'el.querySelector("[data-m]").textContent=p(Math.floor(s%3600/60));'
        . 'el.querySelector("[data-s]").textContent=p(s%60);setTimeout(t,1000)}t()})();</script>';
    return $html;
}

/**
 * Sale-Banner-HTML aus Hub-Daten bauen (modernes Hero-Design).
 */
function bschi_sale_render_banner( array $d ): string {
    $bg       = $d['bg_color'] ?: '#4b5a42';
    $fg       = $d['text_color'] ?: '#ffffff';
    $headline = trim( (string) ( $d['headline'] ?? '' ) );
    $subline  = trim( (string) ( $d['subline'] ?? '' ) );
    $text     = trim( (string) ( $d['text'] ?? '' ) );
    $coupon   = trim( (string) ( $d['coupon_code'] ?? '' ) );
    $rabatt   = $d['rabatt_pct'] ?? null;
    $cta_text = trim( (string) ( $d['cta_text'] ?? '' ) );
    $cta_url  = trim( (string) ( $d['cta_url'] ?? '' ) );
    $image    = trim( (string) ( $d['image_url'] ?? '' ) );

    if ( ! $headline && ! $text && ! $coupon && ! $rabatt ) {
        return ''; // keine Inhalte gepflegt → nichts rendern
    }

    // Hintergrund: Bild mit Overlay, sonst Marken-Verlauf
    if ( $image ) {
        $bgcss = 'background:linear-gradient(135deg,rgba(0,0,0,.42),rgba(0,0,0,.28)),url(' . esc_url( $image ) . ') center/cover no-repeat;';
    } else {
        $bgcss = 'background:linear-gradient(135deg,' . esc_attr( $bg ) . ',' . esc_attr( bschi_shade_color( $bg, -0.28 ) ) . ');';
    }

    $rabatt_fmt = $rabatt ? rtrim( rtrim( number_format( (float) $rabatt, 1, ',', '.' ), '0' ), ',' ) : '';

    ob_start();
    echo bschi_banner_styles();
    echo bschi_banner_copy_js();
    ?>
    <div class="bschi-sb" style="<?= $bgcss; ?>color:<?= esc_attr( $fg ); ?>">
        <?php if ( $rabatt_fmt ) : ?>
            <div class="bschi-sb__badge" style="color:<?= esc_attr( $bg ); ?>"><b>-<?= esc_html( $rabatt_fmt ); ?>%</b><span>Rabatt</span></div>
        <?php endif; ?>
        <div class="bschi-sb__eyebrow">Aktion</div>
        <?php if ( $headline ) : ?><h2 class="bschi-sb__headline"><?= esc_html( $headline ); ?></h2><?php endif; ?>
        <?php if ( $subline ) : ?><p class="bschi-sb__subline"><?= esc_html( $subline ); ?></p><?php endif; ?>
        <?php if ( $text ) : ?><p class="bschi-sb__text"><?= nl2br( esc_html( $text ) ); ?></p><?php endif; ?>
        <?php echo bschi_banner_countdown( $d['countdown_ts'] ?? null ); ?>
        <?php if ( $coupon ) : ?>
            <div class="bschi-sb__coupon"><span class="lbl">Code</span><code><?= esc_html( $coupon ); ?></code>
                <button type="button" class="bschi-sb__copy" data-bschi-copy="<?= esc_attr( $coupon ); ?>">Kopieren</button></div>
        <?php endif; ?>
        <?php if ( $cta_text ) : ?>
            <a class="bschi-sb__cta" style="color:<?= esc_attr( $bg ); ?>" href="<?= esc_url( $cta_url ?: home_url( '/shop' ) ); ?>"><?= esc_html( $cta_text ); ?></a>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
