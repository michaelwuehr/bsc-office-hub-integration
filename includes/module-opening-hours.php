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
	$a = shortcode_atts( [
		'standort'    => '',        // leer = alle
		'stil'        => 'banner',  // banner (Box) | text (reine Textzeile, passt sich dem Theme an)
		'groesse'     => '',        // Schriftgroesse in px (leer = 14 bzw. Theme bei stil=text)
		'farbe'       => '',        // Textfarbe (leer = #1a1a18 bzw. inherit bei stil=text)
		'farbe_offen' => '#2e9e5b', // Statusfarbe geoeffnet (Punkt)
		'farbe_zu'    => '#c0392b', // Statusfarbe geschlossen (Punkt)
		'punkt'       => 'an',      // an | aus - roter/gruener Punkt
	], $atts, 'bsc_offen_banner' );
	$data = bschi_hub_get( '/api/v1/shop/oeffnungszeiten', BSCHI_OZ_CACHE_TTL );
	if ( empty( $data['standorte'] ) ) {
		return '';
	}
	if ( $a['standort'] !== '' ) {
		$data['standorte'] = array_values( array_filter( $data['standorte'],
			fn( $s ) => ( $s['id'] ?? '' ) === $a['standort'] ) );
	}
	$hex = fn( $v, $std ) => preg_match( '/^#[0-9a-fA-F]{3,8}$/', (string) $v ) ? $v : $std;
	$stil_text = ( $a['stil'] === 'text' );
	$punkt_an  = ( $a['punkt'] !== 'aus' );
	$groesse   = (int) $a['groesse'];
	$groesse   = ( $groesse >= 8 && $groesse <= 48 ) ? $groesse : 0;
	$farbe     = preg_match( '/^#[0-9a-fA-F]{3,8}$/', (string) $a['farbe'] ) ? $a['farbe'] : '';
	$f_offen   = $hex( $a['farbe_offen'], '#2e9e5b' );
	$f_zu      = $hex( $a['farbe_zu'], '#c0392b' );

	if ( $stil_text ) {
		// Reine Textzeile: kein Hintergrund/Rahmen, erbt Theme-Schrift und -Farbe
		$css = 'display:none;align-items:center;gap:7px;line-height:1.4'
			 . ( $groesse ? ';font-size:' . $groesse . 'px' : '' )
			 . ( $farbe ? ';color:' . $farbe : '' );
		$anzeige = $punkt_an ? 'inline-flex' : 'inline';
	} else {
		$css = 'display:none;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;line-height:1.4;'
			 . 'background:#f4f1ec;border:1px solid #d8d0c4'
			 . ';font-size:' . ( $groesse ?: 14 ) . 'px'
			 . ';color:' . ( $farbe ?: '#1a1a18' );
		$anzeige = 'flex';
	}
	$json = wp_json_encode( $data );
	$cfg  = wp_json_encode( [ 'punkt' => $punkt_an, 'offen' => $f_offen, 'zu' => $f_zu, 'anzeige' => $anzeige ] );
	$id   = 'bschi-oz-' . wp_generate_password( 6, false );
	ob_start();
	?>
	<<?php echo $stil_text ? 'span' : 'div'; ?> class="bschi-oz" id="<?php echo esc_attr( $id ); ?>" style="<?php echo esc_attr( $css ); ?>">
		<?php if ( $punkt_an ) : ?>
		<span class="bschi-oz-dot" style="width:9px;height:9px;border-radius:50%;flex-shrink:0;display:inline-block;background:#999"></span>
		<?php endif; ?>
		<span class="bschi-oz-text"></span>
	</<?php echo $stil_text ? 'span' : 'div'; ?>>
	<script>
	(function(){
		var data = <?php echo $json; ?>;
		var cfg = <?php echo $cfg; ?>;
		var el = document.getElementById('<?php echo esc_js( $id ); ?>');
		if (!el || !data.standorte || !data.standorte.length) return;
		var TAGE = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
		var TAGE_DE = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
		function pad(n){ return (n < 10 ? '0' : '') + n; }
		function dstr(d){ return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
		function blocksFor(s, d){
			var ds = dstr(d), extra = [];
			(data.sonderoeffnungen || []).forEach(function(so){
				if (so.standort === s.id && so.datum === ds) extra.push([so.von, so.bis, so.label || '']);
			});
			var ex = (s.ausnahmen || []).filter(function(a){ return a.datum === ds; })[0];
			if (ex) return (ex.zeiten || []).map(function(b){ return [b[0], b[1], ex.grund || '']; }).concat(extra);
			if ((data.feiertage || []).indexOf(ds) >= 0) return extra; // Feiertag: nur Sonderöffnungen
			return ((s.week || {})[TAGE[d.getDay()]] || []).concat(extra);
		}
		function mins(t){ var p = t.split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1], 10); }
		function kurz(t){ return t.replace(/^0/, ''); }
		var now = new Date(), nowM = now.getHours() * 60 + now.getMinutes();
		var offen = [], naechste = null;
		data.standorte.forEach(function(s){
			var name = (s.label || '').replace('Laden ', '');
			blocksFor(s, now).forEach(function(b){
				if (nowM >= mins(b[0]) && nowM < mins(b[1])) offen.push({ name: name, bis: b[1], label: b[2] || '' });
			});
			// nächste Öffnung in den kommenden 14 Tagen suchen
			for (var i = 0; i <= 14; i++) {
				var d = new Date(now); d.setDate(d.getDate() + i);
				var bs = blocksFor(s, d);
				for (var j = 0; j < bs.length; j++) {
					if (i === 0 && nowM >= mins(bs[j][0])) continue; // heute schon vorbei/laufend
					var cand = { tage: i, von: bs[j][0], bis: bs[j][1], name: name, dow: d.getDay(), label: bs[j][2] || '' };
					if (!naechste || cand.tage < naechste.tage ||
						(cand.tage === naechste.tage && mins(cand.von) < mins(naechste.von))) naechste = cand;
					break;
				}
				if (naechste && naechste.tage <= i) break;
			}
		});
		var txt, farbe;
		if (offen.length) {
			farbe = cfg.offen;
			txt = 'Jetzt geöffnet in ' + offen.map(function(o){ return o.name + (o.label ? ' – ' + o.label : '') + ' (bis ' + kurz(o.bis) + ' Uhr)'; }).join(' und ');
		} else if (naechste) {
			farbe = cfg.zu;
			var wann = naechste.tage === 0 ? 'heute' : (naechste.tage === 1 ? 'morgen' : ('am ' + TAGE_DE[naechste.dow]));
			txt = (data.standorte.length > 1 ? 'Beide Läden geschlossen – wir öffnen ' : 'Geschlossen – wir öffnen ')
				+ wann + ' ' + kurz(naechste.von) + '–' + kurz(naechste.bis) + ' Uhr in ' + naechste.name
				+ (naechste.label ? ' (' + naechste.label + ')' : '');
		} else {
			farbe = cfg.zu;
			txt = 'Derzeit geschlossen';
		}
		if (data.hinweis) txt += ' · ' + data.hinweis;
		var dot = el.querySelector('.bschi-oz-dot');
		if (dot) dot.style.background = farbe;
		el.querySelector('.bschi-oz-text').textContent = txt;
		el.style.display = cfg.anzeige;
	})();
	</script>
	<?php
	return (string) ob_get_clean();
} );
