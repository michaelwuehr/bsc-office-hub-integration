<?php
/**
 * Modul: Pflanzenflohmarkt.
 *
 * Zeigt den Pflanzen-Bestand des Office Hubs (eigener Bestand OHNE Xentral, mit Menge)
 * als Karten-Grid mit Detail-Overlay. „Sofort kaufen" ist ein ganz normaler Shop-Kauf: der Hub
 * legt je Artikel ein VERSTECKTES WooCommerce-Produkt mit Lagerbestand = Hub-Menge an
 * (REST-Route unten), der Button führt mit gewählter Stückzahl direkt in den Checkout.
 *
 * Shortcode:  [bsc_pflanzenflohmarkt spalten="3"]
 * AJAX:       bschi_pf_detail (Detail-HTML) · bschi_pf_anfrage · bschi_pf_abo (je nopriv)
 * REST (Hub → Shop, Secret):
 *   POST /wp-json/bschi/v1/pflanzen-produkt          Produkt anlegen/aktualisieren (menge!)
 *   POST /wp-json/bschi/v1/pflanzen-produkt-loeschen Produkt löschen
 * Rückmeldung (Shop → Hub): Bestell-Status-Hooks melden verkauft/storno inkl. Stückzahl an
 *   POST /api/v1/pflanzen/extern/wc-status (SKU PF-<artikel_id>, Feld menge).
 */

defined( 'ABSPATH' ) || exit;

// ─── Shortcode ────────────────────────────────────────────────────────────────

add_shortcode( 'bsc_pflanzenflohmarkt', 'bschi_pf_shortcode' );

function bschi_pf_shortcode( $atts ): string {
    if ( ! bschi_feature_enabled( 'pflanzenflohmarkt' ) ) {
        return '';
    }
    $atts = shortcode_atts( [ 'spalten' => '3' ], $atts, 'bsc_pflanzenflohmarkt' );
    $cols = max( 2, min( 4, (int) $atts['spalten'] ) );

    $data = bschi_hub_get( '/api/v1/pflanzen/public/liste', 10 * MINUTE_IN_SECONDS );
    if ( $data === null ) {
        return '<p>Der Pflanzenflohmarkt ist gerade nicht erreichbar – bitte später erneut versuchen.</p>';
    }
    $items = $data['items'] ?? [];

    ob_start();
    bschi_pf_styles();
    echo '<div class="bschi-pf" style="--bschi-pf-cols:' . (int) $cols . '">';
    if ( ! $items ) {
        echo '<p class="bschi-pf__leer">Aktuell sind keine Pflanzen im Flohmarkt. '
           . 'Trag dich unten ein – wir melden uns, sobald es Neues gibt.</p>';
    }
    echo '<div class="bschi-pf__grid">';
    foreach ( $items as $it ) {
        echo bschi_pf_card_html( $it );
    }
    echo '</div>';
    echo bschi_pf_abo_html();
    echo bschi_pf_overlay_html();
    echo '</div>';
    bschi_pf_js();
    return ob_get_clean();
}

function bschi_pf_preis_html( $it ): string {
    if ( empty( $it['preis_eur'] ) ) {
        return '<span class="bschi-pf__preis">Preis auf Anfrage</span>';
    }
    return '<span class="bschi-pf__preis">' . number_format( (float) $it['preis_eur'], 2, ',', '.' ) . '&nbsp;€</span>';
}

function bschi_pf_card_html( array $it ): string {
    $bild  = ! empty( $it['thumb'] ) ? bschi_hub_url( $it['thumb'] ) : '';
    $menge = (int) ( $it['menge'] ?? 0 );
    $band  = '';
    if ( ( $it['status'] ?? '' ) === 'ausverkauft' || $menge < 1 ) {
        $band = '<span class="bschi-pf__band">Ausverkauft</span>';
    }
    $h  = '<div class="bschi-pf__card' . ( $band ? ' bschi-pf__card--weg' : '' ) . '" '
        . 'data-pf-id="' . (int) $it['id'] . '" onclick="bschiPfOpen(' . (int) $it['id'] . ')">';
    $h .= '<div class="bschi-pf__bild">';
    $h .= $bild
        ? '<img src="' . esc_url( $bild ) . '" alt="' . esc_attr( $it['titel'] ?? '' ) . '" loading="lazy">'
        : '<span class="bschi-pf__nobild">Kein Bild</span>';
    $h .= $band . '</div>';
    $h .= '<div class="bschi-pf__body">';
    $h .= '<h3 class="bschi-pf__titel">' . esc_html( $it['titel'] ?? '' ) . '</h3>';
    if ( ! empty( $it['botanischer_name'] ) ) {
        $h .= '<div class="bschi-pf__bot">' . esc_html( $it['botanischer_name'] ) . '</div>';
    }
    if ( ! empty( $it['kurzbeschreibung'] ) ) {
        $h .= '<div class="bschi-pf__kurz">' . esc_html( $it['kurzbeschreibung'] ) . '</div>';
    }
    if ( ! $band && $menge > 0 && $menge <= 3 ) {
        $h .= '<div class="bschi-pf__knapp">Nur noch ' . $menge . ' verfügbar</div>';
    }
    $h .= '<div class="bschi-pf__foot">' . bschi_pf_preis_html( $it )
        . '<span class="bschi-pf__mehr">Details ansehen</span></div>';
    $h .= '</div></div>';
    return $h;
}

function bschi_pf_abo_html(): string {
    $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
    return '<div class="bschi-pf__abo">'
        . '<div class="bschi-pf__abo-txt"><strong>Nichts Passendes dabei?</strong> '
        . 'Trag deine E-Mail-Adresse ein – wir benachrichtigen dich, sobald neue Pflanzen '
        . 'in den Flohmarkt kommen. Abmeldung jederzeit per Link in jeder Mail.</div>'
        . '<form class="bschi-pf__abo-form" onsubmit="return bschiPfAbo(this,\'' . $ajax . '\')">'
        . '<input type="email" name="email" required placeholder="deine@email.de">'
        . '<button type="submit">Benachrichtigen lassen</button></form>'
        . '<div class="bschi-pf__abo-msg" style="display:none"></div></div>';
}

function bschi_pf_overlay_html(): string {
    static $done = false;   // bei mehreren Shortcodes auf einer Seite nur ein Overlay (IDs!)
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<div class="bschi-pf__overlay" id="bschi-pf-overlay" onclick="if(event.target===this)bschiPfClose()">'
        . '<div class="bschi-pf__modal"><button class="bschi-pf__close" onclick="bschiPfClose()" '
        . 'aria-label="Schließen">&times;</button><div class="bschi-pf__modal-body" id="bschi-pf-modal-body">'
        . '<p style="text-align:center;padding:40px 0">Lädt&hellip;</p></div></div></div>';
}

// ─── Detail (AJAX, serverseitig gerendert – Secret bleibt im Backend) ─────────

add_action( 'wp_ajax_bschi_pf_detail', 'bschi_pf_ajax_detail' );
add_action( 'wp_ajax_nopriv_bschi_pf_detail', 'bschi_pf_ajax_detail' );

function bschi_pf_ajax_detail(): void {
    if ( ! bschi_feature_enabled( 'pflanzenflohmarkt' ) ) {
        wp_send_json_error( [ 'msg' => 'Deaktiviert' ], 403 );
    }
    $id = (int) ( $_GET['id'] ?? 0 );
    $d  = $id ? bschi_hub_get( '/api/v1/pflanzen/public/' . $id, 5 * MINUTE_IN_SECONDS ) : null;
    if ( ! $d ) {
        wp_send_json_error( [ 'msg' => 'Artikel nicht gefunden' ], 404 );
    }
    wp_send_json_success( [ 'html' => bschi_pf_detail_html( $d ) ] );
}

function bschi_pf_detail_html( array $d ): string {
    $ajax   = esc_url( admin_url( 'admin-ajax.php' ) );
    $bilder = $d['bilder'] ?? [];
    $menge  = (int) ( $d['menge'] ?? 0 );
    $status = $d['status'] ?? '';

    $h = '<div class="bschi-pf__detail">';
    // Galerie
    if ( $bilder ) {
        $erste = bschi_hub_url( $bilder[0]['url'] );
        $h .= '<div class="bschi-pf__galerie"><img id="bschi-pf-hauptbild" src="' . esc_url( $erste ) . '" alt="">';
        if ( count( $bilder ) > 1 ) {
            $h .= '<div class="bschi-pf__thumbs">';
            foreach ( $bilder as $b ) {
                $h .= '<img src="' . esc_url( bschi_hub_url( $b['thumb'] ) ) . '" '
                    . 'onclick="document.getElementById(\'bschi-pf-hauptbild\').src=this.dataset.full" '
                    . 'data-full="' . esc_url( bschi_hub_url( $b['url'] ) ) . '" alt="">';
            }
            $h .= '</div>';
        }
        $h .= '</div>';
    }
    // Kopf
    $h .= '<h2 class="bschi-pf__d-titel">' . esc_html( $d['titel'] ?? '' ) . '</h2>';
    if ( ! empty( $d['botanischer_name'] ) ) {
        $h .= '<div class="bschi-pf__bot">' . esc_html( $d['botanischer_name'] ) . '</div>';
    }
    $h .= '<div class="bschi-pf__d-preis">' . bschi_pf_preis_html( $d ) . '</div>';

    if ( $status === 'ausverkauft' || $menge < 1 ) {
        $h .= '<div class="bschi-pf__hinweis">Diese Pflanze ist aktuell ausverkauft.</div>';
    } elseif ( $menge <= 3 ) {
        $h .= '<div class="bschi-pf__hinweis">Nur noch ' . $menge . ' Stück verfügbar.</div>';
    }

    // Kauf mit Stückzahl (add-to-cart trägt quantity in den Warenkorb/Checkout)
    $h .= '<div class="bschi-pf__cta">';
    if ( $status === 'online' && $menge > 0 && ! empty( $d['wc_product_id'] )
         && function_exists( 'wc_get_checkout_url' ) ) {
        $kauf_url = add_query_arg( 'add-to-cart', (int) $d['wc_product_id'], wc_get_checkout_url() );
        $h .= '<span class="bschi-pf__qty">Stück: <select id="bschi-pf-qty">';
        for ( $i = 1; $i <= min( 10, $menge ); $i++ ) {
            $h .= '<option value="' . $i . '">' . $i . '</option>';
        }
        $h .= '</select></span>';
        $h .= '<a class="bschi-pf__kaufen" data-kauf="' . esc_url( $kauf_url ) . '" '
            . 'href="' . esc_url( $kauf_url ) . '" onclick="return bschiPfKauf(this)">Sofort kaufen</a>';
    }
    $h .= '<button class="bschi-pf__anfragen" onclick="bschiPfAnfrageToggle()">Anfrage senden</button>';
    $h .= '</div>';

    // Anfrage-Formular (eingeklappt)
    $h .= '<form class="bschi-pf__anfrage-form" id="bschi-pf-anfrage" style="display:none" '
        . 'onsubmit="return bschiPfAnfrage(this,\'' . $ajax . '\',' . (int) $d['id'] . ')">'
        . '<input type="text" name="name" required placeholder="Dein Name">'
        . '<input type="email" name="email" required placeholder="deine@email.de">'
        . '<input type="tel" name="telefon" placeholder="Telefon (optional)">'
        . '<textarea name="nachricht" rows="3" placeholder="Deine Nachricht"></textarea>'
        . '<button type="submit">Anfrage absenden</button>'
        . '<div class="bschi-pf__abo-msg" style="display:none"></div></form>';

    // Beschreibung
    if ( ! empty( $d['beschreibung'] ) ) {
        $h .= '<div class="bschi-pf__beschr">' . wpautop( esc_html( $d['beschreibung'] ) ) . '</div>';
    }
    // Kontakt
    $kontakt = array_filter( [ $d['kontakt_email'] ?? '', $d['kontakt_telefon'] ?? '' ] );
    if ( $kontakt ) {
        $h .= '<div class="bschi-pf__kontakt">Fragen? ' . esc_html( implode( ' · ', $kontakt ) ) . '</div>';
    }
    $h .= '</div>';
    return $h;
}

// ─── Anfrage + Abo (AJAX-Proxy → Hub, Secret bleibt serverseitig) ─────────────

add_action( 'wp_ajax_bschi_pf_anfrage', 'bschi_pf_ajax_anfrage' );
add_action( 'wp_ajax_nopriv_bschi_pf_anfrage', 'bschi_pf_ajax_anfrage' );

function bschi_pf_ajax_anfrage(): void {
    if ( ! bschi_feature_enabled( 'pflanzenflohmarkt' ) ) {
        wp_send_json_error( [ 'msg' => 'Deaktiviert' ], 403 );
    }
    $id = (int) ( $_POST['id'] ?? 0 );
    [ $code, $data ] = bschi_gm_hub_post_json( '/api/v1/pflanzen/public/' . $id . '/anfrage', [
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

add_action( 'wp_ajax_bschi_pf_abo', 'bschi_pf_ajax_abo' );
add_action( 'wp_ajax_nopriv_bschi_pf_abo', 'bschi_pf_ajax_abo' );

function bschi_pf_ajax_abo(): void {
    if ( ! bschi_feature_enabled( 'pflanzenflohmarkt' ) ) {
        wp_send_json_error( [ 'msg' => 'Deaktiviert' ], 403 );
    }
    [ $code, $data ] = bschi_gm_hub_post_json( '/api/v1/pflanzen/public/abo', [
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

// ─── REST: Hub pflegt das versteckte Kauf-Produkt (Bestand = Hub-Menge) ───────

add_action( 'rest_api_init', function () {
    register_rest_route( 'bschi/v1', '/pflanzen-produkt', [
        'methods'             => 'POST',
        'callback'            => 'bschi_pf_rest_produkt',
        'permission_callback' => 'bschi_voucher_permission',   // gleiche Secret-Prüfung wie Coupons
    ] );
    register_rest_route( 'bschi/v1', '/pflanzen-produkt-loeschen', [
        'methods'             => 'POST',
        'callback'            => 'bschi_pf_rest_produkt_loeschen',
        'permission_callback' => 'bschi_voucher_permission',
    ] );
} );

function bschi_pf_rest_produkt( WP_REST_Request $request ) {
    if ( ! class_exists( 'WC_Product_Simple' ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'WooCommerce nicht aktiv' ], 503 );
    }
    $sku     = sanitize_text_field( (string) $request->get_param( 'sku' ) );
    $titel   = sanitize_text_field( (string) $request->get_param( 'titel' ) );
    $preis   = round( (float) $request->get_param( 'preis' ), 2 );
    $menge   = max( 0, (int) $request->get_param( 'menge' ) );
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

    $bestand = $kaufbar ? $menge : 0;
    $product->set_name( $titel );
    $product->set_sku( $sku );
    $product->set_regular_price( $preis > 0 ? (string) $preis : '' );
    $product->set_short_description( sanitize_textarea_field( (string) $request->get_param( 'kurz' ) ) );
    $product->set_description( sanitize_textarea_field( (string) $request->get_param( 'beschreibung' ) ) );
    $product->set_catalog_visibility( 'hidden' );   // nie im Shop-Katalog/Suche – nur via Flohmarkt-Seite
    $product->set_sold_individually( false );       // mehrere Stück je Bestellung erlaubt
    $product->set_manage_stock( true );
    $product->set_backorders( 'no' );
    $product->set_stock_quantity( $bestand );
    $product->set_stock_status( $bestand > 0 ? 'instock' : 'outofstock' );
    $product->set_status( $kaufbar ? 'publish' : 'draft' );
    $id = $product->save();
    if ( ! $id ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Produkt konnte nicht gespeichert werden' ], 500 );
    }

    // Produktbild einmalig aus dem Hub laden (öffentliche Bild-URL); bei geänderter Quelle neu
    $bild_url = esc_url_raw( (string) $request->get_param( 'bild_url' ) );
    if ( $bild_url && get_post_meta( $id, '_bschi_pf_bild_url', true ) !== $bild_url ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att_id = media_sideload_image( $bild_url, $id, $titel, 'id' );
        if ( ! is_wp_error( $att_id ) ) {
            set_post_thumbnail( $id, $att_id );
            update_post_meta( $id, '_bschi_pf_bild_url', $bild_url );
        } else {
            error_log( '[BSCHI] PF-Produktbild fehlgeschlagen (' . $sku . '): ' . $att_id->get_error_message() );
        }
    }

    return new WP_REST_Response( [
        'ok'           => true,
        'id'           => $id,
        'checkout_url' => function_exists( 'wc_get_checkout_url' )
            ? add_query_arg( 'add-to-cart', $id, wc_get_checkout_url() ) : '',
    ], 200 );
}

function bschi_pf_rest_produkt_loeschen( WP_REST_Request $request ) {
    $pid     = (int) $request->get_param( 'product_id' );
    $product = $pid ? wc_get_product( $pid ) : null;
    if ( $product ) {
        $product->delete( true );
    }
    return new WP_REST_Response( [ 'ok' => true ], 200 );
}

// ─── Bestell-Hooks: Kauf/Storno inkl. Stückzahl an den Hub zurückmelden ───────

function bschi_pf_order_event( $order_id, string $event ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    foreach ( $order->get_items() as $item ) {
        $product = is_callable( [ $item, 'get_product' ] ) ? $item->get_product() : null;
        $sku     = $product ? (string) $product->get_sku() : '';
        if ( strpos( $sku, 'PF-' ) !== 0 ) {
            continue;
        }
        // Doppelte Meldungen (processing → completed) vermeiden – je SKU und Event einmal
        $meta_key = '_bschi_pf_gemeldet_' . $event . '_' . $sku;
        if ( $order->get_meta( $meta_key ) ) {
            continue;
        }
        bschi_hub_post( '/api/v1/pflanzen/extern/wc-status', [
            'sku'   => $sku,
            'event' => $event,
            'menge' => max( 1, (int) $item->get_quantity() ),
            'order' => $order->get_order_number(),
            'kunde' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        ] );
        $order->update_meta_data( $meta_key, time() );
        $order->save();
        // Listen-/Detail-Cache verwerfen, damit die Flohmarkt-Seite sofort den neuen Bestand zeigt
        delete_transient( 'bschi_get_' . md5( '/api/v1/pflanzen/public/liste' ) );
        delete_transient( 'bschi_get_' . md5( '/api/v1/pflanzen/public/' . (int) substr( $sku, 3 ) ) );
    }
}

// Bestellung platziert/bezahlt → Bestand runter (auch on-hold: Vorkasse bindet den Bestand)
add_action( 'woocommerce_order_status_processing', fn( $oid ) => bschi_pf_order_event( $oid, 'verkauft' ) );
add_action( 'woocommerce_order_status_completed',  fn( $oid ) => bschi_pf_order_event( $oid, 'verkauft' ) );
add_action( 'woocommerce_order_status_on-hold',    fn( $oid ) => bschi_pf_order_event( $oid, 'verkauft' ) );
// Storno/Fehlschlag → Hub bucht den Bestand zurück
add_action( 'woocommerce_order_status_cancelled',  fn( $oid ) => bschi_pf_order_event( $oid, 'storno' ) );
add_action( 'woocommerce_order_status_refunded',   fn( $oid ) => bschi_pf_order_event( $oid, 'storno' ) );
add_action( 'woocommerce_order_status_failed',     fn( $oid ) => bschi_pf_order_event( $oid, 'storno' ) );

// ─── Styles + JS (inline, einmal je Seitenaufbau) ─────────────────────────────

function bschi_pf_styles(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    ?>
    <style>
    .bschi-pf__grid{display:grid;grid-template-columns:repeat(var(--bschi-pf-cols,3),1fr);gap:22px;margin:8px 0 26px}
    @media(max-width:900px){.bschi-pf__grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:560px){.bschi-pf__grid{grid-template-columns:1fr}}
    .bschi-pf__card{border:1px solid #e3e1db;border-radius:12px;overflow:hidden;background:#fff;cursor:pointer;
      transition:box-shadow .15s,transform .15s;display:flex;flex-direction:column}
    .bschi-pf__card:hover{box-shadow:0 6px 22px rgba(0,0,0,.10);transform:translateY(-2px)}
    .bschi-pf__card--weg{opacity:.75}
    .bschi-pf__bild{position:relative;aspect-ratio:4/3;background:#eef3ec;display:flex;align-items:center;justify-content:center}
    .bschi-pf__bild img{width:100%;height:100%;object-fit:cover;display:block}
    .bschi-pf__nobild{color:#9a988f;font-size:.85em}
    .bschi-pf__band{position:absolute;top:12px;left:-34px;transform:rotate(-35deg);padding:4px 40px;
      font-size:.75em;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#fff;background:#4b5a42}
    .bschi-pf__body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:6px;flex:1}
    .bschi-pf__titel{margin:0;font-size:clamp(1.02em,1.6vw,1.15em);line-height:1.3}
    .bschi-pf__d-titel{margin:.2em 0 0;font-size:clamp(1.25em,2.2vw,1.6em)}
    .bschi-pf__bot{font-size:.86em;color:#6d6b62;font-style:italic}
    .bschi-pf__kurz{font-size:.9em;color:#3f3e38;flex:1}
    .bschi-pf__knapp{font-size:.82em;font-weight:700;color:#8a5a1d}
    .bschi-pf__foot{display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:8px;flex-wrap:wrap}
    .bschi-pf__preis{font-weight:700;color:#3d6b2f;font-size:1.06em}
    .bschi-pf__mehr{font-size:.84em;color:#3d6b2f;text-decoration:underline}
    .bschi-pf__leer{padding:14px 0}
    .bschi-pf__abo{background:#eef3ec;border-radius:12px;padding:20px 22px;margin:6px 0 10px}
    .bschi-pf__abo-txt{margin-bottom:12px;font-size:.94em}
    .bschi-pf__abo-form{display:flex;gap:10px;flex-wrap:wrap}
    .bschi-pf__abo-form input{flex:1;min-width:220px;padding:11px 14px;border:1px solid #cfcdc4;border-radius:8px}
    .bschi-pf__abo-form button,.bschi-pf__anfrage-form button{background:#3d6b2f;color:#fff;border:0;
      border-radius:8px;padding:11px 20px;font-weight:600;cursor:pointer}
    .bschi-pf__abo-msg{margin-top:10px;font-size:.9em;font-weight:600}
    .bschi-pf__overlay{display:none;position:fixed;inset:0;background:rgba(20,20,16,.6);z-index:99999;
      align-items:flex-start;justify-content:center;padding:4vh 12px;overflow-y:auto}
    .bschi-pf__overlay.offen{display:flex}
    .bschi-pf__modal{position:relative;background:#fff;border-radius:14px;max-width:760px;width:100%;
      padding:26px 28px 30px;margin-bottom:6vh}
    .bschi-pf__close{position:absolute;top:10px;right:14px;background:none;border:0;font-size:30px;
      line-height:1;cursor:pointer;color:#6d6b62;padding:6px}
    .bschi-pf__galerie img#bschi-pf-hauptbild{width:100%;max-height:420px;object-fit:cover;border-radius:10px}
    .bschi-pf__thumbs{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
    .bschi-pf__thumbs img{width:76px;height:58px;object-fit:cover;border-radius:6px;cursor:pointer}
    .bschi-pf__d-preis{margin:10px 0 4px;font-size:1.25em}
    .bschi-pf__hinweis{background:#fdf3e7;border:1px solid #eddcc3;color:#8a5a1d;border-radius:8px;
      padding:10px 14px;margin:10px 0;font-size:.92em}
    .bschi-pf__cta{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0;align-items:center}
    .bschi-pf__qty select{padding:10px 12px;border:1px solid #cfcdc4;border-radius:8px;font:inherit}
    .bschi-pf__kaufen{background:#3d6b2f;color:#fff !important;border-radius:10px;padding:15px 34px;
      font-size:1.12em;font-weight:700;text-decoration:none;display:inline-block}
    .bschi-pf__kaufen:hover{background:#325726}
    .bschi-pf__anfragen{background:#fff;border:2px solid #3d6b2f;color:#3d6b2f;border-radius:10px;
      padding:12px 22px;font-weight:600;cursor:pointer}
    .bschi-pf__anfrage-form{display:flex;flex-direction:column;gap:10px;background:#f7f6f2;
      border-radius:10px;padding:16px;margin:0 0 14px}
    .bschi-pf__anfrage-form input,.bschi-pf__anfrage-form textarea{padding:10px 13px;
      border:1px solid #cfcdc4;border-radius:8px;font:inherit}
    .bschi-pf__beschr{margin:10px 0;line-height:1.6}
    .bschi-pf__kontakt{margin-top:18px;padding-top:12px;border-top:1px solid #eceae4;
      font-size:.9em;color:#6d6b62}
    /* Mobil: Bottom-Sheet statt schwebender Karte (griffiger, nichts klebt oben) */
    @media(max-width:640px){
      .bschi-pf__overlay{padding:0;align-items:flex-end;overflow:hidden}
      .bschi-pf__modal{max-width:none;width:100%;margin:0;border-radius:18px 18px 0 0;
        max-height:calc(100dvh - 6vh);overflow-y:auto;-webkit-overflow-scrolling:touch;
        padding:10px 16px calc(28px + env(safe-area-inset-bottom,0px))}
      .bschi-pf__modal::before{content:"";display:block;width:44px;height:4px;border-radius:2px;
        background:#d8d6cf;margin:2px auto 10px}
      .bschi-pf__close{position:sticky;top:6px;float:right;background:#f2f1ec;border-radius:50%;
        width:40px;height:40px;font-size:24px;line-height:40px;padding:0;text-align:center;
        z-index:5;box-shadow:0 1px 4px rgba(0,0,0,.12)}
      .bschi-pf__galerie img#bschi-pf-hauptbild{max-height:280px}
      .bschi-pf__d-titel{font-size:1.3em}
      .bschi-pf__cta{gap:10px}
      .bschi-pf__qty{flex:1 1 100%;display:flex;align-items:center;gap:8px}
      .bschi-pf__qty select{flex:1;min-height:46px}
      .bschi-pf__kaufen{flex:1;text-align:center;padding:15px 10px}
      .bschi-pf__anfragen{flex:1;padding:13px 10px}
    }
    </style>
    <?php
}

function bschi_pf_js(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
    ?>
    <script>
    function bschiPfOpen(id){
      var ov=document.getElementById('bschi-pf-overlay'),body=document.getElementById('bschi-pf-modal-body');
      ov.classList.add('offen');document.documentElement.style.overflow='hidden';
      body.innerHTML='<p style="text-align:center;padding:40px 0">L&auml;dt&hellip;</p>';
      fetch('<?php echo $ajax; ?>?action=bschi_pf_detail&id='+id)
        .then(function(r){return r.json();})
        .then(function(d){body.innerHTML=(d.success&&d.data.html)?d.data.html:'<p>Artikel konnte nicht geladen werden.</p>';})
        .catch(function(){body.innerHTML='<p>Artikel konnte nicht geladen werden.</p>';});
    }
    function bschiPfClose(){
      document.getElementById('bschi-pf-overlay').classList.remove('offen');
      document.documentElement.style.overflow='';
    }
    function bschiPfKauf(a){
      var qty=document.getElementById('bschi-pf-qty');
      var url=a.dataset.kauf;
      if(qty&&qty.value>1){url+='&quantity='+encodeURIComponent(qty.value);}
      window.location.href=url;
      return false;
    }
    function bschiPfAnfrageToggle(){
      var f=document.getElementById('bschi-pf-anfrage');
      if(f){f.style.display=(f.style.display==='none')?'flex':'none';}
    }
    function _bschiPfMsg(form,ok,text){
      var m=form.querySelector('.bschi-pf__abo-msg')||form.parentNode.querySelector('.bschi-pf__abo-msg');
      if(m){m.style.display='block';m.style.color=ok?'#3d6b2f':'#a03325';m.textContent=text;}
    }
    function bschiPfAbo(form,ajax){
      var btn=form.querySelector('button');btn.disabled=true;btn.textContent='Sendet…';
      var fd=new FormData(form);fd.append('action','bschi_pf_abo');
      fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        _bschiPfMsg(form,!!d.success,(d.data&&d.data.msg)||'Fehler – bitte später erneut versuchen.');
        if(d.success){form.reset();}
      }).catch(function(){_bschiPfMsg(form,false,'Fehler – bitte später erneut versuchen.');})
      .finally(function(){btn.disabled=false;btn.textContent='Benachrichtigen lassen';});
      return false;
    }
    function bschiPfAnfrage(form,ajax,id){
      var btn=form.querySelector('button');btn.disabled=true;btn.textContent='Sendet…';
      var fd=new FormData(form);fd.append('action','bschi_pf_anfrage');fd.append('id',id);
      fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        _bschiPfMsg(form,!!d.success,(d.data&&d.data.msg)||'Fehler – bitte später erneut versuchen.');
        if(d.success){form.reset();}
      }).catch(function(){_bschiPfMsg(form,false,'Fehler – bitte später erneut versuchen.');})
      .finally(function(){btn.disabled=false;btn.textContent='Anfrage absenden';});
      return false;
    }
    </script>
    <?php
}
