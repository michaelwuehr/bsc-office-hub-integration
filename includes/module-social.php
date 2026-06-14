<?php
/**
 * BSC Office Hub Integration – Modul: Social-Media-Feed.
 *
 * Shortcode [bsc_hub_social] zeigt die letzten Social-Media-Posts (importierter
 * Instagram/Facebook-Feed + veröffentlichte eigene Posts) aus dem Office Hub
 * als "eingebettete" Social-Media-Karten (Plattform-Header, Bild, Likes/Kommentare,
 * Caption-Auszug, "Auf … ansehen"-Link). Klick öffnet den Original-Post.
 *
 * Bilder laufen server-seitig über admin-ajax vom Hub (Secret bleibt am Server).
 *
 * Welche Plattformen angezeigt werden, steuert die Plugin-Einstellung
 * "Social-Media-Plattformen"; der Shortcode-Parameter übersteuert sie.
 * Attribute: [bsc_hub_social limit="8" plattform="instagram,tiktok" columns="4"]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BSCHI_SOCIAL_CACHE_TTL', 900 ); // 15 Minuten

/** Bild-Proxy: streamt ein Social-Media-Bild vom Hub (auch für nicht eingeloggte Besucher). */
function bschi_social_image_proxy(): void {
    if ( ! bschi_feature_enabled( 'social_feed' ) ) {
        status_header( 403 ); exit;
    }
    $source = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '';
    $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    if ( ! in_array( $source, [ 'feed', 'post' ], true ) || $id < 1 ) {
        status_header( 400 ); exit;
    }
    $bin = bschi_hub_get_binary( '/api/v1/shop/social/' . $source . '/' . $id . '/image' );
    if ( ! $bin ) {
        status_header( 404 ); exit;
    }
    $type = strtolower( trim( strtok( (string) $bin['content_type'], ';' ) ) );
    if ( ! str_starts_with( $type, 'image/' ) ) {
        $type = 'image/jpeg';
    }
    header( 'Content-Type: ' . $type );
    header( 'Cache-Control: public, max-age=3600' );
    header( "Content-Security-Policy: sandbox; default-src 'none'" );
    echo $bin['body']; // phpcs:ignore -- binärer Bild-Stream
    exit;
}
add_action( 'wp_ajax_bschi_social_img', 'bschi_social_image_proxy' );
add_action( 'wp_ajax_nopriv_bschi_social_img', 'bschi_social_image_proxy' );

add_shortcode( 'bsc_hub_social', function ( $atts ): string {
    if ( ! bschi_feature_enabled( 'social_feed' ) ) {
        return '';
    }
    $a = shortcode_atts( [
        'limit'     => 8,
        'plattform' => '',
        'columns'   => '',
    ], $atts, 'bsc_hub_social' );

    // Plattform-Auswahl: Shortcode-Parameter > Plugin-Einstellung
    $raw   = $a['plattform'] !== '' ? $a['plattform'] : ( bschi_get_settings()['social_platforms'] ?? 'instagram,facebook' );
    $plats = implode( ',', array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', (string) $raw ) ) ) ) );

    $limit = max( 1, min( 40, (int) $a['limit'] ) );
    $path  = '/api/v1/shop/social?limit=' . $limit;
    if ( $plats ) {
        $path .= '&plattform=' . rawurlencode( $plats );
    }
    $data  = bschi_hub_get( $path, BSCHI_SOCIAL_CACHE_TTL );
    $posts = $data['posts'] ?? null;
    if ( empty( $posts ) ) {
        return '';
    }
    return bschi_social_render( $posts, (int) $a['columns'] );
} );

/** Plattform-Metadaten: [Label, Markenfarbe, Icon-SVG]. */
function bschi_social_plat_meta( string $plat ): array {
    $ig = '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor" aria-hidden="true"><path d="M12 2.2c3.2 0 3.6 0 4.9.07 1.17.05 1.8.25 2.23.42.56.22.96.48 1.38.9.42.42.68.82.9 1.38.17.42.37 1.06.42 2.23.06 1.27.07 1.65.07 4.85s0 3.58-.07 4.85c-.05 1.17-.25 1.8-.42 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.17-1.06.37-2.23.42-1.27.06-1.65.07-4.85.07s-3.58 0-4.85-.07c-1.17-.05-1.8-.25-2.23-.42a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.17-.42-.37-1.06-.42-2.23C2.2 15.58 2.2 15.2 2.2 12s0-3.58.07-4.85c.05-1.17.25-1.8.42-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.17 1.06-.37 2.23-.42C8.42 2.2 8.8 2.2 12 2.2zm0 1.8c-3.14 0-3.51.01-4.75.07-.9.04-1.38.2-1.7.32-.43.17-.74.37-1.06.69-.32.32-.52.63-.69 1.06-.13.32-.28.8-.32 1.7C3.21 9.49 3.2 9.86 3.2 12s.01 2.51.07 3.75c.04.9.2 1.38.32 1.7.17.43.37.74.69 1.06.32.32.63.52 1.06.69.32.13.8.28 1.7.32 1.24.06 1.61.07 4.75.07s3.51-.01 4.75-.07c.9-.04 1.38-.2 1.7-.32.43-.17.74-.37 1.06-.69.32-.32.52-.63.69-1.06.13-.32.28-.8.32-1.7.06-1.24.07-1.61.07-3.75s-.01-2.51-.07-3.75c-.04-.9-.2-1.38-.32-1.7a2.86 2.86 0 0 0-.69-1.06 2.86 2.86 0 0 0-1.06-.69c-.32-.13-.8-.28-1.7-.32C15.51 4.01 15.14 4 12 4zm0 3.06A4.94 4.94 0 1 1 12 17a4.94 4.94 0 0 1 0-9.88zm0 1.8a3.14 3.14 0 1 0 0 6.28 3.14 3.14 0 0 0 0-6.28zm5.16-.95a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3z"/></svg>';
    $fb = '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor" aria-hidden="true"><path d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.95.93-1.95 1.87V12h3.32l-.53 3.47h-2.79v8.38A12 12 0 0 0 24 12z"/></svg>';
    $tt = '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor" aria-hidden="true"><path d="M16.6 5.82a4.28 4.28 0 0 1-1.06-2.82h-3.2v12.93a2.59 2.59 0 0 1-2.59 2.5 2.59 2.59 0 1 1 .76-5.07V9.9a5.86 5.86 0 0 0-.76-.05A5.79 5.79 0 1 0 15.34 15.6V9.01a7.49 7.49 0 0 0 4.36 1.4V7.2a4.3 4.3 0 0 1-3.1-1.38z"/></svg>';
    $li = '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.42v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.07 2.07 0 1 1 0-4.14 2.07 2.07 0 0 1 0 4.14zM7.12 20.45H3.55V9h3.57v11.45zM22.22 0H1.77C.8 0 0 .78 0 1.74v20.52C0 23.22.8 24 1.77 24h20.45c.98 0 1.78-.78 1.78-1.74V1.74C24 .78 23.2 0 22.22 0z"/></svg>';
    $pi = '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12c0 4.24 2.64 7.86 6.36 9.32-.09-.79-.17-2.01.03-2.88.18-.78 1.18-4.98 1.18-4.98s-.3-.6-.3-1.49c0-1.4.81-2.44 1.82-2.44.86 0 1.27.64 1.27 1.42 0 .86-.55 2.15-.83 3.34-.24 1 .5 1.81 1.48 1.81 1.78 0 3.14-1.87 3.14-4.58 0-2.39-1.72-4.07-4.18-4.07-2.84 0-4.51 2.13-4.51 4.34 0 .86.33 1.78.74 2.28.08.1.09.19.07.29-.08.32-.25 1-.28 1.14-.05.18-.15.22-.34.13-1.27-.59-2.06-2.44-2.06-3.93 0-3.2 2.32-6.13 6.7-6.13 3.52 0 6.25 2.51 6.25 5.86 0 3.5-2.2 6.31-5.27 6.31-1.03 0-2-.53-2.33-1.17l-.63 2.42c-.23.88-.85 1.98-1.27 2.65.96.3 1.97.45 3.03.45 5.52 0 10-4.48 10-10S17.52 2 12 2z"/></svg>';
    $map = [
        'instagram' => [ 'Instagram', '#c13584', $ig ],
        'facebook'  => [ 'Facebook', '#1877f2', $fb ],
        'tiktok'    => [ 'TikTok', '#010101', $tt ],
        'linkedin'  => [ 'LinkedIn', '#0a66c2', $li ],
        'pinterest' => [ 'Pinterest', '#e60023', $pi ],
    ];
    return $map[ $plat ] ?? [ 'Social', '#4b5a42', '' ];
}

function bschi_social_render( array $posts, int $columns = 0 ): string {
    $ajax = admin_url( 'admin-ajax.php' );
    $grid_style = ( $columns >= 1 && $columns <= 8 ) ? 'grid-template-columns:repeat(' . $columns . ',1fr)' : '';
    ob_start();
    echo bschi_social_styles();
    ?>
    <div class="bschi-soc" style="<?= esc_attr( $grid_style ); ?>">
        <?php foreach ( $posts as $p ) :
            $meta    = bschi_social_plat_meta( (string) ( $p['plattform'] ?? '' ) );
            $img     = $ajax . '?action=bschi_social_img&source=' . rawurlencode( $p['source'] ?? 'feed' ) . '&id=' . (int) ( $p['id'] ?? 0 );
            $cap     = trim( (string) ( $p['text'] ?? '' ) );
            $url     = trim( (string) ( $p['permalink'] ?? '' ) );
            $account = trim( (string) ( $p['account'] ?? '' ) ) ?: $meta[0];
            $likes   = isset( $p['likes'] ) && $p['likes'] !== null ? (int) $p['likes'] : null;
            $comments = isset( $p['comments'] ) && $p['comments'] !== null ? (int) $p['comments'] : null;
            $ts      = ! empty( $p['date'] ) ? strtotime( $p['date'] ) : 0;
            $href    = $url ? ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow"' : '';
            $mtag    = $url ? 'a' : 'span';
            ?>
            <article class="bschi-soc__post">
                <div class="bschi-soc__head">
                    <span class="bschi-soc__av" style="background:<?= esc_attr( $meta[1] ); ?>"><?= $meta[2]; ?></span>
                    <span class="bschi-soc__id">
                        <span class="bschi-soc__acc"><?= esc_html( $account ); ?></span>
                        <span class="bschi-soc__sub"><?= esc_html( $meta[0] ); ?><?php if ( $ts ) : ?> &middot; <?= esc_html( date_i18n( 'j. M Y', $ts ) ); ?><?php endif; ?></span>
                    </span>
                </div>
                <<?= $mtag . $href; ?> class="bschi-soc__media">
                    <img src="<?= esc_url( $img ); ?>" alt="<?= esc_attr( wp_trim_words( $cap, 12, '' ) ); ?>" loading="lazy">
                </<?= $mtag; ?>>
                <?php if ( $likes !== null || $comments !== null ) : ?>
                    <div class="bschi-soc__act">
                        <?php if ( $likes !== null ) : ?><span>&#9829; <?= esc_html( number_format_i18n( $likes ) ); ?></span><?php endif; ?>
                        <?php if ( $comments !== null ) : ?><span>&#128172; <?= esc_html( number_format_i18n( $comments ) ); ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ( $cap ) : ?>
                    <div class="bschi-soc__cap"><b><?= esc_html( $account ); ?></b> <?= esc_html( $cap ); ?></div>
                <?php endif; ?>
                <?php if ( $url ) : ?>
                    <a class="bschi-soc__foot" style="color:<?= esc_attr( $meta[1] ); ?>" href="<?= esc_url( $url ); ?>" target="_blank" rel="noopener nofollow">Auf <?= esc_html( $meta[0] ); ?> ansehen &#8599;</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function bschi_social_styles(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style id="bschi-social-css">
    .bschi-soc{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;margin:0 0 24px;font-family:inherit}
    .bschi-soc__post{background:#fff;border:1px solid #e6e6e6;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 4px 18px rgba(0,0,0,.07)}
    .bschi-soc__head{display:flex;align-items:center;gap:10px;padding:11px 14px}
    .bschi-soc__av{width:38px;height:38px;border-radius:50%;flex:none;color:#fff;display:flex;align-items:center;justify-content:center;padding:8px;box-sizing:border-box}
    .bschi-soc__id{display:flex;flex-direction:column;min-width:0;line-height:1.25}
    .bschi-soc__acc{font-weight:700;font-size:14px;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .bschi-soc__sub{font-size:11.5px;color:#8a8a8a}
    .bschi-soc__media{display:block;background:#000;line-height:0}
    .bschi-soc__media img{width:100%;aspect-ratio:1;object-fit:cover;display:block;transition:opacity .2s}
    .bschi-soc__media:hover img{opacity:.94}
    .bschi-soc__act{display:flex;gap:18px;padding:10px 14px 2px;font-size:14px;font-weight:600;color:#333}
    .bschi-soc__cap{padding:6px 14px 12px;font-size:13.5px;line-height:1.5;color:#262626;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
    .bschi-soc__cap b{font-weight:700;color:#1a1a1a}
    .bschi-soc__foot{display:block;padding:11px 14px;border-top:1px solid #efefef;font-size:13px;font-weight:700;text-decoration:none}
    .bschi-soc__foot:hover{text-decoration:underline}
    </style>';
}
