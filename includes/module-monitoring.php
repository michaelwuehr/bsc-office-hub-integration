<?php
/**
 * BSC Office Hub Integration – Modul: Shop-Gesundheits-Monitoring.
 *
 * Portiert aus BSC WC Health Monitor v2.4.2 (1:1-Checks, BSCHI-Prefix).
 * Pusht an die bestehenden Hub-Endpoints unter /api/v1/monitoring/*.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BSCHI_OPTION_CACHE',    'bschi_last_cache' );
define( 'BSCHI_OPTION_BLOCKS',   'bschi_last_blocks' );
define( 'BSCHI_OPTION_UPDATES',  'bschi_last_updates' );
define( 'BSCHI_OPTION_COMMENTS', 'bschi_last_comments' );
define( 'BSCHI_OPTION_ORDERS',   'bschi_last_orders' );
define( 'BSCHI_OPTION_HEALTH',   'bschi_last_health' );
define( 'BSCHI_OPTION_BLK_SNAP', 'bschi_block_snapshot' );

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 1: CACHE-INTEGRITÄT
// ═══════════════════════════════════════════════════════════════════════════════

function bschi_run_cache_check( string $trigger = 'manual' ): array {
    $checks   = [];
    $errors   = [];
    $warnings = [];
    $settings = bschi_get_settings();

    // ── Check 1: fix-session-cache-limiter.php (optional) ────────────────────
    if ( $settings['check_session_fix'] ?? true ) {
        $session_fix_path = WPMU_PLUGIN_DIR . '/fix-session-cache-limiter.php';
        $session_fix_ok   = false;
        if ( file_exists( $session_fix_path ) ) {
            $content        = file_get_contents( $session_fix_path );
            $session_fix_ok = str_contains( $content, 'session.cache_limiter' )
                           && str_contains( $content, 'session.use_cookies' );
        }
        $checks['session_fix'] = $session_fix_ok;
        if ( ! $session_fix_ok ) {
            $errors[] = 'fix-session-cache-limiter.php fehlt oder unvollständig — Cache-Control: no-store + Set-Cookie: PHPSESSID möglich!';
        }
    }

    // ── Check 2: fix-fb-no-setcookie.php (optional) ──────────────────────────
    if ( $settings['check_fb_fix'] ?? true ) {
        $fb_fix_path = WPMU_PLUGIN_DIR . '/fix-fb-no-setcookie.php';
        $fb_fix_ok   = false;
        if ( file_exists( $fb_fix_path ) ) {
            $content   = file_get_contents( $fb_fix_path );
            $fb_fix_ok = str_contains( $content, 'param_builder_server_setup' )
                      && str_contains( $content, 'facebook_for_woocommerce_integration_pixel_enabled' );
        }
        $checks['fb_fix'] = $fb_fix_ok;
        if ( ! $fb_fix_ok ) {
            $errors[] = 'fix-fb-no-setcookie.php fehlt oder unvollständig — Set-Cookie: _fbp möglich!';
        }
    }

    // ── Check 3: Facebook-Funktionsname noch vorhanden ────────────────────────
    $fb_tracker = WP_PLUGIN_DIR . '/facebook-for-woocommerce/facebook-commerce-events-tracker.php';
    $fb_fn_ok   = true;
    if ( file_exists( $fb_tracker ) ) {
        $content  = file_get_contents( $fb_tracker );
        $fb_fn_ok = str_contains( $content, 'param_builder_server_setup' );
        if ( ! $fb_fn_ok ) {
            $errors[] = 'Facebook Plugin aktualisiert: param_builder_server_setup() nicht mehr gefunden! '
                      . 'fix-fb-no-setcookie.php muss angepasst werden.';
        }
    }
    $checks['fb_function_present'] = $fb_fn_ok;

    // ── Check 4: Varnish Self-Check ───────────────────────────────────────────
    $test_url = trailingslashit( $settings['site_url'] );

    $r1 = wp_remote_head( $test_url, [
        'timeout'     => 10,
        'redirection' => 0,
        'sslverify'   => false,
        'headers'     => [ 'User-Agent' => 'BSCHI-Check/' . BSCHI_VERSION ],
    ] );
    sleep( 2 );
    $r2 = wp_remote_head( $test_url, [
        'timeout'     => 10,
        'redirection' => 0,
        'sslverify'   => false,
        'headers'     => [ 'User-Agent' => 'BSCHI-Check/' . BSCHI_VERSION ],
    ] );

    $varnish_ok      = false;
    $varnish_details = [];

    if ( ! is_wp_error( $r1 ) && ! is_wp_error( $r2 ) ) {
        $h1  = wp_remote_retrieve_headers( $r1 );
        $h2  = wp_remote_retrieve_headers( $r2 );
        $vc1 = strtolower( (string) ( $h1['x-varnish-cache'] ?? '' ) );
        $xc1 = strtolower( (string) ( $h1['x-cacheable']    ?? '' ) );
        $vc2 = strtolower( (string) ( $h2['x-varnish-cache'] ?? '' ) );
        $xc2 = strtolower( (string) ( $h2['x-cacheable']    ?? '' ) );
        $sc  = (string) ( $h1['set-cookie']    ?? '' );
        $cc  = (string) ( $h1['cache-control'] ?? '' );

        if ( empty( $vc1 ) && empty( $vc2 ) ) {
            $warnings[]                = 'Kein x-varnish-cache Header im Self-Check. Externer curl-Test empfohlen.';
            $checks['varnish_caching'] = null;
            $varnish_ok                = true;
        } else {
            $varnish_ok = ( $vc2 === 'hit' ) && ( $xc2 === 'yes' );
            if ( ! $varnish_ok ) {
                $errors[] = "Varnish cached nicht! Request 2: x-varnish-cache={$vc2}, x-cacheable={$xc2}";
            }
            $checks['varnish_caching'] = $varnish_ok;
        }
        if ( $sc ) {
            $errors[] = 'Set-Cookie in Antwort: ' . substr( $sc, 0, 100 );
        }
        if ( str_contains( strtolower( $cc ), 'no-store' ) ) {
            $errors[] = 'Cache-Control: no-store in Antwort!';
        }
        $varnish_details = [
            'r1_varnish'    => $vc1 ?: null,
            'r1_cacheable'  => $xc1 ?: null,
            'r2_varnish'    => $vc2 ?: null,
            'r2_cacheable'  => $xc2 ?: null,
            'set_cookie'    => $sc  ?: null,
            'cache_control' => $cc  ?: null,
        ];
    } else {
        $errmsg                    = is_wp_error( $r1 ) ? $r1->get_error_message() : $r2->get_error_message();
        $errors[]                  = "Varnish Self-Check fehlgeschlagen: {$errmsg}";
        $checks['varnish_caching'] = false;
        $varnish_details           = [ 'http_error' => $errmsg ];
    }

    $has_critical = ( isset( $checks['session_fix'] ) && ! $checks['session_fix'] )
                 || ( isset( $checks['fb_fix'] )      && ! $checks['fb_fix'] )
                 || ( $checks['varnish_caching'] === false );

    $status = 'ok';
    if ( ! empty( $errors ) ) {
        $status = $has_critical ? 'error' : 'warning';
    } elseif ( ! empty( $warnings ) ) {
        $status = 'warning';
    }

    $result = [
        'status'          => $status,
        'trigger'         => $trigger,
        'timestamp'       => current_time( 'c' ),
        'source'          => parse_url( get_site_url(), PHP_URL_HOST ),
        'checks'          => $checks,
        'varnish_details' => $varnish_details,
        'errors'          => $errors,
        'warnings'        => $warnings,
        'plugin_version'  => BSCHI_VERSION,
    ];

    update_option( BSCHI_OPTION_CACHE, $result );
    bschi_hub_post( '/api/v1/monitoring/cache-status', $result );

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 2: BLOCKIERTE BESTELLANFRAGEN
// ═══════════════════════════════════════════════════════════════════════════════

function bschi_run_block_check( string $trigger = 'manual' ): array {
    global $wpdb;

    $snapshot = get_option( BSCHI_OPTION_BLK_SNAP, [] );
    $blocks   = [];
    $alerts   = [];
    $summary  = 'ok';

    // ── PayPal reCAPTCHA ──────────────────────────────────────────────────────
    $ppcp_counter = (int) $wpdb->get_var(
        "SELECT option_value FROM {$wpdb->options}
         WHERE option_name = 'ppcp_recaptcha_rejection_counter' LIMIT 1"
    );
    $ppcp_raw     = $wpdb->get_var(
        "SELECT option_value FROM {$wpdb->options}
         WHERE option_name = 'woocommerce_ppcp-recaptcha_settings' LIMIT 1"
    );
    $ppcp_cfg     = maybe_unserialize( $ppcp_raw );
    $ppcp_enabled = ( $ppcp_cfg['enabled'] ?? 'no' ) === 'yes';
    $prev_ppcp    = (int) ( $snapshot['ppcp_recaptcha']['counter'] ?? $ppcp_counter );
    $ppcp_delta   = $ppcp_counter - $prev_ppcp;

    $ppcp_status = 'ok';
    if ( $ppcp_enabled ) {
        $ppcp_status = 'warning';
        $alerts[]    = 'PayPal reCAPTCHA ist aktiv — Blockierungen möglich.';
        $summary     = 'warning';
    }
    if ( $ppcp_delta > 0 ) {
        $ppcp_status = 'error';
        $alerts[]    = "PayPal reCAPTCHA: {$ppcp_delta} neue Bestellung(en) blockiert (Gesamt: {$ppcp_counter}).";
        $summary     = 'error';
    }

    $blocks['ppcp_recaptcha'] = [
        'label'         => 'PayPal reCAPTCHA (Fraud Protection)',
        'status'        => $ppcp_status,
        'enabled'       => $ppcp_enabled,
        'counter_total' => $ppcp_counter,
        'counter_delta' => $ppcp_delta,
        'prev_snapshot' => $prev_ppcp,
    ];

    // ── WooCommerce Checkout-Fehler ───────────────────────────────────────────
    $wc_errors   = bschi_parse_wc_checkout_errors( WP_CONTENT_DIR . '/uploads/wc-logs/' );
    $prev_wc_cnt = (int) ( $snapshot['wc_checkout_errors']['recent_count'] ?? 0 );
    $wc_delta    = max( 0, $wc_errors['count'] - $prev_wc_cnt );
    $wc_status   = 'ok';

    if ( $wc_errors['count'] > 0 ) {
        $wc_status = $wc_delta > 0 ? 'error' : 'warning';
        if ( $wc_delta > 0 ) {
            $alerts[] = "WooCommerce Logs: {$wc_delta} neue Checkout-Fehler in den letzten 48h.";
            $summary  = 'error';
        } elseif ( $summary === 'ok' ) {
            $summary = 'warning';
        }
    }

    $blocks['wc_checkout_errors'] = [
        'label'        => 'WooCommerce Checkout-Fehler (wc-logs)',
        'status'       => $wc_status,
        'recent_count' => $wc_errors['count'],
        'delta'        => $wc_delta,
        'last_seen'    => $wc_errors['last_seen'],
        'examples'     => $wc_errors['examples'],
    ];

    // ── Snapshot aktualisieren ────────────────────────────────────────────────
    update_option( BSCHI_OPTION_BLK_SNAP, [
        'ppcp_recaptcha'     => [ 'counter'      => $ppcp_counter ],
        'wc_checkout_errors' => [ 'recent_count' => $wc_errors['count'] ],
    ] );

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'blocks'         => $blocks,
        'alerts'         => $alerts,
        'plugin_version' => BSCHI_VERSION,
    ];

    update_option( BSCHI_OPTION_BLOCKS, $result );
    bschi_hub_post( '/api/v1/monitoring/order-blocks', $result );

    return $result;
}

function bschi_parse_wc_checkout_errors( string $log_dir ): array {
    $result = [ 'count' => 0, 'last_seen' => null, 'examples' => [] ];
    if ( ! is_dir( $log_dir ) ) {
        return $result;
    }

    $cutoff    = time() - ( 48 * HOUR_IN_SECONDS );
    $patterns  = [ '/checkout/i', '/order.*fail|fail.*order/i', '/payment.*error|error.*payment/i', '/blocked|rejected|declined/i' ];
    $log_files = glob( $log_dir . '*.log' ) ?: [];
    usort( $log_files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );

    foreach ( array_slice( $log_files, 0, 5 ) as $file ) {
        if ( filemtime( $file ) < $cutoff ) {
            continue;
        }
        foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
            $matched = false;
            foreach ( $patterns as $regex ) {
                if ( preg_match( $regex, $line ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched || ! preg_match( '/\b(ERROR|CRITICAL)\b/i', $line ) ) {
                continue;
            }
            $result['count']++;
            if ( preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m ) ) {
                if ( ! $result['last_seen'] || $m[1] > $result['last_seen'] ) {
                    $result['last_seen'] = $m[1];
                }
            }
            if ( count( $result['examples'] ) < 3 ) {
                $result['examples'][] = substr( trim( $line ), 0, 150 );
            }
        }
    }
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 3: UPDATES
// ═══════════════════════════════════════════════════════════════════════════════

function bschi_run_update_check( string $trigger = 'manual' ): array {
    wp_update_plugins();
    wp_update_themes();

    $updates = [];
    $summary = 'ok';
    $alerts  = [];

    // ── WordPress Core ────────────────────────────────────────────────────────
    $core_update  = get_site_transient( 'update_core' );
    $core_updates = [];
    if ( isset( $core_update->updates ) ) {
        foreach ( $core_update->updates as $update ) {
            if ( isset( $update->response ) && $update->response === 'upgrade' ) {
                $core_updates[] = [
                    'current' => get_bloginfo( 'version' ),
                    'new'     => $update->current ?? '?',
                    'type'    => 'major',
                ];
            }
        }
    }
    $core_count = count( $core_updates );
    if ( $core_count > 0 ) {
        $alerts[] = 'WordPress Core-Update verfügbar: ' . ( $core_updates[0]['new'] ?? '?' );
        $summary  = 'warning';
    }
    $updates['core'] = [
        'status' => $core_count > 0 ? 'warning' : 'ok',
        'count'  => $core_count,
        'items'  => $core_updates,
    ];

    // ── Plugins ───────────────────────────────────────────────────────────────
    $plugin_update  = get_site_transient( 'update_plugins' );
    $plugin_updates = [];
    if ( ! empty( $plugin_update->response ) ) {
        foreach ( $plugin_update->response as $plugin_file => $data ) {
            $plugin_data      = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
            $plugin_updates[] = [
                'slug'    => dirname( $plugin_file ),
                'name'    => $plugin_data['Name'] ?? $plugin_file,
                'current' => $plugin_data['Version'] ?? '?',
                'new'     => $data->new_version ?? '?',
            ];
        }
    }
    $plugin_count = count( $plugin_updates );
    if ( $plugin_count > 0 ) {
        $alerts[] = "{$plugin_count} Plugin-Update(s) verfügbar.";
        $summary  = 'warning';
    }
    $updates['plugins'] = [
        'status' => $plugin_count > 0 ? 'warning' : 'ok',
        'count'  => $plugin_count,
        'items'  => $plugin_updates,
    ];

    // ── Themes ────────────────────────────────────────────────────────────────
    $theme_update  = get_site_transient( 'update_themes' );
    $theme_updates = [];
    if ( ! empty( $theme_update->response ) ) {
        foreach ( $theme_update->response as $theme_slug => $data ) {
            $theme           = wp_get_theme( $theme_slug );
            $theme_updates[] = [
                'slug'    => $theme_slug,
                'name'    => $theme->get( 'Name' ) ?: $theme_slug,
                'current' => $theme->get( 'Version' ) ?: '?',
                'new'     => $data['new_version'] ?? '?',
            ];
        }
    }
    $theme_count = count( $theme_updates );
    if ( $theme_count > 0 ) {
        $alerts[] = "{$theme_count} Theme-Update(s) verfügbar.";
        $summary  = 'warning';
    }
    $updates['themes'] = [
        'status' => $theme_count > 0 ? 'warning' : 'ok',
        'count'  => $theme_count,
        'items'  => $theme_updates,
    ];

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'total_pending'  => $core_count + $plugin_count + $theme_count,
        'updates'        => $updates,
        'alerts'         => $alerts,
        'plugin_version' => BSCHI_VERSION,
    ];

    update_option( BSCHI_OPTION_UPDATES, $result );
    bschi_hub_post( '/api/v1/monitoring/updates', $result );

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 4: KOMMENTARE
// ═══════════════════════════════════════════════════════════════════════════════

function bschi_run_comment_check( string $trigger = 'manual' ): array {
    $counts = wp_count_comments();

    $pending  = (int) ( $counts->moderated      ?? 0 );
    $spam     = (int) ( $counts->spam           ?? 0 );
    $trash    = (int) ( $counts->trash          ?? 0 );
    $approved = (int) ( $counts->approved       ?? 0 );
    $total    = (int) ( $counts->total_comments ?? 0 );

    $alerts  = [];
    $summary = 'ok';

    if ( $pending > 0 ) {
        $alerts[] = "{$pending} Kommentar(e) warten auf Freigabe.";
        $summary  = 'warning';
    }
    if ( $spam > 10 ) {
        $alerts[] = "{$spam} Spam-Kommentare aufgelaufen — Spam-Ordner leeren empfohlen.";
        if ( $summary !== 'error' ) {
            $summary = 'warning';
        }
    }

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'comments'       => [
            'pending'  => $pending,
            'spam'     => $spam,
            'trash'    => $trash,
            'approved' => $approved,
            'total'    => $total,
        ],
        'alerts'         => $alerts,
        'plugin_version' => BSCHI_VERSION,
    ];

    update_option( BSCHI_OPTION_COMMENTS, $result );
    bschi_hub_post( '/api/v1/monitoring/comments', $result );

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 5: BESTELLSTATISTIKEN
// ═══════════════════════════════════════════════════════════════════════════════

function bschi_run_order_check( string $trigger = 'manual' ): array {
    $wc_stati = [
        'pending'        => 'Ausstehend',
        'processing'     => 'In Bearbeitung',
        'on-hold'        => 'Wartend',
        'completed'      => 'Abgeschlossen',
        'cancelled'      => 'Storniert',
        'refunded'       => 'Erstattet',
        'failed'         => 'Fehlgeschlagen',
        'checkout-draft' => 'Entwurf',
    ];

    $by_status = [];
    $total     = 0;
    $alerts    = [];
    $summary   = 'ok';

    foreach ( $wc_stati as $slug => $label ) {
        $count              = function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( $slug ) : 0;
        $by_status[ $slug ] = [ 'label' => $label, 'count' => $count ];
        $total             += $count;
    }

    $failed  = $by_status['failed']['count']  ?? 0;
    $pending = $by_status['pending']['count'] ?? 0;
    $on_hold = $by_status['on-hold']['count'] ?? 0;

    if ( $failed > 0 ) {
        $alerts[] = "{$failed} fehlgeschlagene Bestellung(en) vorhanden.";
        $summary  = 'warning';
    }
    if ( $pending > 5 ) {
        $alerts[] = "{$pending} Bestellungen ausstehend (Zahlungseingang unklar).";
        $summary  = 'warning';
    }
    if ( $on_hold > 3 ) {
        $alerts[] = "{$on_hold} Bestellungen auf Hold — manuelle Prüfung empfohlen.";
        if ( $summary !== 'error' ) {
            $summary = 'warning';
        }
    }

    $result = [
        'status'         => $summary,
        'trigger'        => $trigger,
        'timestamp'      => current_time( 'c' ),
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'total'          => $total,
        'by_status'      => $by_status,
        'alerts'         => $alerts,
        'plugin_version' => BSCHI_VERSION,
    ];

    update_option( BSCHI_OPTION_ORDERS, $result );
    bschi_hub_post( '/api/v1/monitoring/orders', $result );

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODUL 6: HEALTH CHECKS (Cron, Doppelbestellungen, MwSt., SEPA)
// ═══════════════════════════════════════════════════════════════════════════════

function bschi_check_cron(): array {
    $disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    if ( $disabled ) {
        return [
            'status'              => 'error',
            'wp_cron_disabled'    => true,
            'next_run_delta_s'    => null,
            'last_cron_run_ago_s' => null,
            'alerts'              => [ 'WP-Cron ist deaktiviert (DISABLE_WP_CRON=true)' ],
        ];
    }
    $next     = wp_next_scheduled( BSCHI_CRON_HOOK );
    $delta    = $next ? ( $next - time() ) : null;
    $settings = bschi_get_settings();
    $interval = bschi_interval_seconds( $settings['cron_interval'] );
    $status   = 'ok';
    $alerts   = [];

    // Geplanter Lauf: überfällig, nicht eingeplant oder zu weit in der Zukunft?
    if ( $delta === null || $delta < 0 || $delta > $interval * 2 ) {
        $status   = 'warning';
        $alerts[] = 'Nächster Cron-Lauf nicht planmäßig oder überfällig';
    }

    // Letzter echter Cron-Lauf (nur trigger='cron' setzt diesen Wert)
    $last_cron_ts  = (int) get_option( 'bschi_last_cron_run', 0 );
    if ( $last_cron_ts === 0 ) {
        // Migration: Wert des Vorgänger-Plugins übernehmen
        $last_cron_ts = (int) get_option( 'bschwm_last_cron_run', 0 );
    }
    $last_cron_ago = $last_cron_ts > 0 ? ( time() - $last_cron_ts ) : null;
    $max_age       = $interval * 2;

    if ( $last_cron_ago === null ) {
        $status   = 'warning';
        $alerts[] = 'Noch kein echter Cron-Lauf aufgezeichnet – Server Cron prüfen!';
    } elseif ( $last_cron_ago > $max_age ) {
        $status   = 'warning';
        $alerts[] = 'Letzter echter Cron-Lauf zu lange her (' . round( $last_cron_ago / 60 ) . ' Min.) – Server Cron prüfen!';
    }

    return [
        'status'              => $status,
        'wp_cron_disabled'    => false,
        'next_run_delta_s'    => $delta,
        'last_cron_run_ago_s' => $last_cron_ago,
        'alerts'              => $alerts,
    ];
}

function bschi_has_tax_rate( float $rate, string $class ): bool {
    global $wpdb;
    $tax_class = ( $class === '' ) ? '' : $class;
    // DECIMAL(10,4)-Spalte: Rundungstoleranz ±0.001 statt exakter Float-Vergleich
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE ABS(tax_rate - %f) < 0.001 AND tax_rate_class = %s",
        $rate,
        $tax_class
    ) );
    return $count > 0;
}

function bschi_check_german_market_tax(): bool {
    if ( ! class_exists( 'WGM_Tax' ) ) {
        return true; // Plugin nicht aktiv → Check irrelevant, kein Fehler
    }
    return method_exists( 'WGM_Tax', 'get_tax_rates' ) && ! empty( WGM_Tax::get_tax_rates() );
}

function bschi_check_tax(): array {
    $s = bschi_get_settings();

    $all_checks = [
        'tax_enabled'          => 'yes' === get_option( 'woocommerce_calc_taxes' ),
        'prices_include_tax'   => 'yes' === get_option( 'woocommerce_prices_include_tax' ),
        'display_shop_incl'    => 'incl' === get_option( 'woocommerce_tax_display_shop' ),
        'display_cart_incl'    => 'incl' === get_option( 'woocommerce_tax_display_cart' ),
        'standard_rate_19'     => bschi_has_tax_rate( 19.0, '' ),
        'reduced_rate_7'       => bschi_has_tax_rate( 7.0, 'reduced-rate' ),
        'german_market_active' => class_exists( 'WGM_Tax' ),
        'german_market_tax_ok' => bschi_check_german_market_tax(),
    ];

    // Deaktivierbare Checks (kritische Checks immer aktiv)
    $optional = [
        'prices_include_tax'   => 'check_tax_prices_include_tax',
        'display_shop_incl'    => 'check_tax_display_shop_incl',
        'display_cart_incl'    => 'check_tax_display_cart_incl',
        'reduced_rate_7'       => 'check_tax_reduced_rate_7',
        'german_market_tax_ok' => 'check_tax_german_market_tax_ok',
    ];

    $checks = [];
    foreach ( $all_checks as $key => $val ) {
        if ( isset( $optional[ $key ] ) && ! ( $s[ $optional[ $key ] ] ?? true ) ) {
            continue; // Check deaktiviert → überspringen
        }
        $checks[ $key ] = $val;
    }

    $alerts = [];
    foreach ( $checks as $k => $v ) {
        if ( ! $v ) {
            $alerts[] = "Check fehlgeschlagen: {$k}";
        }
    }
    return [
        'status' => empty( $alerts ) ? 'ok' : ( count( $alerts ) > 2 ? 'error' : 'warning' ),
        'alerts' => $alerts,
        'checks' => $checks,
    ];
}

function bschi_check_sepa(): array {
    global $wpdb;
    $token_table = $wpdb->prefix . 'woocommerce_payment_tokens';

    // WooPayments SEPA
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$token_table} WHERE type = %s", 'sepa_debit' ) );
    if ( $count > 0 ) {
        return [ 'status' => 'ok', 'plugin' => 'woocommerce-payments', 'mandate_count' => $count, 'alerts' => [] ];
    }

    // Stripe WC SEPA
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$token_table} WHERE type = %s", 'stripe_sepa' ) );
    if ( $count > 0 ) {
        return [ 'status' => 'ok', 'plugin' => 'woo-stripe-payment', 'mandate_count' => $count, 'alerts' => [] ];
    }

    // Mollie Mandate (eigene Tabelle)
    $mollie_table = $wpdb->prefix . 'mollie_pending_payment';
    $exists       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mollie_table ) );
    if ( $exists === $mollie_table ) {
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$mollie_table}`" ); // phpcs:ignore -- Tabellenname aus $wpdb->prefix (trusted) + Konstante
        if ( $count > 0 ) {
            return [ 'status' => 'ok', 'plugin' => 'mollie', 'mandate_count' => $count, 'alerts' => [] ];
        }
    }

    return [
        'status'        => 'warning',
        'plugin'        => 'none',
        'mandate_count' => 0,
        'alerts'        => [ 'Kein SEPA-Plugin gefunden oder keine Mandate' ],
    ];
}

/**
 * Health-Sub-Check Doppelbestellungen (Basis-Variante aus BSCHWM).
 * Phase 2 ersetzt das durch das Scoring in module-double-orders.php –
 * dieser Check bleibt für die Hub-Payload-Kompatibilität erhalten.
 */
function bschi_check_double_orders( int $window_minutes = 10 ): array {
    if ( ! class_exists( 'WC_Order_Query' ) ) {
        return [ 'status' => 'ok', 'count' => 0, 'window_minutes' => $window_minutes, 'examples' => [] ];
    }
    $orders = wc_get_orders( [
        'status'       => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
        'date_created' => '>' . strtotime( '-24 hours' ),
        'limit'        => 500, // Kein -1: verhindert Memory-Exhaustion bei großen Shops
        'return'       => 'objects',
        'type'         => 'shop_order', // Explizit: keine Refunds (HPOS-Kompatibilität)
    ] );
    // Gruppieren nach billing_email + order_total
    $groups = [];
    foreach ( $orders as $order ) {
        if ( ! $order instanceof \WC_Order ) {
            continue; // HPOS kann auch OrderRefund-Objekte liefern
        }
        $date_created = $order->get_date_created();
        if ( ! $date_created ) {
            continue;
        }
        $key              = strtolower( $order->get_billing_email() ) . '|' . number_format( (float) $order->get_total(), 2 );
        $groups[ $key ][] = $date_created->getTimestamp();
    }
    $duplicates = [];
    foreach ( $groups as $key => $timestamps ) {
        if ( count( $timestamps ) < 2 ) {
            continue;
        }
        sort( $timestamps );
        for ( $i = 1; $i < count( $timestamps ); $i++ ) {
            if ( ( $timestamps[ $i ] - $timestamps[ $i - 1 ] ) < ( $window_minutes * 60 ) ) {
                // E-Mail anonymisieren: user@example.com → u***@example.com
                $key_parts    = explode( '|', $key, 2 );
                $email        = $key_parts[0] ?? '';
                $amount       = $key_parts[1] ?? '0.00';
                $parts        = explode( '@', $email );
                $anon         = substr( $parts[0], 0, 1 ) . '***@' . ( $parts[1] ?? '' );
                $duplicates[] = $anon . ' (€' . $amount . ')';
                break;
            }
        }
    }
    $count = count( $duplicates );
    return [
        'status'         => $count > 0 ? 'warning' : 'ok',
        'count'          => $count,
        'window_minutes' => $window_minutes,
        'examples'       => array_slice( $duplicates, 0, 5 ),
    ];
}

// ─── Alle Monitoring-Checks ausführen ────────────────────────────────────────

function bschi_monitoring_run_all( string $trigger = 'manual' ): void {
    bschi_run_cache_check( $trigger );
    bschi_run_block_check( $trigger );
    bschi_run_update_check( $trigger );
    bschi_run_comment_check( $trigger );
    bschi_run_order_check( $trigger );

    $cron_result   = bschi_check_cron();
    $double_result = bschi_check_double_orders();
    $tax_result    = bschi_check_tax();

    $s           = bschi_get_settings();
    $sepa_result = ( $s['check_sepa'] ?? true )
        ? bschi_check_sepa()
        : [ 'status' => 'ok', 'plugin' => 'deaktiviert', 'mandate_count' => 0, 'alerts' => [] ];

    // Worst-Status über alle Sub-Checks berechnen
    $status_order  = [ 'ok' => 0, 'warning' => 1, 'error' => 2 ];
    $health_status = 'ok';
    foreach ( [ $cron_result, $double_result, $tax_result, $sepa_result ] as $sub ) {
        $sub_status = $sub['status'] ?? 'ok';
        if ( ( $status_order[ $sub_status ] ?? 0 ) > ( $status_order[ $health_status ] ?? 0 ) ) {
            $health_status = $sub_status;
        }
    }

    $health_payload = [
        'status'         => $health_status,
        'source'         => parse_url( get_site_url(), PHP_URL_HOST ),
        'trigger'        => $trigger,
        'timestamp'      => gmdate( 'c' ),
        'plugin_version' => BSCHI_VERSION,
        'cron'           => $cron_result,
        'double_orders'  => $double_result,
        'tax'            => $tax_result,
        'sepa'           => $sepa_result,
    ];
    bschi_hub_post( '/api/v1/monitoring/health', $health_payload );
    update_option( BSCHI_OPTION_HEALTH, $health_payload );
}
