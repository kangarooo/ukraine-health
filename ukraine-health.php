<?php
/**
 * Plugin Name:       Ukraine Health – Hero Support
 * Plugin URI:        https://github.com/kangarooo/ukraine-health
 * Description:       Connects Heroes (patients in rehabilitation) with Supporters (hairdressers, nail care, clothing, care products, mobility aids…). Requests are grouped per hospital and service; when a group reaches its minimum size, matching Supporters are notified (email, Telegram, WhatsApp) and can accept it with an appointment date.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            kangarooo
 * License:           GPL-2.0-or-later
 * Text Domain:       ukraine-health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UH_VERSION', '1.3.0' );
define( 'UH_DB_VERSION', '4' );
define( 'UH_FILE', __FILE__ );
define( 'UH_DIR', plugin_dir_path( __FILE__ ) );
define( 'UH_URL', plugin_dir_url( __FILE__ ) );

require_once UH_DIR . 'includes/class-uh-install.php';
require_once UH_DIR . 'includes/class-uh-repo.php';
require_once UH_DIR . 'includes/class-uh-network.php';
require_once UH_DIR . 'includes/class-uh-privacy.php';
require_once UH_DIR . 'includes/class-uh-reports.php';
require_once UH_DIR . 'includes/class-uh-cron.php';
require_once UH_DIR . 'includes/class-uh-telegram.php';
require_once UH_DIR . 'includes/class-uh-twofa.php';
require_once UH_DIR . 'includes/class-uh-i18n.php';
require_once UH_DIR . 'includes/class-uh-notify.php';
require_once UH_DIR . 'includes/class-uh-matching.php';
require_once UH_DIR . 'includes/class-uh-frontend.php';
require_once UH_DIR . 'includes/class-uh-admin.php';
require_once UH_DIR . 'includes/class-uh-rest.php';

register_activation_hook( __FILE__, array( 'UH_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'UH_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		UH_I18n::init();
		load_plugin_textdomain( 'ukraine-health', false, dirname( plugin_basename( UH_FILE ) ) . '/languages' );
		UH_Install::maybe_upgrade();
		UH_Network::init();
		UH_Privacy::init();
		UH_Cron::init();
		UH_Telegram::init();
		UH_TwoFA::init();
		new UH_Frontend();
		new UH_Rest();
		if ( is_admin() ) {
			new UH_Admin();
		}
	}
);
