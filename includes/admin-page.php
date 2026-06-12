<?php
/**
 * BSC Office Hub Integration – Admin-UI (Settings + Status-Dashboard).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Admin Notices ────────────────────────────────────────────────────────────

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) || ! bschi_feature_enabled( 'monitoring' ) ) {
        return;
    }

    $modules = [
        BSCHI_OPTION_CACHE    => 'Cache Integrity',
        BSCHI_OPTION_BLOCKS   => 'Blockierte Bestellungen',
        BSCHI_OPTION_UPDATES  => 'Updates verfügbar',
        BSCHI_OPTION_COMMENTS => 'Kommentare',
        BSCHI_OPTION_ORDERS   => 'Bestellungen',
        BSCHI_OPTION_HEALTH   => 'Health Checks',
    ];

    $url = admin_url( 'options-general.php?page=' . BSCHI_SLUG );

    foreach ( $modules as $option => $label ) {
        $result = get_option( $option );
        if ( ! $result || $result['status'] === 'ok' ) {
            continue;
        }
        $class  = $result['status'] === 'error' ? 'notice-error' : 'notice-warning';
        $ts     = human_time_diff( strtotime( $result['timestamp'] ), current_time( 'timestamp' ) );
        $alerts = $result['alerts'] ?? $result['errors'] ?? $result['warnings'] ?? [];
        $list   = ! empty( $alerts ) ? '• ' . implode( '<br>• ', array_map( 'esc_html', $alerts ) ) : '';

        printf(
            '<div class="notice %s is-dismissible"><p><strong>BSC Office Hub Integration – %s</strong> (vor %s)%s<br><a href="%s">→ Details ansehen</a></p></div>',
            esc_attr( $class ),
            esc_html( $label ),
            esc_html( $ts ),
            $list ? '<br>' . $list : '',
            esc_url( $url )
        );
    }
} );

// ─── Menü ─────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_options_page(
        'BSC - Office Hub Integration',
        'Office Hub',
        'manage_options',
        BSCHI_SLUG,
        'bschi_render_admin_page'
    );
} );

// ─── Admin-Seite ─────────────────────────────────────────────────────────────

function bschi_render_admin_page(): void {
    // Speichern
    if ( isset( $_POST['bschi_save'] ) && check_admin_referer( 'bschi_save' ) ) {
        $allowed_intervals = array_keys( bschi_interval_options() );
        $new_interval      = in_array( $_POST['cron_interval'] ?? '', $allowed_intervals, true )
            ? $_POST['cron_interval']
            : 'bschi_1h';

        update_option( BSCHI_OPTION_SETTINGS, [
            'site_url'      => esc_url_raw( trim( $_POST['site_url'] ?? '' ) ),
            'hub_url'       => esc_url_raw( trim( $_POST['hub_url']  ?? '' ) ),
            'hub_secret'    => sanitize_text_field( trim( $_POST['hub_secret'] ?? '' ) ),
            'cron_interval' => $new_interval,

            // Feature-Schalter
            'feature_monitoring'    => isset( $_POST['feature_monitoring'] ),
            'feature_double_orders' => isset( $_POST['feature_double_orders'] ),
            'feature_sale_banner'   => isset( $_POST['feature_sale_banner'] ),
            'feature_customer_docs' => isset( $_POST['feature_customer_docs'] ),
            'feature_pricelist'     => isset( $_POST['feature_pricelist'] ),
            'feature_chat'          => isset( $_POST['feature_chat'] ),

            // Doppelbestellungen
            'double_orders_autohold'  => isset( $_POST['double_orders_autohold'] ),
            'double_orders_window_h'  => max( 1, min( 168, (int) ( $_POST['double_orders_window_h'] ?? 48 ) ) ),
            'double_orders_score_min' => max( 10, min( 100, (int) ( $_POST['double_orders_score_min'] ?? 60 ) ) ),

            // Optionale Monitoring-Checks
            'check_session_fix'              => isset( $_POST['check_session_fix'] ),
            'check_fb_fix'                   => isset( $_POST['check_fb_fix'] ),
            'check_tax_prices_include_tax'   => isset( $_POST['check_tax_prices_include_tax'] ),
            'check_tax_display_shop_incl'    => isset( $_POST['check_tax_display_shop_incl'] ),
            'check_tax_display_cart_incl'    => isset( $_POST['check_tax_display_cart_incl'] ),
            'check_tax_reduced_rate_7'       => isset( $_POST['check_tax_reduced_rate_7'] ),
            'check_tax_german_market_tax_ok' => isset( $_POST['check_tax_german_market_tax_ok'] ),
            'check_sepa'                     => isset( $_POST['check_sepa'] ),
        ] );
        bschi_reschedule_cron( $new_interval );
        echo '<div class="notice notice-success inline"><p>Einstellungen gespeichert.</p></div>';
    }

    // Checks manuell ausführen
    if ( isset( $_POST['bschi_check'] ) && check_admin_referer( 'bschi_check' ) ) {
        bschi_run_all_checks( 'manual' );
        echo '<div class="notice notice-info inline"><p>Alle Checks abgeschlossen.</p></div>';
    }

    // Sale-Banner-Cache leeren + neu laden
    $sale_preview = null;
    if ( isset( $_POST['bschi_sale_refresh'] ) && check_admin_referer( 'bschi_sale_refresh' ) ) {
        $sale_preview = bschi_sale_get_current( true );
        echo '<div class="notice notice-info inline"><p>Sale-Banner-Cache geleert und neu geladen.</p></div>';
    }

    $cache    = get_option( BSCHI_OPTION_CACHE );
    $blocks   = get_option( BSCHI_OPTION_BLOCKS );
    $updates  = get_option( BSCHI_OPTION_UPDATES );
    $comments = get_option( BSCHI_OPTION_COMMENTS );
    $orders   = get_option( BSCHI_OPTION_ORDERS );
    $health   = get_option( BSCHI_OPTION_HEALTH );
    $s        = bschi_get_settings();
    ?>
    <div class="wrap">
        <h1>BSC - Office Hub Integration</h1>
        <p>Verbindet diesen Shop mit dem BSC Office Hub: Monitoring, Doppelbestellungs-Erkennung, Sale-Banner, Kundendokumente, Preislisten und Chat.</p>

        <?php if ( bschi_feature_enabled( 'monitoring' ) ) : ?>
        <form method="post" style="margin-bottom:16px">
            <?php wp_nonce_field( 'bschi_check' ); ?>
            <input type="hidden" name="bschi_check" value="1">
            <button type="submit" class="button button-primary">&#9654; Alle Checks jetzt ausführen</button>
        </form>

        <?php
        bschi_render_section( 'Cache-Integrität',        $cache,    'bschi_render_cache' );
        bschi_render_section( 'Blockierte Bestellungen', $blocks,   'bschi_render_blocks' );
        bschi_render_section( 'Fällige Updates',         $updates,  'bschi_render_updates' );
        bschi_render_section( 'Kommentare',              $comments, 'bschi_render_comments' );
        bschi_render_section( 'Bestellstatistiken',      $orders,   'bschi_render_orders' );
        bschi_render_section( 'Health Checks',           $health,   'bschi_render_health' );
        ?>
        <?php endif; ?>

        <?php if ( bschi_feature_enabled( 'double_orders' ) ) : ?>
        <hr>
        <?php bschi_dup_render_analyzer(); ?>
        <?php endif; ?>

        <?php if ( bschi_feature_enabled( 'sale_banner' ) ) : ?>
        <hr>
        <h2>Sale-Banner</h2>
        <p>Shortcode: <code>[bsc_hub_sale]</code> – z.B. via Snippets-Plugin oder direkt im Flatsome-Seiteninhalt einbinden.
           Zeigt automatisch das aktive Shop-Banner bzw. die aktive Sale-Kampagne aus dem Office Hub (Cache: 5 Min.).</p>
        <form method="post" style="margin-bottom:12px">
            <?php wp_nonce_field( 'bschi_sale_refresh' ); ?>
            <input type="hidden" name="bschi_sale_refresh" value="1">
            <button type="submit" class="button">Cache leeren &amp; Status laden</button>
        </form>
        <?php
        if ( $sale_preview !== null ) {
            if ( ! empty( $sale_preview['active'] ) ) {
                echo '<p><strong style="color:#00a32a">Aktiv:</strong> '
                    . esc_html( ( $sale_preview['type'] === 'banner' ? 'Shop-Banner' : 'Sale-Kampagne' ) . ' "' . ( $sale_preview['name'] ?? '' ) . '"' )
                    . ( ! empty( $sale_preview['coupon_code'] ) ? ' | Code: <code>' . esc_html( $sale_preview['coupon_code'] ) . '</code>' : '' )
                    . '</p>';
                echo '<div style="max-width:680px">' . bschi_sale_render_banner( $sale_preview ) . '</div>';
            } else {
                echo '<p><em>Aktuell kein aktives Banner / keine aktive Sale-Kampagne im Office Hub.</em></p>';
            }
        }
        ?>
        <?php endif; ?>

        <hr>
        <h2>Einstellungen</h2>
        <form method="post">
            <?php wp_nonce_field( 'bschi_save' ); ?>
            <input type="hidden" name="bschi_save" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="site_url">Site URL</label></th>
                    <td>
                        <input type="url" id="site_url" name="site_url" class="regular-text"
                            value="<?= esc_attr( $s['site_url'] ); ?>" required>
                        <p class="description">Öffentliche URL für den Varnish Self-Check.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hub_url">Office Hub URL</label></th>
                    <td>
                        <input type="url" id="hub_url" name="hub_url" class="regular-text"
                            value="<?= esc_attr( $s['hub_url'] ); ?>"
                            placeholder="http://192.168.x.x:8445">
                        <p class="description">Basis-URL des BSC Office Hub. Leer lassen um die Hub-Anbindung zu deaktivieren.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="hub_secret">Hub Secret</label></th>
                    <td>
                        <input type="password" id="hub_secret" name="hub_secret" class="regular-text"
                            value="<?= esc_attr( $s['hub_secret'] ); ?>">
                        <p class="description">Muss mit <code>BSCHI_SECRET</code> (bzw. <code>BSCHWM_SECRET</code>) im Office Hub übereinstimmen.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cron_interval">Prüf-Intervall</label></th>
                    <td>
                        <select id="cron_interval" name="cron_interval">
                            <?php foreach ( bschi_interval_options() as $value => $label ) : ?>
                                <option value="<?= esc_attr( $value ); ?>" <?= selected( $s['cron_interval'], $value, false ); ?>>
                                    <?= esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Wie oft sollen die Monitoring-Checks automatisch an den Office Hub gemeldet werden?</p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:24px">Module</h3>
            <p class="description" style="margin-bottom:12px">Jedes Modul lässt sich einzeln aktivieren/deaktivieren.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Aktive Module</th>
                    <td>
                        <label><input type="checkbox" name="feature_monitoring" value="1" <?= checked( $s['feature_monitoring'] ?? true, true, false ); ?>>
                            Shop-Monitoring (Cache, Blockierungen, Updates, Kommentare, Bestellungen, Health)</label><br>
                        <label><input type="checkbox" name="feature_double_orders" value="1" <?= checked( $s['feature_double_orders'] ?? true, true, false ); ?>>
                            Doppelbestellungs-Erkennung (Echtzeit)</label><br>
                        <label><input type="checkbox" name="feature_sale_banner" value="1" <?= checked( $s['feature_sale_banner'] ?? false, true, false ); ?>>
                            Sale-Banner-Shortcode <code>[bsc_hub_sale]</code></label><br>
                        <label><input type="checkbox" name="feature_customer_docs" value="1" <?= checked( $s['feature_customer_docs'] ?? false, true, false ); ?>>
                            Kundendokumente im Kundenkonto (My-Account: "Meine Dokumente")</label><br>
                        <label><input type="checkbox" name="feature_pricelist" value="1" <?= checked( $s['feature_pricelist'] ?? false, true, false ); ?>>
                            Preisliste für Händler/Hotel-Kunden (My-Account: "Preisliste")</label><br>
                        <label><input type="checkbox" name="feature_chat" value="1" <?= checked( $s['feature_chat'] ?? false, true, false ); ?>>
                            Woidsiederei-Chat (Floating-Widget für eingeloggte Kunden)</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Doppelbestellungen</th>
                    <td>
                        <label><input type="checkbox" name="double_orders_autohold" value="1" <?= checked( $s['double_orders_autohold'] ?? true, true, false ); ?>>
                            Jüngere Bestellung eines Duplikat-Paars automatisch auf "Wartend" setzen</label><br><br>
                        <label>Kandidaten-Zeitfenster:
                            <input type="number" name="double_orders_window_h" min="1" max="168" style="width:70px"
                                value="<?= esc_attr( $s['double_orders_window_h'] ?? 48 ); ?>"> Stunden</label><br>
                        <label>Duplikat ab Score:
                            <input type="number" name="double_orders_score_min" min="10" max="100" style="width:70px"
                                value="<?= esc_attr( $s['double_orders_score_min'] ?? 60 ); ?>"> Punkte</label>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top:24px">Optionale Monitoring-Checks</h3>
            <p class="description" style="margin-bottom:12px">Deaktivierte Checks werden übersprungen und erzeugen weder Fehler noch Warnungen.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Cache-Integrität</th>
                    <td>
                        <label><input type="checkbox" name="check_session_fix" value="1" <?= checked( $s['check_session_fix'] ?? true, true, false ); ?>>
                            fix-session-cache-limiter.php prüfen</label><br>
                        <label><input type="checkbox" name="check_fb_fix" value="1" <?= checked( $s['check_fb_fix'] ?? true, true, false ); ?>>
                            fix-fb-no-setcookie.php prüfen</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">MwSt.-Konfiguration</th>
                    <td>
                        <label><input type="checkbox" name="check_tax_prices_include_tax" value="1" <?= checked( $s['check_tax_prices_include_tax'] ?? true, true, false ); ?>>
                            Bruttopreise (inkl. MwSt.) prüfen</label><br>
                        <label><input type="checkbox" name="check_tax_display_shop_incl" value="1" <?= checked( $s['check_tax_display_shop_incl'] ?? true, true, false ); ?>>
                            Shop-Preisanzeige inkl. MwSt. prüfen</label><br>
                        <label><input type="checkbox" name="check_tax_display_cart_incl" value="1" <?= checked( $s['check_tax_display_cart_incl'] ?? true, true, false ); ?>>
                            Warenkorb-Preisanzeige inkl. MwSt. prüfen</label><br>
                        <label><input type="checkbox" name="check_tax_reduced_rate_7" value="1" <?= checked( $s['check_tax_reduced_rate_7'] ?? true, true, false ); ?>>
                            Steuersatz 7% (ermäßigt) prüfen</label><br>
                        <label><input type="checkbox" name="check_tax_german_market_tax_ok" value="1" <?= checked( $s['check_tax_german_market_tax_ok'] ?? true, true, false ); ?>>
                            German Market Steuern prüfen</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">SEPA-Mandate</th>
                    <td>
                        <label><input type="checkbox" name="check_sepa" value="1" <?= checked( $s['check_sepa'] ?? true, true, false ); ?>>
                            SEPA-Mandate prüfen</label>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Speichern' ); ?>
        </form>
    </div>
    <?php
}

// ─── Hilfsfunktion: Sektion rendern ──────────────────────────────────────────

function bschi_render_section( string $title, mixed $data, string $render_fn ): void {
    $status_colors = [ 'ok' => '#00a32a', 'warning' => '#996800', 'error' => '#d63638' ];
    $status        = $data['status'] ?? 'unknown';
    $color         = $status_colors[ $status ] ?? '#555';
    $ts            = $data ? esc_html( date_i18n( 'd.m.Y H:i:s', strtotime( $data['timestamp'] ) ) ) : '—';

    echo "<h2 style='margin-top:24px'>{$title} <span style='font-size:13px;color:{$color};font-weight:normal'>● " . esc_html( strtoupper( $status ) ) . " · {$ts}</span></h2>";

    if ( $data ) {
        echo $render_fn( $data );
    } else {
        echo '<p><em>Noch kein Check durchgeführt.</em></p>';
    }
}

// ─── Render: Cache ────────────────────────────────────────────────────────────

function bschi_render_cache( array $r ): string {
    $check_labels = [
        'session_fix'         => 'fix-session-cache-limiter.php vorhanden & vollständig',
        'fb_fix'              => 'fix-fb-no-setcookie.php vorhanden & vollständig',
        'fb_function_present' => 'Facebook Plugin: param_builder_server_setup() vorhanden',
        'varnish_caching'     => 'Varnish cacht korrekt (Request 2 = HIT)',
    ];

    $rows = '';
    foreach ( $r['checks'] as $key => $val ) {
        $icon  = $val === null ? '?' : ( $val ? 'OK' : 'FAIL' );
        $label = esc_html( $check_labels[ $key ] ?? $key );
        $color = $val === null ? '#555' : ( $val ? '#00a32a' : '#d63638' );
        $rows .= "<tr><td style='width:48px;padding:6px 8px;color:{$color};font-weight:bold'>{$icon}</td><td style='padding:6px 8px'>{$label}</td></tr>\n";
    }

    $vd  = $r['varnish_details'] ?? [];
    $vdr = '';
    if ( ! empty( $vd ) && ! isset( $vd['http_error'] ) ) {
        $vdr = sprintf(
            '<tr><td colspan="2" style="padding:4px 8px 8px;color:#555"><small>Req 1: <code>%s/%s</code> → Req 2: <code>%s/%s</code>%s%s</small></td></tr>',
            esc_html( $vd['r1_varnish'] ?? '?' ), esc_html( $vd['r1_cacheable'] ?? '?' ),
            esc_html( $vd['r2_varnish'] ?? '?' ), esc_html( $vd['r2_cacheable'] ?? '?' ),
            $vd['set_cookie']    ? ' | Set-Cookie: <code>' . esc_html( substr( $vd['set_cookie'], 0, 60 ) ) . '</code>' : '',
            $vd['cache_control'] ? ' | CC: <code>' . esc_html( $vd['cache_control'] ) . '</code>' : ''
        );
    } elseif ( isset( $vd['http_error'] ) ) {
        $vdr = '<tr><td colspan="2" style="color:#d63638;padding:4px 8px 8px"><small>HTTP-Fehler: ' . esc_html( $vd['http_error'] ) . '</small></td></tr>';
    }

    $msgs = '';
    foreach ( $r['errors'] ?? [] as $m ) {
        $msgs .= '<tr><td colspan="2" style="padding:4px 8px;color:#d63638">FEHLER: ' . esc_html( $m ) . '</td></tr>';
    }
    foreach ( $r['warnings'] ?? [] as $m ) {
        $msgs .= '<tr><td colspan="2" style="padding:4px 8px;color:#996800">WARNUNG: ' . esc_html( $m ) . '</td></tr>';
    }

    return bschi_table( $rows . $vdr . $msgs );
}

// ─── Render: Blockierungen ────────────────────────────────────────────────────

function bschi_render_blocks( array $r ): string {
    $rows = '';

    $ppcp = $r['blocks']['ppcp_recaptcha'] ?? null;
    if ( $ppcp ) {
        $icon      = bschi_status_icon( $ppcp['status'] );
        $enabled   = $ppcp['enabled'] ? '<span style="color:#d63638">aktiv</span>' : '<span style="color:#00a32a">inaktiv</span>';
        $delta_str = $ppcp['counter_delta'] > 0
            ? '<strong style="color:#d63638">+' . (int) $ppcp['counter_delta'] . ' neu</strong>'
            : '<span style="color:#00a32a">keine neuen</span>';
        $rows .= "<tr><td style='width:28px;padding:8px'>{$icon}</td><td style='padding:8px'>"
            . '<strong>' . esc_html( $ppcp['label'] ) . '</strong><br><small>'
            . "Status: {$enabled} | Gesamt: <code>" . (int) $ppcp['counter_total'] . "</code> | Seit letztem Check: {$delta_str}"
            . '</small></td></tr>';
    }

    $wc = $r['blocks']['wc_checkout_errors'] ?? null;
    if ( $wc ) {
        $icon  = bschi_status_icon( $wc['status'] );
        $last  = $wc['last_seen'] ? esc_html( date_i18n( 'd.m.Y H:i', strtotime( $wc['last_seen'] ) ) ) : '—';
        $delta = (int) $wc['delta'];
        $dstr  = $delta > 0 ? '<strong style="color:#d63638">+' . $delta . ' neu</strong>' : '<span style="color:#00a32a">keine neuen</span>';
        $rows .= "<tr><td style='width:28px;padding:8px'>{$icon}</td><td style='padding:8px'>"
            . '<strong>' . esc_html( $wc['label'] ) . '</strong><br><small>'
            . "48h-Fehler: <code>{$wc['recent_count']}</code> | Neu: {$dstr} | Letzter: {$last}";
        if ( ! empty( $wc['examples'] ) ) {
            $rows .= '<details style="margin-top:4px"><summary style="cursor:pointer;color:#2271b1">Beispiele</summary><ul style="margin:4px 0 0 16px">';
            foreach ( $wc['examples'] as $ex ) {
                $rows .= '<li><code style="font-size:11px;word-break:break-all">' . esc_html( $ex ) . '</code></li>';
            }
            $rows .= '</ul></details>';
        }
        $rows .= '</small></td></tr>';
    }

    return bschi_table( $rows );
}

// ─── Render: Updates ─────────────────────────────────────────────────────────

function bschi_render_updates( array $r ): string {
    $rows     = '';
    $sections = [ 'core' => 'WordPress Core', 'plugins' => 'Plugins', 'themes' => 'Themes' ];

    foreach ( $sections as $key => $label ) {
        $data = $r['updates'][ $key ] ?? null;
        if ( ! $data ) {
            continue;
        }
        $icon  = bschi_status_icon( $data['status'] );
        $count = (int) $data['count'];
        $rows .= "<tr><td style='width:28px;padding:8px'>{$icon}</td><td style='padding:8px'>"
            . "<strong>{$label}</strong>: ";

        if ( $count === 0 ) {
            $rows .= '<span style="color:#00a32a">aktuell</span>';
        } else {
            $rows .= "<strong style='color:#996800'>{$count} Update(s) verfügbar</strong>";
            if ( ! empty( $data['items'] ) ) {
                $rows .= '<details style="margin-top:4px"><summary style="cursor:pointer;color:#2271b1">Details</summary><ul style="margin:4px 0 0 16px">';
                foreach ( $data['items'] as $item ) {
                    $name  = esc_html( $item['name'] ?? $item['slug'] ?? '?' );
                    $cur   = esc_html( $item['current'] ?? '?' );
                    $new   = esc_html( $item['new'] ?? '?' );
                    $rows .= "<li>{$name}: <code>{$cur}</code> → <code>{$new}</code></li>";
                }
                $rows .= '</ul></details>';
            }
        }
        $rows .= '</td></tr>';
    }

    return bschi_table( $rows );
}

// ─── Render: Kommentare ───────────────────────────────────────────────────────

function bschi_render_comments( array $r ): string {
    $c    = $r['comments'];
    $rows = '';

    $items = [
        [ 'Ausstehend (zur Freigabe)', $c['pending'],  $c['pending'] > 0 ? 'warning' : 'ok' ],
        [ 'Spam',                      $c['spam'],     $c['spam'] > 10   ? 'warning' : 'ok' ],
        [ 'Papierkorb',                $c['trash'],    'ok' ],
        [ 'Genehmigt',                 $c['approved'], 'ok' ],
        [ 'Gesamt',                    $c['total'],    'ok' ],
    ];

    foreach ( $items as [ $label, $count, $status ] ) {
        $icon  = bschi_status_icon( $status );
        $color = $status === 'ok' ? '#555' : '#996800';
        $rows .= "<tr><td style='width:28px;padding:6px 8px'>{$icon}</td>"
            . "<td style='padding:6px 8px'>{$label}</td>"
            . "<td style='padding:6px 8px;color:{$color}'><strong>{$count}</strong></td></tr>";
    }

    return bschi_table( $rows );
}

// ─── Render: Bestellungen ─────────────────────────────────────────────────────

function bschi_render_orders( array $r ): string {
    $rows = "<tr style='background:#f0f0f1'>"
        . "<th style='padding:6px 8px'>Status</th>"
        . "<th style='padding:6px 8px'>Anzahl</th>"
        . '</tr>';

    $alert_stati = [ 'failed' => 'error', 'pending' => 'warning', 'on-hold' => 'warning' ];

    foreach ( $r['by_status'] as $slug => $data ) {
        $status = $alert_stati[ $slug ] ?? 'ok';
        if ( $status !== 'ok' && $data['count'] === 0 ) {
            $status = 'ok';
        }
        $icon  = bschi_status_icon( $status );
        $color = $status === 'error' ? '#d63638' : ( $status === 'warning' ? '#996800' : '#555' );
        $rows .= '<tr>'
            . "<td style='padding:6px 8px'>{$icon} " . esc_html( $data['label'] ) . '</td>'
            . "<td style='padding:6px 8px;color:{$color}'><strong>" . (int) $data['count'] . '</strong></td>'
            . '</tr>';
    }

    $rows .= "<tr style='border-top:2px solid #ddd'>"
        . "<td style='padding:8px;font-weight:bold'>Gesamt</td>"
        . "<td style='padding:8px;font-weight:bold'>" . (int) $r['total'] . '</td>'
        . '</tr>';

    return bschi_table( $rows );
}

// ─── Render: Health Checks ───────────────────────────────────────────────────

function bschi_render_health( array $r ): string {
    $rows = '';

    // ── WP-Cron ──────────────────────────────────────────────────────────────
    $cron = $r['cron'] ?? null;
    if ( $cron ) {
        $icon     = bschi_status_icon( $cron['status'] );
        $delta    = $cron['next_run_delta_s'];
        $disabled = (bool) ( $cron['wp_cron_disabled'] ?? false );
        if ( $disabled ) {
            $delta_str = '<strong style="color:#d63638">DEAKTIVIERT (DISABLE_WP_CRON)</strong>';
        } elseif ( $delta === null ) {
            $delta_str = '<span style="color:#d63638">nicht eingeplant</span>';
        } elseif ( $delta < 0 ) {
            $delta_str = '<span style="color:#d63638">überfällig seit ' . gmdate( 'H:i:s', abs( $delta ) ) . '</span>';
        } else {
            $delta_str = 'in ' . gmdate( 'H:i:s', $delta );
        }
        $last_ago     = $cron['last_cron_run_ago_s'] ?? null;
        $last_ago_str = $last_ago === null
            ? '<span style="color:#d63638">noch nie aufgezeichnet</span>'
            : 'vor ' . ( $last_ago < 3600 ? round( $last_ago / 60 ) . ' Min.' : round( $last_ago / 3600, 1 ) . ' Std.' );

        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>WP-Cron</strong><br><small>'
            . 'Nächster geplanter Lauf: ' . $delta_str . '<br>'
            . 'Letzter echter Cron-Lauf: ' . $last_ago_str;
        if ( ! empty( $cron['alerts'] ) ) {
            $rows .= '<br><span style="color:#996800">' . esc_html( implode( ' | ', $cron['alerts'] ) ) . '</span>';
        }
        $rows .= '</small></td></tr>';
    }

    // ── Doppelte Bestellungen ─────────────────────────────────────────────────
    $double = $r['double_orders'] ?? null;
    if ( $double ) {
        $icon  = bschi_status_icon( $double['status'] );
        $count = (int) $double['count'];
        $cstr  = $count > 0
            ? '<strong style="color:#996800">' . $count . ' Duplikat(e)</strong>'
            : '<span style="color:#00a32a">keine</span>';
        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>Doppelte Bestellungen</strong> <small style="color:#555">(Fenster: ' . (int) $double['window_minutes'] . ' Min.)</small><br>'
            . '<small>Gefunden: ' . $cstr;
        if ( ! empty( $double['examples'] ) ) {
            $rows .= ' <details style="display:inline-block;margin-left:8px"><summary style="cursor:pointer;color:#2271b1">Beispiele anzeigen</summary>'
                . '<ul style="margin:4px 0 0 16px">';
            foreach ( $double['examples'] as $ex ) {
                $rows .= '<li><code>' . esc_html( $ex ) . '</code></li>';
            }
            $rows .= '</ul></details>';
        }
        $rows .= '</small></td></tr>';
    }

    // ── MwSt.-Konfiguration ───────────────────────────────────────────────────
    $tax = $r['tax'] ?? null;
    if ( $tax ) {
        $icon         = bschi_status_icon( $tax['status'] );
        $check_labels = [
            'tax_enabled'          => 'Steuerberechnung aktiv',
            'prices_include_tax'   => 'Bruttopreise (inkl. MwSt.)',
            'display_shop_incl'    => 'Shop-Preisanzeige: inkl. MwSt.',
            'display_cart_incl'    => 'Warenkorb-Preisanzeige: inkl. MwSt.',
            'standard_rate_19'     => 'Steuersatz 19% (Standard) vorhanden',
            'reduced_rate_7'       => 'Steuersatz 7% (ermäßigt) vorhanden',
            'german_market_active' => 'German Market Plugin aktiv',
            'german_market_tax_ok' => 'German Market Steuern konfiguriert',
        ];
        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>MwSt.-Konfiguration</strong><br>'
            . '<table style="margin-top:6px;font-size:12px;border-collapse:collapse">';
        foreach ( $tax['checks'] ?? [] as $key => $val ) {
            $color = $val ? '#00a32a' : '#d63638';
            $badge = $val ? 'OK' : 'FAIL';
            $label = esc_html( $check_labels[ $key ] ?? $key );
            $rows .= "<tr><td style='color:{$color};font-weight:bold;padding:2px 10px 2px 0;width:44px'>{$badge}</td>"
                . "<td style='padding:2px 0'>{$label}</td></tr>";
        }
        $rows .= '</table></td></tr>';
    }

    // ── SEPA-Mandate ──────────────────────────────────────────────────────────
    $sepa = $r['sepa'] ?? null;
    if ( $sepa ) {
        $icon     = bschi_status_icon( $sepa['status'] );
        $plugin   = esc_html( $sepa['plugin'] ?? 'none' );
        $mandates = (int) ( $sepa['mandate_count'] ?? 0 );
        $mstr     = $mandates > 0
            ? '<span style="color:#00a32a"><strong>' . $mandates . '</strong></span>'
            : '<span style="color:#996800"><strong>0</strong></span>';
        $rows .= "<tr><td style='width:40px;padding:8px;vertical-align:top'>{$icon}</td><td style='padding:8px'>"
            . '<strong>SEPA-Mandate</strong><br>'
            . '<small>Gateway-Plugin: <code>' . $plugin . '</code> | Aktive Mandate: ' . $mstr;
        if ( ! empty( $sepa['alerts'] ) ) {
            $rows .= '<br><span style="color:#996800">' . esc_html( implode( ' | ', $sepa['alerts'] ) ) . '</span>';
        }
        $rows .= '</small></td></tr>';
    }

    return bschi_table( $rows );
}

// ─── Hilfsfunktionen UI ───────────────────────────────────────────────────────

function bschi_table( string $rows ): string {
    return "<table class='widefat' style='max-width:680px;border-collapse:collapse;margin-bottom:16px'><tbody>{$rows}</tbody></table>";
}

function bschi_status_icon( string $status ): string {
    return match ( $status ) {
        'ok'      => '<span style="color:#00a32a;font-weight:bold">OK</span>',
        'warning' => '<span style="color:#996800;font-weight:bold">WARN</span>',
        'error'   => '<span style="color:#d63638;font-weight:bold">ERR</span>',
        default   => '<span style="color:#555">?</span>',
    };
}
