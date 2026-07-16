/**
 * Woidsiederei First-Party-Tracking (BSC Office Hub Integration, Modul "Tracking").
 *
 * Datenschutz-Grundsätze:
 *  - Startet erst nach Consent "Statistik" (Complianz-Banner; generisches API als Fallback).
 *  - KEINE Cookies (Varnish-/Cache-sicher): visitor_id in localStorage, Session in sessionStorage.
 *  - Respektiert Do-Not-Track und Global Privacy Control.
 *  - Sendet per navigator.sendBeacon (text/plain, kein Preflight) an den Marketing Hub.
 *  - Eingeloggte Shop-Mitarbeiter werden nicht getrackt (Flag vom Server).
 */
(function () {
  'use strict';
  var cfg = window.bschiTrack || {};
  if (!cfg.endpoint || cfg.disabled) return;
  if (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl) return;

  var LS = window.localStorage, SS = window.sessionStorage;
  var queue = [], flushTimer = null, started = false, ctxPushed = false;

  function rnd() {
    var b = new Uint8Array(10);
    (window.crypto || window.msCrypto).getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }

  function utmFromUrl() {
    try {
      var p = new URLSearchParams(location.search);
      if (!p.get('utm_source') && !p.get('utm_campaign')) return null;
      return { s: p.get('utm_source') || '', m: p.get('utm_medium') || '',
               c: p.get('utm_campaign') || '', n: p.get('utm_content') || '' };
    } catch (e) { return null; }
  }

  function getJson(key) { try { return JSON.parse(LS.getItem(key) || 'null'); } catch (e) { return null; } }

  function initIds() {
    var vid = LS.getItem('ws_vid');
    if (!vid) { vid = rnd(); LS.setItem('ws_vid', vid); }
    var now = Date.now();
    var last = parseInt(SS.getItem('ws_last') || '0', 10);
    var sid = SS.getItem('ws_sid');
    if (!sid || now - last > 30 * 60 * 1000) { sid = rnd().slice(0, 16); SS.setItem('ws_sid', sid); }
    SS.setItem('ws_last', String(now));
    // UTM-Persistenz: first einmalig, last bei jedem UTM-Einstieg (90 Tage)
    var u = utmFromUrl();
    if (u) {
      var wrap = { u: u, ts: now };
      if (!getJson('ws_utm_first') || (getJson('ws_utm_first').ts || 0) < now - 90 * 86400000) {
        LS.setItem('ws_utm_first', JSON.stringify(wrap));
      }
      LS.setItem('ws_utm_last', JSON.stringify(wrap));
    }
    if (!LS.getItem('ws_ref0') && document.referrer && document.referrer.indexOf(location.host) < 0) {
      LS.setItem('ws_ref0', document.referrer.slice(0, 500));
    }
    return { vid: vid, sid: sid };
  }

  function utm(kind) {
    var w = getJson(kind === 'first' ? 'ws_utm_first' : 'ws_utm_last');
    return (w && w.ts > Date.now() - 90 * 86400000) ? w.u : null;
  }

  function flush() {
    if (!queue.length) return;
    var ids = initIds();
    var payload = { v: ids.vid, e: queue.splice(0, 25) };
    var uf = utm('first'), ul = utm('last');
    if (uf) payload.uf = uf;
    if (ul) payload.ul = ul;
    var r0 = LS.getItem('ws_ref0');
    if (r0) payload.r0 = r0;
    try {
      var blob = new Blob([JSON.stringify(payload)], { type: 'text/plain' });
      if (!(navigator.sendBeacon && navigator.sendBeacon(cfg.endpoint, blob))) {
        fetch(cfg.endpoint, { method: 'POST', body: blob, keepalive: true, mode: 'cors' }).catch(function () {});
      }
    } catch (e) {}
  }

  function track(type, extra) {
    var ids = initIds();
    var ev = { t: type, s: ids.sid, p: location.pathname + location.search.slice(0, 100) };
    var u = utmFromUrl();
    if (u) ev.u = u;
    if (document.referrer && document.referrer.indexOf(location.host) < 0) ev.r = document.referrer.slice(0, 400);
    if (extra) for (var k in extra) if (extra[k] != null) ev[k] = extra[k];
    queue.push(ev);
    clearTimeout(flushTimer);
    flushTimer = setTimeout(flush, 4000);
    if (queue.length >= 20) flush();
  }

  function pushCtx() {
    // visitor_id + UTM in die WooCommerce-Session heben (Server verknüpft die Bestellung) –
    // funktioniert für klassischen UND Block-Checkout, ohne eigenes Cookie.
    if (ctxPushed || !cfg.ajax) return;
    ctxPushed = true;
    var ids = initIds();
    var fd = new FormData();
    fd.append('action', 'bschi_trk_ctx');
    fd.append('v', ids.vid);
    var uf = utm('first'), ul = utm('last');
    if (uf) fd.append('uf', JSON.stringify(uf));
    if (ul) fd.append('ul', JSON.stringify(ul));
    fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
  }

  function start() {
    if (started) return;
    started = true;
    track('consent', { g: 1, c: 'statistics', b: cfg.consentSource || 'complianz' });
    if (cfg.ctx === 'product' && cfg.sku) {
      track('product_view', { sku: cfg.sku, val: cfg.price || null });
    } else if (cfg.ctx === 'category') {
      track('category_view', { x: { cat: cfg.category || '' } });
    } else if (cfg.ctx === 'checkout') {
      track('begin_checkout', cfg.cartTotal ? { val: cfg.cartTotal } : null);
      pushCtx();
    } else if (cfg.ctx === 'search') {
      track('search', { x: { q: (cfg.searchQuery || '').slice(0, 80) } });
    }
    track('pageview');
    if (cfg.hasCart) pushCtx();
    // WooCommerce-AJAX "in den Warenkorb" / "aus dem Warenkorb entfernt"
    if (window.jQuery) {
      window.jQuery(document.body).on('added_to_cart', function (ev, frags, hash, btn) {
        var sku = btn && btn.data ? (btn.data('product_sku') || String(btn.data('product_id') || '')) : '';
        track('add_to_cart', { sku: sku || null });
        pushCtx();
      });
      window.jQuery(document.body).on('removed_from_cart', function () {
        track('remove_from_cart');
      });
    }
    // Klassisches Produktformular (Einzelprodukt ohne AJAX)
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (f && f.classList && f.classList.contains('cart')) {
        track('add_to_cart', { sku: cfg.sku || null });
        pushCtx();
        flush();
      }
    }, true);
    // Scrolltiefe: maximale Tiefe in % je Pageview, einmalig beim Verlassen gesendet
    var maxScroll = 0, scrollSent = false, scrollTick = false;
    function measureScroll() {
      scrollTick = false;
      var doc = document.documentElement;
      var total = (doc.scrollHeight || 0) - window.innerHeight;
      if (total < 200) return; // kurze Seiten nicht werten
      var d = Math.min(100, Math.round((window.scrollY || doc.scrollTop || 0) / total * 100));
      if (d > maxScroll) maxScroll = d;
    }
    window.addEventListener('scroll', function () {
      if (!scrollTick) { scrollTick = true; requestAnimationFrame(measureScroll); }
    }, { passive: true });
    function sendScroll() {
      if (scrollSent || !started || maxScroll <= 0) return;
      scrollSent = true;
      track('scroll', { val: maxScroll });
    }
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') { sendScroll(); flush(); }
    });
    window.addEventListener('pagehide', function () { sendScroll(); flush(); });
  }

  function revoke() {
    // Widerruf: sofort stoppen + lokale Kennungen löschen (Event geht noch als Protokoll raus)
    if (started) { queue.push({ t: 'consent', g: 0, c: 'statistics', b: cfg.consentSource || 'complianz' }); flush(); }
    started = false;
    try { LS.removeItem('ws_vid'); LS.removeItem('ws_utm_first'); LS.removeItem('ws_utm_last'); LS.removeItem('ws_ref0'); SS.removeItem('ws_sid'); } catch (e) {}
  }

  // ── Consent-Bridge ──────────────────────────────────────────────────────────
  function complianzOk() {
    try { return typeof window.cmplz_has_consent === 'function' && window.cmplz_has_consent('statistics'); }
    catch (e) { return false; }
  }
  document.addEventListener('cmplz_status_change', function () { complianzOk() ? start() : revoke(); });
  document.addEventListener('cmplz_enable_category', function (e) {
    if (e.detail && (e.detail.category === 'statistics' || e.detail === 'statistics')) start();
  });
  // Generisches API (falls Banner wechselt): window.wsConsentGranted() / wsConsentRevoked()
  window.wsConsentGranted = start;
  window.wsConsentRevoked = revoke;
  if (complianzOk() || window.WS_TRACK_CONSENT === true) start();
})();
