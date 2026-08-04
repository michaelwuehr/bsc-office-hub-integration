<?php
/**
 * Modul: Gebrauchtmaschinen-Verkauf.
 *
 * Zeigt den Gebrauchtmaschinen-/Teile-Bestand des Office Hubs (eigener Bestand OHNE Xentral)
 * als Karten-Grid mit Detail-Overlay. „Sofort kaufen" ist ein ganz normaler Shop-Kauf: der Hub
 * legt je Artikel ein VERSTECKTES WooCommerce-Produkt an (REST-Route unten), der Button führt
 * direkt in den Checkout (Login/Registrierung + vorhandene Bezahlmethoden inklusive).
 *
 * Shortcode:  [bsc_gebrauchtmaschinen art="alle|maschine|teil" spalten="3"]
 * AJAX:       bschi_gm_detail (Detail-HTML) · bschi_gm_anfrage · bschi_gm_abo (je nopriv)
 * REST (Hub → Shop, Secret):
 *   POST /wp-json/bschi/v1/gebraucht-produkt          Produkt anlegen/aktualisieren
 *   POST /wp-json/bschi/v1/gebraucht-produkt-loeschen Produkt löschen
 * Rückmeldung (Shop → Hub): Bestell-Status-Hooks melden verkauft/storno an
 *   POST /api/v1/gebrauchtmaschinen/extern/wc-status (SKU GM-<artikel_id>).
 */

defined( 'ABSPATH' ) || exit;

// ─── Shortcode ────────────────────────────────────────────────────────────────

add_shortcode( 'bsc_gebrauchtmaschinen', 'bschi_gm_shortcode' );

function bschi_gm_shortcode( $atts ): string {
    if ( ! bschi_feature_enabled( 'gebrauchtmaschinen' ) ) {
        return '';
    }
    $atts = shortcode_atts( [ 'art' => 'alle', 'spalten' => '3' ], $atts, 'bsc_gebrauchtmaschinen' );
    $art  = in_array( $atts['art'], [ 'maschine', 'teil' ], true ) ? $atts['art'] : '';
    $cols = max( 2, min( 4, (int) $atts['spalten'] ) );

    $path = '/api/v1/gebrauchtmaschinen/public/liste' . ( $art ? '?art=' . $art : '' );
    $data = bschi_hub_get( $path, 10 * MINUTE_IN_SECONDS );
    if ( $data === null ) {
        return '<p>Das Gebrauchtmaschinen-Angebot ist gerade nicht erreichbar – bitte später erneut versuchen.</p>';
    }
    $items = $data['items'] ?? [];

    ob_start();
    bschi_gm_styles();
    echo '<div class="bschi-gm" style="--bschi-gm-cols:' . (int) $cols . '">';
    if ( ! $items ) {
        echo '<p class="bschi-gm__leer">Aktuell sind keine Gebrauchtmaschinen im Angebot. '
           . 'Trag dich unten ein – wir melden uns, sobald es Neues gibt.</p>';
    }
    echo '<div class="bschi-gm__grid">';
    foreach ( $items as $it ) {
        echo bschi_gm_card_html( $it );
    }
    echo '</div>';
    echo bschi_gm_abo_html();
    echo bschi_gm_overlay_html();
    echo '</div>';
    bschi_gm_js();
    return ob_get_clean();
}

function bschi_gm_preis_html( $it ): string {
    if ( empty( $it['preis_eur'] ) ) {
        return '<span class="bschi-gm__preis">Preis auf Anfrage</span>';
    }
    $p = number_format( (float) $it['preis_eur'], 2, ',', '.' ) . '&nbsp;€';
    if ( ! empty( $it['preis_vb'] ) ) {
        $p .= ' <span class="bschi-gm__vb">VB</span>';
    }
    return '<span class="bschi-gm__preis">' . $p . '</span>';
}

function bschi_gm_card_html( array $it ): string {
    $meta = implode( ' · ', array_filter( [
        $it['hersteller'] ?? '', $it['modell'] ?? '',
        ! empty( $it['baujahr'] ) ? 'Bj. ' . (int) $it['baujahr'] : '',
    ] ) );
    $bild = ! empty( $it['thumb'] ) ? bschi_hub_url( $it['thumb'] ) : '';
    $band = '';
    if ( ( $it['status'] ?? '' ) === 'reserviert' ) {
        $band = '<span class="bschi-gm__band bschi-gm__band--res">Reserviert</span>';
    } elseif ( ( $it['status'] ?? '' ) === 'verkauft' ) {
        $band = '<span class="bschi-gm__band bschi-gm__band--sold">Verkauft</span>';
    }
    $h  = '<div class="bschi-gm__card' . ( $band ? ' bschi-gm__card--weg' : '' ) . '" '
        . 'data-gm-id="' . (int) $it['id'] . '" onclick="bschiGmOpen(' . (int) $it['id'] . ')">';
    $h .= '<div class="bschi-gm__bild">';
    $h .= $bild
        ? '<img src="' . esc_url( $bild ) . '" alt="' . esc_attr( $it['titel'] ?? '' ) . '" loading="lazy">'
        : '<span class="bschi-gm__nobild">Kein Bild</span>';
    $h .= $band . '</div>';
    $h .= '<div class="bschi-gm__body">';
    $h .= '<span class="bschi-gm__zustand">' . esc_html( ucfirst( $it['zustand'] ?? 'gebraucht' ) )
        . ( ( $it['art'] ?? '' ) === 'teil' ? ' · Teil' : '' ) . '</span>';
    $h .= '<h3 class="bschi-gm__titel">' . esc_html( $it['titel'] ?? '' ) . '</h3>';
    if ( $meta ) {
        $h .= '<div class="bschi-gm__meta">' . esc_html( $meta ) . '</div>';
    }
    if ( ! empty( $it['kurzbeschreibung'] ) ) {
        $h .= '<div class="bschi-gm__kurz">' . esc_html( $it['kurzbeschreibung'] ) . '</div>';
    }
    $h .= '<div class="bschi-gm__foot">' . bschi_gm_preis_html( $it )
        . '<span class="bschi-gm__mehr">Details ansehen</span></div>';
    $h .= '</div></div>';
    return $h;
}

function bschi_gm_abo_html(): string {
    $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
    return '<div class="bschi-gm__abo">'
        . '<div class="bschi-gm__abo-txt"><strong>Nichts Passendes dabei?</strong> '
        . 'Trag deine E-Mail-Adresse ein – wir benachrichtigen dich, sobald neue Maschinen '
        . 'oder Teile ins Angebot kommen. Abmeldung jederzeit per Link in jeder Mail.</div>'
        . '<form class="bschi-gm__abo-form" onsubmit="return bschiGmAbo(this,\'' . $ajax . '\')">'
        . '<input type="email" name="email" required placeholder="deine@email.de">'
        . '<button type="submit">Benachrichtigen lassen</button></form>'
        . '<div class="bschi-gm__abo-msg" style="display:none"></div></div>';
}

function bschi_gm_overlay_html(): string {
    static $done = false;   // bei mehreren Shortcodes auf einer Seite nur ein Overlay (IDs!)
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<div class="bschi-gm__overlay" id="bschi-gm-overlay" onclick="if(event.target===this)bschiGmClose()">'
        . '<div class="bschi-gm__modal"><button class="bschi-gm__close" onclick="bschiGmClose()" '
        . 'aria-label="Schließen">&times;</button><div class="bschi-gm__modal-body" id="bschi-gm-modal-body">'
        . '<p style="text-align:center;padding:40px 0">Lädt&hellip;</p></div></div></div>';
}

// ─── Detail (AJAX, serverseitig gerendert – Secret bleibt im Backend) ─────────

add_action( 'wp_ajax_bschi_gm_detail', 'bschi_gm_ajax_detail' );
add_action( 'wp_ajax_nopriv_bschi_gm_detail', 'bschi_gm_ajax_detail' );

function bschi_gm_ajax_detail(): void {
    if ( ! bschi_feature_enabled( 'gebrauchtmaschinen' ) ) {
        wp_send_json_error( [ 'msg' => 'Deaktiviert' ], 403 );
    }
    $id = (int) ( $_GET['id'] ?? 0 );
    $d  = $id ? bschi_hub_get( '/api/v1/gebrauchtmaschinen/public/' . $id, 5 * MINUTE_IN_SECONDS ) : null;
    if ( ! $d ) {
        wp_send_json_error( [ 'msg' => 'Artikel nicht gefunden' ], 404 );
    }
    wp_send_json_success( [ 'html' => bschi_gm_detail_html( $d ) ] );
}

function bschi_gm_detail_html( array $d ): string {
    $ajax   = esc_url( admin_url( 'admin-ajax.php' ) );
    $bilder = $d['bilder'] ?? [];
    $meta   = implode( ' · ', array_filter( [
        $d['hersteller'] ?? '', $d['modell'] ?? '',
        ! empty( $d['baujahr'] ) ? 'Baujahr ' . (int) $d['baujahr'] : '',
        ! empty( $d['standort'] ) ? 'Standort ' . $d['standort'] : '',
    ] ) );

    $h = '<div class="bschi-gm__detail">';
    // Galerie
    if ( $bilder ) {
        $erste = bschi_hub_url( $bilder[0]['url'] );
        $h .= '<div class="bschi-gm__galerie"><img id="bschi-gm-hauptbild" src="' . esc_url( $erste ) . '" alt="">';
        if ( count( $bilder ) > 1 ) {
            $h .= '<div class="bschi-gm__thumbs">';
            foreach ( $bilder as $b ) {
                $h .= '<img src="' . esc_url( bschi_hub_url( $b['thumb'] ) ) . '" '
                    . 'onclick="document.getElementById(\'bschi-gm-hauptbild\').src=this.dataset.full" '
                    . 'data-full="' . esc_url( bschi_hub_url( $b['url'] ) ) . '" alt="">';
            }
            $h .= '</div>';
        }
        $h .= '</div>';
    }
    // Kopf
    $h .= '<span class="bschi-gm__zustand">' . esc_html( ucfirst( $d['zustand'] ?? 'gebraucht' ) ) . '</span>';
    $h .= '<h2 class="bschi-gm__d-titel">' . esc_html( $d['titel'] ?? '' ) . '</h2>';
    if ( $meta ) {
        $h .= '<div class="bschi-gm__meta">' . esc_html( $meta ) . '</div>';
    }
    $h .= '<div class="bschi-gm__d-preis">' . bschi_gm_preis_html( $d ) . '</div>';

    // Kauf-/Anfrage-Buttons
    $status = $d['status'] ?? '';
    if ( $status === 'reserviert' ) {
        $h .= '<div class="bschi-gm__hinweis">Dieser Artikel ist aktuell reserviert.</div>';
    } elseif ( $status === 'verkauft' ) {
        $h .= '<div class="bschi-gm__hinweis">Dieser Artikel ist bereits verkauft.</div>';
    }
    $h .= '<div class="bschi-gm__cta">';
    if ( $status === 'online' && ! empty( $d['wc_product_id'] ) && function_exists( 'wc_get_checkout_url' ) ) {
        $kauf_url = add_query_arg( 'add-to-cart', (int) $d['wc_product_id'], wc_get_checkout_url() );
        $h .= '<a class="bschi-gm__kaufen" href="' . esc_url( $kauf_url ) . '">Sofort kaufen</a>';
    }
    if ( $status !== 'verkauft' ) {
        $h .= '<button class="bschi-gm__anfragen" onclick="bschiGmAnfrageToggle()">Anfrage senden</button>';
    }
    if ( ! empty( $d['expose_url'] ) ) {
        $h .= '<a class="bschi-gm__expose" href="' . esc_url( bschi_hub_url( $d['expose_url'] ) )
            . '" target="_blank" rel="noopener">Exposé (PDF)</a>';
    }
    $h .= '</div>';

    // Anfrage-Formular (eingeklappt)
    if ( $status !== 'verkauft' ) {
        $h .= '<form class="bschi-gm__anfrage-form" id="bschi-gm-anfrage" style="display:none" '
            . 'onsubmit="return bschiGmAnfrage(this,\'' . $ajax . '\',' . (int) $d['id'] . ')">'
            . '<input type="text" name="name" required placeholder="Dein Name">'
            . '<input type="email" name="email" required placeholder="deine@email.de">'
            . '<input type="tel" name="telefon" placeholder="Telefon (optional)">'
            . '<textarea name="nachricht" rows="3" placeholder="Deine Nachricht"></textarea>'
            . '<button type="submit">Anfrage absenden</button>'
            . '<div class="bschi-gm__abo-msg" style="display:none"></div></form>';
    }

    // Beschreibung
    if ( ! empty( $d['beschreibung'] ) ) {
        $h .= '<div class="bschi-gm__beschr">' . wpautop( esc_html( $d['beschreibung'] ) ) . '</div>';
    }
    // Technische Daten
    if ( ! empty( $d['tech_daten'] ) && is_array( $d['tech_daten'] ) ) {
        $h .= '<h3 class="bschi-gm__h3">Technische Daten</h3><table class="bschi-gm__tech">';
        foreach ( $d['tech_daten'] as $row ) {
            $h .= '<tr><td>' . esc_html( $row[0] ?? '' ) . '</td><td>' . esc_html( $row[1] ?? '' ) . '</td></tr>';
        }
        $h .= '</table>';
    }
    // PDFs
    if ( ! empty( $d['pdfs'] ) ) {
        $h .= '<h3 class="bschi-gm__h3">Unterlagen</h3><ul class="bschi-gm__pdfs">';
        foreach ( $d['pdfs'] as $p ) {
            $h .= '<li><a href="' . esc_url( bschi_hub_url( $p['url'] ) ) . '" target="_blank" rel="noopener">'
                . esc_html( $p['beschreibung'] ?: $p['filename'] ) . '</a></li>';
        }
        $h .= '</ul>';
    }
    // Kontakt
    $kontakt = array_filter( [ $d['kontakt_email'] ?? '', $d['kontakt_telefon'] ?? '' ] );
    if ( $kontakt ) {
        $h .= '<div class="bschi-gm__kontakt">Fragen? ' . esc_html( implode( ' · ', $kontakt ) ) . '</div>';
    }
    $h .= '</div>';
    return $h;
}

// ─── Anfrage + Abo (AJAX-Proxy → Hub, Secret bleibt serverseitig) ─────────────

function bschi_gm_hub_post_json( string $path, array $payload ): array {
    $endpoint = bschi_hub_url( $path );
    if ( ! $endpoint ) {
        return [ 0, null ];
    }
    $response = wp_remote_post( $endpoint, [
        'timeout' => 15,
        'headers' => bschi_hub_headers(),
        'body'    => wp_json_encode( $payload ),
    ] );
    if ( is_wp_error( $response ) ) {
        error_log( '[BSCHI] GM-POST fehlgeschlagen (' . $path . '): ' . $response->get_error_message() );
        return [ 0, null ];
    }
    return [
        (int) wp_remote_retrieve_response_code( $response ),
        json_decode( wp_remote_retrieve_body( $response ), true ),
    ];
}

add_action( 'wp_ajax_bschi_gm_anfrage', 'bschi_gm_ajax_anfrage' );
add_action( 'wp_ajax_nopriv_bschi_gm_anfrage', 'bschi_gm_ajax_anfrage' );

function bschi_gm_ajax_anfrage(): void {
    if ( ! bschi_feature_enabled( 'gebrauchtmaschinen' ) ) {
        wp_send_json_error( [ 'msg' => 'Deaktiviert' ], 403 );
    }
    $id = (int) ( $_POST['id'] ?? 0 );
    [ $code, $data ] = bschi_gm_hub_post_json( '/api/v1/gebrauchtmaschinen/public/' . $id . '/anfrage', [
        'name'      => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
        'email'     => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
        'telefon'   => sanitize_text_field( wp_unslash( $_POST['telefon'] ?? '' ) ),
        'nachricht' => sanitize_textarea_field( wp_unslash( $_POST['nachricht'] ?? '' ) ),
    ] );
    if ( $code === 200 ) {
        wp_send_json_success( [ 'msg' => 'Danke! Wir melden uns so schnell wie möglich.' ] );
    }
    wp_send_json_error( [ 'msg' => $data['detail'] ?? 'Anfrage konnte nicht gesendet werden.' ], 400 );
}

add_action( 'wp_ajax_bschi_gm_abo', 'bschi_gm_ajax_abo' );
add_action( 'wp_ajax_nopriv_bschi_gm_abo', 'bschi_gm_ajax_abo' );

function bschi_gm_ajax_abo(): void {
    if ( ! bschi_feature_enabled( 'gebrauchtmaschinen' ) ) {
        wp_send_json_error( [ 'msg' => 'Deaktiviert' ], 403 );
    }
    [ $code, $data ] = bschi_gm_hub_post_json( '/api/v1/gebrauchtmaschinen/public/abo', [
        'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
    ] );
    if ( $code === 200 ) {
        $msg = ( $data['status'] ?? '' ) === 'bereits_bestaetigt'
            ? 'Du bist schon eingetragen – wir melden uns bei Neuigkeiten.'
            : 'Fast geschafft: bitte bestätige den Link, den wir dir gerade gemailt haben.';
        wp_send_json_success( [ 'msg' => $msg ] );
    }
    wp_send_json_error( [ 'msg' => $data['detail'] ?? 'Eintragung fehlgeschlagen.' ], 400 );
}

// ─── REST: Hub pflegt das versteckte Kauf-Produkt ─────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'bschi/v1', '/gebraucht-produkt', [
        'methods'             => 'POST',
        'callback'            => 'bschi_gm_rest_produkt',
        'permission_callback' => 'bschi_voucher_permission',   // gleiche Secret-Prüfung wie Coupons
    ] );
    register_rest_route( 'bschi/v1', '/gebraucht-produkt-loeschen', [
        'methods'             => 'POST',
        'callback'            => 'bschi_gm_rest_produkt_loeschen',
        'permission_callback' => 'bschi_voucher_permission',
    ] );
} );

function bschi_gm_rest_produkt( WP_REST_Request $request ) {
    if ( ! class_exists( 'WC_Product_Simple' ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'WooCommerce nicht aktiv' ], 503 );
    }
    $sku     = sanitize_text_field( (string) $request->get_param( 'sku' ) );
    $titel   = sanitize_text_field( (string) $request->get_param( 'titel' ) );
    $preis   = round( (float) $request->get_param( 'preis' ), 2 );
    $kaufbar = (bool) $request->get_param( 'kaufbar' );
    if ( ! $sku || ! $titel ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'sku/titel fehlt' ], 400 );
    }

    $pid = (int) $request->get_param( 'product_id' );
    if ( $pid && ! wc_get_product( $pid ) ) {
        $pid = 0;
    }
    if ( ! $pid ) {
        $pid = (int) wc_get_product_id_by_sku( $sku );
    }
    $product = $pid ? wc_get_product( $pid ) : new WC_Product_Simple();
    if ( ! $product ) {
        $product = new WC_Product_Simple();
    }

    $product->set_name( $titel );
    $product->set_sku( $sku );
    $product->set_regular_price( $preis > 0 ? (string) $preis : '' );
    $product->set_short_description( sanitize_textarea_field( (string) $request->get_param( 'kurz' ) ) );
    $product->set_description( sanitize_textarea_field( (string) $request->get_param( 'beschreibung' ) ) );
    $product->set_catalog_visibility( 'hidden' );   // nie im Shop-Katalog/Suche – nur via Angebots-Seite
    $product->set_sold_individually( true );
    $product->set_manage_stock( true );
    $product->set_stock_quantity( $kaufbar ? 1 : 0 );
    $product->set_stock_status( $kaufbar ? 'instock' : 'outofstock' );
    $product->set_status( $kaufbar ? 'publish' : 'draft' );
    $id = $product->save();
    if ( ! $id ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Produkt konnte nicht gespeichert werden' ], 500 );
    }

    // Produktbild einmalig aus dem Hub laden (öffentliche Bild-URL); bei geänderter Quelle neu
    $bild_url = esc_url_raw( (string) $request->get_param( 'bild_url' ) );
    if ( $bild_url && get_post_meta( $id, '_bschi_gm_bild_url', true ) !== $bild_url ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att_id = media_sideload_image( $bild_url, $id, $titel, 'id' );
        if ( ! is_wp_error( $att_id ) ) {
            set_post_thumbnail( $id, $att_id );
            update_post_meta( $id, '_bschi_gm_bild_url', $bild_url );
        } else {
            error_log( '[BSCHI] GM-Produktbild fehlgeschlagen (' . $sku . '): ' . $att_id->get_error_message() );
        }
    }

    return new WP_REST_Response( [
        'ok'           => true,
        'id'           => $id,
        'checkout_url' => function_exists( 'wc_get_checkout_url' )
            ? add_query_arg( 'add-to-cart', $id, wc_get_checkout_url() ) : '',
    ], 200 );
}

function bschi_gm_rest_produkt_loeschen( WP_REST_Request $request ) {
    $pid     = (int) $request->get_param( 'product_id' );
    $product = $pid ? wc_get_product( $pid ) : null;
    if ( $product ) {
        $product->delete( true );
    }
    return new WP_REST_Response( [ 'ok' => true ], 200 );
}

// ─── Bestell-Hooks: Kauf/Storno an den Hub zurückmelden ───────────────────────

function bschi_gm_order_event( $order_id, string $event ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    foreach ( $order->get_items() as $item ) {
        $product = is_callable( [ $item, 'get_product' ] ) ? $item->get_product() : null;
        $sku     = $product ? (string) $product->get_sku() : '';
        if ( strpos( $sku, 'GM-' ) !== 0 ) {
            continue;
        }
        // Doppelte Meldungen (processing → completed) vermeiden
        $meta_key = '_bschi_gm_gemeldet_' . $event;
        if ( $order->get_meta( $meta_key ) ) {
            continue;
        }
        bschi_hub_post( '/api/v1/gebrauchtmaschinen/extern/wc-status', [
            'sku'   => $sku,
            'event' => $event,
            'order' => $order->get_order_number(),
            'kunde' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        ] );
        $order->update_meta_data( $meta_key, time() );
        $order->save();
        // Listen-/Detail-Cache verwerfen, damit die Angebots-Seite sofort „Verkauft" zeigt
        foreach ( [ '', '?art=maschine', '?art=teil' ] as $q ) {
            delete_transient( 'bschi_get_' . md5( '/api/v1/gebrauchtmaschinen/public/liste' . $q ) );
        }
        delete_transient( 'bschi_get_' . md5( '/api/v1/gebrauchtmaschinen/public/' . (int) substr( $sku, 3 ) ) );
    }
}

// Bestellung platziert/bezahlt → Maschine ist weg (auch on-hold: Vorkasse-Bestellung bindet den Bestand)
add_action( 'woocommerce_order_status_processing', fn( $oid ) => bschi_gm_order_event( $oid, 'verkauft' ) );
add_action( 'woocommerce_order_status_completed',  fn( $oid ) => bschi_gm_order_event( $oid, 'verkauft' ) );
add_action( 'woocommerce_order_status_on-hold',    fn( $oid ) => bschi_gm_order_event( $oid, 'verkauft' ) );
// Storno/Fehlschlag → Hub stellt den Artikel wieder online (setzt auch den Bestand zurück)
add_action( 'woocommerce_order_status_cancelled',  fn( $oid ) => bschi_gm_order_event( $oid, 'storno' ) );
add_action( 'woocommerce_order_status_refunded',   fn( $oid ) => bschi_gm_order_event( $oid, 'storno' ) );
add_action( 'woocommerce_order_status_failed',     fn( $oid ) => bschi_gm_order_event( $oid, 'storno' ) );

// ─── Styles + JS (inline, einmal je Seitenaufbau) ─────────────────────────────

function bschi_gm_styles(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    ?>
    <style>
    .bschi-gm__grid{display:grid;grid-template-columns:repeat(var(--bschi-gm-cols,3),1fr);gap:22px;margin:8px 0 26px}
    @media(max-width:900px){.bschi-gm__grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:560px){.bschi-gm__grid{grid-template-columns:1fr}}
    .bschi-gm__card{border:1px solid #e3e1db;border-radius:12px;overflow:hidden;background:#fff;cursor:pointer;
      transition:box-shadow .15s,transform .15s;display:flex;flex-direction:column}
    .bschi-gm__card:hover{box-shadow:0 6px 22px rgba(0,0,0,.10);transform:translateY(-2px)}
    .bschi-gm__card--weg{opacity:.75}
    .bschi-gm__bild{position:relative;aspect-ratio:4/3;background:#f2f1ec;display:flex;align-items:center;justify-content:center}
    .bschi-gm__bild img{width:100%;height:100%;object-fit:cover;display:block}
    .bschi-gm__nobild{color:#9a988f;font-size:.85em}
    .bschi-gm__band{position:absolute;top:12px;left:-34px;transform:rotate(-35deg);padding:4px 40px;
      font-size:.75em;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff}
    .bschi-gm__band--res{background:#b45309}.bschi-gm__band--sold{background:#4b5a42}
    .bschi-gm__body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:6px;flex:1}
    .bschi-gm__zustand{font-size:.72em;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4b5a42}
    .bschi-gm__titel{margin:0;font-size:clamp(1.02em,1.6vw,1.15em);line-height:1.3}
    .bschi-gm__d-titel{margin:.2em 0 0;font-size:clamp(1.25em,2.2vw,1.6em)}
    .bschi-gm__meta{font-size:.86em;color:#6d6b62}
    .bschi-gm__kurz{font-size:.9em;color:#3f3e38;flex:1}
    .bschi-gm__foot{display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:8px;flex-wrap:wrap}
    .bschi-gm__preis{font-weight:700;color:#4b5a42;font-size:1.06em}
    .bschi-gm__vb{font-size:.75em;font-weight:600;color:#6d6b62}
    .bschi-gm__mehr{font-size:.84em;color:#4b5a42;text-decoration:underline}
    .bschi-gm__leer{padding:14px 0}
    .bschi-gm__abo{background:#f2f4ef;border-radius:12px;padding:20px 22px;margin:6px 0 10px}
    .bschi-gm__abo-txt{margin-bottom:12px;font-size:.94em}
    .bschi-gm__abo-form{display:flex;gap:10px;flex-wrap:wrap}
    .bschi-gm__abo-form input{flex:1;min-width:220px;padding:11px 14px;border:1px solid #cfcdc4;border-radius:8px}
    .bschi-gm__abo-form button,.bschi-gm__anfrage-form button{background:#4b5a42;color:#fff;border:0;
      border-radius:8px;padding:11px 20px;font-weight:600;cursor:pointer}
    .bschi-gm__abo-msg{margin-top:10px;font-size:.9em;font-weight:600}
    .bschi-gm__overlay{display:none;position:fixed;inset:0;background:rgba(20,20,16,.6);z-index:99999;
      align-items:flex-start;justify-content:center;padding:4vh 12px;overflow-y:auto}
    .bschi-gm__overlay.offen{display:flex}
    .bschi-gm__modal{position:relative;background:#fff;border-radius:14px;max-width:760px;width:100%;
      padding:26px 28px 30px;margin-bottom:6vh}
    .bschi-gm__close{position:absolute;top:10px;right:14px;background:none;border:0;font-size:30px;
      line-height:1;cursor:pointer;color:#6d6b62;padding:6px}
    .bschi-gm__galerie img#bschi-gm-hauptbild{width:100%;max-height:420px;object-fit:cover;border-radius:10px}
    .bschi-gm__thumbs{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
    .bschi-gm__thumbs img{width:76px;height:58px;object-fit:cover;border-radius:6px;cursor:pointer}
    .bschi-gm__d-preis{margin:10px 0 4px;font-size:1.25em}
    .bschi-gm__hinweis{background:#fdf3e7;border:1px solid #eddcc3;color:#8a5a1d;border-radius:8px;
      padding:10px 14px;margin:10px 0;font-size:.92em}
    .bschi-gm__cta{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0;align-items:center}
    .bschi-gm__kaufen{background:#4b5a42;color:#fff !important;border-radius:10px;padding:15px 34px;
      font-size:1.12em;font-weight:700;text-decoration:none;display:inline-block}
    .bschi-gm__kaufen:hover{background:#3d4a36}
    .bschi-gm__anfragen{background:#fff;border:2px solid #4b5a42;color:#4b5a42;border-radius:10px;
      padding:12px 22px;font-weight:600;cursor:pointer}
    .bschi-gm__expose{font-size:.92em;color:#4b5a42;text-decoration:underline}
    .bschi-gm__anfrage-form{display:flex;flex-direction:column;gap:10px;background:#f7f6f2;
      border-radius:10px;padding:16px;margin:0 0 14px}
    .bschi-gm__anfrage-form input,.bschi-gm__anfrage-form textarea{padding:10px 13px;
      border:1px solid #cfcdc4;border-radius:8px;font:inherit}
    .bschi-gm__beschr{margin:10px 0;line-height:1.6}
    .bschi-gm__h3{margin:18px 0 8px;font-size:1.05em}
    .bschi-gm__tech{width:100%;border-collapse:collapse;font-size:.93em}
    .bschi-gm__tech td{padding:7px 10px;border-bottom:1px solid #eceae4}
    .bschi-gm__tech td:first-child{color:#6d6b62;width:42%}
    .bschi-gm__pdfs{margin:0;padding-left:20px}
    .bschi-gm__pdfs li{margin-bottom:5px}
    .bschi-gm__kontakt{margin-top:18px;padding-top:12px;border-top:1px solid #eceae4;
      font-size:.9em;color:#6d6b62}
    </style>
    <?php
}

function bschi_gm_js(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
    ?>
    <script>
    function bschiGmOpen(id){
      var ov=document.getElementById('bschi-gm-overlay'),body=document.getElementById('bschi-gm-modal-body');
      ov.classList.add('offen');document.documentElement.style.overflow='hidden';
      body.innerHTML='<p style="text-align:center;padding:40px 0">L&auml;dt&hellip;</p>';
      fetch('<?php echo $ajax; ?>?action=bschi_gm_detail&id='+id)
        .then(function(r){return r.json();})
        .then(function(d){body.innerHTML=(d.success&&d.data.html)?d.data.html:'<p>Artikel konnte nicht geladen werden.</p>';})
        .catch(function(){body.innerHTML='<p>Artikel konnte nicht geladen werden.</p>';});
    }
    function bschiGmClose(){
      document.getElementById('bschi-gm-overlay').classList.remove('offen');
      document.documentElement.style.overflow='';
    }
    function bschiGmAnfrageToggle(){
      var f=document.getElementById('bschi-gm-anfrage');
      if(f){f.style.display=(f.style.display==='none')?'flex':'none';}
    }
    function _bschiGmMsg(form,ok,text){
      var m=form.querySelector('.bschi-gm__abo-msg')||form.parentNode.querySelector('.bschi-gm__abo-msg');
      if(m){m.style.display='block';m.style.color=ok?'#3d6b2f':'#a03325';m.textContent=text;}
    }
    function bschiGmAbo(form,ajax){
      var btn=form.querySelector('button');btn.disabled=true;btn.textContent='Sendet…';
      var fd=new FormData(form);fd.append('action','bschi_gm_abo');
      fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        _bschiGmMsg(form,!!d.success,(d.data&&d.data.msg)||'Fehler – bitte später erneut versuchen.');
        if(d.success){form.reset();}
      }).catch(function(){_bschiGmMsg(form,false,'Fehler – bitte später erneut versuchen.');})
      .finally(function(){btn.disabled=false;btn.textContent='Benachrichtigen lassen';});
      return false;
    }
    function bschiGmAnfrage(form,ajax,id){
      var btn=form.querySelector('button');btn.disabled=true;btn.textContent='Sendet…';
      var fd=new FormData(form);fd.append('action','bschi_gm_anfrage');fd.append('id',id);
      fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        _bschiGmMsg(form,!!d.success,(d.data&&d.data.msg)||'Fehler – bitte später erneut versuchen.');
        if(d.success){form.reset();}
      }).catch(function(){_bschiGmMsg(form,false,'Fehler – bitte später erneut versuchen.');})
      .finally(function(){btn.disabled=false;btn.textContent='Anfrage absenden';});
      return false;
    }
    </script>
    <?php
}
