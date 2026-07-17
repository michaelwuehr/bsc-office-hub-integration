<?php
/**
 * Modul: Geschenkgutschein-Konfigurator (Shortcode [bsc_gutschein_shop]).
 *
 * Kunde wählt Design (Vorlagen vom Office Hub), Einsatzort (Online-Shop oder Laden),
 * Betrag (Festbeträge + frei 5-250 €) und Grußtext – Live-Vorschau im iframe, dann
 * normaler WooCommerce-Kauf über ein verstecktes virtuelles Gutschein-Produkt.
 * Nach Zahlungseingang meldet das Plugin die Bestellung an den Office Hub
 * (POST /api/v1/shop/gutschein-bestellung) – dort passiert die Ausstellung
 * (Tillhub-Ladengutschein bzw. WC-Coupon) + PDF + Mail-Zustellung.
 *
 * USt-Hinweis: Gutschein-Produkt wird als Mehrzweck-Gutschein OHNE Steuer angelegt
 * (tax_status none) – Besteuerung erfolgt erst bei der Einlösung.
 *
 * Feature-Schalter: feature_gutschein_shop (Default AUS).
 */

defined( 'ABSPATH' ) || exit;

const BSCHI_GS_DESIGN_TTL = 3600;

// ─── Verstecktes WC-Produkt (einmalig automatisch angelegt) ──────────────────

function bschi_gs_product_id(): int {
    $pid = (int) get_option( 'bschi_gs_product_id', 0 );
    if ( $pid && get_post_status( $pid ) === 'publish' ) {
        return $pid;
    }
    if ( ! function_exists( 'wc_get_product' ) ) {
        return 0;
    }
    $p = new WC_Product_Simple();
    $p->set_name( 'Geschenkgutschein' );
    $p->set_status( 'publish' );
    $p->set_catalog_visibility( 'hidden' );
    $p->set_virtual( true );
    $p->set_regular_price( '25' );
    $p->set_tax_status( 'none' );      // Mehrzweck-Gutschein: keine USt beim Verkauf
    $p->set_sold_individually( false );
    $pid = $p->save();
    update_option( 'bschi_gs_product_id', $pid );
    return (int) $pid;
}

// ─── Shortcode: Konfigurator ─────────────────────────────────────────────────

add_shortcode( 'bsc_gutschein_shop', function (): string {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) || ! function_exists( 'WC' ) ) {
        return '';
    }
    $feed = bschi_hub_get( '/api/v1/shop/gutschein-designs', BSCHI_GS_DESIGN_TTL );
    if ( ! $feed || empty( $feed['designs'] ) ) {
        return '<p>Der Gutschein-Konfigurator ist gerade nicht erreichbar – bitte später erneut versuchen.</p>';
    }
    $pid = bschi_gs_product_id();
    if ( ! $pid ) {
        return '';
    }
    $betraege = $feed['betraege'] ?? [ 10, 25, 50, 100 ];
    $frei_min = (float) ( $feed['frei_min'] ?? 5 );
    $frei_max = (float) ( $feed['frei_max'] ?? 250 );

    ob_start();
    ?>
    <div id="bschi-gs" style="max-width:860px;margin:0 auto">
      <form method="post" id="bschi-gs-form">
        <?php wp_nonce_field( 'bschi_gs_add', 'bschi_gs_nonce' ); ?>
        <input type="hidden" name="bschi_gs_add" value="1">
        <input type="hidden" name="bschi_gs_design" id="bschi-gs-design" value="<?php echo esc_attr( $feed['designs'][0]['key'] ); ?>">

        <h3 style="margin:14px 0 8px">1. Design wählen</h3>
        <div id="bschi-gs-gallery" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px"></div>

        <h3 style="margin:20px 0 8px">2. Wo soll der Gutschein gelten?</h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <label class="bschi-gs-typ" style="flex:1;min-width:220px;border:2px solid #ccc;border-radius:10px;padding:12px;cursor:pointer">
            <input type="radio" name="bschi_gs_typ" value="online" checked>
            <b>Online-Shop</b><br><span style="font-size:13px;color:#666">Einlösbar auf woidsiederei.de – Code zum Eingeben an der Kasse.</span>
          </label>
          <label class="bschi-gs-typ" style="flex:1;min-width:220px;border:2px solid #ccc;border-radius:10px;padding:12px;cursor:pointer">
            <input type="radio" name="bschi_gs_typ" value="laden">
            <b>Laden</b><br><span style="font-size:13px;color:#666">Einlösbar in Theresienthal &amp; Schweinhütt – mit Barcode für die Kasse.</span>
          </label>
        </div>

        <h3 style="margin:20px 0 8px">3. Betrag</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <?php foreach ( $betraege as $b ) : ?>
            <button type="button" class="bschi-gs-betrag" data-betrag="<?php echo esc_attr( $b ); ?>"
              style="padding:10px 20px;border:2px solid #ccc;border-radius:10px;background:#fff;cursor:pointer;font-weight:700"><?php echo esc_html( $b ); ?> €</button>
          <?php endforeach; ?>
          <span style="color:#666">oder frei:</span>
          <input type="number" name="bschi_gs_betrag" id="bschi-gs-betrag" value="25" required
            min="<?php echo esc_attr( $frei_min ); ?>" max="<?php echo esc_attr( $frei_max ); ?>" step="0.5"
            style="width:110px;padding:9px;border:2px solid #ccc;border-radius:10px"> €
        </div>

        <h3 style="margin:20px 0 8px">4. Persönlich machen (optional)</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <input type="text" name="bschi_gs_empfaenger" id="bschi-gs-empfaenger" maxlength="60" placeholder="Für wen? (Name)"
            style="padding:10px;border:2px solid #ccc;border-radius:10px">
          <input type="text" name="bschi_gs_absender" id="bschi-gs-absender" maxlength="60" placeholder="Von wem? (dein Name)"
            style="padding:10px;border:2px solid #ccc;border-radius:10px">
        </div>
        <textarea name="bschi_gs_gruss" id="bschi-gs-gruss" maxlength="300" rows="3" placeholder="Dein Grußtext …"
          style="width:100%;margin-top:10px;padding:10px;border:2px solid #ccc;border-radius:10px"></textarea>

        <h3 style="margin:20px 0 8px">Vorschau</h3>
        <div style="border:1px solid #ddd;border-radius:10px;overflow:hidden;background:#f7f5f1">
          <iframe id="bschi-gs-preview" style="width:100%;height:430px;border:0" title="Gutschein-Vorschau"></iframe>
        </div>

        <button type="submit" style="margin-top:16px;padding:14px 28px;border:0;border-radius:10px;background:#5a6b52;color:#fff;font-size:16px;font-weight:700;cursor:pointer;min-height:48px">
          In den Warenkorb
        </button>
        <p style="font-size:12px;color:#777;margin-top:8px">Der Gutschein kommt nach Zahlungseingang als PDF per E-Mail –
          zum Ausdrucken oder Weiterleiten. Kein Verfallsdatum, keine Barauszahlung.</p>
      </form>
    </div>
    <script>
    (function(){
      var designs = <?php echo wp_json_encode( array_map( function ( $d ) {
          return [ 'key' => $d['key'], 'name' => $d['name'], 'html' => $d['html'] ];
      }, $feed['designs'] ) ); ?>;
      var cfg = { design: designs[0].key };

      function esc(s){ var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
      function previewHtml(d){
        var betrag = (document.getElementById('bschi-gs-betrag').value || '25').replace('.', ',');
        var typ = document.querySelector('input[name=bschi_gs_typ]:checked').value;
        var emp = document.getElementById('bschi-gs-empfaenger').value.trim();
        var abs = document.getElementById('bschi-gs-absender').value.trim();
        var gruss = document.getElementById('bschi-gs-gruss').value.trim();
        return d.html
          .split('{{EMPFAENGER_ZEILE}}').join(emp ? 'F&uuml;r ' + esc(emp) : '')
          .split('{{GRUSS}}').join(esc(gruss))
          .split('{{ABSENDER_ZEILE}}').join(abs ? 'Von ' + esc(abs) : '')
          .split('{{BETRAG}}').join(esc(betrag))
          .split('{{CODE_BLOCK}}').join('<span class="nur-code">Code nach Kauf</span>')
          .split('{{CODE_NR}}').join('folgt nach Kauf')
          .split('{{TYP_LABEL}}').join(typ === 'laden'
            ? 'Einl&ouml;sbar in unseren L&auml;den (Theresienthal &amp; Schweinh&uuml;tt)'
            : 'Einl&ouml;sbar im Online-Shop woidsiederei.de');
      }
      function renderPreview(){
        var d = designs.find(function(x){ return x.key === cfg.design; }) || designs[0];
        var f = document.getElementById('bschi-gs-preview');
        // A4-Seite auf iframe-Breite skalieren
        f.srcdoc = previewHtml(d).replace('</style>',
          '.blatt{transform-origin:top left;transform:scale(0.52);width:210mm;height:230mm}</style>');
      }
      function renderGallery(){
        var g = document.getElementById('bschi-gs-gallery');
        g.innerHTML = '';
        designs.forEach(function(d){
          var cell = document.createElement('div');
          cell.style.cssText = 'border:3px solid ' + (d.key === cfg.design ? '#5a6b52' : '#ddd')
            + ';border-radius:10px;overflow:hidden;cursor:pointer;background:#fff';
          var f = document.createElement('iframe');
          f.style.cssText = 'width:100%;height:110px;border:0;pointer-events:none';
          f.setAttribute('scrolling', 'no');
          f.srcdoc = previewHtml(d).replace('</style>',
            '.blatt{transform-origin:top left;transform:scale(0.17);width:210mm;height:150mm}</style>');
          var lbl = document.createElement('div');
          lbl.style.cssText = 'padding:6px 8px;font-size:12.5px;font-weight:600;text-align:center';
          lbl.textContent = d.name;
          cell.appendChild(f); cell.appendChild(lbl);
          cell.addEventListener('click', function(){
            cfg.design = d.key;
            document.getElementById('bschi-gs-design').value = d.key;
            renderGallery(); renderPreview();
          });
          g.appendChild(cell);
        });
      }
      document.querySelectorAll('.bschi-gs-betrag').forEach(function(b){
        b.addEventListener('click', function(){
          document.getElementById('bschi-gs-betrag').value = b.dataset.betrag;
          renderPreview();
        });
      });
      ['bschi-gs-betrag','bschi-gs-empfaenger','bschi-gs-absender','bschi-gs-gruss'].forEach(function(id){
        document.getElementById(id).addEventListener('input', renderPreview);
      });
      document.querySelectorAll('input[name=bschi_gs_typ]').forEach(function(r){
        r.addEventListener('change', function(){
          document.querySelectorAll('.bschi-gs-typ').forEach(function(l){
            l.style.borderColor = l.querySelector('input').checked ? '#5a6b52' : '#ccc';
          });
          renderPreview();
        });
      });
      document.querySelector('.bschi-gs-typ input').dispatchEvent(new Event('change'));
      renderGallery(); renderPreview();
    })();
    </script>
    <?php
    return (string) ob_get_clean();
} );

// ─── Warenkorb: Formular-Submit → Cart-Item mit Konfiguration ────────────────

add_action( 'template_redirect', function () {
    if ( empty( $_POST['bschi_gs_add'] ) || ! bschi_feature_enabled( 'gutschein_shop' ) || ! function_exists( 'WC' ) ) {
        return;
    }
    if ( ! isset( $_POST['bschi_gs_nonce'] ) || ! wp_verify_nonce( $_POST['bschi_gs_nonce'], 'bschi_gs_add' ) ) {
        return;
    }
    $betrag = round( (float) str_replace( ',', '.', (string) ( $_POST['bschi_gs_betrag'] ?? 0 ) ), 2 );
    if ( $betrag < 5 || $betrag > 250 ) {
        wc_add_notice( 'Bitte einen Gutschein-Betrag zwischen 5 und 250 € wählen.', 'error' );
        return;
    }
    $typ = ( $_POST['bschi_gs_typ'] ?? '' ) === 'laden' ? 'laden' : 'online';
    $daten = [
        'typ'        => $typ,
        'betrag'     => $betrag,
        'design'     => sanitize_key( $_POST['bschi_gs_design'] ?? 'klassik' ),
        'empfaenger' => sanitize_text_field( wp_unslash( $_POST['bschi_gs_empfaenger'] ?? '' ) ),
        'absender'   => sanitize_text_field( wp_unslash( $_POST['bschi_gs_absender'] ?? '' ) ),
        'gruss'      => sanitize_textarea_field( wp_unslash( $_POST['bschi_gs_gruss'] ?? '' ) ),
    ];
    $pid = bschi_gs_product_id();
    if ( $pid && WC()->cart ) {
        WC()->cart->add_to_cart( $pid, 1, 0, [], [ 'bschi_gutschein' => $daten, 'unique_key' => md5( wp_json_encode( $daten ) . microtime() ) ] );
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }
} );

// Preis des Cart-Items = konfigurierter Betrag
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
    foreach ( $cart->get_cart() as $item ) {
        if ( ! empty( $item['bschi_gutschein']['betrag'] ) ) {
            $item['data']->set_price( (float) $item['bschi_gutschein']['betrag'] );
        }
    }
}, 20 );

// Konfiguration im Warenkorb anzeigen
add_filter( 'woocommerce_get_item_data', function ( $rows, $item ) {
    if ( ! empty( $item['bschi_gutschein'] ) ) {
        $g = $item['bschi_gutschein'];
        $rows[] = [ 'key' => 'Einsatzort', 'value' => $g['typ'] === 'laden' ? 'Laden (Theresienthal & Schweinhütt)' : 'Online-Shop' ];
        if ( ! empty( $g['empfaenger'] ) ) {
            $rows[] = [ 'key' => 'Für', 'value' => esc_html( $g['empfaenger'] ) ];
        }
    }
    return $rows;
}, 10, 2 );

// Konfiguration in die Bestellposition übernehmen
add_action( 'woocommerce_checkout_create_order_line_item', function ( $line_item, $cart_item_key, $values ) {
    if ( ! empty( $values['bschi_gutschein'] ) ) {
        $g = $values['bschi_gutschein'];
        $line_item->add_meta_data( '_bschi_gutschein', wp_json_encode( $g ), true );
        $line_item->add_meta_data( 'Einsatzort', $g['typ'] === 'laden' ? 'Laden' : 'Online-Shop', true );
        if ( ! empty( $g['empfaenger'] ) ) {
            $line_item->add_meta_data( 'Für', $g['empfaenger'], true );
        }
    }
}, 10, 3 );

// ─── Zahlungseingang → Ausstellung im Office anstoßen ────────────────────────

add_action( 'woocommerce_order_status_processing', 'bschi_gs_order_paid', 20 );
add_action( 'woocommerce_order_status_completed', 'bschi_gs_order_paid', 20 );

function bschi_gs_order_paid( $order_id ): void {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( '_bschi_gs_gemeldet' ) ) {
        return;
    }
    $items = [];
    foreach ( $order->get_items() as $item_id => $item ) {
        $raw = $item->get_meta( '_bschi_gutschein' );
        if ( ! $raw ) {
            continue;
        }
        $g = json_decode( $raw, true );
        if ( ! is_array( $g ) || empty( $g['betrag'] ) ) {
            continue;
        }
        $items[] = [
            'item_key'   => (string) $item_id,
            'typ'        => $g['typ'] ?? 'online',
            'betrag'     => (float) $g['betrag'],
            'design'     => $g['design'] ?? 'klassik',
            'empfaenger' => $g['empfaenger'] ?? '',
            'absender'   => $g['absender'] ?? '',
            'gruss'      => $g['gruss'] ?? '',
        ];
    }
    if ( ! $items ) {
        return;
    }
    // Blockierend mit Antwort-Prüfung – erst bei Erfolg als gemeldet markieren
    // (Office-Seite ist zusätzlich idempotent je (Order, Item)).
    $endpoint = bschi_hub_url( '/api/v1/shop/gutschein-bestellung' );
    if ( ! $endpoint ) {
        return;
    }
    $resp = wp_remote_post( $endpoint, [
        'timeout' => 12,
        'headers' => bschi_hub_headers(),
        'body'    => wp_json_encode( [
            'wc_order_id'   => (string) $order->get_id(),
            'kaeufer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'kaeufer_email' => $order->get_billing_email(),
            'items'         => $items,
        ] ),
    ] );
    if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
        $order->update_meta_data( '_bschi_gs_gemeldet', gmdate( 'c' ) );
        $order->add_order_note( 'Geschenkgutschein-Ausstellung an den Office Hub übergeben (' . count( $items ) . ' Stück).' );
        $order->save();
    } else {
        $detail = is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_response_code( $resp );
        error_log( '[BSCHI] Gutschein-Meldung fehlgeschlagen (Order ' . $order_id . '): ' . $detail );
        $order->add_order_note( 'Gutschein-Meldung an den Office Hub fehlgeschlagen – wird beim nächsten Statuswechsel erneut versucht.' );
    }
}
