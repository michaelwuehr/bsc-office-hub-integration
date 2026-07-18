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
          <label class="bschi-gs-typ" style="flex:1;min-width:200px;border:2px solid #ccc;border-radius:10px;padding:12px;cursor:pointer">
            <input type="radio" name="bschi_gs_typ" value="kombi" checked>
            <b>Überall (empfohlen)</b><br><span style="font-size:13px;color:#666">Im Laden <b>und</b> online einlösbar – mit Barcode für die Kasse, Code fürs Online-Kassenfeld.</span>
          </label>
          <label class="bschi-gs-typ" style="flex:1;min-width:200px;border:2px solid #ccc;border-radius:10px;padding:12px;cursor:pointer">
            <input type="radio" name="bschi_gs_typ" value="online">
            <b>Nur Online-Shop</b><br><span style="font-size:13px;color:#666">Einlösbar auf woidsiederei.de – Code zum Eingeben an der Kasse.</span>
          </label>
          <label class="bschi-gs-typ" style="flex:1;min-width:200px;border:2px solid #ccc;border-radius:10px;padding:12px;cursor:pointer">
            <input type="radio" name="bschi_gs_typ" value="laden">
            <b>Nur Laden</b><br><span style="font-size:13px;color:#666">Einlösbar in Theresienthal &amp; Schweinhütt – mit Barcode für die Kasse.</span>
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

        <h3 style="margin:20px 0 8px">5. Zustellung</h3>
        <div style="display:flex;flex-direction:column;gap:8px">
          <label style="cursor:pointer"><input type="radio" name="bschi_gs_zustellung" value="selbst" checked>
            An mich – ich verschenke den Gutschein selbst (PDF per E-Mail an meine Adresse)</label>
          <label style="cursor:pointer"><input type="radio" name="bschi_gs_zustellung" value="direkt">
            Direkt an die/den Beschenkte(n) per E-Mail senden</label>
          <div id="bschi-gs-direkt" style="display:none;gap:10px;flex-wrap:wrap;padding-left:24px">
            <input type="email" name="bschi_gs_empf_email" id="bschi-gs-empf-email" maxlength="120" placeholder="E-Mail der/des Beschenkten"
              style="padding:10px;border:2px solid #ccc;border-radius:10px;min-width:260px">
            <label style="display:flex;align-items:center;gap:6px">Wunschdatum:
              <input type="date" name="bschi_gs_wunschdatum" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
                style="padding:9px;border:2px solid #ccc;border-radius:10px"></label>
            <span style="font-size:12px;color:#777;width:100%">Du bekommst in jedem Fall eine Sicherheitskopie an deine E-Mail-Adresse.</span>
          </div>
        </div>

        <h3 style="margin:20px 0 8px">Vorschau</h3>
        <div style="border:1px solid #ddd;border-radius:10px;overflow:hidden;background:#f7f5f1">
          <iframe id="bschi-gs-preview" sandbox="" style="width:100%;height:430px;border:0" title="Gutschein-Vorschau"></iframe>
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
          .split('{{TYP_LABEL}}').join(
            typ === 'laden' ? 'Einl&ouml;sbar in unseren L&auml;den (Theresienthal &amp; Schweinh&uuml;tt)'
            : typ === 'online' ? 'Einl&ouml;sbar im Online-Shop woidsiederei.de'
            : '&Uuml;berall einl&ouml;sbar &ndash; im Laden und online');
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
          f.setAttribute('sandbox', '');
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
      document.querySelectorAll('input[name=bschi_gs_zustellung]').forEach(function(r){
        r.addEventListener('change', function(){
          var box = document.getElementById('bschi-gs-direkt');
          var an = document.querySelector('input[name=bschi_gs_zustellung]:checked').value === 'direkt';
          box.style.display = an ? 'flex' : 'none';
          document.getElementById('bschi-gs-empf-email').required = an;
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
    $typ_in = (string) ( $_POST['bschi_gs_typ'] ?? '' );
    $typ = in_array( $typ_in, [ 'online', 'laden', 'kombi' ], true ) ? $typ_in : 'kombi';
    $daten = [
        'typ'        => $typ,
        'betrag'     => $betrag,
        'design'     => sanitize_key( $_POST['bschi_gs_design'] ?? 'klassik' ),
        'empfaenger' => sanitize_text_field( wp_unslash( $_POST['bschi_gs_empfaenger'] ?? '' ) ),
        'absender'   => sanitize_text_field( wp_unslash( $_POST['bschi_gs_absender'] ?? '' ) ),
        'gruss'      => sanitize_textarea_field( wp_unslash( $_POST['bschi_gs_gruss'] ?? '' ) ),
    ];
    if ( ( $_POST['bschi_gs_zustellung'] ?? '' ) === 'direkt' ) {
        $email = sanitize_email( wp_unslash( $_POST['bschi_gs_empf_email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            wc_add_notice( 'Bitte eine gültige E-Mail-Adresse für die Zustellung angeben.', 'error' );
            return;
        }
        $daten['empfaenger_email'] = $email;
        $wunsch = sanitize_text_field( $_POST['bschi_gs_wunschdatum'] ?? '' );
        if ( $wunsch && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $wunsch ) && $wunsch >= gmdate( 'Y-m-d' ) ) {
            $daten['zustell_datum'] = $wunsch;
        }
    }
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
        $rows[] = [ 'key' => 'Einsatzort', 'value' => $g['typ'] === 'laden' ? 'Laden (Theresienthal & Schweinhütt)' : ( $g['typ'] === 'kombi' ? 'Überall (Laden + Online)' : 'Online-Shop' ) ];
        if ( ! empty( $g['empfaenger'] ) ) {
            $rows[] = [ 'key' => 'Für', 'value' => esc_html( $g['empfaenger'] ) ];
        }
        if ( ! empty( $g['empfaenger_email'] ) ) {
            $rows[] = [ 'key' => 'Zustellung', 'value' => esc_html( $g['empfaenger_email'] )
                . ( ! empty( $g['zustell_datum'] ) ? ' am ' . esc_html( implode( '.', array_reverse( explode( '-', $g['zustell_datum'] ) ) ) ) : '' ) ];
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
            'empfaenger_email' => $g['empfaenger_email'] ?? '',
            'zustell_datum'    => $g['zustell_datum'] ?? '',
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

// ─── Restwert-Abfrage: Shortcode [bsc_gutschein_restwert] + REST-Proxy ───────

add_action( 'rest_api_init', function () {
    register_rest_route( 'bschi/v1', '/gutschein-restwert', [
        'methods'             => 'POST',
        'callback'            => 'bschi_gs_restwert',
        'permission_callback' => '__return_true',   // öffentlich, aber IP-gedrosselt
    ] );
} );

function bschi_gs_restwert( WP_REST_Request $request ) {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) {
        return new WP_REST_Response( [ 'ok' => false ], 404 );
    }
    // Drossel: max. 10 Abfragen je IP und 10 Minuten (kein Code-Durchprobieren)
    $ip  = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    $key = 'bschi_gsrw_' . md5( $ip );
    $n   = (int) get_transient( $key );
    if ( $n >= 10 ) {
        return new WP_REST_Response( [ 'ok' => false, 'msg' => 'Zu viele Anfragen – bitte später erneut versuchen.' ], 429 );
    }
    set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

    $code = strtoupper( sanitize_text_field( $request->get_param( 'code' ) ?? '' ) );
    if ( strlen( $code ) < 6 || strlen( $code ) > 40 ) {
        return new WP_REST_Response( [ 'ok' => false, 'msg' => 'Ungültiger Code.' ], 400 );
    }

    // 1) Online-Gutschein? WC-Coupon lokal prüfen
    $cid = wc_get_coupon_id_by_code( strtolower( $code ) );
    if ( ! $cid ) {
        $cid = wc_get_coupon_id_by_code( $code );
    }
    if ( $cid ) {
        $coupon    = new WC_Coupon( $cid );
        $eingeloest = $coupon->get_usage_count() >= max( 1, (int) $coupon->get_usage_limit() );
        return new WP_REST_Response( [
            'ok' => true, 'typ' => 'online', 'betrag' => (float) $coupon->get_amount(),
            'status' => $eingeloest ? 'eingelöst' : 'noch nicht eingelöst',
        ], 200 );
    }

    // 2) Laden-Gutschein? Office fragt Tillhub nach dem Restwert
    $endpoint = bschi_hub_url( '/api/v1/shop/gutschein-restwert' );
    if ( $endpoint ) {
        $resp = wp_remote_post( $endpoint, [
            'timeout' => 10,
            'headers' => bschi_hub_headers(),
            'body'    => wp_json_encode( [ 'code' => $code ] ),
        ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $d = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $d['gefunden'] ) ) {
                return new WP_REST_Response( [
                    'ok' => true, 'typ' => 'laden', 'betrag' => (float) ( $d['betrag'] ?? 0 ),
                    'restwert' => $d['restwert'],
                ], 200 );
            }
        }
    }
    return new WP_REST_Response( [ 'ok' => false, 'msg' => 'Gutschein nicht gefunden.' ], 404 );
}

add_shortcode( 'bsc_gutschein_restwert', function (): string {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) {
        return '';
    }
    ob_start();
    ?>
    <div id="bschi-gsrw" style="max-width:480px">
      <div style="display:flex;gap:8px">
        <input type="text" id="bschi-gsrw-code" maxlength="40" placeholder="Gutschein-Code eingeben"
          style="flex:1;padding:11px;border:2px solid #ccc;border-radius:10px;font-family:monospace">
        <button type="button" id="bschi-gsrw-btn"
          style="padding:11px 20px;border:0;border-radius:10px;background:#5a6b52;color:#fff;font-weight:700;cursor:pointer;min-height:48px">Prüfen</button>
      </div>
      <div id="bschi-gsrw-out" style="margin-top:10px;font-size:15px"></div>
    </div>
    <script>
    document.getElementById('bschi-gsrw-btn').addEventListener('click', function(){
      var btn = this, out = document.getElementById('bschi-gsrw-out');
      var code = document.getElementById('bschi-gsrw-code').value.trim();
      if (!code) return;
      btn.disabled = true; btn.textContent = 'Prüft …'; out.textContent = '';
      fetch('<?php echo esc_js( rest_url( 'bschi/v1/gutschein-restwert' ) ); ?>', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({code: code})
      }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok && d.typ === 'laden') {
          out.innerHTML = d.restwert != null
            ? '<b>Laden-Gutschein</b> – Restwert: <b>' + Number(d.restwert).toFixed(2).replace('.', ',') + ' €</b> (von ' + Number(d.betrag).toFixed(2).replace('.', ',') + ' €)'
            : '<b>Laden-Gutschein</b> über ' + Number(d.betrag).toFixed(2).replace('.', ',') + ' € – Restwert gerade nicht abrufbar, bitte an der Kasse fragen.';
        } else if (d.ok) {
          out.innerHTML = '<b>Online-Gutschein</b> über ' + Number(d.betrag).toFixed(2).replace('.', ',') + ' € – ' + d.status + '.';
        } else {
          out.textContent = d.msg || 'Gutschein nicht gefunden.';
        }
      }).catch(function(){ out.textContent = 'Abfrage gerade nicht möglich.'; })
        .finally(function(){ btn.disabled = false; btn.textContent = 'Prüfen'; });
    });
    </script>
    <?php
    return (string) ob_get_clean();
} );

// ─── Mein Konto → Gutscheine (Kundenansicht mit Restwert) ────────────────────

add_action( 'init', function () {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) {
        return;
    }
    add_rewrite_endpoint( 'gutscheine', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'guthaben', EP_ROOT | EP_PAGES );
    if ( get_option( 'bschi_gs_rewrite_flushed' ) !== '2' ) {
        flush_rewrite_rules();
        update_option( 'bschi_gs_rewrite_flushed', '2' );
    }
} );

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) {
        return $items;
    }
    // Nach "Bestellungen" einsortieren
    $neu = [];
    foreach ( $items as $k => $v ) {
        $neu[ $k ] = $v;
        if ( 'orders' === $k ) {
            $neu['guthaben']    = 'Mein Guthaben';
            $neu['gutscheine']  = 'Gutscheine';
        }
    }
    if ( ! isset( $neu['guthaben'] ) )   { $neu['guthaben'] = 'Mein Guthaben'; }
    if ( ! isset( $neu['gutscheine'] ) ) { $neu['gutscheine'] = 'Gutscheine'; }
    return $neu;
} );

// ─── Mein Guthaben (Wallet-Kontostand + Historie) ────────────────────────────

add_action( 'woocommerce_account_guthaben_endpoint', function () {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) {
        return;
    }
    $user  = wp_get_current_user();
    $email = $user && $user->user_email ? $user->user_email : '';
    if ( ! $email ) {
        echo '<p>Keine E-Mail-Adresse am Konto hinterlegt.</p>';
        return;
    }
    $endpoint = bschi_hub_url( '/api/v1/shop/wallet' );
    $d = [ 'saldo' => 0, 'transaktionen' => [] ];
    if ( $endpoint ) {
        $resp = wp_remote_post( $endpoint, [
            'timeout' => 12,
            'headers' => bschi_hub_headers(),
            'body'    => wp_json_encode( [ 'email' => $email ] ),
        ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $d = json_decode( wp_remote_retrieve_body( $resp ), true ) ?: $d;
        }
    }
    $saldo = (float) ( $d['saldo'] ?? 0 );
    $txs   = is_array( $d['transaktionen'] ?? null ) ? $d['transaktionen'] : [];

    echo '<div style="background:#5a6b52;color:#fff;border-radius:12px;padding:18px 20px;margin-bottom:18px">'
       . '<div style="font-size:13px;opacity:.85">Dein aktuelles Guthaben</div>'
       . '<div style="font-size:34px;font-weight:800">' . number_format( $saldo, 2, ',', '.' ) . ' €</div></div>';

    // Aufladen-Button (P2)
    $auflade_url = home_url( '/guthaben-aufladen/' );
    echo '<p><a href="' . esc_url( $auflade_url ) . '" class="button" style="background:#5a6b52;color:#fff">Guthaben aufladen</a> '
       . '<span style="color:#777;font-size:13px;margin-left:8px">Dein Guthaben wird an der Online-Kasse automatisch angeboten.</span></p>';

    // In Laden-Gutschein umwandeln (P3)
    if ( $saldo >= 5 ) {
        echo '<div style="border:1px solid #ddd;border-radius:10px;padding:14px;margin:14px 0">'
           . '<div style="font-weight:600;margin-bottom:6px">Im Laden verwenden?</div>'
           . '<div style="font-size:13px;color:#666;margin-bottom:8px">Wandle einen Teil deines Guthabens in einen Laden-Gutschein um (Barcode fürs Kassieren in Theresienthal &amp; Schweinhütt).</div>'
           . '<input type="number" id="bschi-wl-conv" min="5" max="' . esc_attr( floor( $saldo ) ) . '" step="0.5" value="' . esc_attr( min( 25, floor( $saldo ) ) ) . '" style="width:110px;padding:9px;border:2px solid #ccc;border-radius:10px"> € '
           . '<button type="button" id="bschi-wl-conv-btn" class="button">In Laden-Gutschein umwandeln</button>'
           . '<div id="bschi-wl-conv-msg" style="margin-top:8px;font-size:13px"></div></div>';
        ?>
        <script>
        document.getElementById('bschi-wl-conv-btn').addEventListener('click',function(){
          var btn=this,msg=document.getElementById('bschi-wl-conv-msg');
          var b=parseFloat((document.getElementById('bschi-wl-conv').value||'0').replace(',','.'));
          if(!(b>=5)){msg.textContent='Betrag ab 5 €';return;}
          btn.disabled=true;msg.textContent='Erstelle Gutschein …';
          var fd=new FormData();fd.append('action','bschi_wl_convert');fd.append('betrag',b);
          fd.append('nonce','<?php echo esc_js( wp_create_nonce( 'bschi_wl_convert' ) ); ?>');
          fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){return r.json();}).then(function(d){
              if(d&&d.ok){msg.innerHTML='Laden-Gutschein erstellt: <b>'+d.code+'</b> ('+d.betrag+' €). Kommt auch per E-Mail. Neues Guthaben: '+d.neuer_saldo+' €. <a href="">Seite neu laden</a>';}
              else{msg.textContent=(d&&d.msg)||'Fehlgeschlagen';btn.disabled=false;}
            }).catch(function(){msg.textContent='Fehler';btn.disabled=false;});
        });
        </script>
        <?php
    }

    if ( $txs ) {
        echo '<h3 style="margin-top:20px">Buchungen</h3><table class="woocommerce-table shop_table" style="width:100%"><thead><tr>'
           . '<th>Datum</th><th>Vorgang</th><th>Betrag</th><th>Saldo danach</th></tr></thead><tbody>';
        foreach ( $txs as $t ) {
            $b = (float) ( $t['betrag'] ?? 0 );
            $farbe = $b >= 0 ? '#2e7d32' : '#b00';
            echo '<tr><td>' . esc_html( implode( '.', array_reverse( explode( '-', substr( (string) ( $t['datum'] ?? '' ), 0, 10 ) ) ) ) )
               . ' ' . esc_html( substr( (string) ( $t['datum'] ?? '' ), 11, 5 ) ) . '</td>'
               . '<td>' . esc_html( $t['typ_txt'] ?? '' ) . ( ! empty( $t['referenz'] ) ? '<br><span style="font-size:11px;color:#777">' . esc_html( $t['referenz'] ) . '</span>' : '' ) . '</td>'
               . '<td style="color:' . $farbe . ';font-weight:600">' . ( $b >= 0 ? '+' : '' ) . number_format( $b, 2, ',', '.' ) . ' €</td>'
               . '<td>' . number_format( (float) ( $t['saldo_nach'] ?? 0 ), 2, ',', '.' ) . ' €</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#777">Noch keine Guthaben-Buchungen.</p>';
    }
} );

add_action( 'woocommerce_account_gutscheine_endpoint', function () {
    $user = wp_get_current_user();
    $email = $user && $user->user_email ? $user->user_email : '';
    if ( ! $email ) {
        echo '<p>Keine E-Mail-Adresse am Konto hinterlegt.</p>';
        return;
    }
    // Office fragen (5 Minuten je Nutzer zwischengespeichert – Tillhub-Restwert ist live teuer)
    $cache_key = 'bschi_gs_konto_' . md5( strtolower( $email ) );
    $daten = get_transient( $cache_key );
    if ( false === $daten ) {
        $endpoint = bschi_hub_url( '/api/v1/shop/gutscheine-kunde' );
        $daten = [];
        if ( $endpoint ) {
            $resp = wp_remote_post( $endpoint, [
                'timeout' => 12,
                'headers' => bschi_hub_headers(),
                'body'    => wp_json_encode( [ 'email' => $email ] ),
            ] );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $daten = json_decode( wp_remote_retrieve_body( $resp ), true ) ?: [];
            }
        }
        set_transient( $cache_key, $daten, 5 * MINUTE_IN_SECONDS );
    }
    $aktive     = is_array( $daten['aktive'] ?? null ) ? $daten['aktive'] : [];
    $eingeloest = is_array( $daten['eingeloest'] ?? null ) ? $daten['eingeloest'] : [];
    if ( ! $aktive && ! $eingeloest ) {
        echo '<p>Du hast noch keine Gutscheine bei uns gekauft. '
           . 'Schau doch mal beim <a href="' . esc_url( home_url( '/gutschein/' ) ) . '">Gutschein-Konfigurator</a> vorbei.</p>';
        return;
    }
    $ddmmyy = function ( $d ) { return esc_html( implode( '.', array_reverse( explode( '-', (string) $d ) ) ) ); };

    // Guthaben-Übersicht
    echo '<div style="background:#5a6b52;color:#fff;border-radius:12px;padding:16px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">'
       . '<div><div style="font-size:13px;opacity:.85">Dein verfügbares Guthaben</div>'
       . '<div style="font-size:30px;font-weight:800">' . number_format( (float) ( $daten['guthaben'] ?? 0 ), 2, ',', '.' ) . ' €</div></div>'
       . '<div style="font-size:13px;opacity:.9;text-align:right">' . count( $aktive ) . ' aktive<br>' . count( $eingeloest ) . ' eingelöste Gutscheine</div></div>';

    // Aktive Gutscheine
    if ( $aktive ) {
        echo '<h3>Verfügbare Gutscheine</h3><table class="woocommerce-table shop_table" style="width:100%"><thead><tr>'
           . '<th>Datum</th><th>Art</th><th>Guthaben</th><th>Code</th><th>PDF</th></tr></thead><tbody>';
        foreach ( $aktive as $g ) {
            $verf = (float) ( $g['verfuegbar'] ?? $g['betrag'] );
            $teil = ! empty( $g['eingeloest_extern'] ) ? '<br><span style="font-size:11px;color:#777">von ' . number_format( (float) $g['betrag'], 2, ',', '.' ) . ' € · teilw. eingelöst</span>' : '';
            $herk = ! empty( $g['aus_rest'] ) ? '<br><span style="font-size:11px;color:#777">Restguthaben-Gutschein</span>' : '';
            $zu   = ! empty( $g['zustellung'] ) ? '<br><span style="font-size:11px;color:#777">Geschenk: ' . esc_html( $g['zustellung'] ) . '</span>' : '';
            $pdf_url = wp_nonce_url( admin_url( 'admin-post.php?action=bschi_gs_pdf&code=' . rawurlencode( (string) ( $g['code'] ?? '' ) ) ), 'bschi_gs_pdf_' . ( $g['code'] ?? '' ) );
            echo '<tr><td>' . $ddmmyy( $g['datum'] ?? '' ) . '</td>'
               . '<td>' . esc_html( $g['typ_txt'] ?? '' ) . ( ! empty( $g['empfaenger'] ) ? '<br><span style="font-size:11px;color:#777">für ' . esc_html( $g['empfaenger'] ) . '</span>' : '' ) . $herk . '</td>'
               . '<td><b>' . number_format( $verf, 2, ',', '.' ) . ' €</b>' . $teil . '</td>'
               . '<td style="font-family:monospace">' . esc_html( $g['code'] ?? '' ) . $zu . '</td>'
               . '<td><a href="' . esc_url( $pdf_url ) . '">herunterladen</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    // Eingelöste Gutscheine (Historie)
    if ( $eingeloest ) {
        echo '<h3 style="margin-top:22px">Bereits eingelöst</h3><table class="woocommerce-table shop_table" style="width:100%"><thead><tr>'
           . '<th>Eingelöst am</th><th>Art</th><th>Betrag</th><th>Wo</th><th>Code</th></tr></thead><tbody>';
        foreach ( $eingeloest as $g ) {
            echo '<tr style="opacity:.75"><td>' . $ddmmyy( $g['eingeloest_am'] ?? $g['datum'] ?? '' ) . '</td>'
               . '<td>' . esc_html( $g['typ_txt'] ?? '' ) . '</td>'
               . '<td>' . number_format( (float) ( $g['eingeloest_betrag'] ?? $g['betrag'] ), 2, ',', '.' ) . ' €</td>'
               . '<td>' . esc_html( $g['kanal'] ?? '' ) . '</td>'
               . '<td style="font-family:monospace">' . esc_html( $g['code'] ?? '' ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
} );

// ─── Coupon-Status (Batch) für die Office-Statistik ──────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'bschi/v1', '/coupon-status', [
        'methods'             => 'POST',
        'callback'            => 'bschi_gs_coupon_status',
        'permission_callback' => 'bschi_voucher_permission',   // X-BSCHI-Secret (wie /coupon)
    ] );
} );

function bschi_gs_coupon_status( WP_REST_Request $request ) {
    $codes = $request->get_param( 'codes' );
    if ( ! is_array( $codes ) ) {
        return new WP_REST_Response( [ 'ok' => false ], 400 );
    }
    $out = [];
    foreach ( array_slice( $codes, 0, 300 ) as $code ) {
        $code = sanitize_text_field( (string) $code );
        $cid  = wc_get_coupon_id_by_code( strtolower( $code ) ) ?: wc_get_coupon_id_by_code( $code );
        if ( ! $cid ) {
            $out[] = [ 'code' => $code, 'gefunden' => false ];
            continue;
        }
        $coupon = new WC_Coupon( $cid );
        $out[]  = [
            'code'       => $code,
            'gefunden'   => true,
            'betrag'     => (float) $coupon->get_amount(),
            'eingeloest' => $coupon->get_usage_count() >= max( 1, (int) $coupon->get_usage_limit() ),
        ];
    }
    return new WP_REST_Response( [ 'ok' => true, 'status' => $out ], 200 );
}

// ─── Coupon deaktivieren (Split-Modell: alter Gutschein wird storniert) ──────

add_action( 'rest_api_init', function () {
    register_rest_route( 'bschi/v1', '/coupon-deactivate', [
        'methods'             => 'POST',
        'callback'            => 'bschi_gs_coupon_deactivate',
        'permission_callback' => 'bschi_voucher_permission',   // X-BSCHI-Secret
    ] );
} );

function bschi_gs_coupon_deactivate( WP_REST_Request $request ) {
    if ( ! class_exists( 'WC_Coupon' ) ) {
        return new WP_REST_Response( [ 'ok' => false ], 503 );
    }
    $code = sanitize_text_field( (string) $request->get_param( 'code' ) );
    $cid  = $code ? ( wc_get_coupon_id_by_code( strtolower( $code ) ) ?: wc_get_coupon_id_by_code( $code ) ) : 0;
    if ( ! $cid ) {
        return new WP_REST_Response( [ 'ok' => true, 'note' => 'nicht gefunden' ], 200 );
    }
    $coupon = new WC_Coupon( $cid );
    $coupon->set_amount( 0 );
    $coupon->set_date_expires( gmdate( 'Y-m-d', time() - 86400 ) );  // gestern -> ungültig
    $coupon->set_usage_limit( 1 );
    $coupon->update_meta_data( '_bschi_storniert', gmdate( 'c' ) );
    $coupon->save();
    return new WP_REST_Response( [ 'ok' => true, 'deaktiviert' => $code ], 200 );
}

// ═══ Online-Einlösung an der Kasse (Universal-/Online-Gutschein, Split-Modell) ═══
// Ablauf: Kunde gibt Code im Warenkorb/Checkout ein -> Office prüft -> Rabatt als
// negative Gebühr (Guthaben wie Bargeld auf den Warenkorb). Bei bezahlter Bestellung
// ruft das Plugin den Office-Split-Kern -> alter Gutschein storniert, Rest neu.

// 1) Code-Eingabefeld unter dem Warenkorb
add_action( 'woocommerce_cart_totals_after_order_total', 'bschi_gs_redeem_field' );
add_action( 'woocommerce_review_order_after_order_total', 'bschi_gs_redeem_field' );
function bschi_gs_redeem_field() {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) return;
    $aktiv = WC()->session ? WC()->session->get( 'bschi_gs_redeem' ) : null;
    echo '<tr class="bschi-gs-redeem-row"><th>Gutschein</th><td>';
    if ( is_array( $aktiv ) && ! empty( $aktiv['code'] ) ) {
        echo 'Eingelöst: <b>' . esc_html( $aktiv['code'] ) . '</b> '
           . '(' . wc_price( (float) $aktiv['betrag'] ) . ' Guthaben) '
           . '<a href="#" id="bschi-gs-remove" style="color:#a00">entfernen</a>';
    } else {
        echo '<input type="text" id="bschi-gs-code" placeholder="Gutschein-Code" style="width:60%;padding:6px">'
           . ' <button type="button" id="bschi-gs-apply" class="button">Einlösen</button>'
           . '<div id="bschi-gs-msg" style="font-size:12px;color:#a00;margin-top:4px"></div>';
    }
    echo '</td></tr>';
    ?>
    <script>
    (function(){
      var apply=document.getElementById('bschi-gs-apply'), rem=document.getElementById('bschi-gs-remove');
      function post(action, code){
        var fd=new FormData(); fd.append('action',action); if(code)fd.append('code',code);
        fd.append('nonce','<?php echo esc_js( wp_create_nonce( 'bschi_gs_redeem' ) ); ?>');
        return fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});
      }
      if(apply)apply.addEventListener('click',function(){
        var code=(document.getElementById('bschi-gs-code').value||'').trim();
        var msg=document.getElementById('bschi-gs-msg');
        if(!code)return; apply.disabled=true; msg.textContent='Prüfe …';
        post('bschi_gs_apply',code).then(function(d){
          if(d&&d.ok){ location.reload(); }
          else { msg.textContent=(d&&d.msg)||'Gutschein ungültig'; apply.disabled=false; }
        }).catch(function(){msg.textContent='Fehler';apply.disabled=false;});
      });
      if(rem)rem.addEventListener('click',function(e){e.preventDefault();post('bschi_gs_remove').then(function(){location.reload();});});
    })();
    </script>
    <?php
}

// 2) AJAX: Code anwenden / entfernen
add_action( 'wp_ajax_bschi_gs_apply', 'bschi_gs_ajax_apply' );
add_action( 'wp_ajax_nopriv_bschi_gs_apply', 'bschi_gs_ajax_apply' );
function bschi_gs_ajax_apply() {
    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bschi_gs_redeem' ) || ! WC()->session ) {
        wp_send_json( [ 'ok' => false, 'msg' => 'Sitzung abgelaufen' ] );
    }
    $code = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
    $endpoint = bschi_hub_url( '/api/v1/shop/gutschein-pruefen' );
    if ( ! $code || ! $endpoint ) wp_send_json( [ 'ok' => false, 'msg' => 'Ungültig' ] );
    $r = wp_remote_post( $endpoint, [ 'timeout' => 12, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'code' => $code ] ) ] );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) {
        wp_send_json( [ 'ok' => false, 'msg' => 'Prüfung fehlgeschlagen' ] );
    }
    $d = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( empty( $d['gueltig'] ) ) {
        $grund = ( $d['grund'] ?? '' ) === 'nur_laden' ? 'Dieser Gutschein ist nur im Laden einlösbar.' : 'Gutschein nicht gefunden oder bereits eingelöst.';
        wp_send_json( [ 'ok' => false, 'msg' => $grund ] );
    }
    WC()->session->set( 'bschi_gs_redeem', [ 'code' => $code, 'betrag' => (float) $d['betrag'] ] );
    wp_send_json( [ 'ok' => true ] );
}
add_action( 'wp_ajax_bschi_gs_remove', 'bschi_gs_ajax_remove' );
add_action( 'wp_ajax_nopriv_bschi_gs_remove', 'bschi_gs_ajax_remove' );
function bschi_gs_ajax_remove() {
    if ( WC()->session ) WC()->session->__unset( 'bschi_gs_redeem' );
    wp_send_json( [ 'ok' => true ] );
}

// 3) Guthaben als negative Gebühr auf den Warenkorb (min(Guthaben, Zwischensumme))
add_action( 'woocommerce_cart_calculate_fees', 'bschi_gs_apply_fee', 20 );
function bschi_gs_apply_fee( $cart ) {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) || ! WC()->session ) return;
    $a = WC()->session->get( 'bschi_gs_redeem' );
    if ( ! is_array( $a ) || empty( $a['code'] ) ) return;
    $subtotal = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
    $einsatz  = min( (float) $a['betrag'], $subtotal );
    if ( $einsatz > 0.001 ) {
        $cart->add_fee( 'Gutschein ' . $a['code'], -1 * round( $einsatz, 2 ), false );
    }
}

// 4) Bei bezahlter Bestellung: Gutschein im Office einlösen (Split) – idempotent
add_action( 'woocommerce_order_status_processing', 'bschi_gs_redeem_on_paid', 25 );
add_action( 'woocommerce_order_status_completed', 'bschi_gs_redeem_on_paid', 25 );
function bschi_gs_redeem_on_paid( $order_id ) {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) return;
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( '_bschi_gs_redeemed' ) ) return;
    // eingesetzten Betrag aus der Gutschein-Gebühr der Bestellung lesen
    $code = ''; $betrag = 0.0;
    foreach ( $order->get_items( 'fee' ) as $fee ) {
        if ( strpos( (string) $fee->get_name(), 'Gutschein ' ) === 0 ) {
            $code = trim( str_replace( 'Gutschein ', '', $fee->get_name() ) );
            $betrag = abs( (float) $fee->get_total() );
            break;
        }
    }
    if ( ! $code || $betrag <= 0 ) return;
    $endpoint = bschi_hub_url( '/api/v1/shop/gutschein-einloesen' );
    if ( ! $endpoint ) return;
    $r = wp_remote_post( $endpoint, [ 'timeout' => 15, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'code' => $code, 'betrag' => $betrag ] ) ] );
    if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
        $d = json_decode( wp_remote_retrieve_body( $r ), true );
        $order->update_meta_data( '_bschi_gs_redeemed', gmdate( 'c' ) );
        $note = 'Gutschein ' . $code . ' eingelöst: ' . wc_price( $betrag );
        if ( ! empty( $d['rest_code'] ) ) $note .= '. Restguthaben ' . wc_price( (float) $d['rest'] ) . ' als neuer Gutschein ' . $d['rest_code'] . ' versendet.';
        $order->add_order_note( $note );
        $order->save();
    } else {
        $order->add_order_note( 'ACHTUNG: Gutschein ' . $code . ' (' . wc_price( $betrag ) . ') konnte im Office nicht eingelöst werden – bitte manuell prüfen.' );
        $order->save();
    }
    if ( WC()->session ) WC()->session->__unset( 'bschi_gs_redeem' );
}

// ═══════════════ WALLET: Aufladen + Mit Guthaben zahlen (P2) ═══════════════════

// Verstecktes WC-Produkt fürs Aufladen (einmalig)
function bschi_wallet_product_id(): int {
    $pid = (int) get_option( 'bschi_wallet_product_id', 0 );
    if ( $pid && get_post_status( $pid ) === 'publish' ) {
        return $pid;
    }
    if ( ! function_exists( 'wc_get_product' ) ) {
        return 0;
    }
    $p = new WC_Product_Simple();
    $p->set_name( 'Guthaben aufladen' );
    $p->set_status( 'publish' );
    $p->set_catalog_visibility( 'hidden' );
    $p->set_virtual( true );
    $p->set_regular_price( '25' );
    $p->set_tax_status( 'none' );   // Guthaben-Aufladung ohne USt (Besteuerung bei Einlösung)
    $p->set_sold_individually( true );
    $pid = $p->save();
    update_option( 'bschi_wallet_product_id', $pid );
    return (int) $pid;
}

// Shortcode: Aufladen [bsc_guthaben_aufladen]
add_shortcode( 'bsc_guthaben_aufladen', function (): string {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) || ! function_exists( 'WC' ) ) {
        return '';
    }
    if ( ! is_user_logged_in() ) {
        return '<p>Bitte <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">melde dich an</a>, um dein Guthaben aufzuladen.</p>';
    }
    $pid = bschi_wallet_product_id();
    if ( ! $pid ) { return ''; }
    ob_start(); ?>
    <div style="max-width:520px">
      <form method="post">
        <?php wp_nonce_field( 'bschi_wl_add', 'bschi_wl_nonce' ); ?>
        <input type="hidden" name="bschi_wl_add" value="1">
        <h3 style="margin:0 0 10px">Guthaben aufladen</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
          <?php foreach ( [ 10, 25, 50, 100 ] as $b ) : ?>
            <button type="button" class="bschi-wl-b" data-b="<?php echo esc_attr( $b ); ?>"
              style="padding:10px 18px;border:2px solid #ccc;border-radius:10px;background:#fff;cursor:pointer;font-weight:700"><?php echo esc_html( $b ); ?> €</button>
          <?php endforeach; ?>
          <span style="color:#666">oder</span>
          <input type="number" name="bschi_wl_betrag" id="bschi-wl-betrag" value="25" min="5" max="500" step="0.5" required
            style="width:110px;padding:9px;border:2px solid #ccc;border-radius:10px"> €
        </div>
        <button type="submit" style="padding:13px 26px;border:0;border-radius:10px;background:#5a6b52;color:#fff;font-weight:700;cursor:pointer">Aufladen &amp; bezahlen</button>
        <p style="font-size:12px;color:#777;margin-top:8px">Nach Zahlung wird dein Guthaben sofort gutgeschrieben.</p>
      </form>
    </div>
    <script>
    document.querySelectorAll('.bschi-wl-b').forEach(function(b){b.addEventListener('click',function(){document.getElementById('bschi-wl-betrag').value=b.dataset.b;});});
    </script>
    <?php
    return (string) ob_get_clean();
} );

// Aufladen -> Warenkorb (eigener Preis, an den Kunden gebunden)
add_action( 'template_redirect', function () {
    if ( empty( $_POST['bschi_wl_add'] ) || ! bschi_feature_enabled( 'gutschein_shop' ) || ! function_exists( 'WC' ) ) {
        return;
    }
    if ( ! isset( $_POST['bschi_wl_nonce'] ) || ! wp_verify_nonce( $_POST['bschi_wl_nonce'], 'bschi_wl_add' ) || ! is_user_logged_in() ) {
        return;
    }
    $betrag = round( (float) str_replace( ',', '.', (string) ( $_POST['bschi_wl_betrag'] ?? 0 ) ), 2 );
    if ( $betrag < 5 || $betrag > 500 ) {
        wc_add_notice( 'Aufladebetrag zwischen 5 und 500 € wählen.', 'error' );
        return;
    }
    $pid = bschi_wallet_product_id();
    if ( $pid && WC()->cart ) {
        WC()->cart->add_to_cart( $pid, 1, 0, [], [ 'bschi_wallet_topup' => $betrag, 'unique_key' => md5( 'wl' . $betrag . microtime() ) ] );
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }
} );

// Aufladebetrag = Warenkorbpreis + Anzeige
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
    foreach ( $cart->get_cart() as $item ) {
        if ( ! empty( $item['bschi_wallet_topup'] ) ) {
            $item['data']->set_price( (float) $item['bschi_wallet_topup'] );
        }
    }
}, 20 );
add_filter( 'woocommerce_get_item_data', function ( $rows, $item ) {
    if ( ! empty( $item['bschi_wallet_topup'] ) ) {
        $rows[] = [ 'key' => 'Guthaben-Aufladung', 'value' => wc_price( (float) $item['bschi_wallet_topup'] ) ];
    }
    return $rows;
}, 10, 2 );
add_action( 'woocommerce_checkout_create_order_line_item', function ( $li, $key, $values ) {
    if ( ! empty( $values['bschi_wallet_topup'] ) ) {
        $li->add_meta_data( '_bschi_wallet_topup', (float) $values['bschi_wallet_topup'], true );
    }
}, 10, 3 );

// Bezahlte Aufladung -> Office gutschreiben (idempotent)
add_action( 'woocommerce_order_status_processing', 'bschi_wallet_topup_paid', 30 );
add_action( 'woocommerce_order_status_completed', 'bschi_wallet_topup_paid', 30 );
function bschi_wallet_topup_paid( $order_id ) {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) { return; }
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( '_bschi_wl_topped' ) ) { return; }
    $summe = 0.0;
    foreach ( $order->get_items() as $item ) {
        $b = (float) $item->get_meta( '_bschi_wallet_topup' );
        if ( $b > 0 ) { $summe += $b; }
    }
    if ( $summe <= 0 ) { return; }
    $email = $order->get_billing_email();
    $endpoint = bschi_hub_url( '/api/v1/shop/wallet-aufladen' );
    if ( ! $email || ! $endpoint ) { return; }
    $r = wp_remote_post( $endpoint, [ 'timeout' => 15, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'email' => $email, 'betrag' => $summe,
            'name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'referenz' => 'Aufladung Bestellung ' . $order->get_order_number() ] ) ] );
    if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
        $order->update_meta_data( '_bschi_wl_topped', gmdate( 'c' ) );
        $order->add_order_note( 'Guthaben aufgeladen: ' . wc_price( $summe ) );
        $order->save();
    } else {
        $order->add_order_note( 'ACHTUNG: Guthaben-Aufladung (' . wc_price( $summe ) . ') im Office fehlgeschlagen – bitte prüfen.' );
        $order->save();
    }
}

// ── Mit Guthaben zahlen (Checkbox an der Kasse, negative Gebühr) ─────────────
add_action( 'woocommerce_review_order_before_payment', 'bschi_wallet_pay_field' );
function bschi_wallet_pay_field() {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) || ! is_user_logged_in() || ! WC()->cart ) { return; }
    // Aufladung im Warenkorb? Dann kein Bezahlen mit Guthaben (Zirkel vermeiden)
    foreach ( WC()->cart->get_cart() as $it ) { if ( ! empty( $it['bschi_wallet_topup'] ) ) { return; } }
    $email = wp_get_current_user()->user_email;
    $cache = get_transient( 'bschi_wl_saldo_' . md5( strtolower( $email ) ) );
    if ( false === $cache ) {
        $cache = 0.0;
        $ep = bschi_hub_url( '/api/v1/shop/wallet-saldo' );
        if ( $ep ) {
            $resp = wp_remote_post( $ep, [ 'timeout' => 10, 'headers' => bschi_hub_headers(), 'body' => wp_json_encode( [ 'email' => $email ] ) ] );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $cache = (float) ( json_decode( wp_remote_retrieve_body( $resp ), true )['saldo'] ?? 0 );
            }
        }
        set_transient( 'bschi_wl_saldo_' . md5( strtolower( $email ) ), $cache, 60 );
    }
    if ( $cache <= 0 ) { return; }
    $checked = WC()->session && WC()->session->get( 'bschi_wl_use' ) ? 'checked' : '';
    echo '<tr class="bschi-wl-pay"><th>Guthaben</th><td>'
       . '<label><input type="checkbox" id="bschi-wl-use" ' . $checked . '> Mit Guthaben zahlen ('
       . wc_price( $cache ) . ' verfügbar)</label></td></tr>';
    ?>
    <script>
    (function(){var c=document.getElementById('bschi-wl-use');if(!c)return;c.addEventListener('change',function(){
      var fd=new FormData();fd.append('action','bschi_wl_toggle');fd.append('use',c.checked?'1':'0');
      fd.append('nonce','<?php echo esc_js( wp_create_nonce( 'bschi_wl_toggle' ) ); ?>');
      fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin'}).then(function(){
        if(window.jQuery)jQuery(document.body).trigger('update_checkout');});});})();
    </script>
    <?php
}
add_action( 'wp_ajax_bschi_wl_toggle', function () {
    if ( wp_verify_nonce( $_POST['nonce'] ?? '', 'bschi_wl_toggle' ) && WC()->session ) {
        WC()->session->set( 'bschi_wl_use', ! empty( $_POST['use'] ) ? 1 : 0 );
    }
    wp_send_json_success();
} );

// Guthaben als negative Gebühr (min(Saldo, Summe))
add_action( 'woocommerce_cart_calculate_fees', function ( $cart ) {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) || ! is_user_logged_in() || ! WC()->session ) { return; }
    if ( ! WC()->session->get( 'bschi_wl_use' ) ) { return; }
    foreach ( $cart->get_cart() as $it ) { if ( ! empty( $it['bschi_wallet_topup'] ) ) { return; } }
    $email = wp_get_current_user()->user_email;
    $ep = bschi_hub_url( '/api/v1/shop/wallet-saldo' );
    $saldo = 0.0;
    if ( $ep ) {
        $resp = wp_remote_post( $ep, [ 'timeout' => 10, 'headers' => bschi_hub_headers(), 'body' => wp_json_encode( [ 'email' => $email ] ) ] );
        if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
            $saldo = (float) ( json_decode( wp_remote_retrieve_body( $resp ), true )['saldo'] ?? 0 );
        }
    }
    $summe = (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
    $einsatz = min( $saldo, $summe );
    if ( $einsatz > 0.001 ) { $cart->add_fee( 'Guthaben', -1 * round( $einsatz, 2 ), false ); }
}, 25 );

// Bezahlte Bestellung mit Guthaben-Einsatz -> Office belasten (idempotent)
add_action( 'woocommerce_order_status_processing', 'bschi_wallet_pay_on_paid', 35 );
add_action( 'woocommerce_order_status_completed', 'bschi_wallet_pay_on_paid', 35 );
function bschi_wallet_pay_on_paid( $order_id ) {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) ) { return; }
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( '_bschi_wl_charged' ) ) { return; }
    $betrag = 0.0;
    foreach ( $order->get_items( 'fee' ) as $fee ) {
        if ( 'Guthaben' === $fee->get_name() ) { $betrag = abs( (float) $fee->get_total() ); break; }
    }
    if ( $betrag <= 0 ) { return; }
    $email = $order->get_billing_email();
    $ep = bschi_hub_url( '/api/v1/shop/wallet-zahlen' );
    if ( ! $email || ! $ep ) { return; }
    $r = wp_remote_post( $ep, [ 'timeout' => 15, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'email' => $email, 'betrag' => $betrag, 'referenz' => 'Zahlung Bestellung ' . $order->get_order_number() ] ) ] );
    if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
        $order->update_meta_data( '_bschi_wl_charged', gmdate( 'c' ) );
        $order->add_order_note( 'Mit Guthaben bezahlt: ' . wc_price( $betrag ) );
        $order->save();
        if ( WC()->session ) { WC()->session->set( 'bschi_wl_use', 0 ); }
    } else {
        $order->add_order_note( 'ACHTUNG: Guthaben-Belastung (' . wc_price( $betrag ) . ') im Office fehlgeschlagen – bitte prüfen.' );
        $order->save();
    }
}

// AJAX: Guthaben in Laden-Gutschein umwandeln (P3)
add_action( 'wp_ajax_bschi_wl_convert', function () {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) || ! is_user_logged_in()
         || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bschi_wl_convert' ) ) {
        wp_send_json( [ 'ok' => false, 'msg' => 'Sitzung abgelaufen' ] );
    }
    $betrag = round( (float) str_replace( ',', '.', (string) ( $_POST['betrag'] ?? 0 ) ), 2 );
    if ( $betrag < 5 ) { wp_send_json( [ 'ok' => false, 'msg' => 'Betrag ab 5 €' ] ); }
    $user  = wp_get_current_user();
    $ep = bschi_hub_url( '/api/v1/shop/wallet-umwandeln' );
    if ( ! $ep ) { wp_send_json( [ 'ok' => false, 'msg' => 'nicht verfügbar' ] ); }
    $r = wp_remote_post( $ep, [ 'timeout' => 20, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'email' => $user->user_email, 'betrag' => $betrag,
            'name' => trim( $user->first_name . ' ' . $user->last_name ) ] ) ] );
    if ( is_wp_error( $r ) ) { wp_send_json( [ 'ok' => false, 'msg' => 'Verbindungsfehler' ] ); }
    $code = wp_remote_retrieve_response_code( $r );
    $d = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( 200 === $code && ! empty( $d['ok'] ) ) {
        delete_transient( 'bschi_wl_saldo_' . md5( strtolower( $user->user_email ) ) );
        wp_send_json( [ 'ok' => true, 'code' => $d['code'], 'betrag' => number_format( (float) $d['betrag'], 2, ',', '.' ),
                        'neuer_saldo' => number_format( (float) ( $d['neuer_saldo'] ?? 0 ), 2, ',', '.' ) ] );
    }
    wp_send_json( [ 'ok' => false, 'msg' => $d['detail'] ?? 'Umwandlung fehlgeschlagen' ] );
} );

// ─── Gutschein-PDF-Download (Mein Konto -> Gutscheine) ───────────────────────

add_action( 'admin_post_bschi_gs_pdf', 'bschi_gs_pdf_download' );
function bschi_gs_pdf_download() {
    if ( ! bschi_feature_enabled( 'gutschein_shop' ) || ! is_user_logged_in() ) {
        wp_die( 'Bitte anmelden.' );
    }
    $code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
    if ( ! $code || ! check_admin_referer( 'bschi_gs_pdf_' . $code ) ) {
        wp_die( 'Ungültige Anfrage.' );
    }
    $email = wp_get_current_user()->user_email;
    $ep = bschi_hub_url( '/api/v1/shop/gutschein-pdf' );
    if ( ! $ep ) { wp_die( 'Nicht verfügbar.' ); }
    $resp = wp_remote_post( $ep, [ 'timeout' => 20, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'code' => $code, 'email' => $email ] ) ] );
    if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
        wp_die( 'Gutschein-PDF nicht verfügbar oder keine Berechtigung.' );
    }
    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename="Gutschein-' . $code . '.pdf"' );
    echo wp_remote_retrieve_body( $resp );
    exit;
}
