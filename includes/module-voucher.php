<?php
/**
 * Modul: Gutschein-Ausgabe (Online-Shop-Coupons für das Aura-Benefit).
 *
 * Der Office Hub ruft beim Einlösen einer Online-Gutschein-Prämie diese REST-Route auf
 * und legt einen WooCommerce-Gutschein (fester Euro-Betrag) an. Der Mitarbeiter gibt den
 * Code dann im Online-Shop an der Kasse ein.
 *
 * Route:  POST /wp-json/bschi/v1/coupon   (Header X-BSCHI-Secret == hub_secret)
 * Body:   { amount: 10.0, title: "...", expires: "2027-07-13" (optional) }
 * Antwort:{ ok, code, id, amount, expires }
 *
 * Erste REST-Route des Plugins (bisher reiner Client). Secret-geschützt, nur mit dem
 * gemeinsamen Hub-Secret; keine öffentliche Coupon-Erzeugung.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'bschi/v1', '/coupon', [
        'methods'             => 'POST',
        'callback'            => 'bschi_voucher_create',
        'permission_callback' => 'bschi_voucher_permission',
    ] );
} );

function bschi_voucher_permission( WP_REST_Request $request ): bool {
    $settings = bschi_get_settings();
    $expected = trim( (string) ( $settings['hub_secret'] ?? '' ) );
    $got      = trim( (string) $request->get_header( 'x-bschi-secret' ) );
    return $expected !== '' && hash_equals( $expected, $got );
}

function bschi_voucher_create( WP_REST_Request $request ) {
    if ( ! class_exists( 'WC_Coupon' ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'WooCommerce nicht aktiv' ], 503 );
    }
    $amount = round( (float) $request->get_param( 'amount' ), 2 );
    if ( $amount <= 0 ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Betrag fehlt/ungültig' ], 400 );
    }
    $title = sanitize_text_field( (string) $request->get_param( 'title' ) );

    // Code: entweder vorgegeben (kombi = gleicher Code wie Tillhub) oder generiert
    $wunsch = sanitize_text_field( (string) $request->get_param( 'code' ) );
    $code = '';
    if ( $wunsch && ! wc_get_coupon_id_by_code( strtolower( $wunsch ) ) && ! wc_get_coupon_id_by_code( $wunsch ) ) {
        $code = $wunsch;
    } else {
        for ( $i = 0; $i < 6; $i++ ) {
            $candidate = 'AURA-' . strtoupper( wp_generate_password( 8, false, false ) );
            if ( ! wc_get_coupon_id_by_code( $candidate ) ) { $code = $candidate; break; }
        }
    }
    if ( $code === '' ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Kein freier Code gefunden' ], 500 );
    }

    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_discount_type( 'fixed_cart' );   // fester Euro-Betrag auf den Warenkorb
    $coupon->set_amount( $amount );
    $coupon->set_individual_use( true );
    $coupon->set_usage_limit( 1 );
    $coupon->set_description( $title ?: ( 'Aura-Gutschein ' . $amount . ' €' ) );
    $expires = $request->get_param( 'expires' );
    if ( $expires ) {
        // WooCommerce erwartet ein Datum (Y-m-d); Gültigkeit endet am Tagesende
        $ts = strtotime( (string) $expires );
        if ( $ts ) { $coupon->set_date_expires( gmdate( 'Y-m-d', $ts ) ); }
    }
    $id = $coupon->save();
    if ( ! $id ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Coupon konnte nicht gespeichert werden' ], 500 );
    }
    return new WP_REST_Response( [
        'ok'      => true,
        'code'    => $code,
        'id'      => $id,
        'amount'  => $amount,
        'expires' => $expires ?: null,
    ], 201 );
}
