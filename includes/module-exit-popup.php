<?php
/**
 * Modul: Exit-Intent-Popup mit Kontext-Regeln (Marketing Hub)
 *
 * Zeigt beim Verlassen der Seite (Desktop: Maus verlässt oben; mobil: schnelles
 * Hochscrollen nach 20 s) ein personalisiertes Angebot. Regeln kommen aus dem
 * Marketing Hub (Zeitfenster, utm_source, Erstbesuch, Verweildauer, Regen am
 * Standort). Goodie erst nach E-Mail-Eingabe – die Anmeldung läuft als
 * Double-Opt-in über CleverReach (Hinweistext im Popup).
 * Frequency-Cap: max. 1× je 7 Tage, nie im Checkout/Warenkorb.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', function (): void {
	if ( ! bschi_feature_enabled( 'exit_popup' ) ) {
		return;
	}
	$s   = bschi_get_settings();
	$hub = rtrim( $s['tracking_hub_url'] ?? '', '/' );
	if ( $hub === '' || is_checkout() ) {
		return;
	}
	// Feed serverseitig holen (Secret bleibt auf dem Server), 10 min Cache
	$feed = get_transient( 'bschi_ep_feed' );
	if ( ! is_array( $feed ) ) {
		$resp = wp_remote_get( $hub . '/api/v1/track/exit-popup', [
			'timeout' => 6,
			'headers' => [ 'X-BSMH-Secret' => $s['tracking_secret'] ?? '' ],
		] );
		$feed = ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 )
			? json_decode( wp_remote_retrieve_body( $resp ), true ) : [];
		set_transient( 'bschi_ep_feed', is_array( $feed ) ? $feed : [], 600 );
	}
	if ( empty( $feed['aktiv'] ) || empty( $feed['regeln'] ) ) {
		return;
	}
	$cart_total = 0.0;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		$cart_total = (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
	}
	$json = wp_json_encode( [
		'regeln'    => $feed['regeln'],
		'wetter'    => $feed['wetter'] ?? [],
		'cartTotal' => round( $cart_total, 2 ),
		'isProduct' => function_exists( 'is_product' ) && is_product(),
	] );
	?>
	<div id="bschi-ep" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(26,26,24,.55);align-items:center;justify-content:center;padding:16px">
	  <div style="background:#fff;border-radius:16px;max-width:420px;width:100%;padding:26px 24px;box-shadow:0 18px 60px rgba(0,0,0,.35);position:relative;font-family:inherit">
	    <button type="button" id="bschi-ep-close" style="position:absolute;top:10px;right:12px;border:0;background:none;font-size:20px;cursor:pointer;color:#999">✕</button>
	    <div id="bschi-ep-headline" style="font-size:20px;font-weight:700;margin-bottom:8px"></div>
	    <div id="bschi-ep-text" style="font-size:14.5px;line-height:1.5;color:#444;margin-bottom:14px"></div>
	    <div id="bschi-ep-form">
	      <input type="email" id="bschi-ep-mail" placeholder="deine@email.de" style="width:100%;padding:11px 12px;border:1px solid #d8d0c4;border-radius:9px;font-size:14px;box-sizing:border-box">
	      <button type="button" id="bschi-ep-send" style="width:100%;margin-top:8px;padding:12px;border:0;border-radius:9px;background:#4b5a42;color:#fff;font-size:14.5px;font-weight:700;cursor:pointer"></button>
	      <div style="font-size:11px;color:#888;margin-top:8px;line-height:1.45">Mit dem Absenden meldest du dich zu unserem Newsletter an (Double-Opt-in – du bestätigst per E-Mail und kannst dich jederzeit abmelden). <a href="/datenschutz/" target="_blank" style="color:#888">Datenschutz</a></div>
	    </div>
	    <div id="bschi-ep-done" style="display:none;font-size:14.5px;line-height:1.55"></div>
	  </div>
	</div>
	<script>
	(function(){
	  var CFG = <?php echo $json; ?>;
	  var LS = window.localStorage, SS = window.sessionStorage;
	  try {
	    if (LS.getItem('bschi_ep_last') && Date.now() - parseInt(LS.getItem('bschi_ep_last'), 10) < 7*24*3600*1000) return;
	    if (LS.getItem('bschi_ep_abo')) return; // bereits angemeldet
	    var utm = new URLSearchParams(location.search).get('utm_source');
	    if (utm) SS.setItem('bschi_utm', utm.toLowerCase());
	    var erst = !LS.getItem('bschi_seen');
	    LS.setItem('bschi_seen', '1');
	  } catch (e) { return; }
	  var t0 = Date.now(), shown = false;
	  function minsOnSite(){ return (Date.now() - t0) / 60000; }
	  function ruleMatch(r){
	    switch (r.trigger) {
	      case 'erstbesuch': return erst;
	      case 'utm': return (SS.getItem('bschi_utm') || '') === String(r.param || '').toLowerCase();
	      case 'verweildauer': return minsOnSite() >= (parseFloat(r.param) || 8);
	      case 'wetter_regen': return !!(CFG.wetter && CFG.wetter.regen);
	      case 'warenkorb_min': return CFG.cartTotal > 0 && CFG.cartTotal < (parseFloat(r.param) || 45);
	      case 'produkt_zeit': return CFG.isProduct && minsOnSite() >= (parseFloat(r.param) || 2);
	      case 'wiederkehrend': return !erst;
	      case 'zeit': {
	        var m = String(r.param || '').toLowerCase().match(/^([a-z,]+)\s+(\d{1,2})-(\d{1,2})$/);
	        if (!m) return false;
	        var tage = { mo:1, di:2, mi:3, do:4, fr:5, sa:6, so:0 }, now = new Date();
	        var ok = m[1].split(',').some(function(t){ return tage[t] === now.getDay(); });
	        return ok && now.getHours() >= parseInt(m[2], 10) && now.getHours() < parseInt(m[3], 10);
	      }
	    }
	    return false;
	  }
	  function pick(){
	    return CFG.regeln.filter(ruleMatch).sort(function(a, b){ return (a.prioritaet || 10) - (b.prioritaet || 10); })[0] || null;
	  }
	  function fillText(r, s){
	    var schwelle = parseFloat(r.param) || 0;
	    var rest = Math.max(0, schwelle - (CFG.cartTotal || 0));
	    return String(s || '').replace('{rest}', rest.toFixed(2).replace('.', ',') + ' €')
	                          .replace('{schwelle}', schwelle.toFixed(2).replace('.', ',') + ' €');
	  }
	  function showSlideIn(r){
	    if (document.getElementById('bschi-si')) return;
	    try { if (LS.getItem('bschi_si_' + r.id) && Date.now() - parseInt(LS.getItem('bschi_si_' + r.id), 10) < 24*3600*1000) return;
	          LS.setItem('bschi_si_' + r.id, String(Date.now())); } catch (e) {}
	    var d = document.createElement('div'); d.id = 'bschi-si';
	    d.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99998;background:#fff;border:1px solid #d8d0c4;border-radius:14px;box-shadow:0 10px 36px rgba(0,0,0,.22);padding:16px 18px;max-width:320px;font-size:13.5px;line-height:1.5';
	    d.innerHTML = '<button type="button" style="position:absolute;top:6px;right:9px;border:0;background:none;font-size:16px;cursor:pointer;color:#999">✕</button>'
	      + '<b>' + fillText(r, r.headline || '') + '</b><div style="margin-top:4px;color:#444">' + fillText(r, r.text || '') + '</div>'
	      + (r.gutschein_code && r.email_gate === false ? '<div style="margin-top:8px">Code: <b style="font-family:monospace">' + r.gutschein_code + '</b></div>' : '')
	      + (r.email_gate !== false ? '<button type="button" id="bschi-si-cta" style="margin-top:10px;padding:9px 14px;border:0;border-radius:8px;background:#4b5a42;color:#fff;font-weight:700;cursor:pointer">' + (r.gutschein_text ? r.gutschein_text + ' sichern' : 'Mehr erfahren') + '</button>' : '');
	    document.body.appendChild(d);
	    d.querySelector('button').onclick = function(){ d.remove(); };
	    var cta = d.querySelector('#bschi-si-cta');
	    if (cta) cta.onclick = function(){ d.remove(); shown = false; showPopup(r); };
	  }
	  function showCartBar(r){
	    if (document.getElementById('bschi-cb')) return;
	    var d = document.createElement('div'); d.id = 'bschi-cb';
	    d.style.cssText = 'position:fixed;left:0;right:0;top:0;z-index:99998;background:#4b5a42;color:#fff;text-align:center;padding:9px 40px;font-size:13.5px';
	    d.innerHTML = fillText(r, (r.headline ? r.headline + ' – ' : '') + (r.text || ''))
	      + '<button type="button" style="position:absolute;right:10px;top:6px;border:0;background:none;color:#fff;font-size:15px;cursor:pointer">✕</button>';
	    document.body.appendChild(d);
	    d.querySelector('button').onclick = function(){ d.remove(); try { SS.setItem('bschi_cb_off', '1'); } catch (e) {} };
	  }
	  function showPopup(r){
	    if (shown) return;
	    shown = true;
	    try { LS.setItem('bschi_ep_last', String(Date.now())); } catch (e) {}
	    var ep = document.getElementById('bschi-ep');
	    document.getElementById('bschi-ep-headline').textContent = fillText(r, r.headline || 'Bevor du gehst …');
	    document.getElementById('bschi-ep-text').textContent = fillText(r, r.text || '');
	    document.getElementById('bschi-ep-send').textContent = (r.gutschein_text ? r.gutschein_text + ' sichern' : 'Jetzt anmelden');
	    ep.style.display = 'flex';
	    document.getElementById('bschi-ep-close').onclick = function(){ ep.style.display = 'none'; };
	    ep.onclick = function(e){ if (e.target === ep) ep.style.display = 'none'; };
	    document.getElementById('bschi-ep-send').onclick = function(){
	      var mail = (document.getElementById('bschi-ep-mail').value || '').trim();
	      if (!/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(mail)) { document.getElementById('bschi-ep-mail').style.borderColor = '#c0392b'; return; }
	      var btn = document.getElementById('bschi-ep-send');
	      btn.disabled = true; btn.textContent = '⏳ …';
	      fetch('<?php echo esc_js( rest_url( 'bschi/v1/exit-popup' ) ); ?>', {
	        method: 'POST', headers: { 'Content-Type': 'application/json' },
	        body: JSON.stringify({ email: mail, regel_id: r.id || '' })
	      }).then(function(res){ return res.json().catch(function(){ return {}; }).then(function(d){ return { ok: res.ok, d: d }; }); })
	      .then(function(x){
	        if (!x.ok) throw new Error((x.d && (x.d.message || x.d.detail)) || 'Fehler');
	        try { LS.setItem('bschi_ep_abo', '1'); } catch (e) {}
	        document.getElementById('bschi-ep-form').style.display = 'none';
	        var done = document.getElementById('bschi-ep-done');
	        done.innerHTML = 'Fast geschafft! Bitte bestätige deine Anmeldung über den Link in deinem Postfach.'
	          + (r.gutschein_code ? '<br><br>Dein Code: <b style="font-family:monospace;font-size:16px">' + r.gutschein_code + '</b><br><span style="font-size:12px;color:#666">' + (r.gutschein_text || '') + '</span>' : '');
	        done.style.display = 'block';
	      }).catch(function(e){ btn.disabled = false; btn.textContent = 'Erneut versuchen'; });
	    };
	  }
	  function show(){
	    var r = CFG.regeln.filter(function(x){ return (x.anzeige || 'exit') === 'exit' && ruleMatch(x); })
	      .sort(function(a, b){ return (a.prioritaet || 10) - (b.prioritaet || 10); })[0];
	    if (r) showPopup(r);
	  }
	  // Passive Anzeigen (Slide-in / Leiste): beim Laden + nach Verweildauer prüfen
	  function passive(){
	    CFG.regeln.filter(function(x){ return (x.anzeige || 'exit') !== 'exit' && ruleMatch(x); })
	      .sort(function(a, b){ return (a.prioritaet || 10) - (b.prioritaet || 10); })
	      .slice(0, 1).forEach(function(r){
	        if ((r.anzeige || '') === 'cartbar') { if (!SS.getItem('bschi_cb_off')) showCartBar(r); }
	        else showSlideIn(r);
	      });
	  }
	  setTimeout(passive, 1500);
	  setInterval(passive, 30000);
	  document.addEventListener('mouseout', function(e){
	    if (!e.toElement && !e.relatedTarget && e.clientY < 10) show();
	  });
	  var lastY = 0, lastT = 0;
	  window.addEventListener('scroll', function(){
	    var y = window.scrollY, t = Date.now();
	    if (minsOnSite() > 0.33 && lastY - y > 500 && t - lastT < 700 && y < 900) show();
	    lastY = y; lastT = t;
	  }, { passive: true });
	})();
	</script>
	<?php
} );

// Proxy: Browser → WP → Marketing Hub (Secret bleibt serverseitig)
add_action( 'rest_api_init', function (): void {
	register_rest_route( 'bschi/v1', '/exit-popup', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$s    = bschi_get_settings();
			$hub  = rtrim( $s['tracking_hub_url'] ?? '', '/' );
			$resp = wp_remote_post( $hub . '/api/v1/track/exit-popup/anmelden', [
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json', 'X-BSMH-Secret' => $s['tracking_secret'] ?? '' ],
				'body'    => wp_json_encode( [
					'email'    => sanitize_email( (string) $req->get_param( 'email' ) ),
					'regel_id' => sanitize_text_field( (string) $req->get_param( 'regel_id' ) ),
				] ),
			] );
			if ( is_wp_error( $resp ) ) {
				return new WP_REST_Response( [ 'message' => 'Hub nicht erreichbar' ], 502 );
			}
			$code = wp_remote_retrieve_response_code( $resp );
			return new WP_REST_Response( json_decode( wp_remote_retrieve_body( $resp ), true ), $code ?: 502 );
		},
	] );
} );
