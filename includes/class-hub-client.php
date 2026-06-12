<?php
/**
 * BSC Office Hub Integration – zentraler HTTP-Client.
 *
 * Alle Requests laufen server-seitig (WordPress → Hub) mit Secret-Header.
 * GET-Antworten können per Transient gecacht werden.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Basis-Header für alle Hub-Requests.
 * X-BSCHWM-Secret wird übergangsweise mitgesendet (alte Hub-Versionen).
 */
function bschi_hub_headers(): array {
    $settings = bschi_get_settings();
    return [
        'Content-Type'    => 'application/json',
        'X-BSCHI-Secret'  => $settings['hub_secret'],
        'X-BSCHWM-Secret' => $settings['hub_secret'],
        'X-BSCHI-Source'  => parse_url( get_site_url(), PHP_URL_HOST ),
    ];
}

function bschi_hub_url( string $path ): string {
    $settings = bschi_get_settings();
    if ( empty( $settings['hub_url'] ) ) {
        return '';
    }
    return rtrim( $settings['hub_url'], '/' ) . $path;
}

/**
 * GET zum Hub. Liefert dekodiertes JSON-Array oder null bei Fehler.
 *
 * @param string $path       z.B. '/api/v1/shop/sale/current'
 * @param int    $cache_ttl  Sekunden Transient-Cache (0 = kein Cache)
 */
function bschi_hub_get( string $path, int $cache_ttl = 0 ): ?array {
    $endpoint = bschi_hub_url( $path );
    if ( ! $endpoint ) {
        return null;
    }

    $cache_key = 'bschi_get_' . md5( $path );
    if ( $cache_ttl > 0 ) {
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
    }

    $response = wp_remote_get( $endpoint, [
        'timeout' => 10,
        'headers' => bschi_hub_headers(),
    ] );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
        $detail = is_wp_error( $response )
            ? $response->get_error_message()
            : 'HTTP ' . wp_remote_retrieve_response_code( $response );
        error_log( '[BSCHI] Hub GET fehlgeschlagen (' . $path . '): ' . $detail );
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) ) {
        return null;
    }
    if ( $cache_ttl > 0 ) {
        set_transient( $cache_key, $data, $cache_ttl );
    }
    return $data;
}

/**
 * Binär-GET zum Hub (PDF/PNG-Streams). Liefert ['body' => string, 'content_type' => string] oder null.
 */
function bschi_hub_get_binary( string $path ): ?array {
    $endpoint = bschi_hub_url( $path );
    if ( ! $endpoint ) {
        return null;
    }
    $response = wp_remote_get( $endpoint, [
        'timeout' => 25,
        'headers' => bschi_hub_headers(),
    ] );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
        $detail = is_wp_error( $response )
            ? $response->get_error_message()
            : 'HTTP ' . wp_remote_retrieve_response_code( $response );
        error_log( '[BSCHI] Hub Binary-GET fehlgeschlagen (' . $path . '): ' . $detail );
        return null;
    }
    return [
        'body'         => wp_remote_retrieve_body( $response ),
        'content_type' => wp_remote_retrieve_header( $response, 'content-type' ) ?: 'application/octet-stream',
        'disposition'  => wp_remote_retrieve_header( $response, 'content-disposition' ) ?: '',
    ];
}

/**
 * Shop-Kunde des eingeloggten WP-Users im Hub auflösen (12h-Cache in user_meta).
 * Liefert das resolve-Array oder null.
 */
function bschi_resolve_current_customer( bool $skip_cache = false ): ?array {
    $user = wp_get_current_user();
    if ( ! $user || ! $user->exists() ) {
        return null;
    }
    $email = $user->billing_email ?: $user->user_email;
    if ( ! $email ) {
        return null;
    }
    $cache_key = 'bschi_resolve_' . md5( strtolower( $email ) );
    if ( ! $skip_cache ) {
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }
    }
    $data = bschi_hub_get( '/api/v1/shop/customer/resolve?email=' . rawurlencode( $email ) );
    // Nur erfolgreiche Treffer lange cachen – Fehler/Nicht-Treffer kurz,
    // sonst blockiert ein Hub-Ausfall die Module bis zu 12h
    $ttl = ( is_array( $data ) && ! empty( $data['found'] ) ) ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
    set_transient( $cache_key, is_array( $data ) ? $data : [], $ttl );
    return $data;
}

/**
 * POST zum Hub (fire-and-forget, loggt Fehler). Für Monitoring-Pushes.
 */
function bschi_hub_post( string $path, array $payload ): void {
    $endpoint = bschi_hub_url( $path );
    if ( ! $endpoint ) {
        return;
    }

    $response = wp_remote_post( $endpoint, [
        'timeout' => 10,
        'headers' => bschi_hub_headers(),
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[BSCHI] Hub Push fehlgeschlagen (' . $path . '): ' . $response->get_error_message() );
    } elseif ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
        error_log( '[BSCHI] Hub Push HTTP-Fehler (' . $path . '): '
            . wp_remote_retrieve_response_code( $response )
            . ' — ' . wp_remote_retrieve_body( $response ) );
    }
}
