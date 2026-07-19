<?php
/**
 * BSC Office Hub Integration – Modul: Produkt-Abos (P2).
 *
 * Office Hub = Abo-Engine (Source of Truth), dieses Modul = Kundenerlebnis:
 *  - "Abonnieren"-Button auf Produktseiten -> Abo-Liste (user_meta, nur eingeloggt)
 *  - Shortcode [bsc_abo_liste]: Produkte sammeln, Intervall + Laufzeit wählen
 *    (Rabattvorschau aus Hub-Staffel), Abschluss über den normalen WC-Checkout
 *    (Erstbestellung = erste Lieferung; Abo-Rabatt als reduzierter Artikelpreis)
 *  - Nach Zahlungseingang: POST /shop/abo-abschluss (idempotent je Bestellung)
 *  - Mein Konto -> "Abos": Übersicht + Kündigen (1 Klick, zum Laufzeitende) +
 *    Lieferung überspringen
 *  - Shortcode [bsc_abo_kuendigung]: gesetzliche Kündigungsschaltfläche
 *    (eingeloggt: direkt; sonst Verlangen mit Eingangsbestätigung)
 *
 * Feature-Schalter: feature_abo (Default AUS). Zusätzlich prüft der Hub selbst
 * das Setting feature_abo – doppelt gesichert.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const BSCHI_ABO_META = '_bschi_abo_liste';   // user_meta: [[product_id, menge], ...]

// ── Hub-Konfiguration (Staffel, Intervalle) mit 10-Minuten-Cache ─────────────
function bschi_abo_config(): array {
    $c = get_transient( 'bschi_abo_config' );
    if ( is_array( $c ) ) { return $c; }
    $c = [ 'feature_an' => false, 'intervalle' => [ 1, 2, 3 ], 'laufzeiten' => [ 3, 6, 12 ],
           'rabatt_staffel' => [ '3' => 0, '6' => 5, '12' => 10 ], 'versand_frei_ab' => 49 ];
    $ep = bschi_hub_url( '/api/v1/shop/abo-config' );
    if ( $ep ) {
        $r = wp_remote_post( $ep, [ 'timeout' => 8, 'headers' => bschi_hub_headers(), 'body' => '{}' ] );
        if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
            $d = json_decode( wp_remote_retrieve_body( $r ), true );
            if ( is_array( $d ) ) { $c = array_merge( $c, $d ); }
        }
    }
    set_transient( 'bschi_abo_config', $c, 600 );
    return $c;
}

function bschi_abo_aktiv(): bool {
    if ( ! bschi_feature_enabled( 'abo' ) || ! function_exists( 'WC' ) ) { return false; }
    $c = bschi_abo_config();
    return ! empty( $c['feature_an'] );
}

// ── Abo-Liste (user_meta) ────────────────────────────────────────────────────
function bschi_abo_liste_get( int $user_id ): array {
    $l = get_user_meta( $user_id, BSCHI_ABO_META, true );
    return is_array( $l ) ? $l : [];
}

function bschi_abo_liste_set( int $user_id, array $liste ): void {
    update_user_meta( $user_id, BSCHI_ABO_META, array_values( $liste ) );
}

// ── Produktseite: "Abonnieren"-Button ────────────────────────────────────────
add_action( 'woocommerce_after_add_to_cart_button', function () {
    if ( ! bschi_abo_aktiv() ) { return; }
    global $product;
    if ( ! $product || ! $product->is_purchasable() || $product->is_virtual() ) { return; }
    $pid = $product->get_id();
    if ( is_user_logged_in() ) {
        $drauf = false;
        foreach ( bschi_abo_liste_get( get_current_user_id() ) as $e ) {
            if ( (int) $e[0] === $pid ) { $drauf = true; break; }
        }
        $label = $drauf ? 'Auf der Abo-Liste' : 'Abonnieren';
        echo '<button type="button" id="bschi-abo-add" data-pid="' . esc_attr( $pid ) . '" ' . ( $drauf ? 'disabled' : '' )
           . ' style="margin-left:8px;padding:.618em 1em;border:2px solid #5a6b52;border-radius:4px;background:#fff;color:#5a6b52;font-weight:700;cursor:pointer">'
           . esc_html( $label ) . '</button>'
           . '<div id="bschi-abo-msg" style="font-size:12px;color:#5a6b52;margin-top:6px"></div>';
        ?>
        <script>
        (function(){var b=document.getElementById('bschi-abo-add');if(!b)return;
        b.addEventListener('click',function(){
          var fd=new FormData();fd.append('action','bschi_abo_add');fd.append('pid',b.dataset.pid);
          var q=document.querySelector('form.cart input.qty');fd.append('menge',q&&q.value?q.value:'1');
          fd.append('nonce','<?php echo esc_js( wp_create_nonce( 'bschi_abo' ) ); ?>');
          b.disabled=true;b.textContent='…';
          fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin'})
          .then(function(r){return r.json();}).then(function(d){
            b.textContent=d&&d.success?'Auf der Abo-Liste':'Abonnieren';
            var m=document.getElementById('bschi-abo-msg');
            if(d&&d.success){m.innerHTML='Gemerkt. <a href="<?php echo esc_js( esc_url( home_url( '/abo/' ) ) ); ?>">Zur Abo-Liste</a>';}
            else{b.disabled=false;m.textContent=(d&&d.data)||'Fehler';}
          });
        });})();
        </script>
        <?php
    } else {
        echo '<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" '
           . 'style="display:inline-block;margin-left:8px;padding:.618em 1em;border:2px solid #5a6b52;border-radius:4px;color:#5a6b52;font-weight:700;text-decoration:none">Abonnieren</a>'
           . '<div style="font-size:12px;color:#777;margin-top:6px">Zum Abonnieren bitte anmelden.</div>';
    }
}, 15 );

add_action( 'wp_ajax_bschi_abo_add', function () {
    if ( ! bschi_abo_aktiv() || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bschi_abo' ) ) {
        wp_send_json_error( 'Sitzung abgelaufen' );
    }
    $pid = (int) ( $_POST['pid'] ?? 0 );
    $menge = max( 1, min( 50, (int) ( $_POST['menge'] ?? 1 ) ) );
    $p = wc_get_product( $pid );
    if ( ! $p || ! $p->is_purchasable() ) { wp_send_json_error( 'Produkt nicht verfügbar' ); }
    $uid = get_current_user_id();
    $liste = bschi_abo_liste_get( $uid );
    foreach ( $liste as $e ) {
        if ( (int) $e[0] === $pid ) { wp_send_json_success(); }
    }
    $liste[] = [ $pid, $menge ];
    bschi_abo_liste_set( $uid, $liste );
    wp_send_json_success();
} );

add_action( 'wp_ajax_bschi_abo_liste', function () {
    if ( ! bschi_abo_aktiv() || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bschi_abo' ) ) {
        wp_send_json_error( 'Sitzung abgelaufen' );
    }
    $uid = get_current_user_id();
    $aktion = sanitize_text_field( $_POST['was'] ?? '' );
    $pid = (int) ( $_POST['pid'] ?? 0 );
    $liste = bschi_abo_liste_get( $uid );
    if ( 'entfernen' === $aktion ) {
        $liste = array_filter( $liste, fn( $e ) => (int) $e[0] !== $pid );
    } elseif ( 'menge' === $aktion ) {
        $m = max( 1, min( 50, (int) ( $_POST['menge'] ?? 1 ) ) );
        foreach ( $liste as &$e ) { if ( (int) $e[0] === $pid ) { $e[1] = $m; } }
        unset( $e );
    }
    bschi_abo_liste_set( $uid, $liste );
    wp_send_json_success();
} );

// ── Shortcode: Abo-Liste + Abschluss ─────────────────────────────────────────
add_shortcode( 'bsc_abo_liste', function (): string {
    if ( ! bschi_abo_aktiv() ) { return ''; }
    if ( ! is_user_logged_in() ) {
        return '<p>Bitte <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">melde dich an</a>, um deine Abo-Liste zu sehen.</p>';
    }
    $cfg = bschi_abo_config();
    $liste = bschi_abo_liste_get( get_current_user_id() );
    ob_start();
    echo '<div style="max-width:680px">';
    echo '<h3 style="margin:0 0 4px">Deine Abo-Liste</h3>';
    echo '<p style="color:#666;font-size:13px;margin:0 0 14px">Produkte sammeln, Lieferrhythmus und Laufzeit wählen – längere Laufzeit = mehr Rabatt auf jede Lieferung.</p>';
    if ( ! $liste ) {
        echo '<p>Deine Abo-Liste ist noch leer. Nutze den Button <b>Abonnieren</b> auf den Produktseiten.</p></div>';
        return ob_get_clean();
    }
    $warenwert = 0.0;
    echo '<div id="bschi-abo-items">';
    foreach ( $liste as $e ) {
        $p = wc_get_product( (int) $e[0] );
        if ( ! $p ) { continue; }
        $preis = (float) wc_get_price_including_tax( $p );
        $warenwert += $preis * (int) $e[1];
        echo '<div style="display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid #eee" data-pid="' . esc_attr( $p->get_id() ) . '">'
           . '<div style="width:52px;flex:none">' . $p->get_image( [ 52, 52 ] ) . '</div>'
           . '<div style="flex:1;min-width:0"><div style="font-weight:600;font-size:14px">' . esc_html( $p->get_name() ) . '</div>'
           . '<div style="font-size:12px;color:#777">' . wp_kses_post( wc_price( $preis ) ) . ' / Stück</div></div>'
           . '<input type="number" class="bschi-abo-qty" min="1" max="50" value="' . esc_attr( (int) $e[1] ) . '" style="width:64px;padding:6px;border:1px solid #ccc;border-radius:6px">'
           . '<button type="button" class="bschi-abo-del" style="border:0;background:none;color:#a00;cursor:pointer;font-size:18px" title="Entfernen">&times;</button>'
           . '</div>';
    }
    echo '</div>';
    $staffel = $cfg['rabatt_staffel'];
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0 6px">'
       . '<label style="flex:1;min-width:150px;font-size:13px">Lieferrhythmus<br><select id="bschi-abo-int" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px">'
       . '<option value="1">monatlich</option><option value="2">alle 2 Monate</option><option value="3">alle 3 Monate</option></select></label>'
       . '<label style="flex:1;min-width:150px;font-size:13px">Laufzeit<br><select id="bschi-abo-lz" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px">';
    foreach ( $cfg['laufzeiten'] as $lz ) {
        $r = (float) ( $staffel[ (string) $lz ] ?? 0 );
        echo '<option value="' . esc_attr( $lz ) . '" data-rabatt="' . esc_attr( $r ) . '">' . esc_html( $lz ) . ' Monate'
           . ( $r > 0 ? ' (' . esc_html( rtrim( rtrim( number_format( $r, 1, ',', '' ), '0' ), ',' ) ) . ' % Rabatt)' : '' ) . '</option>';
    }
    echo '</select></label></div>';
    echo '<div id="bschi-abo-summe" data-warenwert="' . esc_attr( number_format( $warenwert, 2, '.', '' ) ) . '" data-frei="' . esc_attr( (float) $cfg['versand_frei_ab'] ) . '"'
       . ' style="background:#f5f7f4;border-radius:10px;padding:12px;font-size:14px;margin-bottom:12px"></div>'
       . '<div style="font-size:12px;color:#777;margin-bottom:12px">Die Laufzeit ist nach dem Abschluss fest. Nach der Laufzeit verlängert sich dein Abo auf unbestimmte Zeit und ist dann monatlich kündbar. Kündigen kannst du jederzeit mit einem Klick in deinem Kundenkonto – wirksam zum Laufzeitende. Preise je Lieferung entsprechen den aktuellen Shop-Preisen abzüglich deines Abo-Rabatts.</div>'
       . '<form method="post"><input type="hidden" name="bschi_abo_start" value="1">'
       . wp_nonce_field( 'bschi_abo_start', 'bschi_abo_nonce', true, false )
       . '<input type="hidden" name="bschi_abo_int_f" id="bschi-abo-int-f" value="1">'
       . '<input type="hidden" name="bschi_abo_lz_f" id="bschi-abo-lz-f" value="3">'
       . '<button type="submit" style="padding:13px 26px;border:0;border-radius:10px;background:#5a6b52;color:#fff;font-weight:700;cursor:pointer">Abo abschließen &amp; zur Kasse</button></form>';
    ?>
    <script>
    (function(){
      var nonce='<?php echo esc_js( wp_create_nonce( 'bschi_abo' ) ); ?>';
      var ajax='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
      function post(was,pid,menge){var fd=new FormData();fd.append('action','bschi_abo_liste');fd.append('nonce',nonce);
        fd.append('was',was);fd.append('pid',pid);if(menge)fd.append('menge',menge);
        return fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'});}
      function summe(){
        var box=document.getElementById('bschi-abo-summe');if(!box)return;
        var w=0;document.querySelectorAll('#bschi-abo-items [data-pid]').forEach(function(r){
          var preis=0;/* Preise stehen serverseitig fest; wir nutzen data-warenwert als Basis */});
        w=parseFloat(box.dataset.warenwert)||0;
        var lz=document.getElementById('bschi-abo-lz');
        var rab=parseFloat(lz.options[lz.selectedIndex].dataset.rabatt)||0;
        var nach=w*(1-rab/100);var frei=parseFloat(box.dataset.frei)||0;
        box.innerHTML='Warenwert je Lieferung: <b>'+w.toFixed(2).replace('.',',')+' €</b>'
          +(rab>0?' · mit '+rab+' % Abo-Rabatt: <b>'+nach.toFixed(2).replace('.',',')+' €</b>':'')
          +'<br><span style="font-size:12px;color:#666">'+(nach>=frei?'Versandkostenfrei (ab '+frei+' € Warenwert).':'Versand kommt hinzu – versandkostenfrei ab '+frei+' € Warenwert.')+'</span>';
        document.getElementById('bschi-abo-lz-f').value=lz.value;
        document.getElementById('bschi-abo-int-f').value=document.getElementById('bschi-abo-int').value;
      }
      document.getElementById('bschi-abo-lz').addEventListener('change',summe);
      document.getElementById('bschi-abo-int').addEventListener('change',summe);
      document.querySelectorAll('.bschi-abo-del').forEach(function(b){b.addEventListener('click',function(){
        var row=b.closest('[data-pid]');post('entfernen',row.dataset.pid).then(function(){location.reload();});});});
      document.querySelectorAll('.bschi-abo-qty').forEach(function(i){i.addEventListener('change',function(){
        var row=i.closest('[data-pid]');post('menge',row.dataset.pid,i.value).then(function(){location.reload();});});});
      summe();
    })();
    </script>
    <?php
    echo '</div>';
    return ob_get_clean();
} );

// Abschluss: Abo-Liste in den Warenkorb + Session-Flag, dann Kasse
add_action( 'template_redirect', function () {
    if ( empty( $_POST['bschi_abo_start'] ) || ! bschi_abo_aktiv() || ! is_user_logged_in() ) { return; }
    if ( ! wp_verify_nonce( $_POST['bschi_abo_nonce'] ?? '', 'bschi_abo_start' ) ) { return; }
    $int = in_array( (int) $_POST['bschi_abo_int_f'], [ 1, 2, 3 ], true ) ? (int) $_POST['bschi_abo_int_f'] : 1;
    $lz  = in_array( (int) $_POST['bschi_abo_lz_f'], [ 3, 6, 12 ], true ) ? (int) $_POST['bschi_abo_lz_f'] : 3;
    $liste = bschi_abo_liste_get( get_current_user_id() );
    if ( ! $liste ) { return; }
    WC()->cart->empty_cart();
    foreach ( $liste as $e ) {
        WC()->cart->add_to_cart( (int) $e[0], max( 1, (int) $e[1] ), 0, [], [ 'bschi_abo_item' => 1 ] );
    }
    $cfg = bschi_abo_config();
    $rabatt = (float) ( $cfg['rabatt_staffel'][ (string) $lz ] ?? 0 );
    WC()->session->set( 'bschi_abo', [ 'intervall' => $int, 'laufzeit' => $lz, 'rabatt' => $rabatt ] );
    wp_safe_redirect( wc_get_checkout_url() );
    exit;
} );

// Abo-Rabatt = reduzierter Artikelpreis (steuerlich sauber, kommt so nach Xentral)
add_action( 'woocommerce_before_calculate_totals', function ( $cart ) {
    if ( ! bschi_abo_aktiv() || ! WC()->session ) { return; }
    $abo = WC()->session->get( 'bschi_abo' );
    if ( ! is_array( $abo ) || (float) $abo['rabatt'] <= 0 ) { return; }
    foreach ( $cart->get_cart() as $it ) {
        if ( empty( $it['bschi_abo_item'] ) ) { continue; }
        $p = $it['data'];
        $preis = (float) $p->get_price();
        $p->set_price( round( $preis * ( 1 - (float) $abo['rabatt'] / 100 ), 2 ) );
    }
}, 30 );

// Hinweiszeile an der Kasse, solange ein Abo-Abschluss im Warenkorb liegt
add_action( 'woocommerce_review_order_after_order_total', function () {
    if ( ! bschi_abo_aktiv() || ! WC()->session ) { return; }
    $abo = WC()->session->get( 'bschi_abo' );
    if ( ! is_array( $abo ) ) { return; }
    $int = [ 1 => 'monatlich', 2 => 'alle 2 Monate', 3 => 'alle 3 Monate' ][ (int) $abo['intervall'] ] ?? '';
    echo '<tr><th>Abo</th><td style="font-size:13px">Lieferung ' . esc_html( $int ) . ' · Laufzeit '
       . esc_html( (int) $abo['laufzeit'] ) . ' Monate'
       . ( (float) $abo['rabatt'] > 0 ? ' · ' . esc_html( rtrim( rtrim( number_format( (float) $abo['rabatt'], 1, ',', '' ), '0' ), ',' ) ) . ' % Abo-Rabatt (bereits abgezogen)' : '' )
       . '</td></tr>';
}, 30 );

// Bestellung: Abo-Meta übernehmen + Session leeren
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
    if ( ! bschi_abo_aktiv() || ! WC()->session ) { return; }
    $abo = WC()->session->get( 'bschi_abo' );
    if ( ! is_array( $abo ) ) { return; }
    $hat_abo_item = false;
    foreach ( WC()->cart->get_cart() as $it ) {
        if ( ! empty( $it['bschi_abo_item'] ) ) { $hat_abo_item = true; break; }
    }
    if ( $hat_abo_item ) {
        $order->update_meta_data( '_bschi_abo', $abo );
    }
}, 10, 1 );

add_action( 'woocommerce_thankyou', function () {
    if ( WC()->session ) { WC()->session->set( 'bschi_abo', null ); }
} );

// Zahlungseingang -> Abo im Hub anlegen (idempotent)
add_action( 'woocommerce_order_status_processing', 'bschi_abo_on_paid', 40 );
add_action( 'woocommerce_order_status_completed', 'bschi_abo_on_paid', 40 );
function bschi_abo_on_paid( $order_id ) {
    if ( ! bschi_feature_enabled( 'abo' ) ) { return; }
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_meta( '_bschi_abo_gemeldet' ) ) { return; }
    $abo = $order->get_meta( '_bschi_abo' );
    if ( ! is_array( $abo ) || empty( $abo['laufzeit'] ) ) { return; }
    $items = [];
    foreach ( $order->get_items() as $li ) {
        $p = $li->get_product();
        $items[] = [ 'sku' => ( $p && $p->get_sku() ) ? $p->get_sku() : (string) $li->get_product_id(),
                     'name' => $li->get_name(), 'menge' => (int) $li->get_quantity(),
                     'preis' => (float) $order->get_item_total( $li, true ) ];
    }
    $ep = bschi_hub_url( '/api/v1/shop/abo-abschluss' );
    if ( ! $ep || ! $items ) { return; }
    $u = get_user_by( 'id', $order->get_customer_id() );
    $r = wp_remote_post( $ep, [ 'timeout' => 15, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [
            'email' => $order->get_billing_email(),
            'name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'intervall_monate' => (int) $abo['intervall'], 'laufzeit_monate' => (int) $abo['laufzeit'],
            'zahlart' => (string) $order->get_payment_method(),
            'wc_order_id' => (string) $order->get_order_number(),
            'betrag' => (float) $order->get_total(), 'items' => $items ] ) ] );
    if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
        $d = json_decode( wp_remote_retrieve_body( $r ), true );
        $order->update_meta_data( '_bschi_abo_gemeldet', gmdate( 'c' ) );
        $order->add_order_note( 'Abo gestartet (Office-Abo #' . (int) ( $d['abo_id'] ?? 0 ) . ')' );
        $order->save();
        if ( $u ) { bschi_abo_liste_set( $u->ID, [] ); }   // Abo-Liste leeren
    } else {
        $order->add_order_note( 'ACHTUNG: Abo-Anlage im Office fehlgeschlagen – bitte prüfen.' );
        $order->save();
    }
}

// ── Mein Konto: Tab "Abos" ───────────────────────────────────────────────────
add_action( 'init', function () {
    if ( bschi_feature_enabled( 'abo' ) ) {
        add_rewrite_endpoint( 'abos', EP_ROOT | EP_PAGES );
        if ( ! get_option( 'bschi_abo_flushed' ) ) { flush_rewrite_rules(); update_option( 'bschi_abo_flushed', 1 ); }
    }
} );

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
    if ( ! bschi_abo_aktiv() ) { return $items; }
    $neu = [];
    foreach ( $items as $k => $v ) {
        $neu[ $k ] = $v;
        if ( 'orders' === $k ) { $neu['abos'] = 'Abos'; }
    }
    if ( ! isset( $neu['abos'] ) ) { $neu['abos'] = 'Abos'; }
    return $neu;
} );

add_action( 'woocommerce_account_abos_endpoint', 'bschi_abo_account_tab' );
function bschi_abo_account_tab() {
    if ( ! bschi_abo_aktiv() ) { return; }
    $email = wp_get_current_user()->user_email;
    $ep = bschi_hub_url( '/api/v1/shop/abos' );
    $abos = [];
    if ( $ep ) {
        $r = wp_remote_post( $ep, [ 'timeout' => 10, 'headers' => bschi_hub_headers(),
            'body' => wp_json_encode( [ 'email' => $email ] ) ] );
        if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
            $abos = json_decode( wp_remote_retrieve_body( $r ), true )['items'] ?? [];
        }
    }
    echo '<h3>Deine Abos</h3>';
    echo '<p style="font-size:13px;color:#666"><a href="' . esc_url( home_url( '/abo/' ) ) . '">Zur Abo-Liste</a> – dort stellst du neue Abos zusammen.</p>';
    if ( ! $abos ) { echo '<p>Du hast noch kein Abo.</p>'; return; }
    $st = [ 'aktiv' => [ 'Aktiv', '#27ae60' ], 'gekuendigt' => [ 'Gekündigt', '#e67e22' ],
            'zahlung_offen' => [ 'Zahlung offen', '#c0392b' ], 'abgelaufen' => [ 'Beendet', '#7f8c8d' ],
            'storniert' => [ 'Beendet', '#7f8c8d' ] ];
    $fmt = fn( $iso ) => $iso ? date_i18n( 'd.m.Y', strtotime( $iso ) ) : '—';
    foreach ( $abos as $a ) {
        $s = $st[ $a['status'] ] ?? [ $a['status'], '#7f8c8d' ];
        $beendet = in_array( $a['status'], [ 'abgelaufen', 'storniert' ], true );
        echo '<div style="border:1px solid #e3e3e3;border-radius:12px;padding:14px;margin-bottom:12px' . ( $beendet ? ';opacity:.7' : '' ) . '">'
           . '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:6px">'
           . '<b>Abo #' . (int) $a['id'] . '</b>'
           . '<span style="padding:2px 10px;border-radius:99px;font-size:12px;font-weight:700;color:#fff;background:' . esc_attr( $s[1] ) . '">' . esc_html( $s[0] ) . '</span></div>'
           . '<div style="font-size:13px;color:#555">Lieferung ' . esc_html( $a['intervall_txt'] ) . ' · Laufzeit ' . (int) $a['laufzeit_monate'] . ' Monate'
           . ( ! empty( $a['verlaengert'] ) ? ' (verlängert, monatlich kündbar)' : '' )
           . ( (float) $a['rabatt_pct'] > 0 ? ' · ' . esc_html( rtrim( rtrim( number_format( (float) $a['rabatt_pct'], 1, ',', '' ), '0' ), ',' ) ) . ' % Rabatt' : '' ) . '<br>'
           . 'Nächste Lieferung: <b>' . esc_html( $fmt( $a['naechste_lieferung'] ) ) . '</b>'
           . ( $a['ende_am'] ? ' · Ende: ' . esc_html( $fmt( $a['ende_am'] ) ) : '' ) . '</div>'
           . '<ul style="font-size:13px;margin:8px 0 10px 18px">';
        foreach ( $a['items'] as $i ) {
            echo '<li>' . esc_html( $i['name'] ?: $i['sku'] ) . ' &times; ' . (int) $i['menge'] . '</li>';
        }
        echo '</ul>';
        if ( ! $beendet ) {
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap">'
               . '<button type="button" class="button bschi-abo-skip" data-id="' . (int) $a['id'] . '">Nächste Lieferung überspringen</button>'
               . ( 'gekuendigt' === $a['status'] ? '' :
                   '<button type="button" class="button bschi-abo-cancel" data-id="' . (int) $a['id'] . '" style="color:#a00;border-color:#a00">Abo kündigen (zum Laufzeitende)</button>' )
               . '</div>';
        }
        echo '</div>';
    }
    ?>
    <script>
    (function(){
      var nonce='<?php echo esc_js( wp_create_nonce( 'bschi_abo_acct' ) ); ?>';
      function call(action,id,confirmTxt){
        if(confirmTxt&&!confirm(confirmTxt))return;
        var fd=new FormData();fd.append('action',action);fd.append('abo_id',id);fd.append('nonce',nonce);
        fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(d){
          if(d&&d.success){alert(d.data||'Erledigt.');location.reload();}
          else{alert((d&&d.data)||'Fehler');}
        });
      }
      document.querySelectorAll('.bschi-abo-skip').forEach(function(b){b.addEventListener('click',function(){
        call('bschi_abo_skip',b.dataset.id,'Die nächste Lieferung wird übersprungen. Fortfahren?');});});
      document.querySelectorAll('.bschi-abo-cancel').forEach(function(b){b.addEventListener('click',function(){
        call('bschi_abo_cancel',b.dataset.id,'Dein Abo wird zum Laufzeitende gekündigt und läuft bis dahin normal weiter. Jetzt kündigen?');});});
    })();
    </script>
    <?php
}

function bschi_abo_acct_action( string $pfad, string $ok_msg ): void {
    if ( ! bschi_abo_aktiv() || ! is_user_logged_in()
         || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bschi_abo_acct' ) ) {
        wp_send_json_error( 'Sitzung abgelaufen' );
    }
    $ep = bschi_hub_url( $pfad );
    if ( ! $ep ) { wp_send_json_error( 'Nicht verfügbar' ); }
    $r = wp_remote_post( $ep, [ 'timeout' => 15, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'email' => wp_get_current_user()->user_email,
                                    'abo_id' => (int) ( $_POST['abo_id'] ?? 0 ) ] ) ] );
    if ( is_wp_error( $r ) ) { wp_send_json_error( 'Verbindungsfehler' ); }
    $d = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( wp_remote_retrieve_response_code( $r ) === 200 && ! empty( $d['ok'] ) ) {
        $msg = $ok_msg;
        if ( ! empty( $d['ende_am'] ) ) { $msg .= ' Dein Abo endet zum ' . date_i18n( 'd.m.Y', strtotime( $d['ende_am'] ) ) . '.'; }
        if ( ! empty( $d['naechste_lieferung'] ) ) { $msg .= ' Nächste Lieferung: ' . date_i18n( 'd.m.Y', strtotime( $d['naechste_lieferung'] ) ) . '.'; }
        wp_send_json_success( $msg );
    }
    wp_send_json_error( $d['detail'] ?? 'Aktion fehlgeschlagen' );
}
add_action( 'wp_ajax_bschi_abo_cancel', fn() => bschi_abo_acct_action( '/api/v1/shop/abo-kuendigen', 'Kündigung bestätigt – du erhältst eine E-Mail.' ) );
add_action( 'wp_ajax_bschi_abo_skip', fn() => bschi_abo_acct_action( '/api/v1/shop/abo-ueberspringen', 'Lieferung übersprungen.' ) );

// ── Shortcode: gesetzliche Kündigungsschaltfläche ────────────────────────────
add_shortcode( 'bsc_abo_kuendigung', function (): string {
    if ( ! bschi_feature_enabled( 'abo' ) ) { return ''; }
    if ( is_user_logged_in() ) {
        return '<p>Du bist angemeldet – deine Abos kündigst du mit einem Klick unter '
             . '<a href="' . esc_url( wc_get_account_endpoint_url( 'abos' ) ) . '">Mein Konto &rarr; Abos</a>.</p>';
    }
    $out = '';
    if ( ! empty( $_POST['bschi_abo_kbtn'] ) && wp_verify_nonce( $_POST['bschi_abo_kbtn_nonce'] ?? '', 'bschi_abo_kbtn' ) ) {
        $email = sanitize_email( $_POST['bschi_kbtn_email'] ?? '' );
        $ep = bschi_hub_url( '/api/v1/shop/abo-kuendigung-verlangen' );
        if ( $email && $ep ) {
            $r = wp_remote_post( $ep, [ 'timeout' => 12, 'headers' => bschi_hub_headers(),
                'body' => wp_json_encode( [ 'email' => $email,
                    'hinweis' => sanitize_text_field( $_POST['bschi_kbtn_hinweis'] ?? '' ) ] ) ] );
            $d = ( ! is_wp_error( $r ) ) ? json_decode( wp_remote_retrieve_body( $r ), true ) : null;
            if ( $d && ! empty( $d['ok'] ) ) {
                return '<div style="background:#eef5ee;border-radius:10px;padding:16px"><b>Deine Kündigung ist eingegangen</b> ('
                     . esc_html( $d['eingegangen_am'] ) . ' Uhr). Wir bestätigen sie dir per E-Mail an '
                     . esc_html( $email ) . ' mit dem Wirksamkeitsdatum.</div>';
            }
        }
        $out = '<p style="color:#a00">Das hat leider nicht geklappt – bitte prüfe die E-Mail-Adresse oder melde dich bei uns.</p>';
    }
    return $out
        . '<form method="post" style="max-width:480px">'
        . '<h3>Verträge hier kündigen</h3>'
        . '<p style="font-size:13px;color:#666">Hier kündigst du dein Produkt-Abo (Kündigungsschaltfläche gem. &sect; 312k BGB). Schneller geht es angemeldet unter Mein Konto &rarr; Abos.</p>'
        . wp_nonce_field( 'bschi_abo_kbtn', 'bschi_abo_kbtn_nonce', true, false )
        . '<input type="hidden" name="bschi_abo_kbtn" value="1">'
        . '<p><label>E-Mail-Adresse deines Kundenkontos<br><input type="email" name="bschi_kbtn_email" required style="width:100%;padding:9px;border:1px solid #ccc;border-radius:6px"></label></p>'
        . '<p><label>Hinweis (optional, z. B. Abo-Nummer)<br><input type="text" name="bschi_kbtn_hinweis" style="width:100%;padding:9px;border:1px solid #ccc;border-radius:6px"></label></p>'
        . '<button type="submit" style="padding:12px 22px;border:0;border-radius:10px;background:#5a6b52;color:#fff;font-weight:700;cursor:pointer">Jetzt kündigen</button></form>';
} );
