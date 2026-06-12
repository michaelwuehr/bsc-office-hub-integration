<?php
/**
 * BSC Office Hub Integration – Modul: Doppelbestellungs-Erkennung v2.
 *
 * Verbesserungen gegenüber BSCHWM v2.x:
 * - Echtzeit-Prüfung direkt nach dem Checkout (nicht nur stündlicher Cron)
 * - Scoring statt exaktem E-Mail+Betrag-Key:
 *     Kandidat:  gleiche Billing-E-Mail ODER gleicher Nachname+PLZ
 *     Punkte:    identische Positionsliste +50 | Betrag ±2% +25
 *                Abstand < 30 Min +15 | gleiche Zahlart +10
 *     Duplikat:  ab Score >= double_orders_score_min (Default 60)
 * - Alle Status werden einbezogen (auch failed/pending/cancelled – häufiges
 *   Muster: 1. Bestellung schlägt fehl, Kunde bestellt erneut, beide werden
 *   später doch verarbeitet)
 * - Aktion: jüngere Bestellung auf "Wartend" (on-hold) + Order-Note (optional)
 * - Hub-Push an /api/v1/monitoring/double-orders mit Bestellnummern-Paaren
 * - Dismiss: Meta _bschi_dup_dismissed unterdrückt erneute Meldung
 * - Admin-Analyse-Tool: zwei Bestellnummern -> Score-Aufschlüsselung
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const BSCHI_DUP_META_FLAGGED   = '_bschi_dup_flagged';    // Order-Meta: ID der älteren Partner-Bestellung
const BSCHI_DUP_META_DISMISSED = '_bschi_dup_dismissed';  // Order-Meta: vom Team als "kein Duplikat" markiert

// ─── Feature-Vektor einer Bestellung ─────────────────────────────────────────

function bschi_dup_order_features( \WC_Order $order ): array {
    $items = [];
    foreach ( $order->get_items() as $item ) {
        $pid = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
        $qty = (float) $item->get_quantity();
        $items[ $pid ] = ( $items[ $pid ] ?? 0 ) + $qty;
    }
    ksort( $items );
    return [
        'id'        => $order->get_id(),
        'number'    => $order->get_order_number(),
        'email'     => strtolower( trim( $order->get_billing_email() ) ),
        'lastname'  => strtolower( trim( $order->get_billing_last_name() ) ),
        'firstname' => strtolower( trim( $order->get_billing_first_name() ) ),
        'zip'       => preg_replace( '/\s+/', '', (string) $order->get_billing_postcode() ),
        'total'     => (float) $order->get_total(),
        'payment'   => (string) $order->get_payment_method(),
        'status'    => $order->get_status(),
        'created'   => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
        'items'     => $items,
    ];
}

/**
 * Score-Vergleich zweier Bestellungen. Liefert Score + Begründung.
 */
function bschi_dup_score( array $a, array $b ): array {
    $reasons = [];
    $score   = 0;

    // ── Kandidaten-Bedingung (harte Vorbedingung) ─────────────────────────────
    $same_email = $a['email'] !== '' && $a['email'] === $b['email'];
    $same_name  = $a['lastname'] !== '' && $a['lastname'] === $b['lastname']
               && $a['zip'] !== '' && $a['zip'] === $b['zip'];
    if ( ! $same_email && ! $same_name ) {
        return [ 'score' => 0, 'candidate' => false, 'reasons' => [ 'Weder gleiche E-Mail noch gleicher Name+PLZ' ] ];
    }
    $reasons[] = $same_email ? 'Gleiche Billing-E-Mail' : 'Gleicher Nachname + PLZ';

    // ── Identische Positionsliste (stärkster Indikator) ───────────────────────
    if ( ! empty( $a['items'] ) && $a['items'] === $b['items'] ) {
        $score    += 50;
        $reasons[] = 'Identische Artikel + Mengen (+50)';
    } else {
        // Teil-Überlappung: Jaccard über Produkt-IDs
        $ids_a = array_keys( $a['items'] );
        $ids_b = array_keys( $b['items'] );
        $inter = count( array_intersect( $ids_a, $ids_b ) );
        $union = count( array_unique( array_merge( $ids_a, $ids_b ) ) );
        if ( $union > 0 && ( $inter / $union ) >= 0.8 ) {
            $score    += 30;
            $reasons[] = 'Artikel zu >=80% identisch (+30)';
        }
    }

    // ── Betrag ±2% ────────────────────────────────────────────────────────────
    $max_total = max( abs( $a['total'] ), abs( $b['total'] ), 0.01 );
    $diff_pct  = abs( $a['total'] - $b['total'] ) / $max_total * 100;
    if ( $diff_pct <= 2.0 ) {
        $score    += 25;
        $reasons[] = sprintf( 'Betrag gleich (±%.1f%%) (+25)', $diff_pct );
    }

    // ── Zeitabstand < 30 Minuten ──────────────────────────────────────────────
    $delta_s = abs( $a['created'] - $b['created'] );
    if ( $delta_s < 1800 ) {
        $score    += 15;
        $reasons[] = 'Abstand < 30 Min (+15)';
    }

    // ── Gleiche Zahlart ───────────────────────────────────────────────────────
    if ( $a['payment'] !== '' && $a['payment'] === $b['payment'] ) {
        $score    += 10;
        $reasons[] = 'Gleiche Zahlart (+10)';
    }

    return [ 'score' => $score, 'candidate' => true, 'reasons' => $reasons ];
}

// ─── Echtzeit-Prüfung nach Checkout ──────────────────────────────────────────

add_action( 'woocommerce_checkout_order_processed', 'bschi_dup_check_new_order', 20, 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', function ( $order ) {
    if ( $order instanceof \WC_Order ) {
        bschi_dup_check_new_order( $order->get_id() );
    }
}, 20, 1 );

function bschi_dup_check_new_order( $order_id ): void {
    if ( ! bschi_feature_enabled( 'double_orders' ) || ! function_exists( 'wc_get_orders' ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order instanceof \WC_Order ) {
        return;
    }

    $settings  = bschi_get_settings();
    $window_h  = (int) ( $settings['double_orders_window_h'] ?? 48 );
    $score_min = (int) ( $settings['double_orders_score_min'] ?? 60 );
    $new       = bschi_dup_order_features( $order );

    // Kandidaten: alle Bestellungen im Zeitfenster (alle Status, ohne die neue)
    $candidates = wc_get_orders( [
        'date_created' => '>' . strtotime( "-{$window_h} hours" ),
        'limit'        => 300,
        'exclude'      => [ $order->get_id() ],
        'return'       => 'objects',
        'type'         => 'shop_order',
    ] );

    $matches = [];
    foreach ( $candidates as $cand ) {
        if ( ! $cand instanceof \WC_Order ) {
            continue;
        }
        if ( $cand->get_meta( BSCHI_DUP_META_DISMISSED ) ) {
            continue;
        }
        $feat   = bschi_dup_order_features( $cand );
        $result = bschi_dup_score( $new, $feat );
        if ( $result['score'] >= $score_min ) {
            $matches[] = [ 'order' => $cand, 'features' => $feat, 'result' => $result ];
        }
    }

    if ( ! $matches ) {
        return;
    }

    // Bestes Match (höchster Score, älteste zuerst)
    usort( $matches, fn( $x, $y ) => $y['result']['score'] <=> $x['result']['score'] );
    $best     = $matches[0];
    $partner  = $best['order'];
    $score    = $best['result']['score'];
    $reasons  = $best['result']['reasons'];

    // Jüngere Bestellung markieren (die neue ist per Definition die jüngere)
    $order->update_meta_data( BSCHI_DUP_META_FLAGGED, $partner->get_id() );
    $order->add_order_note( sprintf(
        'BSC Office Hub: Mögliche Doppelbestellung zu #%s (Score %d: %s).',
        $partner->get_order_number(),
        $score,
        implode( ', ', $reasons )
    ) );

    // Auto-Hold (nur wenn die Bestellung nicht ohnehin schon final/storniert ist)
    $held = false;
    if ( ( $settings['double_orders_autohold'] ?? true )
         && in_array( $order->get_status(), [ 'pending', 'processing' ], true ) ) {
        $order->update_status( 'on-hold', 'BSC Office Hub: Automatisch angehalten – mögliche Doppelbestellung zu #' . $partner->get_order_number() . '.' );
        $held = true;
    }
    $order->save();

    // Hub-Push
    bschi_hub_post( '/api/v1/monitoring/double-orders', [
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'trigger'        => 'realtime',
        'timestamp'      => gmdate( 'c' ),
        'plugin_version' => BSCHI_VERSION,
        'pairs'          => [ [
            'order_new'     => (string) $order->get_order_number(),
            'order_existing' => (string) $partner->get_order_number(),
            'customer'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'total_new'     => (float) $order->get_total(),
            'total_existing' => (float) $partner->get_total(),
            'score'         => $score,
            'reasons'       => $reasons,
            'auto_hold'     => $held,
        ] ],
    ] );
}

// ─── Dismiss-Aktion im WP-Admin (Order-Liste/Detail) ─────────────────────────

add_action( 'admin_post_bschi_dup_dismiss', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Keine Berechtigung' );
    }
    $order_id = (int) ( $_GET['order_id'] ?? 0 );
    check_admin_referer( 'bschi_dup_dismiss_' . $order_id );
    $order = wc_get_order( $order_id );
    if ( $order ) {
        $order->update_meta_data( BSCHI_DUP_META_DISMISSED, time() );
        $order->add_order_note( 'BSC Office Hub: Doppelbestellungs-Verdacht vom Team verworfen (kein Duplikat).' );
        $order->save();
    }
    wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
    exit;
} );

// Hinweis-Box in der Bestelldetail-Ansicht, wenn geflaggt
add_action( 'add_meta_boxes', function () {
    $screen = function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
        && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id( 'shop-order' )
        : 'shop_order';
    add_meta_box( 'bschi-dup-box', 'Doppelbestellungs-Verdacht', function ( $post_or_order ) {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
        if ( ! $order ) {
            return;
        }
        $partner_id = (int) $order->get_meta( BSCHI_DUP_META_FLAGGED );
        if ( ! $partner_id ) {
            echo '<p>Kein Verdacht.</p>';
            return;
        }
        if ( $order->get_meta( BSCHI_DUP_META_DISMISSED ) ) {
            echo '<p style="color:#00a32a">Verdacht wurde verworfen (kein Duplikat).</p>';
            return;
        }
        $partner = wc_get_order( $partner_id );
        $url     = wp_nonce_url(
            admin_url( 'admin-post.php?action=bschi_dup_dismiss&order_id=' . $order->get_id() ),
            'bschi_dup_dismiss_' . $order->get_id()
        );
        echo '<p style="color:#d63638"><strong>Mögliche Doppelbestellung zu '
            . ( $partner ? '#' . esc_html( $partner->get_order_number() ) : '#' . (int) $partner_id )
            . '</strong></p><p><a class="button" href="' . esc_url( $url ) . '">Kein Duplikat – Verdacht verwerfen</a></p>';
    }, $screen, 'side', 'high' );
} );

// ─── Admin-Analyse-Tool: zwei Bestellnummern vergleichen ─────────────────────

/**
 * Rendert das Analyse-Formular + Ergebnis (wird in admin-page.php eingebunden).
 */
function bschi_dup_render_analyzer(): void {
    $result_html = '';
    if ( isset( $_POST['bschi_dup_analyze'] ) && check_admin_referer( 'bschi_dup_analyze' ) ) {
        $id_a = trim( (string) ( $_POST['dup_order_a'] ?? '' ) );
        $id_b = trim( (string) ( $_POST['dup_order_b'] ?? '' ) );
        $oa   = bschi_dup_find_order( $id_a );
        $ob   = bschi_dup_find_order( $id_b );
        if ( ! $oa || ! $ob ) {
            $result_html = '<p style="color:#d63638">Bestellung nicht gefunden: ' . esc_html( ! $oa ? $id_a : $id_b ) . '</p>';
        } else {
            $fa  = bschi_dup_order_features( $oa );
            $fb  = bschi_dup_order_features( $ob );
            $res = bschi_dup_score( $fa, $fb );
            $s   = bschi_get_settings();
            $min = (int) ( $s['double_orders_score_min'] ?? 60 );
            $verdict = $res['score'] >= $min
                ? '<strong style="color:#d63638">DUPLIKAT (Score ' . $res['score'] . ' >= ' . $min . ')</strong>'
                : '<strong style="color:#00a32a">kein Duplikat (Score ' . $res['score'] . ' < ' . $min . ')</strong>';
            $rows = '';
            foreach ( [
                'Bestellnummer' => [ $fa['number'], $fb['number'] ],
                'E-Mail'        => [ $fa['email'], $fb['email'] ],
                'Name'          => [ $fa['firstname'] . ' ' . $fa['lastname'], $fb['firstname'] . ' ' . $fb['lastname'] ],
                'PLZ'           => [ $fa['zip'], $fb['zip'] ],
                'Betrag'        => [ number_format( $fa['total'], 2 ) . ' €', number_format( $fb['total'], 2 ) . ' €' ],
                'Zahlart'       => [ $fa['payment'], $fb['payment'] ],
                'Status'        => [ $fa['status'], $fb['status'] ],
                'Erstellt'      => [
                    $fa['created'] ? date_i18n( 'd.m.Y H:i', $fa['created'] ) : '—',
                    $fb['created'] ? date_i18n( 'd.m.Y H:i', $fb['created'] ) : '—',
                ],
                'Positionen'    => [ count( $fa['items'] ) . ' Artikel', count( $fb['items'] ) . ' Artikel' ],
            ] as $label => $vals ) {
                $rows .= '<tr><td style="padding:4px 10px;color:#555">' . esc_html( $label ) . '</td>'
                    . '<td style="padding:4px 10px"><code>' . esc_html( (string) $vals[0] ) . '</code></td>'
                    . '<td style="padding:4px 10px"><code>' . esc_html( (string) $vals[1] ) . '</code></td></tr>';
            }
            $result_html = '<p>' . $verdict . '</p>'
                . '<table class="widefat" style="max-width:680px"><tbody>' . $rows . '</tbody></table>'
                . '<p><strong>Bewertung:</strong></p><ul style="margin-left:18px;list-style:disc">';
            foreach ( $res['reasons'] as $r ) {
                $result_html .= '<li>' . esc_html( $r ) . '</li>';
            }
            $result_html .= '</ul>';
        }
    }
    ?>
    <h2 style="margin-top:24px">Doppelbestellungs-Analyse</h2>
    <p class="description">Zwei Bestellnummern eingeben, um den Duplikat-Score nachzuvollziehen (z.B. historische Paare prüfen und Schwelle/Gewichte justieren).</p>
    <form method="post" style="margin-bottom:12px">
        <?php wp_nonce_field( 'bschi_dup_analyze' ); ?>
        <input type="hidden" name="bschi_dup_analyze" value="1">
        <input type="text" name="dup_order_a" placeholder="Bestellnr. A (z.B. 7761)" value="<?= esc_attr( $_POST['dup_order_a'] ?? '' ); ?>" style="width:160px">
        <input type="text" name="dup_order_b" placeholder="Bestellnr. B (z.B. 7762)" value="<?= esc_attr( $_POST['dup_order_b'] ?? '' ); ?>" style="width:160px">
        <button type="submit" class="button">Vergleichen</button>
    </form>
    <?php
    echo $result_html; // phpcs:ignore -- bereits escaped aufgebaut
}

/**
 * Bestellung per ID oder Bestellnummer finden (inkl. Custom Order Numbers).
 */
function bschi_dup_find_order( string $id_or_number ): ?\WC_Order {
    if ( $id_or_number === '' ) {
        return null;
    }
    $order = wc_get_order( (int) $id_or_number );
    if ( $order instanceof \WC_Order ) {
        return $order;
    }
    // Fallback: Suche über Order-Number-Meta (Plugins wie Sequential Order Numbers)
    $found = wc_get_orders( [
        'limit'      => 1,
        'type'       => 'shop_order',
        'meta_key'   => '_order_number',
        'meta_value' => $id_or_number,
        'return'     => 'objects',
    ] );
    return ( $found && $found[0] instanceof \WC_Order ) ? $found[0] : null;
}
