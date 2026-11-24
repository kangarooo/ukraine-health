<?php
/**
 * Plugin Name:       Ukraine Health – Hero Support
 * Plugin URI:        https://github.com/kangarooo/ukraine-health
 * Description:       Connects Heroes (patients in rehabilitation) with Supporters (hairdressers, nail care, clothing, care products, mobility aids…). Requests are grouped per hospital and service; when a group reaches its minimum size, matching Supporters are notified (email, Telegram, WhatsApp) and can accept it with an appointment date.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            kangarooo
 * License:           GPL-2.0-or-later
 * Text Domain:       ukraine-health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UH_VERSION', '1.1.0' );
define( 'UH_DB_VERSION', '2' );
define( 'UH_FILE', __FILE__ );
define( 'UH_DIR', plugin_dir_path( __FILE__ ) );
define( 'UH_URL', plugin_dir_url( __FILE__ ) );

require_once UH_DIR . 'includes/class-uh-install.php';
require_once UH_DIR . 'includes/class-uh-repo.php';
require_once UH_DIR . 'includes/class-uh-network.php';
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
		load_plugin_textdomain( 'ukraine-health', false, dirname( plugin_basename( UH_FILE ) ) . '/languages' );
		UH_Install::maybe_upgrade();
		UH_Network::init();
		new UH_Frontend();
		new UH_Rest();
		if ( is_admin() ) {
			new UH_Admin();
		}
	}
);
