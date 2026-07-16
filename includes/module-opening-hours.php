<?php
/**
 * Modul: Öffnungszeiten-Mini-Banner  [bsc_offen_banner]
 *
 * Zeigt tagesaktuell und in Besucher-Echtzeit, ob und wo die Läden geöffnet sind:
 *   „Jetzt geöffnet in Schweinhütt (bis 16:00)"
 *   „Beide Läden geschlossen – wir öffnen morgen 10–16 Uhr in Schweinhütt"
 * Daten kommen aus dem Office Hub (Laden → Öffnungszeiten), inkl. bayerischer
 * Feiertage und Ausnahme-Tagen. Die Statuslogik läuft clientseitig (JS) –
 * der Feed wird 15 Minuten gecacht.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BSCHI_OZ_CACHE_TTL = 900;

add_shortcode( 'bsc_offen_banner', function ( $atts ): string {
	bschi_report_link( 'offen_banner' );
	if ( ! bschi_feature_enabled( 'offen_banner' ) ) {
		return '';
	}
	$a = shortcode_atts( [ 'standort' => '' ], $atts, 'bsc_offen_banner' ); // leer = alle
	$data = bschi_hub_get( '/api/v1/shop/oeffnungszeiten', BSCHI_OZ_CACHE_TTL );
	if ( empty( $data['standorte'] ) ) {
		return '';
	}
	if ( $a['standort'] !== '' ) {
		$data['standorte'] = array_values( array_filter( $data['standorte'],
			fn( $s ) => ( $s['id'] ?? '' ) === $a['standort'] ) );
	}
	$json = wp_json_encode( $data );
	$id   = 'bschi-oz-' . wp_generate_password( 6, false );
	ob_start();
	?>
	<div class="bschi-oz" id="<?php echo esc_attr( $id ); ?>" style="display:none;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;font-size:14px;line-height:1.4;background:#f4f1ec;border:1px solid #d8d0c4;color:#1a1a18">
		<span class="bschi-oz-dot" style="width:9px;height:9px;border-radius:50%;flex-shrink:0;background:#999"></span>
		<span class="bschi-oz-text"></span>
	</div>
	<script>
	(function(){
		var data = <?php echo $json; ?>;
		var el = document.getElementById('<?php echo esc_js( $id ); ?>');
		if (!el || !data.standorte || !data.standorte.length) return;
		var TAGE = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
		var TAGE_DE = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
		function pad(n){ return (n < 10 ? '0' : '') + n; }
		function dstr(d){ return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
		function blocksFor(s, d){
			var ds = dstr(d);
			if ((data.feiertage || []).indexOf(ds) >= 0) return [];
			var ex = (s.ausnahmen || []).filter(function(a){ return a.datum === ds; })[0];
			if (ex) return ex.zeiten || [];
			return (s.week || {})[TAGE[d.getDay()]] || [];
		}
		function mins(t){ var p = t.split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1], 10); }
		function kurz(t){ return t.replace(/^0/, ''); }
		var now = new Date(), nowM = now.getHours() * 60 + now.getMinutes();
		var offen = [], naechste = null;
		data.standorte.forEach(function(s){
			var name = (s.label || '').replace('Laden ', '');
			blocksFor(s, now).forEach(function(b){
				if (nowM >= mins(b[0]) && nowM < mins(b[1])) offen.push({ name: name, bis: b[1] });
			});
			// nächste Öffnung in den kommenden 14 Tagen suchen
			for (var i = 0; i <= 14; i++) {
				var d = new Date(now); d.setDate(d.getDate() + i);
				var bs = blocksFor(s, d);
				for (var j = 0; j < bs.length; j++) {
					if (i === 0 && nowM >= mins(bs[j][0])) continue; // heute schon vorbei/laufend
					var cand = { tage: i, von: bs[j][0], bis: bs[j][1], name: name, dow: d.getDay() };
					if (!naechste || cand.tage < naechste.tage ||
						(cand.tage === naechste.tage && mins(cand.von) < mins(naechste.von))) naechste = cand;
					break;
				}
				if (naechste && naechste.tage <= i) break;
			}
		});
		var txt, farbe;
		if (offen.length) {
			farbe = '#2e9e5b';
			txt = 'Jetzt geöffnet in ' + offen.map(function(o){ return o.name + ' (bis ' + kurz(o.bis) + ' Uhr)'; }).join(' und ');
		} else if (naechste) {
			farbe = '#c0392b';
			var wann = naechste.tage === 0 ? 'heute' : (naechste.tage === 1 ? 'morgen' : ('am ' + TAGE_DE[naechste.dow]));
			txt = (data.standorte.length > 1 ? 'Beide Läden geschlossen – wir öffnen ' : 'Geschlossen – wir öffnen ')
				+ wann + ' ' + kurz(naechste.von) + '–' + kurz(naechste.bis) + ' Uhr in ' + naechste.name;
		} else {
			farbe = '#c0392b';
			txt = 'Derzeit geschlossen';
		}
		if (data.hinweis) txt += ' · ' + data.hinweis;
		el.querySelector('.bschi-oz-dot').style.background = farbe;
		el.querySelector('.bschi-oz-text').textContent = txt;
		el.style.display = 'flex';
	})();
	</script>
	<?php
	return (string) ob_get_clean();
} );
