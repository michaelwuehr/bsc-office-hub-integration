<?php
/**
 * Plugin Name:  BSC - Office Hub Integration
 * Description:  Verbindet WooCommerce mit dem BSC Office Hub: Shop-Gesundheits-Monitoring,
 *               Doppelbestellungs-Erkennung, Sale-Banner-Shortcode, Kundendokumente,
 *               Preislisten und Woidsiederei-Chat. Nachfolger des BSC WC Health Monitors.
 * Version:      3.50.0
 * Author:       Michael Wühr
 * License:      GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Update URI:   https://github.com/michaelwuehr/bsc-office-hub-integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Konstanten ───────────────────────────────────────────────────────────────

define( 'BSCHI_VERSION',          '3.50.0' );
define( 'BSCHI_PLUGIN_FILE',      __FILE__ );
define( 'BSCHI_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'BSCHI_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'BSCHI_SLUG',             'bsc-office-hub-integration' );
define( 'BSCHI_OPTION_SETTINGS',  'bschi_settings' );
define( 'BSCHI_CRON_HOOK',        'bschi_scheduled_check' );
define( 'BSCHI_SINGLE_HOOK',      'bschi_single_check' );
define( 'BSCHI_GITHUB_REPO',      'michaelwuehr/bsc-office-hub-integration' );
define( 'BSCHI_UPDATE_TRANSIENT', 'bschi_github_release' );

// ─── Custom Cron Schedules ────────────────────────────────────────────────────

add_filter( 'cron_schedules', function ( array $schedules ): array {
    $schedules['bschi_30min'] = [ 'interval' => 1800,  'display' => 'Alle 30 Minuten' ];
    $schedules['bschi_1h']    = [ 'interval' => 3600,  'display' => 'Stündlich (60 Min.)' ];
    $schedules['bschi_2h']    = [ 'interval' => 7200,  'display' => 'Alle 2 Stunden' ];
    $schedules['bschi_6h']    = [ 'interval' => 21600, 'display' => 'Alle 6 Stunden' ];
    return $schedules;
} );

function bschi_interval_options(): array {
    return [
        'bschi_30min' => 'Alle 30 Minuten',
        'bschi_1h'    => 'Stündlich (60 Min.) – Standard',
        'bschi_2h'    => 'Alle 2 Stunden',
        'bschi_6h'    => 'Alle 6 Stunden',
        'daily'       => 'Täglich',
    ];
}

function bschi_interval_seconds( string $interval ): int {
    return [
        'bschi_30min' => 1800,
        'bschi_1h'    => 3600,
        'bschi_2h'    => 7200,
        'bschi_6h'    => 21600,
        'daily'       => 86400,
    ][ $interval ] ?? 3600;
}

function bschi_reschedule_cron( string $interval ): void {
    wp_clear_scheduled_hook( BSCHI_CRON_HOOK );
    wp_schedule_event( time(), $interval, BSCHI_CRON_HOOK );
}

// ─── Einstellungen ────────────────────────────────────────────────────────────

function bschi_default_settings(): array {
    return [
        'site_url'      => get_site_url(),
        'hub_url'       => '',
        'hub_secret'    => '',
        'cron_interval' => 'bschi_1h',

        // Feature-Schalter (Module)
        'feature_monitoring'    => true,
        'feature_double_orders' => true,
        'feature_sale_banner'   => false,
        'feature_aktion_banner' => false,
        'feature_event_banner'  => false,
        'feature_offen_banner'  => false,
        'feature_exit_popup'    => false,
        'feature_social_feed'   => false,
        'feature_customer_docs' => false,
        'feature_pricelist'     => false,
        'feature_chat'          => false,
        'feature_tracking'      => false,   // First-Party-Tracking – erst nach DSE-Update aktivieren!

        // Tracking (Marketing Hub, Kampagnen-Analytics)
        'tracking_hub_url'      => 'https://marketing.bsc-theresienthal.de',
        'tracking_secret'       => '',

        // Social-Media-Feed: anzuzeigende Plattformen (kommagetrennt)
        'social_platforms'      => 'instagram,facebook',

        // Stellenanzeigen [bsc_hub_jobs]
        'jobs_layout'       => 'compact',// list (untereinander) | grid (Kacheln, voll) | compact (Kacheln, Aufklapper)
        'jobs_columns'      => 3,        // Spalten im Grid (2–4)
        'jobs_apply_url'    => '',       // "Jetzt bewerben"-Ziel (Formular-URL); leer = mailto aus Kontakttext
        'jobs_apply_label'  => 'Jetzt bewerben',

        // Veranstaltungs-Banner [bsc_hub_event]
        'event_layout'      => 'cards',  // cards (Karten-Grid) | list (Übersichts-Liste)
        'event_lead_days'   => 0,        // Vorlaufzeit: nur Termine in den nächsten N Tagen (0 = ohne Begrenzung)
        'event_today_hours' => 0,        // Heute-Banner: Termine bis X Stunden im Voraus zeigen (0 = nur heute)

        // Badge-Layout: Chat-Badge + Trusted-Shops-Badge (Seite/Abstand/Größe je Gerät)
        'badge_chat_d_side' => 'right', 'badge_chat_d_x' => 18, 'badge_chat_d_y' => 18, 'badge_chat_d_size' => 74,
        'badge_chat_m_side' => 'left',  'badge_chat_m_x' => 18, 'badge_chat_m_y' => 96, 'badge_chat_m_size' => 74,
        'badge_ts_d_side'   => 'left',  'badge_ts_d_x'   => 18,   // side 'default' = TS-Badge nicht anfassen
        'badge_ts_m_side'   => 'left',  'badge_ts_m_x'   => 18,

        // Doppelbestellungen
        'double_orders_autohold'    => true,   // jüngere Bestellung automatisch auf "Wartend"
        'double_orders_window_h'    => 48,     // Kandidaten-Zeitfenster in Stunden
        'double_orders_score_min'   => 60,     // Duplikat ab diesem Score

        // Monitoring: optionale Checks (aus BSCHWM F-01/F-02 übernommen)
        'check_session_fix'              => true,
        'check_fb_fix'                   => true,
        'check_tax_prices_include_tax'   => true,
        'check_tax_display_shop_incl'    => true,
        'check_tax_display_cart_incl'    => true,
        'check_tax_reduced_rate_7'       => true,
        'check_tax_german_market_tax_ok' => true,
        'check_sepa'                     => true,
    ];
}

function bschi_get_settings(): array {
    return wp_parse_args(
        get_option( BSCHI_OPTION_SETTINGS, [] ),
        bschi_default_settings()
    );
}

function bschi_feature_enabled( string $feature ): bool {
    $s = bschi_get_settings();
    return (bool) ( $s[ 'feature_' . $feature ] ?? false );
}

/**
 * Migration vom Vorgänger-Plugin "BSC WC Health Monitor" (bschwm_settings).
 * Übernimmt Hub-URL, Secret, Intervall und Check-Toggles – einmalig.
 */
function bschi_migrate_from_bschwm(): void {
    if ( get_option( BSCHI_OPTION_SETTINGS, null ) !== null ) {
        return; // bereits konfiguriert
    }
    $old = get_option( 'bschwm_settings', null );
    if ( ! is_array( $old ) ) {
        return;
    }
    $interval_map = [
        'bschwm_30min' => 'bschi_30min',
        'bschwm_1h'    => 'bschi_1h',
        'bschwm_2h'    => 'bschi_2h',
        'bschwm_6h'    => 'bschi_6h',
        'daily'        => 'daily',
    ];
    $new = bschi_default_settings();
    foreach ( [ 'site_url', 'hub_url', 'hub_secret' ] as $key ) {
        if ( ! empty( $old[ $key ] ) ) {
            $new[ $key ] = $old[ $key ];
        }
    }
    $new['cron_interval'] = $interval_map[ $old['cron_interval'] ?? '' ] ?? 'bschi_1h';
    foreach ( [
        'check_session_fix', 'check_fb_fix',
        'check_tax_prices_include_tax', 'check_tax_display_shop_incl',
        'check_tax_display_cart_incl', 'check_tax_reduced_rate_7',
        'check_tax_german_market_tax_ok', 'check_sepa',
    ] as $key ) {
        if ( isset( $old[ $key ] ) ) {
            $new[ $key ] = (bool) $old[ $key ];
        }
    }
    update_option( BSCHI_OPTION_SETTINGS, $new );
}

// ─── Module laden ─────────────────────────────────────────────────────────────

require_once BSCHI_PLUGIN_DIR . 'includes/class-hub-client.php';
require_once BSCHI_PLUGIN_DIR . 'includes/class-updater.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-monitoring.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-double-orders.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-sale-banner.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-events.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-opening-hours.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-exit-popup.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-social.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-customer-docs.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-account-icons.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-pricelist.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-chat.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-fuehrung.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-jobs.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-tracking.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-voucher.php';
require_once BSCHI_PLUGIN_DIR . 'includes/module-gutschein-shop.php';
require_once BSCHI_PLUGIN_DIR . 'includes/admin-page.php';

// ─── Cron-Lauf: alle aktiven Modul-Checks ─────────────────────────────────────

function bschi_run_all_checks( string $trigger = 'manual' ): void {
    if ( $trigger === 'cron' ) {
        update_option( 'bschi_last_cron_run', time() );
    }
    if ( bschi_feature_enabled( 'monitoring' ) ) {
        bschi_monitoring_run_all( $trigger );
    }
}

add_action( BSCHI_CRON_HOOK, function () {
    bschi_run_all_checks( 'cron' );
} );

add_action( BSCHI_SINGLE_HOOK, function ( string $trigger ) {
    bschi_run_all_checks( $trigger );
} );

// Nach einem Plugin-Update einmalig prüfen (30s verzögert)
add_action( 'upgrader_process_complete', function ( $upgrader, $options ) {
    if ( ( $options['type'] ?? '' ) === 'plugin' ) {
        wp_schedule_single_event( time() + 30, BSCHI_SINGLE_HOOK, [ 'plugin_update' ] );
    }
}, 10, 2 );

// ─── Aktivierung / Deaktivierung ─────────────────────────────────────────────

register_activation_hook( __FILE__, function () {
    bschi_migrate_from_bschwm();
    $settings = bschi_get_settings();
    if ( ! wp_next_scheduled( BSCHI_CRON_HOOK ) ) {
        wp_schedule_event( time(), $settings['cron_interval'], BSCHI_CRON_HOOK );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( BSCHI_CRON_HOOK );
    wp_clear_scheduled_hook( BSCHI_SINGLE_HOOK );
} );
