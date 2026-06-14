<?php
/**
 * BSC Office Hub Integration – Modul: Social-Media-Feed.
 *
 * Shortcode [bsc_hub_social] zeigt die letzten Social-Media-Posts (importierter
 * Instagram/Facebook-Feed + veröffentlichte eigene Posts) aus dem Office Hub
 * als responsives Grid. Bilder werden server-seitig über admin-ajax vom Hub
 * geholt (Secret bleibt am Server) – die Hub-Medien bleiben so nicht öffentlich.
 *
 * Attribute: [bsc_hub_social limit="8" plattform="instagram" columns="4"]
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

    $limit = max( 1, min( 40, (int) $a['limit'] ) );
    $path  = '/api/v1/shop/social?limit=' . $limit;
    if ( $a['plattform'] ) {
        $path .= '&plattform=' . rawurlencode( sanitize_key( $a['plattform'] ) );
    }
    $data  = bschi_hub_get( $path, BSCHI_SOCIAL_CACHE_TTL );
    $posts = $data['posts'] ?? null;
    if ( empty( $posts ) ) {
        return '';
    }
    return bschi_social_render( $posts, (int) $a['columns'] );
} );

function bschi_social_plat_icon( string $plat ): string {
    if ( $plat === 'instagram' ) {
        return '<svg viewBox="0 0 24 24" width="15" height="15" fill="#c13584" aria-hidden="true"><path d="M12 2.2c3.2 0 3.6 0 4.9.07 1.17.05 1.8.25 2.23.42.56.22.96.48 1.38.9.42.42.68.82.9 1.38.17.42.37 1.06.42 2.23.06 1.27.07 1.65.07 4.85s0 3.58-.07 4.85c-.05 1.17-.25 1.8-.42 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.17-1.06.37-2.23.42-1.27.06-1.65.07-4.85.07s-3.58 0-4.85-.07c-1.17-.05-1.8-.25-2.23-.42a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.17-.42-.37-1.06-.42-2.23C2.2 15.58 2.2 15.2 2.2 12s0-3.58.07-4.85c.05-1.17.25-1.8.42-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.17 1.06-.37 2.23-.42C8.42 2.2 8.8 2.2 12 2.2zm0 1.8c-3.14 0-3.51.01-4.75.07-.9.04-1.38.2-1.7.32-.43.17-.74.37-1.06.69-.32.32-.52.63-.69 1.06-.13.32-.28.8-.32 1.7C3.21 9.49 3.2 9.86 3.2 12s.01 2.51.07 3.75c.04.9.2 1.38.32 1.7.17.43.37.74.69 1.06.32.32.63.52 1.06.69.32.13.8.28 1.7.32 1.24.06 1.61.07 4.75.07s3.51-.01 4.75-.07c.9-.04 1.38-.2 1.7-.32.43-.17.74-.37 1.06-.69.32-.32.52-.63.69-1.06.13-.32.28-.8.32-1.7.06-1.24.07-1.61.07-3.75s-.01-2.51-.07-3.75c-.04-.9-.2-1.38-.32-1.7a2.86 2.86 0 0 0-.69-1.06 2.86 2.86 0 0 0-1.06-.69c-.32-.13-.8-.28-1.7-.32C15.51 4.01 15.14 4 12 4zm0 3.06A4.94 4.94 0 1 1 12 17a4.94 4.94 0 0 1 0-9.88zm0 1.8a3.14 3.14 0 1 0 0 6.28 3.14 3.14 0 0 0 0-6.28zm5.16-.95a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3z"/></svg>';
    }
    if ( $plat === 'facebook' ) {
        return '<svg viewBox="0 0 24 24" width="15" height="15" fill="#1877f2" aria-hidden="true"><path d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.95.93-1.95 1.87V12h3.32l-.53 3.47h-2.79v8.38A12 12 0 0 0 24 12z"/></svg>';
    }
    return '';
}

function bschi_social_render( array $posts, int $columns = 0 ): string {
    $ajax = admin_url( 'admin-ajax.php' );
    $grid_style = ( $columns >= 1 && $columns <= 8 )
        ? 'grid-template-columns:repeat(' . $columns . ',1fr)'
        : '';
    ob_start();
    echo bschi_social_styles();
    ?>
    <div class="bschi-soc" style="<?= esc_attr( $grid_style ); ?>">
        <?php foreach ( $posts as $p ) :
            $img = $ajax . '?action=bschi_social_img&source=' . rawurlencode( $p['source'] ?? 'feed' ) . '&id=' . (int) ( $p['id'] ?? 0 );
            $cap = trim( (string) ( $p['text'] ?? '' ) );
            $url = trim( (string) ( $p['permalink'] ?? '' ) );
            $plat = (string) ( $p['plattform'] ?? '' );
            $likes = isset( $p['likes'] ) ? (int) $p['likes'] : null;
            $comments = isset( $p['comments'] ) ? (int) $p['comments'] : null;
            $tag = $url ? 'a' : 'div';
            $href = $url ? ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow"' : '';
            ?>
            <<?= $tag . $href; ?> class="bschi-soc__card">
                <img src="<?= esc_url( $img ); ?>" alt="<?= esc_attr( wp_trim_words( $cap, 12, '' ) ); ?>" loading="lazy">
                <?php $icon = bschi_social_plat_icon( $plat ); ?>
                <?php if ( $icon ) : ?><span class="bschi-soc__plat"><?= $icon; ?></span><?php endif; ?>
                <span class="bschi-soc__ov">
                    <?php if ( $cap ) : ?><span class="bschi-soc__cap"><?= esc_html( $cap ); ?></span><?php endif; ?>
                    <?php if ( $likes !== null || $comments !== null ) : ?>
                        <span class="bschi-soc__stats">
                            <?php if ( $likes !== null ) : ?>&#9829; <?= esc_html( $likes ); ?><?php endif; ?>
                            <?php if ( $comments !== null ) : ?>&nbsp;&nbsp;&#128172; <?= esc_html( $comments ); ?><?php endif; ?>
                        </span>
                    <?php endif; ?>
                </span>
            </<?= $tag; ?>>
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
    .bschi-soc{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px;margin:0 0 24px;font-family:inherit}
    .bschi-soc__card{position:relative;display:block;aspect-ratio:1;border-radius:14px;overflow:hidden;background:#222;text-decoration:none;box-shadow:0 6px 20px rgba(0,0,0,.13)}
    .bschi-soc__card img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .45s ease}
    .bschi-soc__card:hover img{transform:scale(1.06)}
    .bschi-soc__plat{position:absolute;top:9px;right:9px;width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.94);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,.2)}
    .bschi-soc__ov{position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.78),rgba(0,0,0,.15) 55%,transparent);opacity:0;transition:opacity .25s ease;display:flex;flex-direction:column;justify-content:flex-end;padding:12px;color:#fff}
    .bschi-soc__card:hover .bschi-soc__ov{opacity:1}
    .bschi-soc__cap{font-size:12.5px;line-height:1.45;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
    .bschi-soc__stats{font-size:11.5px;margin-top:7px;opacity:.92;font-weight:600}
    @media(hover:none){.bschi-soc__ov{opacity:1;background:linear-gradient(to top,rgba(0,0,0,.7),transparent 45%)}.bschi-soc__cap{-webkit-line-clamp:2}}
    </style>';
}
