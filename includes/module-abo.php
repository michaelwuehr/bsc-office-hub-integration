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
           . '<div id="bschi-abo-msg" style="font-size:12px;color:#5a6b52;margin-top:6px"></div>'
           . '<div style="font-size:12px;margin-top:2px"><a href="#" id="bschi-abo-addto" style="color:#5a6b52">Zu bestehendem Abo hinzufügen</a>'
           . '<span id="bschi-abo-addto-box" style="display:none"></span></div>';
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
        });
        var link=document.getElementById('bschi-abo-addto');
        if(link)link.addEventListener('click',function(ev){ev.preventDefault();
          var box=document.getElementById('bschi-abo-addto-box');
          var fd=new FormData();fd.append('action','bschi_abo_targets');
          fd.append('nonce','<?php echo esc_js( wp_create_nonce( 'bschi_abo' ) ); ?>');
          link.textContent='…';
          fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin'})
          .then(function(r){return r.json();}).then(function(d){
            if(!d||!d.success||!d.data.length){link.textContent='Kein laufendes Abo vorhanden';return;}
            link.style.display='none';box.style.display='';
            var sel=document.createElement('select');sel.style.cssText='padding:6px;border:1px solid #ccc;border-radius:6px;max-width:240px';
            d.data.forEach(function(z){var o=document.createElement('option');o.value=z.id;o.textContent=z.label;sel.appendChild(o);});
            var ok=document.createElement('button');ok.type='button';ok.textContent='Hinzufügen';
            ok.style.cssText='margin-left:6px;padding:6px 12px;border:0;border-radius:6px;background:#5a6b52;color:#fff;cursor:pointer';
            ok.addEventListener('click',function(){
              var fd2=new FormData();fd2.append('action','bschi_abo_items');fd2.append('abo_id',sel.value);
              fd2.append('hinzu_pid',b.dataset.pid);
              var q=document.querySelector('form.cart input.qty');fd2.append('hinzu_menge',q&&q.value?q.value:'1');
              fd2.append('nonce','<?php echo esc_js( wp_create_nonce( 'bschi_abo' ) ); ?>');
              ok.disabled=true;ok.textContent='…';
              fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd2,credentials:'same-origin'})
              .then(function(r){return r.json();}).then(function(d2){
                box.innerHTML=(d2&&d2.success)?'In dein Abo übernommen – gilt ab der nächsten Lieferung.':((d2&&d2.data)||'Fehler');});
            });
            box.appendChild(sel);box.appendChild(ok);
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
           . '<div style="margin:8px 0 10px">';
        $mehrere = count( $a['items'] ) > 1;
        foreach ( $a['items'] as $i ) {
            echo '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f0f0f0;font-size:13px">'
               . '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis">' . esc_html( $i['name'] ?: $i['sku'] ) . '</span>';
            if ( $beendet ) {
                echo '<span>&times; ' . (int) $i['menge'] . '</span>';
            } else {
                echo '<input type="number" class="bschi-abo-item-qty" data-abo="' . (int) $a['id'] . '" data-item="' . (int) $i['id'] . '"'
                   . ' value="' . (int) $i['menge'] . '" min="1" max="50" style="width:64px;padding:5px;border:1px solid #ccc;border-radius:6px">'
                   . ( $mehrere ? '<button type="button" class="bschi-abo-item-del" data-abo="' . (int) $a['id'] . '" data-item="' . (int) $i['id'] . '"'
                       . ' style="border:0;background:none;color:#a00;cursor:pointer;font-size:17px;min-width:32px" title="Aus dem Abo entfernen">&times;</button>' : '' );
            }
            echo '</div>';
        }
        if ( ! $beendet ) {
            echo '<div style="font-size:12px;color:#777;margin-top:5px">Änderungen gelten ab der nächsten Lieferung. Neue Produkte fügst du über die Produktseiten hinzu (Abonnieren &rarr; „Zu bestehendem Abo").</div>';
        }
        echo '</div>';
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
      function itemsCall(fd){fd.append('action','bschi_abo_items');fd.append('nonce',nonce);
        fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();}).then(function(d){
          if(d&&d.success){location.reload();}else{alert((d&&d.data)||'Fehler');}});}
      document.querySelectorAll('.bschi-abo-item-qty').forEach(function(i){i.addEventListener('change',function(){
        var fd=new FormData();fd.append('abo_id',i.dataset.abo);fd.append('item_id',i.dataset.item);fd.append('menge',i.value);itemsCall(fd);});});
      document.querySelectorAll('.bschi-abo-item-del').forEach(function(b){b.addEventListener('click',function(){
        if(!confirm('Produkt aus dem Abo entfernen (ab der nächsten Lieferung)?'))return;
        var fd=new FormData();fd.append('abo_id',b.dataset.abo);fd.append('entfernen',b.dataset.item);itemsCall(fd);});});
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

// ── P3: Bestellung -> Abo (Mein Konto -> Bestellungen -> "Als Abo fortsetzen") ──
add_filter( 'woocommerce_my_account_my_orders_actions', function ( $actions, $order ) {
    if ( ! bschi_abo_aktiv() || ! is_user_logged_in() ) { return $actions; }
    $hat_produkt = false;
    foreach ( $order->get_items() as $li ) {
        $p = $li->get_product();
        if ( $p && $p->is_purchasable() && ! $p->is_virtual() ) { $hat_produkt = true; break; }
    }
    if ( $hat_produkt ) {
        $actions['bschi_abo'] = [
            'url'  => wp_nonce_url( add_query_arg( 'bschi_abo_von_bestellung', $order->get_id(),
                                                   home_url( '/abo/' ) ), 'bschi_abo_von_b' ),
            'name' => 'Als Abo fortsetzen',
        ];
    }
    return $actions;
}, 10, 2 );

add_action( 'template_redirect', function () {
    if ( empty( $_GET['bschi_abo_von_bestellung'] ) || ! bschi_abo_aktiv() || ! is_user_logged_in() ) { return; }
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'bschi_abo_von_b' ) ) { return; }
    $order = wc_get_order( (int) $_GET['bschi_abo_von_bestellung'] );
    if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) { return; }
    $uid = get_current_user_id();
    $liste = bschi_abo_liste_get( $uid );
    $drin = array_map( fn( $e ) => (int) $e[0], $liste );
    foreach ( $order->get_items() as $li ) {
        $p = $li->get_product();
        if ( ! $p || ! $p->is_purchasable() || $p->is_virtual() ) { continue; }
        $pid = $p->get_id();
        if ( in_array( $pid, $drin, true ) ) { continue; }
        $liste[] = [ $pid, max( 1, (int) $li->get_quantity() ) ];
        $drin[] = $pid;
    }
    bschi_abo_liste_set( $uid, $liste );
    wp_safe_redirect( remove_query_arg( [ 'bschi_abo_von_bestellung', '_wpnonce' ] ) );
    exit;
}, 9 );

// ── P3: Produkte/Mengen eines laufenden Abos ändern (Mein Konto) ─────────────
add_action( 'wp_ajax_bschi_abo_items', function () {
    $n = $_POST['nonce'] ?? '';
    if ( ! bschi_abo_aktiv() || ! is_user_logged_in()
         || ( ! wp_verify_nonce( $n, 'bschi_abo_acct' ) && ! wp_verify_nonce( $n, 'bschi_abo' ) ) ) {
        wp_send_json_error( 'Sitzung abgelaufen' );
    }
    $body = [ 'email' => wp_get_current_user()->user_email,
              'abo_id' => (int) ( $_POST['abo_id'] ?? 0 ) ];
    if ( isset( $_POST['entfernen'] ) ) { $body['entfernen'] = [ (int) $_POST['entfernen'] ]; }
    if ( isset( $_POST['item_id'], $_POST['menge'] ) ) {
        $body['mengen'] = [ [ 'item_id' => (int) $_POST['item_id'], 'menge' => (int) $_POST['menge'] ] ];
    }
    if ( ! empty( $_POST['hinzu_pid'] ) ) {
        $p = wc_get_product( (int) $_POST['hinzu_pid'] );
        if ( ! $p || ! $p->is_purchasable() ) { wp_send_json_error( 'Produkt nicht verfügbar' ); }
        $body['hinzu'] = [ [ 'sku' => $p->get_sku() ?: (string) $p->get_id(), 'name' => $p->get_name(),
                             'menge' => max( 1, (int) ( $_POST['hinzu_menge'] ?? 1 ) ),
                             'preis' => (float) wc_get_price_including_tax( $p ) ] ];
    }
    $ep = bschi_hub_url( '/api/v1/shop/abo-items' );
    if ( ! $ep ) { wp_send_json_error( 'Nicht verfügbar' ); }
    $r = wp_remote_post( $ep, [ 'timeout' => 15, 'headers' => bschi_hub_headers(),
                                'body' => wp_json_encode( $body ) ] );
    if ( is_wp_error( $r ) ) { wp_send_json_error( 'Verbindungsfehler' ); }
    $d = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( wp_remote_retrieve_response_code( $r ) === 200 && ! empty( $d['ok'] ) ) {
        wp_send_json_success( 'Gespeichert – gilt ab der nächsten Lieferung.' );
    }
    wp_send_json_error( $d['detail'] ?? 'Änderung fehlgeschlagen' );
} );

// Produktseite: "Zu bestehendem Abo hinzufügen" (Ziele lazy laden)
add_action( 'wp_ajax_bschi_abo_targets', function () {
    if ( ! bschi_abo_aktiv() || ! wp_verify_nonce( $_POST['nonce'] ?? '', 'bschi_abo' ) ) {
        wp_send_json_error( 'Sitzung abgelaufen' );
    }
    $ep = bschi_hub_url( '/api/v1/shop/abos' );
    if ( ! $ep ) { wp_send_json_error( 'Nicht verfügbar' ); }
    $r = wp_remote_post( $ep, [ 'timeout' => 10, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'email' => wp_get_current_user()->user_email ] ) ] );
    if ( is_wp_error( $r ) ) { wp_send_json_error( 'Verbindungsfehler' ); }
    $abos = json_decode( wp_remote_retrieve_body( $r ), true )['items'] ?? [];
    $ziele = [];
    foreach ( $abos as $a ) {
        if ( in_array( $a['status'], [ 'aktiv', 'gekuendigt' ], true ) ) {
            $ziele[] = [ 'id' => (int) $a['id'],
                         'label' => 'Abo #' . (int) $a['id'] . ' (' . $a['intervall_txt'] . ', ' . count( $a['items'] ) . ' Produkte)' ];
        }
    }
    wp_send_json_success( $ziele );
} );

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

// ── P4: Abo-Lieferung -> echte WC-Bestellung (Hub-Scheduler ruft diese Route) ──
add_action( 'rest_api_init', function () {
    register_rest_route( 'bschi/v1', '/abo-lieferung', [
        'methods'             => 'POST',
        'callback'            => 'bschi_abo_lieferung_anlegen',
        'permission_callback' => 'bschi_voucher_permission',   // X-BSCHI-Secret (hash_equals)
    ] );
} );

function bschi_abo_lieferung_anlegen( WP_REST_Request $request ) {
    if ( ! function_exists( 'wc_create_order' ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'WooCommerce nicht aktiv' ], 503 );
    }
    if ( ! bschi_feature_enabled( 'abo' ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'Abo-Feature im Plugin deaktiviert' ], 403 );
    }
    $email  = sanitize_email( (string) $request->get_param( 'email' ) );
    $items  = $request->get_param( 'items' );
    $rabatt = max( 0.0, min( 90.0, (float) $request->get_param( 'rabatt_pct' ) ) );
    $zahlart = sanitize_text_field( (string) $request->get_param( 'zahlart' ) ) ?: 'rechnung';
    $abo_id = (int) $request->get_param( 'abo_id' );
    $lief_id = (int) $request->get_param( 'lieferung_id' );
    $frei_ab = (float) $request->get_param( 'versand_frei_ab' );
    $versand_brutto = (float) $request->get_param( 'versandkosten' );
    if ( ! $email || ! is_array( $items ) || ! $items || ! $abo_id || ! $lief_id ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => 'email, items, abo_id, lieferung_id erforderlich' ], 400 );
    }

    // Idempotenz: existiert schon eine Bestellung zu dieser Lieferung?
    $vorhanden = wc_get_orders( [ 'limit' => 1, 'meta_key' => '_bschi_abo_lieferung_id',
                                  'meta_value' => (string) $lief_id ] );
    if ( $vorhanden ) {
        $o = $vorhanden[0];
        return new WP_REST_Response( [ 'ok' => true, 'duplikat' => true,
            'order_id' => $o->get_order_number(), 'betrag' => (float) $o->get_total(),
            'pay_url' => $o->needs_payment() ? $o->get_checkout_payment_url() : '' ], 200 );
    }

    $user = get_user_by( 'email', $email );
    try {
        $order = wc_create_order( [ 'customer_id' => $user ? $user->ID : 0 ] );
        $fehlend = [];
        foreach ( $items as $i ) {
            $sku = sanitize_text_field( (string) ( $i['sku'] ?? '' ) );
            $menge = max( 1, min( 50, (int) ( $i['menge'] ?? 1 ) ) );
            $pid = wc_get_product_id_by_sku( $sku );
            if ( ! $pid && ctype_digit( $sku ) ) { $pid = (int) $sku; }
            $p = $pid ? wc_get_product( $pid ) : null;
            if ( ! $p || ! $p->is_purchasable() ) { $fehlend[] = $sku; continue; }
            $item_id = $order->add_product( $p, $menge );
            if ( $rabatt > 0 && $item_id ) {
                $li = $order->get_item( $item_id );
                $li->set_subtotal( (float) $li->get_subtotal() );   // Originalpreis als Streichpreis
                $li->set_total( round( (float) $li->get_subtotal() * ( 1 - $rabatt / 100 ), 4 ) );
                $li->save();
            }
        }
        if ( ! $order->get_items() ) {
            $order->delete( true );
            return new WP_REST_Response( [ 'ok' => false,
                'error' => 'Kein Produkt lieferbar (' . implode( ', ', $fehlend ) . ')' ], 409 );
        }

        // Adresse: WC-Kundenprofil, sonst letzte Bestellung dieser E-Mail
        $addr = [];
        if ( $user ) {
            $c = new WC_Customer( $user->ID );
            $addr = array_filter( $c->get_billing() );
        }
        if ( empty( $addr['address_1'] ) ) {
            $letzte = wc_get_orders( [ 'limit' => 1, 'billing_email' => $email,
                                       'orderby' => 'date', 'order' => 'DESC' ] );
            if ( $letzte ) { $addr = array_filter( $letzte[0]->get_address( 'billing' ) ); }
        }
        $addr['email'] = $email;
        $order->set_address( $addr, 'billing' );
        if ( ! empty( $addr['address_1'] ) ) { $order->set_address( $addr, 'shipping' ); }

        // Versand: frei ab Schwelle (Warenwert inkl. USt), sonst Pauschale (brutto -> netto 19 %)
        $order->calculate_totals();
        if ( $versand_brutto > 0 && (float) $order->get_total() < $frei_ab ) {
            $ship = new WC_Order_Item_Shipping();
            $ship->set_method_title( 'Versand' );
            $ship->set_total( round( $versand_brutto / 1.19, 4 ) );
            $order->add_item( $ship );
        }
        $order->calculate_totals();

        $order->update_meta_data( '_bschi_abo_lieferung_id', (string) $lief_id );
        $order->update_meta_data( '_bschi_abo_id', (string) $abo_id );
        $order->set_created_via( 'bschi-abo' );
        $order->add_order_note( 'Abo-Lieferung (Office-Abo #' . $abo_id . ', Lieferung #' . $lief_id
            . ( $rabatt > 0 ? ', ' . $rabatt . ' % Abo-Rabatt' : '' ) . ')' );
        if ( 'rechnung' === $zahlart ) {
            $order->set_payment_method_title( 'Rechnung' );
            $order->set_status( 'processing', 'Abo auf Rechnung – Zahlung per Rechnung.' );
        } else {
            $order->set_payment_method_title( 'paypal' === $zahlart ? 'PayPal' : 'Vorkasse' );
            $order->set_status( 'pending' );
        }
        $order->save();
        return new WP_REST_Response( [ 'ok' => true,
            'order_id' => $order->get_order_number(),
            'betrag' => (float) $order->get_total(),
            'pay_url' => $order->needs_payment() ? $order->get_checkout_payment_url() : '',
            'fehlend' => $fehlend ], 200 );
    } catch ( Throwable $e ) {
        return new WP_REST_Response( [ 'ok' => false, 'error' => substr( $e->getMessage(), 0, 200 ) ], 500 );
    }
}

// Abo-Lieferungs-Bestellungen nie als "unbezahlt" auto-stornieren (Zahllink-Modell)
add_filter( 'woocommerce_cancel_unpaid_order', function ( $cancel, $order ) {
    if ( $order && $order->get_meta( '_bschi_abo_lieferung_id' ) ) { return false; }
    return $cancel;
}, 10, 2 );

// Zahlungseingang einer Abo-Lieferung -> Hub melden (Status bezahlt)
add_action( 'woocommerce_order_status_processing', 'bschi_abo_lieferung_bezahlt', 45 );
add_action( 'woocommerce_order_status_completed', 'bschi_abo_lieferung_bezahlt', 45 );
function bschi_abo_lieferung_bezahlt( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) { return; }
    $lief_id = (int) $order->get_meta( '_bschi_abo_lieferung_id' );
    if ( ! $lief_id || $order->get_meta( '_bschi_abo_lief_gemeldet' ) ) { return; }
    $ep = bschi_hub_url( '/api/v1/shop/abo-lieferung-bezahlt' );
    if ( ! $ep ) { return; }
    $r = wp_remote_post( $ep, [ 'timeout' => 12, 'headers' => bschi_hub_headers(),
        'body' => wp_json_encode( [ 'lieferung_id' => $lief_id,
                                    'wc_order_id' => (string) $order->get_order_number() ] ) ] );
    if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
        $order->update_meta_data( '_bschi_abo_lief_gemeldet', gmdate( 'c' ) );
        $order->save();
    }
}
