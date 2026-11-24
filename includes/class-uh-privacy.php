<?php
/**
 * Privacy: nicknames, when Supporters may see contact details, automatic
 * deletion of contact details, and WordPress's personal data export / erase tools.
 *
 * Heroes and Supporters appear to each other only by nickname. A real name is
 * optional and visible to coordinators only. A Supporter sees a Hero's ward and
 * phone only for an assigned visit, from `contact_hours` before it until it is
 * marked done. `retention_days` after a visit is completed or cancelled (or a
 * request withdrawn) the contact details are wiped; the counts stay for the stats.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/* ---------------------------------------------------------------------
	 * Names
	 * ------------------------------------------------------------------- */

	/** The name other people see. Never the real name. */
	public static function nickname( $user_or_id ) {
		$u = is_object( $user_or_id ) ? $user_or_id : get_userdata( (int) $user_or_id );
		if ( ! $u ) {
			return __( 'a Supporter', 'ukraine-health' );
		}
		$n = trim( (string) get_user_meta( $u->ID, 'nickname', true ) );
		return '' !== $n ? $n : $u->display_name;
	}

	/** A login name that says nothing about the person. */
	public static function random_login() {
		do {
			$login = 'uh_' . strtolower( wp_generate_password( 10, false, false ) );
		} while ( username_exists( $login ) );
		return $login;
	}

	public static function clean_nickname( $raw ) {
		$n = sanitize_text_field( $raw );
		$n = preg_replace( '/\s+/u', ' ', $n );
		return mb_substr( trim( $n ), 0, 40 );
	}

	/* ---------------------------------------------------------------------
	 * Contact window
	 * ------------------------------------------------------------------- */

	/** May the group's Supporter see the Heroes' ward and phone right now? */
	public static function contact_open( $group ) {
		if ( ! $group || 'assigned' !== $group->status || ! $group->appointment ) {
			return false;
		}
		$hours = (int) UH_Install::settings()['contact_hours'];
		if ( $hours <= 0 ) {
			return true;
		}
		return strtotime( current_time( 'mysql' ) ) >= strtotime( $group->appointment ) - $hours * HOUR_IN_SECONDS;
	}

	/** When the contact details will be shown, for the Supporter's notice. */
	public static function contact_opens_at( $group ) {
		$hours = (int) UH_Install::settings()['contact_hours'];
		// Appointments are stored as site-local time strings, like current_time( 'mysql' ).
		return gmdate( 'Y-m-d H:i:s', strtotime( $group->appointment ) - max( 0, $hours ) * HOUR_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------
	 * Automatic deletion
	 * ------------------------------------------------------------------- */

	/** Wipe contact details from requests whose visit is long over. Returns how many. */
	public static function wipe_old() {
		global $wpdb;
		$days = (int) UH_Install::settings()['retention_days'];
		if ( $days < 1 ) {
			return 0;
		}
		$cut = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$r   = UH_Repo::t( 'requests' );
		$g   = UH_Repo::t( 'groups' );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT r.id FROM $r r JOIN $g g ON g.id=r.group_id
				WHERE r.wiped_at IS NULL AND (
					( g.status='completed' AND COALESCE(g.completed_at, g.appointment, r.created_at) < %s )
					OR ( g.status='cancelled' AND r.created_at < %s )
					OR ( r.status='withdrawn' AND r.created_at < %s )
				) LIMIT 500",
				$cut,
				$cut,
				$cut
			)
		);
		foreach ( $ids as $id ) {
			self::wipe_request( (int) $id );
		}
		if ( $ids ) {
			UH_Repo::log( 'wiped', null, null, array( 'requests' => count( $ids ) ) );
		}
		return count( $ids );
	}

	public static function wipe_request( $id ) {
		global $wpdb;
		$wpdb->update(
			UH_Repo::t( 'requests' ),
			array(
				'name'      => '',
				'real_name' => '',
				'phone'     => '',
				'email'     => '',
				'messenger' => '',
				'ward'      => '',
				'sizes'     => null,
				'notes'     => '',
				'tg_chat'   => '',
				'token'     => wp_generate_password( 32, false, false ),
				'wiped_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/* ---------------------------------------------------------------------
	 * WordPress personal data tools (Tools → Export / Erase Personal Data)
	 * ------------------------------------------------------------------- */

	public static function register_exporter( $list ) {
		$list['ukraine-health'] = array(
			'exporter_friendly_name' => __( 'Ukraine Health requests', 'ukraine-health' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function register_eraser( $list ) {
		$list['ukraine-health'] = array(
			'eraser_friendly_name' => __( 'Ukraine Health requests', 'ukraine-health' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	private static function requests_for_email( $email ) {
		global $wpdb;
		$u   = get_user_by( 'email', $email );
		$sql = 'SELECT * FROM ' . UH_Repo::t( 'requests' ) . ' WHERE email=%s' . ( $u ? ' OR user_id=%d' : '' ) . ' ORDER BY id LIMIT 500';
		return $u ? $wpdb->get_results( $wpdb->prepare( $sql, $email, $u->ID ) ) : $wpdb->get_results( $wpdb->prepare( $sql, $email ) ); // phpcs:ignore
	}

	public static function export( $email, $page = 1 ) {
		$items = array();
		foreach ( self::requests_for_email( $email ) as $r ) {
			$g       = UH_Repo::group( $r->group_id );
			$s       = $g ? UH_Repo::service( $g->service_id ) : null;
			$h       = $g ? UH_Repo::hospital( $g->hospital_id ) : null;
			$items[] = array(
				'group_id'    => 'ukraine-health-requests',
				'group_label' => __( 'Ukraine Health requests', 'ukraine-health' ),
				'item_id'     => 'uh-request-' . $r->id,
				'data'        => array(
					array( 'name' => __( 'Service', 'ukraine-health' ), 'value' => $s ? $s->name : '' ),
					array( 'name' => __( 'Hospital', 'ukraine-health' ), 'value' => $h ? $h->name : '' ),
					array( 'name' => __( 'Nickname', 'ukraine-health' ), 'value' => $r->name ),
					array( 'name' => __( 'Real name', 'ukraine-health' ), 'value' => $r->real_name ),
					array( 'name' => __( 'Phone', 'ukraine-health' ), 'value' => $r->phone ),
					array( 'name' => __( 'Ward', 'ukraine-health' ), 'value' => $r->ward ),
					array( 'name' => __( 'Notes', 'ukraine-health' ), 'value' => $r->notes ),
					array( 'name' => __( 'Status', 'ukraine-health' ), 'value' => $r->status ),
					array( 'name' => __( 'Created', 'ukraine-health' ), 'value' => $r->created_at ),
				),
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erase( $email, $page = 1 ) {
		$n = 0;
		foreach ( self::requests_for_email( $email ) as $r ) {
			if ( ! $r->wiped_at ) {
				self::wipe_request( (int) $r->id );
				$n++;
			}
		}
		return array(
			'items_removed'  => $n,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy → Policy guide). */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days  = (int) UH_Install::settings()['retention_days'];
		$hours = (int) UH_Install::settings()['contact_hours'];
		$text  = '<h2>' . __( 'Help requests (Ukraine Health)', 'ukraine-health' ) . '</h2>'
			. '<p>' . __( 'When a Hero asks for help we store a nickname, optionally a real name, the hospital and ward, a phone number, an optional email address, the chosen messenger, clothing sizes when needed, and any notes. This can include information about health and treatment.', 'ukraine-health' ) . '</p>'
			. '<p>' . __( 'Who sees it: the coordinators. The Supporter who accepted a visit sees the nickname, and the ward and phone number only shortly before the visit.', 'ukraine-health' ) . ' ' . sprintf( __( 'This is currently %d hours before the visit.', 'ukraine-health' ), $hours ) . '</p>'
			. '<p>' . sprintf( __( 'How long: contact details are deleted automatically %d days after the visit is completed or cancelled, or the request is withdrawn. Only anonymous counts are kept.', 'ukraine-health' ), $days ) . '</p>'
			. '<p>' . __( 'Accounts: every member is invited by an existing member. We record who invited whom so that everyone can be traced to a person who vouched for them.', 'ukraine-health' ) . '</p>'
			. '<p>' . __( 'Messages are sent by email, and by Telegram or WhatsApp only when you connect them. You can ask for a copy of your data or for it to be deleted at any time.', 'ukraine-health' ) . '</p>';
		wp_add_privacy_policy_content( 'Ukraine Health – Hero Support', wp_kses_post( $text ) );
	}
}
