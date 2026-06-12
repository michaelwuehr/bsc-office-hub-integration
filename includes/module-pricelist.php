<?php
/**
 * BSC Office Hub Integration – Modul: Preisliste für Händler/Hotel-Kunden.
 *
 * My-Account-Endpoint "hub-preisliste": Download der aktuell veröffentlichten
 * Händler-Preisliste (Katalog + optionale Bestellliste) aus dem Office Hub.
 * Sichtbar nur, wenn der Hub den Kunden einem berechtigten Kanal zuordnet
 * (Setting shop_pricelist_channels im Hub, Default: Händler,Hotel).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const BSCHI_PRICELIST_ENDPOINT = 'hub-preisliste';

add_action( 'init', function () {
    if ( bschi_feature_enabled( 'pricelist' ) ) {
        add_rewrite_endpoint( BSCHI_PRICELIST_ENDPOINT, EP_ROOT | EP_PAGES );
        if ( ! get_option( 'bschi_pricelist_rewrite_flushed' ) ) {
            flush_rewrite_rules();
            update_option( 'bschi_pricelist_rewrite_flushed', 1 );
        }
    }
} );

add_filter( 'woocommerce_account_menu_items', function ( array $items ): array {
    if ( ! bschi_feature_enabled( 'pricelist' ) ) {
        return $items;
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) || empty( $resolved['pricelist_available'] ) ) {
        return $items;
    }
    $new = [];
    foreach ( $items as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === BSCHI_DOCS_ENDPOINT || $key === 'orders' ) {
            $new[ BSCHI_PRICELIST_ENDPOINT ] = 'Preisliste';
        }
    }
    if ( ! isset( $new[ BSCHI_PRICELIST_ENDPOINT ] ) ) {
        $new[ BSCHI_PRICELIST_ENDPOINT ] = 'Preisliste';
    }
    return $new;
}, 20 );

add_filter( 'woocommerce_endpoint_' . BSCHI_PRICELIST_ENDPOINT . '_title', fn() => 'Preisliste' );

add_action( 'woocommerce_account_' . BSCHI_PRICELIST_ENDPOINT . '_endpoint', function () {
    if ( ! bschi_feature_enabled( 'pricelist' ) ) {
        echo '<p>Dieser Bereich ist derzeit nicht verfügbar.</p>';
        return;
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) || empty( $resolved['pricelist_available'] ) ) {
        echo '<p>Für dein Konto ist derzeit keine Preisliste freigeschaltet.</p>';
        return;
    }

    $catalog_url   = wp_nonce_url( admin_url( 'admin-ajax.php?action=bschi_pricelist&kind=catalog' ), 'bschi_pricelist' );
    $orderlist_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=bschi_pricelist&kind=orderlist' ), 'bschi_pricelist' );

    echo '<p>Hier findest du immer die aktuelle Preisliste für dein Konto'
        . ( ! empty( $resolved['channels'] ) ? ' (' . esc_html( implode( ', ', $resolved['channels'] ) ) . ')' : '' )
        . '.</p>';
    echo '<p style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="button" href="' . esc_url( $catalog_url ) . '" target="_blank">Preisliste / Katalog (PDF)</a>';
    echo '<a class="button" href="' . esc_url( $orderlist_url ) . '" target="_blank">Bestellliste (PDF)</a>';
    echo '</p>';
} );

add_action( 'wp_ajax_bschi_pricelist', function () {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Bitte zuerst anmelden.', '', [ 'response' => 401 ] );
    }
    check_admin_referer( 'bschi_pricelist' );
    if ( ! bschi_feature_enabled( 'pricelist' ) ) {
        wp_die( 'Nicht verfügbar.', '', [ 'response' => 403 ] );
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) || empty( $resolved['pricelist_available'] ) ) {
        wp_die( 'Keine Preisliste freigeschaltet.', '', [ 'response' => 403 ] );
    }
    $kind = sanitize_key( $_GET['kind'] ?? 'catalog' );
    if ( ! in_array( $kind, [ 'catalog', 'orderlist' ], true ) ) {
        $kind = 'catalog';
    }
    $bin = bschi_hub_get_binary( '/api/v1/shop/customer/' . (int) $resolved['customer_id'] . '/pricelist?kind=' . $kind );
    if ( ! $bin ) {
        wp_die( 'Derzeit ist keine Preisliste veröffentlicht.', '', [ 'response' => 404 ] );
    }
    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( $bin['disposition'] ? 'Content-Disposition: ' . $bin['disposition'] : 'Content-Disposition: inline' );
    echo $bin['body']; // phpcs:ignore -- binärer PDF-Stream
    exit;
} );
