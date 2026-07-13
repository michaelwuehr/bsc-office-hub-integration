<?php
/**
 * Modul: First-Party-Tracking (Kampagnen-Analytics, Marketing Hub).
 *
 * - Liefert assets/ws-track.js aus (statisch, cachebar); das Script startet erst nach
 *   Consent "Statistik" (Complianz-Bridge) und arbeitet ohne Cookies (localStorage).
 * - admin-ajax "bschi_trk_ctx": hebt visitor_id + UTM in die WooCommerce-Session
 *   (funktioniert für klassischen UND Block-Checkout, cache-sicher).
 * - Bestell-Push: bei Bestellabschluss gehen Coupons/Summen/Positionen + E-Mail-Hash
 *   (SHA-256, Klartext verlässt WordPress nie) + visitor/UTM non-blocking an den
 *   Marketing Hub (X-BSMH-Secret). Läuft auch OHNE Consent – dann ohne visitor_id
 *   (Bestellverarbeitung auf eigener Infrastruktur, Art. 6 Abs. 1 lit. f DSGVO).
 *
 * WICHTIG: Modul ist per Default AUS (feature_tracking) – Aktivierung erst nach
 * aktualisierter Datenschutzerklärung.
 */

defined( 'ABSPATH' ) || exit;

function bschi_tracking_hub_url(): string {
    $s = bschi_get_settings();
    return rtrim( $s['tracking_hub_url'] ?? '', '/' );
}

// ─── Frontend-Script ──────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function () {
    if ( ! bschi_feature_enabled( 'tracking' ) || ! bschi_tracking_hub_url() ) {
        return;
    }
    // Shop-Mitarbeiter & Admins nicht tracken
    $disabled = is_user_logged_in() && current_user_can( 'edit_shop_orders' );

    wp_enqueue_script(
        'bschi-ws-track',
        BSCHI_PLUGIN_URL . 'assets/ws-track.js',
        [],
        BSCHI_VERSION,
        [ 'in_footer' => true, 'strategy' => 'defer' ]
    );

    $cfg = [
        'endpoint' => bschi_tracking_hub_url() . '/api/v1/track/events',
        'ajax'     => admin_url( 'admin-ajax.php' ),
        'disabled' => $disabled,
        'ctx'      => 'page',
        'hasCart'  => function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty(),
        'consentSource' => 'complianz',
    ];
    if ( function_exists( 'is_product' ) && is_product() ) {
        $p = wc_get_product( get_queried_object_id() );
        if ( $p ) {
            $cfg['ctx']   = 'product';
            $cfg['sku']   = $p->get_sku() ?: (string) $p->get_id();
            $cfg['price'] = (float) $p->get_price();
        }
    } elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
        $cfg['ctx']      = 'category';
        $cfg['category'] = single_term_title( '', false );
    } elseif ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
        $cfg['ctx']       = 'checkout';
        $cfg['cartTotal'] = function_exists( 'WC' ) && WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : null;
    } elseif ( is_search() ) {
        $cfg['ctx']         = 'search';
        $cfg['searchQuery'] = get_search_query();
    }
    wp_localize_script( 'bschi-ws-track', 'bschiTrack', $cfg );
} );

// ─── visitor_id/UTM → WooCommerce-Session (für die Bestell-Verknüpfung) ──────

add_action( 'wp_ajax_bschi_trk_ctx', 'bschi_trk_ctx_handler' );
add_action( 'wp_ajax_nopriv_bschi_trk_ctx', 'bschi_trk_ctx_handler' );
function bschi_trk_ctx_handler(): void {
    if ( ! bschi_feature_enabled( 'tracking' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
        wp_send_json_success(); // still – keine Details nach außen
    }
    $vid = preg_replace( '/[^a-f0-9]/', '', (string) ( $_POST['v'] ?? '' ) );
    if ( strlen( $vid ) >= 16 && strlen( $vid ) <= 32 ) {
        $ctx = [ 'v' => $vid ];
        foreach ( [ 'uf', 'ul' ] as $k ) {
            $raw = json_decode( wp_unslash( (string) ( $_POST[ $k ] ?? '' ) ), true );
            if ( is_array( $raw ) ) {
                $ctx[ $k ] = array_map( 'sanitize_text_field', array_intersect_key( $raw, array_flip( [ 's', 'm', 'c', 'n' ] ) ) );
            }
        }
        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true ); // WC-eigenes (funktionales) Session-Cookie
        }
        WC()->session->set( 'bschi_trk', $ctx );
    }
    wp_send_json_success();
}

// ─── Bestell-Push an den Marketing Hub ───────────────────────────────────────

// Klassischer Checkout + Store-API (Block-Checkout) – Muster wie module-double-orders
add_action( 'woocommerce_checkout_order_processed', 'bschi_tracking_order_processed', 20, 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'bschi_tracking_order_processed', 20, 1 );

function bschi_tracking_order_processed( $order_or_id ): void {
    if ( ! bschi_feature_enabled( 'tracking' ) || ! bschi_tracking_hub_url() ) {
        return;
    }
    $order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order || $order->get_meta( '_bschi_trk_pushed' ) ) {
        return;
    }
    // Tracking-Kontext aus der WC-Session (nur vorhanden, wenn Besucher Consent hatte)
    $ctx = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( 'bschi_trk' ) : null;
    if ( is_array( $ctx ) && ! empty( $ctx['v'] ) ) {
        $order->update_meta_data( '_bschi_trk_visitor', $ctx['v'] );
    }

    $items = [];
    foreach ( $order->get_items() as $item ) {
        $p       = $item->get_product();
        $items[] = [
            'sku'        => $p ? ( $p->get_sku() ?: (string) $p->get_id() ) : '',
            'product_id' => $item->get_product_id(),
            'name'       => $item->get_name(),
            'qty'        => (float) $item->get_quantity(),
            'price'      => $item->get_quantity() ? round( (float) $item->get_total() / $item->get_quantity(), 2 ) : 0,
        ];
    }
    $email   = strtolower( trim( $order->get_billing_email() ) );
    $payload = [
        'wc_order_id'     => (string) $order->get_id(),
        'wc_order_number' => (string) $order->get_order_number(),
        'wc_order_key'    => str_replace( 'wc_order_', '', (string) $order->get_order_key() ),
        'ts'              => gmdate( 'c' ),
        'total'           => (float) $order->get_total(),
        'currency'        => $order->get_currency(),
        'discount_total'  => (float) $order->get_discount_total(),
        'coupon_codes'    => array_values( $order->get_coupon_codes() ),
        'items'           => $items,
        'email_sha256'    => $email ? hash( 'sha256', $email ) : null,
        'plz'             => $order->get_billing_postcode(),
        'country'         => $order->get_billing_country(),
        'visitor_id'      => is_array( $ctx ) ? ( $ctx['v'] ?? null ) : null,
        'utm_first'       => is_array( $ctx ) ? ( $ctx['uf'] ?? null ) : null,
        'utm_last'        => is_array( $ctx ) ? ( $ctx['ul'] ?? null ) : null,
    ];

    $s = bschi_get_settings();
    wp_remote_post( bschi_tracking_hub_url() . '/api/v1/track/order', [
        'timeout'  => 5,
        'blocking' => false, // Checkout nie ausbremsen
        'headers'  => [
            'Content-Type'  => 'application/json',
            'X-BSMH-Secret' => $s['tracking_secret'] ?? '',
        ],
        'body'     => wp_json_encode( $payload ),
    ] );
    $order->update_meta_data( '_bschi_trk_pushed', gmdate( 'c' ) );
    $order->save();
}
