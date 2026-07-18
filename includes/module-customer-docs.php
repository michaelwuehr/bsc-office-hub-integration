<?php
/**
 * BSC Office Hub Integration – Modul: Kundendokumente im WooCommerce-Kundenkonto.
 *
 * Neuer My-Account-Endpoint "hub-dokumente": Rechnungen, Lieferscheine
 * (inkl. Ablieferbeleg/Unterschrift) und Aufträge als Xentral-Original-PDF.
 * Kunden-Mapping: Billing-E-Mail → Hub-Resolve (12h-Cache). PDFs werden
 * server-seitig über admin-ajax vom Hub geholt (Secret bleibt auf dem Server).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const BSCHI_DOCS_ENDPOINT = 'hub-dokumente';

// ─── My-Account-Endpoint registrieren ────────────────────────────────────────

add_action( 'init', function () {
    if ( bschi_feature_enabled( 'customer_docs' ) ) {
        add_rewrite_endpoint( BSCHI_DOCS_ENDPOINT, EP_ROOT | EP_PAGES );
        // Rewrite-Rules einmalig flushen (nach Aktivierung/Feature-Umschaltung)
        if ( ! get_option( 'bschi_docs_rewrite_flushed' ) ) {
            flush_rewrite_rules();
            update_option( 'bschi_docs_rewrite_flushed', 1 );
        }
    }
} );

add_filter( 'woocommerce_account_menu_items', function ( array $items ): array {
    if ( ! bschi_feature_enabled( 'customer_docs' ) ) {
        return $items;
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) ) {
        return $items; // Kunde (noch) nicht im Hub bekannt → Menüpunkt ausblenden
    }
    // Nach "Bestellungen" einsortieren
    $new = [];
    foreach ( $items as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'orders' ) {
            $new[ BSCHI_DOCS_ENDPOINT ] = 'Dokumente';
        }
    }
    if ( ! isset( $new[ BSCHI_DOCS_ENDPOINT ] ) ) {
        $new[ BSCHI_DOCS_ENDPOINT ] = 'Dokumente';
    }
    return $new;
} );

add_filter( 'woocommerce_endpoint_' . BSCHI_DOCS_ENDPOINT . '_title', fn() => 'Dokumente' );

// ─── Inhalt des Endpoints ────────────────────────────────────────────────────

add_action( 'woocommerce_account_' . BSCHI_DOCS_ENDPOINT . '_endpoint', function () {
    if ( ! bschi_feature_enabled( 'customer_docs' ) ) {
        echo '<p>Dieser Bereich ist derzeit nicht verfügbar.</p>';
        return;
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) ) {
        echo '<p>Zu deinem Konto wurden noch keine Dokumente gefunden. Bei Fragen melde dich gerne bei uns.</p>';
        return;
    }
    $customer_id = (int) $resolved['customer_id'];

    $typ = sanitize_key( $_GET['typ'] ?? 'invoice' );
    if ( ! in_array( $typ, [ 'invoice', 'delivery', 'order' ], true ) ) {
        $typ = 'invoice';
    }

    $tabs = [
        'invoice'  => 'Rechnungen',
        'delivery' => 'Lieferscheine',
        'order'    => 'Aufträge',
    ];
    $base = wc_get_account_endpoint_url( BSCHI_DOCS_ENDPOINT );

    echo '<nav style="display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap">';
    foreach ( $tabs as $key => $label ) {
        $active = $key === $typ;
        printf(
            '<a href="%s" style="padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;%s">%s</a>',
            esc_url( add_query_arg( 'typ', $key, $base ) ),
            $active ? 'background:#87a590;color:#fff' : 'background:#f0f0f0;color:#333',
            esc_html( $label )
        );
    }
    echo '</nav>';

    $data  = bschi_hub_get( '/api/v1/shop/customer/' . $customer_id . '/documents?type=' . $typ . '&limit=100' );
    $items = $data['items'] ?? null;
    if ( $items === null ) {
        echo '<p>Die Dokumente konnten gerade nicht geladen werden – bitte versuche es später erneut.</p>';
        return;
    }
    if ( ! $items ) {
        echo '<p>Keine ' . esc_html( $tabs[ $typ ] ) . ' vorhanden.</p>';
        return;
    }

    echo '<table class="shop_table" style="width:100%"><thead><tr>'
        . '<th>Nummer</th><th>Datum</th>'
        . ( $typ === 'order' ? '<th>Herkunft</th>' : '' )
        . ( $typ !== 'delivery' ? '<th>Betrag</th>' : '<th>Status</th><th>Ablieferbeleg</th>' )
        . '<th></th></tr></thead><tbody>';

    foreach ( $items as $item ) {
        $pdf_url = wp_nonce_url(
            admin_url( 'admin-ajax.php?action=bschi_doc_pdf&typ=' . $typ . '&doc_id=' . rawurlencode( (string) $item['id'] ) ),
            'bschi_doc_pdf'
        );
        $date = ! empty( $item['date'] ) ? date_i18n( 'd.m.Y', strtotime( $item['date'] ) ) : '—';

        echo '<tr>';
        echo '<td>' . esc_html( $item['document_number'] ?: (string) $item['id'] ) . '</td>';
        echo '<td>' . esc_html( $date ) . '</td>';
        if ( $typ === 'order' ) {
            // Herkunft = Vertriebskanal aus dem Hub (Onlineshop, Händlershop, Läden, Märkte, …) – ohne Icon
            $herkunft = is_array( $item['project'] ?? null ) ? trim( (string) ( $item['project']['name'] ?? '' ) ) : '';
            echo '<td>' . esc_html( $herkunft !== '' ? $herkunft : '—' ) . '</td>';
        }
        if ( $typ !== 'delivery' ) {
            $amount = $item['total_amount'] !== null
                ? number_format( (float) $item['total_amount'], 2, ',', '.' ) . ' ' . ( $item['currency'] ?: 'EUR' )
                : '—';
            echo '<td>' . esc_html( $amount ) . '</td>';
        } else {
            echo '<td>' . bschi_docs_delivery_status_cell( $item ) . '</td>';
            if ( ! empty( $item['has_signature'] ) ) {
                $sig_url = wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=bschi_doc_signature&doc_id=' . rawurlencode( (string) $item['id'] ) ),
                    'bschi_doc_pdf'
                );
                echo '<td><a href="' . esc_url( $sig_url ) . '" target="_blank">Unterschrift ansehen</a></td>';
            } else {
                echo '<td>—</td>';
            }
        }
        echo '<td style="text-align:right"><a class="button" href="' . esc_url( $pdf_url ) . '" target="_blank">PDF</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
} );

/**
 * Status-Zelle eines Lieferscheins – wie im Kundenportal:
 * PKW-Anlieferung (Tour-Block) > Paket-Tracking > Beleg-Status.
 */
function bschi_docs_delivery_status_cell( array $item ): string {
    $status_map = [
        'created'   => 'Erstellt',
        'released'  => 'In Bearbeitung',
        'sent'      => 'Versendet',
        'delivered' => 'Geliefert',
        'cancelled' => 'Storniert',
        'canceled'  => 'Storniert',
    ];
    $fmt_d  = fn( $v ) => date_i18n( 'd.m.Y', strtotime( $v ) );
    $fmt_dt = fn( $v ) => date_i18n( 'd.m.Y H:i', strtotime( $v ) ) . ' Uhr';

    $dl = is_array( $item['delivery'] ?? null ) ? $item['delivery'] : null;
    $tr = is_array( $item['tracking'] ?? null ) ? $item['tracking'] : null;
    $parts = [];

    if ( $dl ) {
        // PKW-Anlieferung über das Auslieferungs-Modul
        if ( ! empty( $dl['signed_at'] ) ) {
            $parts[] = '<strong style="color:#2e7d32">Geliefert</strong> am ' . $fmt_dt( $dl['signed_at'] )
                . ( ! empty( $dl['signed_by'] ) ? ' · ' . esc_html( $dl['signed_by'] ) : '' );
        } elseif ( ! empty( $dl['arrived_at'] ) ) {
            $parts[] = '<strong style="color:#2e7d32">Zugestellt</strong> am ' . $fmt_dt( $dl['arrived_at'] );
        } else {
            $parts[] = '<strong>PKW-Anlieferung geplant</strong>';
        }
        if ( ! empty( $dl['tour_date'] ) ) {
            $parts[] = '<small>Tour: ' . $fmt_d( $dl['tour_date'] )
                . ( ! empty( $dl['tour_label'] ) ? ' · ' . esc_html( $dl['tour_label'] ) : '' ) . '</small>';
        }
        if ( empty( $dl['signed_at'] ) && ! empty( $dl['planned_at'] ) ) {
            $parts[] = '<small>Geplante Ankunft: ' . $fmt_dt( $dl['planned_at'] ) . '</small>';
        }
        if ( ! empty( $dl['driver'] ) ) {
            $parts[] = '<small>Fahrer: ' . esc_html( $dl['driver'] ) . '</small>';
        }
        if ( ! empty( $dl['customer_note'] ) ) {
            $parts[] = '<small>Hinweis: ' . esc_html( $dl['customer_note'] ) . '</small>';
        }
    } elseif ( $tr ) {
        // Paketversand mit Tracking
        $lbl = $status_map[ strtolower( (string) ( $item['status'] ?? '' ) ) ] ?? 'Versendet';
        $parts[] = '<strong' . ( $lbl === 'Geliefert' ? ' style="color:#2e7d32"' : '' ) . '>' . esc_html( $lbl ) . '</strong>'
            . ( ! empty( $tr['sent_at'] ) ? ' am ' . $fmt_d( $tr['sent_at'] ) : '' );
        $nr = esc_html( $tr['number'] );
        $parts[] = '<small>Sendung: ' . ( ! empty( $tr['link'] )
            ? '<a href="' . esc_url( $tr['link'] ) . '" target="_blank" rel="noopener">' . $nr . '</a>'
            : $nr ) . '</small>';
    } else {
        $raw = strtolower( (string) ( $item['status'] ?? '' ) );
        $parts[] = esc_html( $status_map[ $raw ] ?? ( $item['status'] ?: '—' ) );
        if ( ! empty( $item['shipping'] ) ) {
            $parts[] = '<small>' . esc_html( $item['shipping'] ) . '</small>';
        }
    }

    return implode( '<br>', $parts );
}

// ─── AJAX-Proxy: PDF / Unterschrift streamen (nur eingeloggte Kunden) ────────

function bschi_docs_stream_guard(): int {
    if ( ! is_user_logged_in() ) {
        wp_die( 'Bitte zuerst anmelden.', '', [ 'response' => 401 ] );
    }
    check_admin_referer( 'bschi_doc_pdf' );
    if ( ! bschi_feature_enabled( 'customer_docs' ) ) {
        wp_die( 'Nicht verfügbar.', '', [ 'response' => 403 ] );
    }
    $resolved = bschi_resolve_current_customer();
    if ( empty( $resolved['found'] ) ) {
        wp_die( 'Kein Kundenkonto im Office Hub gefunden.', '', [ 'response' => 404 ] );
    }
    return (int) $resolved['customer_id'];
}

add_action( 'wp_ajax_bschi_doc_pdf', function () {
    $customer_id = bschi_docs_stream_guard();
    $typ    = sanitize_key( $_GET['typ'] ?? '' );
    $doc_id = sanitize_text_field( $_GET['doc_id'] ?? '' );
    if ( ! in_array( $typ, [ 'invoice', 'delivery', 'order' ], true ) || $doc_id === '' ) {
        wp_die( 'Ungültige Anfrage.', '', [ 'response' => 400 ] );
    }
    $bin = bschi_hub_get_binary( '/api/v1/shop/customer/' . $customer_id . '/documents/' . $typ . '/' . rawurlencode( $doc_id ) . '/pdf' );
    if ( ! $bin ) {
        wp_die( 'PDF konnte nicht geladen werden – bitte später erneut versuchen.', '', [ 'response' => 502 ] );
    }
    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( $bin['disposition'] ? 'Content-Disposition: ' . $bin['disposition'] : 'Content-Disposition: inline' );
    echo $bin['body']; // phpcs:ignore -- binärer PDF-Stream
    exit;
} );

add_action( 'wp_ajax_bschi_doc_signature', function () {
    $customer_id = bschi_docs_stream_guard();
    $doc_id = sanitize_text_field( $_GET['doc_id'] ?? '' );
    if ( $doc_id === '' ) {
        wp_die( 'Ungültige Anfrage.', '', [ 'response' => 400 ] );
    }
    $bin = bschi_hub_get_binary( '/api/v1/shop/customer/' . $customer_id . '/documents/delivery/' . rawurlencode( $doc_id ) . '/signature' );
    if ( ! $bin ) {
        wp_die( 'Keine Unterschrift vorhanden.', '', [ 'response' => 404 ] );
    }
    nocache_headers();
    header( 'Content-Type: image/png' );
    echo $bin['body']; // phpcs:ignore -- binärer PNG-Stream
    exit;
} );
