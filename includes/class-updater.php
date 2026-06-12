<?php
/**
 * BSC Office Hub Integration – GitHub Auto-Update.
 *
 * Hängt sich in den nativen WordPress-Update-Mechanismus ein.
 * Release-Quelle: github.com/michaelwuehr/bsc-office-hub-integration
 * (Release-Asset: bsc-office-hub-integration-{VERSION}.zip)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bschi_fetch_github_release(): ?object {
    $cached = get_transient( BSCHI_UPDATE_TRANSIENT );
    if ( $cached !== false ) {
        return $cached;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . BSCHI_GITHUB_REPO . '/releases/latest',
        [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'BSCHI/' . BSCHI_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
            ],
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! isset( $body->tag_name ) ) {
        return null;
    }

    // ZIP-Asset aus Release-Assets ermitteln, Fallback auf Standard-URL
    $zip_url = null;
    foreach ( $body->assets ?? [] as $asset ) {
        if ( str_ends_with( $asset->name, '.zip' ) ) {
            $zip_url = $asset->browser_download_url;
            break;
        }
    }
    if ( ! $zip_url ) {
        $zip_url = 'https://github.com/' . BSCHI_GITHUB_REPO . '/releases/latest/download/' . BSCHI_SLUG . '.zip';
    }

    $release = (object) [
        'version'      => ltrim( $body->tag_name, 'v' ),
        'tag'          => $body->tag_name,
        'download_url' => $zip_url,
        'details_url'  => $body->html_url,
        'changelog'    => $body->body ?? '',
        'published_at' => $body->published_at ?? '',
    ];

    set_transient( BSCHI_UPDATE_TRANSIENT, $release, 6 * HOUR_IN_SECONDS );
    return $release;
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_file = plugin_basename( BSCHI_PLUGIN_FILE );
    $release     = bschi_fetch_github_release();

    if ( $release && version_compare( $release->version, BSCHI_VERSION, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) [
            'slug'        => BSCHI_SLUG,
            'plugin'      => $plugin_file,
            'new_version' => $release->version,
            'url'         => $release->details_url,
            'package'     => $release->download_url,
        ];
    } else {
        $transient->no_update[ $plugin_file ] = (object) [
            'slug'        => BSCHI_SLUG,
            'plugin'      => $plugin_file,
            'new_version' => BSCHI_VERSION,
            'url'         => 'https://github.com/' . BSCHI_GITHUB_REPO,
            'package'     => '',
        ];
    }

    return $transient;
} );

add_filter( 'plugins_api', function ( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== BSCHI_SLUG ) {
        return $result;
    }

    $release  = bschi_fetch_github_release();
    $raw_base = 'https://raw.githubusercontent.com/' . BSCHI_GITHUB_REPO . '/main/assets';

    return (object) [
        'name'          => 'BSC - Office Hub Integration',
        'slug'          => BSCHI_SLUG,
        'version'       => $release ? $release->version : BSCHI_VERSION,
        'author'        => 'Michael Wühr',
        'homepage'      => 'https://github.com/' . BSCHI_GITHUB_REPO,
        'download_link' => $release ? $release->download_url : '',
        'last_updated'  => $release ? $release->published_at : '',
        'icons'         => [
            '1x' => $raw_base . '/icon-128x128.png',
            '2x' => $raw_base . '/icon-256x256.png',
        ],
        'banners'       => [
            'low'  => $raw_base . '/icon-256x256.png',
            'high' => $raw_base . '/icon-256x256.png',
        ],
        'sections'      => [
            'description' => 'Verbindet WooCommerce mit dem BSC Office Hub: Monitoring, Doppelbestellungs-Erkennung, Sale-Banner, Kundendokumente, Preislisten und Chat.',
            'changelog'   => $release && $release->changelog
                ? '<pre style="white-space:pre-wrap">' . esc_html( $release->changelog ) . '</pre>'
                : '<p>Changelog auf GitHub verfügbar.</p>',
        ],
    ];
}, 10, 3 );

// ─── Plugin-Icon in der WP Plugin-Liste ──────────────────────────────────────
// WordPress zeigt Icons für Nicht-wp.org-Plugins nicht nativ an → JS-Injektion.

add_action( 'admin_footer', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'plugins' ) {
        return;
    }
    $icon_url    = 'https://raw.githubusercontent.com/' . BSCHI_GITHUB_REPO . '/main/assets/icon-128x128.png';
    $plugin_file = plugin_basename( BSCHI_PLUGIN_FILE );
    ?>
    <script>
    (function () {
        var row = document.querySelector('tr[data-plugin="<?= esc_js( $plugin_file ); ?>"]');
        if (!row) return;
        var strong = row.querySelector('.plugin-title strong');
        if (!strong) return;
        var img = document.createElement('img');
        img.src = <?= json_encode( $icon_url ); ?>;
        img.alt = '';
        img.style.cssText = 'width:38px;height:38px;border-radius:6px;object-fit:cover;vertical-align:middle;margin-right:10px;flex-shrink:0;';
        strong.style.display = 'flex';
        strong.style.alignItems = 'center';
        strong.insertBefore(img, strong.firstChild);
    })();
    </script>
    <?php
} );
