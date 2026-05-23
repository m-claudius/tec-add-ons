<?php
namespace TEC_Addons;

if ( ! defined('ABSPATH') ) { exit; }

use TEC_Addons\Mailing\Schedule;
use TEC_Addons\Mailing\Renderer;
use TEC_Addons\Mailing\Sender;

// Doppeldefinition vermeiden
if (class_exists(__NAMESPACE__.'\\Mailing', false)) { return; }

// Helper sind in tec-add-ons.php geladen; defensive Sicherung:
if ( ! class_exists('TEC_Addons\\Mailing\\Schedule', false) ) {
    require_once __DIR__ . '/mailing/class-schedule.php';
    require_once __DIR__ . '/mailing/class-sources.php';
    require_once __DIR__ . '/mailing/class-renderer.php';
    require_once __DIR__ . '/mailing/class-sender.php';
}

/**
 * Mailing — Fassade.
 * Interne Logik in TEC_Addons\Mailing\{Schedule,Sources,Renderer,Sender}.
 * Öffentliche API (init, maybe_install_tables, maybe_install_log_table,
 * maybe_schedule_cron, clear_cron, render_log_table, alle Hook-Callbacks)
 * bleibt unverändert.
 */
class Mailing {

    /* ================= Bootstrap ================= */
    public static function init(): void {
        // Admin actions
        add_action('admin_post_tec_addons_test_weekly',   [__CLASS__, 'test_weekly']);
        add_action('admin_post_tec_addons_test_monthly',  [__CLASS__, 'test_monthly']);
        add_action('admin_post_tec_addons_send_weekly',   [__CLASS__, 'send_weekly_now']);
        add_action('admin_post_tec_addons_send_monthly',  [__CLASS__, 'send_monthly_now']);
        add_action('admin_post_tec_addons_reschedule',    [__CLASS__, 'reschedule_now']);

        // Cron hooks
        add_action('tec_addons_cron_weekly',              [__CLASS__, 'cron_weekly']);
        add_action('tec_addons_cron_monthly',             [__CLASS__, 'cron_monthly']);

        // Toggle hooks (Signatur: old_value, value, option)
        add_action('update_option_tec_addons_weekly_enabled',  [__CLASS__, 'on_toggle_weekly'], 10, 3);
        add_action('update_option_tec_addons_monthly_enabled', [__CLASS__, 'on_toggle_monthly'], 10, 3);

        // wp_mail Fehler loggen
        add_action('wp_mail_failed', [__CLASS__, 'on_mail_failed'], 10, 1);
        add_action('admin_notices',  [__CLASS__, 'render_mailing_quick_actions']);

        // Tabellen anlegen (inkl. Back-Compat)
        self::maybe_install_tables();

        self::maybe_schedule_cron();
    }

    /* ================= DB-Setup ================= */
    public static function maybe_install_tables(): void {
        global $wpdb;
        $log_table  = $wpdb->prefix.'tec_addons_mail_log';
        $sent_table = $wpdb->prefix.'tec_addons_mail_sent';

        $log_exists  = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $log_table) )  === $log_table;
        $sent_exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $sent_table) ) === $sent_table;
        if ( $log_exists && $sent_exists ) return;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE {$log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_key VARCHAR(64) NOT NULL,
            run_type VARCHAR(16) NOT NULL,
            triggered_by VARCHAR(16) NOT NULL,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            recipients INT NOT NULL DEFAULT 0,
            success_cnt INT NOT NULL DEFAULT 0,
            error_cnt INT NOT NULL DEFAULT 0,
            errors LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_run_key (run_key)
        ) {$charset};";

        $sql2 = "CREATE TABLE {$sent_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_key VARCHAR(64) NOT NULL,
            email VARCHAR(190) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'sent',
            error TEXT NULL,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_run_email (run_key,email),
            KEY idx_email (email)
        ) {$charset};";

        dbDelta($sql1);
        dbDelta($sql2);
    }
    // Back-Compat für Bootstrap
    public static function maybe_install_log_table(): void  { self::maybe_install_tables(); }
    public static function maybe_install_sent_table(): void { self::maybe_install_tables(); }

    /* ================= Planung / Reschedule ================= */
    public static function maybe_schedule_cron(): void { Schedule::maybe_schedule_cron(); }
    public static function clear_cron(): void          { Schedule::clear_cron(); }

    public static function reschedule_now(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_reschedule', ['_tec_addons_reschedule']);
        Schedule::clear_cron();
        Schedule::maybe_schedule_cron();
        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ); exit;
    }
    public static function on_toggle_weekly($old, $new, $option): void  { Schedule::clear_cron(); Schedule::maybe_schedule_cron(); }
    public static function on_toggle_monthly($old, $new, $option): void { Schedule::clear_cron(); Schedule::maybe_schedule_cron(); }

    /* ================= Mail-Fehler ================= */
    public static function on_mail_failed($wp_error): void { Sender::on_mail_failed($wp_error); }

    /* ================= Public Actions ================= */
    public static function test_weekly(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_test_weekly', ['_tec_addons_test_weekly']);
        [$start,$end] = Schedule::window_weekly_test();
        $html = Renderer::build_mail_html(__('Wochenmail','tec-add-ons'), 'weekly', $start, $end, null);
        Sender::send_mail(Sender::current_admin_email(), '[TEST] '.__('Wochenmail','tec-add-ons'), $html);
        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ); exit;
    }
    public static function test_monthly(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_test_monthly', ['_tec_addons_test_monthly']);
        [$start,$end] = Schedule::window_monthly_test();
        $html = Renderer::build_mail_html(__('Monatsmail','tec-add-ons'), 'monthly', $start, $end, null);
        Sender::send_mail(Sender::current_admin_email(), '[TEST] '.__('Monatsmail','tec-add-ons'), $html);
        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ); exit;
    }

    public static function send_weekly_now(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_send_weekly', ['_tec_addons_send_weekly']);

        $force = (isset($_REQUEST['force']) && (string)$_REQUEST['force'] === '1');
        Sender::run('weekly', $force ? 'manual-force' : 'manual', $force);

        wp_safe_redirect( add_query_arg(['tec-sent'=>'weekly','forced'=>$force?1:0], wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ) );
        exit;
    }

    public static function send_monthly_now(): void {
        if ( ! current_user_can('manage_options') ) wp_die(__('Nicht erlaubt.','tec-add-ons'));
        self::check_nonce_relaxed('tec_addons_send_monthly', ['_tec_addons_send_monthly']);

        $force = (isset($_REQUEST['force']) && (string)$_REQUEST['force'] === '1');
        Sender::run('monthly', $force ? 'manual-force' : 'manual', $force);

        wp_safe_redirect( add_query_arg(['tec-sent'=>'monthly','forced'=>$force?1:0], wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-mailing') ) );
        exit;
    }

    /* ================= Cron ================= */
    public static function cron_weekly(): void {
        if ( ! Schedule::weekly_enabled() ) return;
        Sender::run('weekly','cron');
        if ( Schedule::weekly_enabled() ) wp_schedule_single_event( Schedule::next_weekly_timestamp(), 'tec_addons_cron_weekly' );
    }
    public static function cron_monthly(): void {
        if ( ! Schedule::monthly_enabled() ) return;
        Sender::run('monthly','cron');
        if ( Schedule::monthly_enabled() ) wp_schedule_single_event( Schedule::next_monthly_timestamp(), 'tec_addons_cron_monthly' );
    }

    /* ================= Admin UI ================= */
    public static function render_mailing_quick_actions(): void {
        if ( ! is_admin() ) return;
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($page !== 'tec-add-ons-mailing') return;

        $action_url = admin_url('admin-post.php');
        $nw  = wp_create_nonce('tec_addons_send_weekly');
        $nm  = wp_create_nonce('tec_addons_send_monthly');

        echo '<div class="notice notice-info" style="padding:12px 16px;margin-top:12px;">';
        echo '<p style="margin:.4em 0 .8em 0;"><strong>Direktversand</strong></p>';

        // Normaler Wochenlauf (benutzt aktuellen run_key)
        echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;margin-right:8px;">'
           . '<input type="hidden" name="action" value="tec_addons_send_weekly"/>'
           . '<input type="hidden" name="_tec_addons_send_weekly" value="'.esc_attr($nw).'"/>'
           . '<button class="button button-primary">Wochenmail jetzt senden</button>'
           . '</form>';

        // Neuer Wochenlauf (frischer run_key)
        echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;margin-right:24px;">'
           . '<input type="hidden" name="action" value="tec_addons_send_weekly"/>'
           . '<input type="hidden" name="_tec_addons_send_weekly" value="'.esc_attr($nw).'"/>'
           . '<input type="hidden" name="force" value="1"/>'
           . '<button class="button">Wochenmail jetzt senden (neuer Lauf)</button>'
           . '</form>';

        // Optional: Monatsmail
        echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;margin-right:8px;">'
           . '<input type="hidden" name="action" value="tec_addons_send_monthly"/>'
           . '<input type="hidden" name="_tec_addons_send_monthly" value="'.esc_attr($nm).'"/>'
           . '<button class="button">Monatsmail jetzt senden</button>'
           . '</form>';

        echo '<form method="post" action="'.esc_url($action_url).'" style="display:inline-block;">'
           . '<input type="hidden" name="action" value="tec_addons_send_monthly"/>'
           . '<input type="hidden" name="_tec_addons_send_monthly" value="'.esc_attr($nm).'"/>'
           . '<input type="hidden" name="force" value="1"/>'
           . '<button class="button">Monatsmail jetzt senden (neuer Lauf)</button>'
           . '</form>';

        echo '</div>';
    }

    public static function render_log_table(int $limit=50): void { Sender::render_log_table($limit); }

    /* ================= UI Helper ================= */
    protected static function check_nonce_relaxed(string $action, array $fields): void {
        foreach ($fields as $f){
            if ( isset($_REQUEST[$f]) && wp_verify_nonce( sanitize_text_field( wp_unslash($_REQUEST[$f]) ), $action ) ) return;
        }
        if ( current_user_can('manage_options') ) return; // Admin darf testen
        wp_die(__('Ungültige Anfrage (Nonce).','tec-add-ons'));
    }
}
