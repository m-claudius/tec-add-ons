<?php
/**
 * Plugin Name: TEC add ons
 * Plugin URI:  https://github.com/m-claudius/tec-add-ons
 * Description: Zusätze für The Events Calendar: Filterleiste, „Hervorgehobene Veranstaltung“ zuerst, Newsletter (Cron/Test/Logs), Community-Events (Frontend-Einreichung, Moderation, Nutzer ↔ Quelle).
 * Version: 1.6.3
 * Author: Matthias Clausen
 * Author URI: https://github.com/m-claudius
 * Requires at least: 6.1
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tec-add-ons
 * Update URI: false
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TEC_ADDONS_VERSION', '1.6.3' );
define( 'TEC_ADDONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEC_ADDONS_URL', plugin_dir_url( __FILE__ ) );

// Mailing helpers (must load BEFORE class-mailing.php facade)
require_once TEC_ADDONS_DIR . 'includes/mailing/class-schedule.php';
require_once TEC_ADDONS_DIR . 'includes/mailing/class-sources.php';
require_once TEC_ADDONS_DIR . 'includes/mailing/class-renderer.php';
require_once TEC_ADDONS_DIR . 'includes/mailing/class-sender.php';

require_once TEC_ADDONS_DIR . 'includes/class-admin.php';
require_once TEC_ADDONS_DIR . 'includes/class-featured-first.php';
require_once TEC_ADDONS_DIR . 'includes/class-filter-bar.php';
require_once TEC_ADDONS_DIR . 'includes/class-mailing.php';
require_once TEC_ADDONS_DIR . 'includes/class-subscribe.php';
require_once TEC_ADDONS_DIR . 'includes/class-stats.php';
require_once TEC_ADDONS_DIR . 'includes/class-community.php'; // NEU

register_activation_hook( __FILE__, function() {
	\TEC_Addons\Subscribe::maybe_install_tables();
	\TEC_Addons\Mailing::maybe_install_log_table();
	\TEC_Addons\Mailing::maybe_schedule_cron();
	\TEC_Addons\Community::maybe_install_tables(); // NEU
});

register_deactivation_hook( __FILE__, function() {
	\TEC_Addons\Mailing::clear_cron();
});

add_action( 'plugins_loaded', function() {
	\TEC_Addons\Admin::init();
	\TEC_Addons\Featured_First::init();
	\TEC_Addons\Filter_Bar::init();
	\TEC_Addons\Mailing::init();
	\TEC_Addons\Subscribe::init();
	\TEC_Addons\Stats::init();
	\TEC_Addons\Community::init(); // NEU
	\TEC_Addons\Subscribe::maybe_install_tables();
	\TEC_Addons\Mailing::maybe_install_log_table();
	\TEC_Addons\Community::maybe_install_tables(); // sicherheitshalber
});
