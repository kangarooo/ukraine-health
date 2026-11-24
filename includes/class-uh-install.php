<?php
/**
 * Tables, roles and default data.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Install {

	const ROLE_HERO        = 'uh_hero';
	const ROLE_SUPPORTER   = 'uh_supporter';
	const ROLE_COORDINATOR = 'uh_coordinator';
	const CAP_MANAGE       = 'uh_manage';

	public static function activate() {
		self::create_tables();
		self::add_roles();
		self::seed();
		if ( false === get_option( 'uh_settings' ) ) {
			add_option( 'uh_settings', self::default_settings() );
		}
		update_option( 'uh_db_version', UH_DB_VERSION );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'uh_daily_digest' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'uh_db_version' ) !== UH_DB_VERSION ) {
			self::activate();
		}
	}

	public static function default_settings() {
		return array(
			'allow_guests'     => 1,
			'messengers'       => array( 'WhatsApp', 'Telegram', 'Viber', 'Signal' ),
			'admin_email'      => get_option( 'admin_email' ),
			'notify_admin'     => 1,
			'telegram_token'   => '',
			'telegram_admin'   => '',
			'wa_gateway'       => 'disabled',
			'twilio_sid'       => '',
			'twilio_token'     => '',
			'twilio_from'      => '',
			'meta_token'       => '',
			'meta_phone_id'    => '',
			'rate_limit'       => 5,
			'invite_guests'    => 1,
			'privacy_notice'   => __( 'Your phone number is shared only with the coordinator and the Supporter who accepts your group.', 'ukraine-health' ),
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( 'uh_settings', array() ), self::default_settings() );
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'uh_';

		dbDelta(
			"CREATE TABLE {$p}hospitals (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			country VARCHAR(64) NOT NULL DEFAULT '',
			city VARCHAR(128) NOT NULL DEFAULT '',
			address VARCHAR(255) NOT NULL DEFAULT '',
			contact VARCHAR(255) NOT NULL DEFAULT '',
			active TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY country (country)
		) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}services (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			category VARCHAR(64) NOT NULL DEFAULT 'other',
			description TEXT NULL,
			min_group INT UNSIGNED NOT NULL DEFAULT 1,
			needs_sizes TINYINT(1) NOT NULL DEFAULT 0,
			chat_url VARCHAR(255) NOT NULL DEFAULT '',
			minutes_each INT UNSIGNED NOT NULL DEFAULT 30,
			active TINYINT(1) NOT NULL DEFAULT 1,
			sort INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY category (category)
		) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}groups (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			service_id BIGINT UNSIGNED NOT NULL,
			hospital_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			supporter_id BIGINT UNSIGNED NULL,
			appointment DATETIME NULL,
			supporter_note TEXT NULL,
			created_at DATETIME NOT NULL,
			ready_at DATETIME NULL,
			assigned_at DATETIME NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY svc_hosp_status (service_id, hospital_id, status),
			KEY supporter_id (supporter_id)
		) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}requests (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			name VARCHAR(128) NOT NULL DEFAULT '',
			phone VARCHAR(32) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			messenger VARCHAR(32) NOT NULL DEFAULT '',
			ward VARCHAR(64) NOT NULL DEFAULT '',
			sizes TEXT NULL,
			notes TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'waiting',
			token CHAR(32) NOT NULL,
			invited_by BIGINT UNSIGNED NULL,
			invite_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY user_id (user_id),
			KEY token (token),
			KEY invited_by (invited_by)
		) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}invites (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(32) NOT NULL,
			inviter_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(16) NOT NULL DEFAULT 'hero',
			note VARCHAR(128) NOT NULL DEFAULT '',
			max_uses INT UNSIGNED NOT NULL DEFAULT 1,
			uses INT UNSIGNED NOT NULL DEFAULT 0,
			revoked TINYINT(1) NOT NULL DEFAULT 0,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY inviter_id (inviter_id)
		) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NULL,
			request_id BIGINT UNSIGNED NULL,
			actor_id BIGINT UNSIGNED NULL,
			action VARCHAR(48) NOT NULL,
			detail TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id)
		) $c;"
		);
	}

	public static function add_roles() {
		add_role( self::ROLE_HERO, __( 'Hero (patient)', 'ukraine-health' ), array( 'read' => true ) );
		add_role( self::ROLE_SUPPORTER, __( 'Supporter (provider)', 'ukraine-health' ), array( 'read' => true ) );
		add_role(
			self::ROLE_COORDINATOR,
			__( 'Coordinator', 'ukraine-health' ),
			array(
				'read'           => true,
				'list_users'     => true,
				self::CAP_MANAGE => true,
			)
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
		}
	}

	private static function seed() {
		global $wpdb;
		$p = $wpdb->prefix . 'uh_';

		if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}services" ) ) {
			// name, category, min_group, needs_sizes, minutes_each.
			$services = array(
				array( 'Haircut', 'beauty', 4, 0, 30 ),
				array( 'Shaving / beard trim', 'beauty', 4, 0, 20 ),
				array( 'Manicure', 'beauty', 3, 0, 40 ),
				array( 'Pedicure', 'beauty', 3, 0, 45 ),
				array( 'Clothing', 'clothing', 1, 1, 15 ),
				array( 'Shoes', 'clothing', 1, 1, 15 ),
				array( 'Laundry', 'clothing', 3, 0, 20 ),
				array( 'Hygiene products', 'care', 1, 0, 10 ),
				array( 'Skin care products', 'care', 1, 0, 10 ),
				array( 'Crutches', 'mobility', 1, 0, 15 ),
				array( 'Walking cane', 'mobility', 1, 0, 10 ),
				array( 'Wheelchair', 'mobility', 1, 0, 30 ),
				array( 'Walker / rollator', 'mobility', 1, 0, 20 ),
				array( 'Massage', 'wellbeing', 3, 0, 45 ),
				array( 'Psychological support', 'wellbeing', 1, 0, 60 ),
				array( 'Translation / paperwork help', 'other', 1, 0, 30 ),
			);
			$i = 0;
			foreach ( $services as $s ) {
				$wpdb->insert(
					"{$p}services",
					array(
						'name'         => $s[0],
						'category'     => $s[1],
						'min_group'    => $s[2],
						'needs_sizes'  => $s[3],
						'minutes_each' => $s[4],
						'sort'         => $i++,
					)
				);
			}
		}

		if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}hospitals" ) ) {
			$hospitals = array(
				array( 'Rīgas Austrumu klīniskā universitātes slimnīca', 'Latvia', 'Rīga', 'Hipokrāta iela 2' ),
				array( 'Paula Stradiņa klīniskā universitātes slimnīca', 'Latvia', 'Rīga', 'Pilsoņu iela 13' ),
				array( 'Traumatoloģijas un ortopēdijas slimnīca', 'Latvia', 'Rīga', 'Duntes iela 22' ),
				array( 'Nacionālais rehabilitācijas centrs "Vaivari"', 'Latvia', 'Jūrmala', 'Asaru prospekts 61' ),
				array( 'Rīgas 2. slimnīca', 'Latvia', 'Rīga', 'Ģimnastikas iela 1' ),
				array( 'Liepājas reģionālā slimnīca', 'Latvia', 'Liepāja', 'Slimnīcas iela 25' ),
				array( 'Daugavpils reģionālā slimnīca', 'Latvia', 'Daugavpils', 'Vasarnīcu iela 20' ),
				array( 'Vidzemes slimnīca', 'Latvia', 'Valmiera', 'Jumaras iela 195' ),
			);
			foreach ( $hospitals as $h ) {
				$wpdb->insert(
					"{$p}hospitals",
					array(
						'name'    => $h[0],
						'country' => $h[1],
						'city'    => $h[2],
						'address' => $h[3],
					)
				);
			}
		}
	}

	public static function categories() {
		return array(
			'beauty'    => __( 'Beauty', 'ukraine-health' ),
			'clothing'  => __( 'Clothing & shoes', 'ukraine-health' ),
			'care'      => __( 'Care products', 'ukraine-health' ),
			'mobility'  => __( 'Mobility aids', 'ukraine-health' ),
			'wellbeing' => __( 'Wellbeing', 'ukraine-health' ),
			'other'     => __( 'Other', 'ukraine-health' ),
		);
	}

	public static function group_statuses() {
		return array(
			'open'      => __( 'Collecting', 'ukraine-health' ),
			'ready'     => __( 'Ready – needs Supporter', 'ukraine-health' ),
			'assigned'  => __( 'Scheduled', 'ukraine-health' ),
			'completed' => __( 'Completed', 'ukraine-health' ),
			'cancelled' => __( 'Cancelled', 'ukraine-health' ),
		);
	}
}
