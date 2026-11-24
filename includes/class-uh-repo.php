<?php
/**
 * Data access.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Repo {

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'uh_' . $name;
	}

	/* ---------- Hospitals ---------- */

	public static function hospitals( $only_active = true ) {
		global $wpdb;
		$where = $only_active ? 'WHERE active=1' : '';
		return $wpdb->get_results( 'SELECT * FROM ' . self::t( 'hospitals' ) . " $where ORDER BY country, city, name" ); // phpcs:ignore
	}

	public static function hospital( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'hospitals' ) . ' WHERE id=%d', $id ) );
	}

	/* ---------- Services ---------- */

	public static function services( $only_active = true ) {
		global $wpdb;
		$where = $only_active ? 'WHERE active=1' : '';
		return $wpdb->get_results( 'SELECT * FROM ' . self::t( 'services' ) . " $where ORDER BY sort, name" ); // phpcs:ignore
	}

	public static function service( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'services' ) . ' WHERE id=%d', $id ) );
	}

	/* ---------- Groups ---------- */

	public static function group( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'groups' ) . ' WHERE id=%d', $id ) );
	}

	/** The group that new requests for this service + hospital join (open or ready, not yet scheduled). */
	public static function collecting_group( $service_id, $hospital_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::t( 'groups' ) . " WHERE service_id=%d AND hospital_id=%d AND status IN ('open','ready') ORDER BY id ASC LIMIT 1",
				$service_id,
				$hospital_id
			)
		);
	}

	public static function groups( $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'status'       => '',
				'service_ids'  => array(),
				'hospital_ids' => array(),
				'supporter_id' => 0,
				'limit'        => 200,
			)
		);
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['status'] ) {
			$st    = (array) $args['status'];
			$where[] = 'g.status IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $st );
		}
		if ( $args['service_ids'] ) {
			$ids     = array_map( 'intval', (array) $args['service_ids'] );
			$where[] = 'g.service_id IN (' . implode( ',', $ids ) . ')';
		}
		if ( $args['hospital_ids'] ) {
			$ids     = array_map( 'intval', (array) $args['hospital_ids'] );
			$where[] = 'g.hospital_id IN (' . implode( ',', $ids ) . ')';
		}
		if ( $args['supporter_id'] ) {
			$where[] = 'g.supporter_id=%d';
			$vals[]  = (int) $args['supporter_id'];
		}
		$sql = 'SELECT g.*, s.name AS service_name, s.min_group, s.category, s.chat_url, h.name AS hospital_name, h.city, h.country,
				(SELECT COUNT(*) FROM ' . self::t( 'requests' ) . " r WHERE r.group_id=g.id AND r.status<>'withdrawn') AS members
			FROM " . self::t( 'groups' ) . ' g
			JOIN ' . self::t( 'services' ) . ' s ON s.id=g.service_id
			JOIN ' . self::t( 'hospitals' ) . ' h ON h.id=g.hospital_id
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY FIELD(g.status,"ready","open","assigned","completed","cancelled"), g.created_at ASC
			LIMIT ' . (int) $args['limit'];
		return $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public static function member_count( $group_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'requests' ) . " WHERE group_id=%d AND status<>'withdrawn'", $group_id ) );
	}

	/* ---------- Requests ---------- */

	public static function request( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'requests' ) . ' WHERE id=%d', $id ) );
	}

	public static function request_by_token( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'requests' ) . ' WHERE token=%s', $token ) );
	}

	public static function group_members( $group_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'requests' ) . " WHERE group_id=%d AND status<>'withdrawn' ORDER BY id", $group_id ) );
	}

	public static function user_requests( $user_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.*, g.status AS group_status, g.appointment, g.service_id, g.hospital_id, s.name AS service_name, s.min_group, h.name AS hospital_name,
					(SELECT COUNT(*) FROM ' . self::t( 'requests' ) . " r2 WHERE r2.group_id=g.id AND r2.status<>'withdrawn') AS members
				FROM " . self::t( 'requests' ) . ' r
				JOIN ' . self::t( 'groups' ) . ' g ON g.id=r.group_id
				JOIN ' . self::t( 'services' ) . ' s ON s.id=g.service_id
				JOIN ' . self::t( 'hospitals' ) . ' h ON h.id=g.hospital_id
				WHERE r.user_id=%d ORDER BY r.created_at DESC LIMIT 100',
				$user_id
			)
		);
	}

	/** Has this user / phone already a live request in this group? */
	public static function duplicate( $group_id, $user_id, $phone ) {
		global $wpdb;
		if ( $user_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'requests' ) . " WHERE group_id=%d AND user_id=%d AND status<>'withdrawn'", $group_id, $user_id ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'requests' ) . " WHERE group_id=%d AND phone=%s AND status<>'withdrawn'", $group_id, $phone ) );
	}

	/* ---------- Log ---------- */

	public static function log( $action, $group_id = null, $request_id = null, $detail = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'log' ),
			array(
				'group_id'   => $group_id,
				'request_id' => $request_id,
				'actor_id'   => get_current_user_id() ?: null,
				'action'     => $action,
				'detail'     => is_string( $detail ) ? $detail : wp_json_encode( $detail ),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	public static function recent_log( $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'log' ) . ' ORDER BY id DESC LIMIT %d', $limit ) );
	}

	/* ---------- Stats ---------- */

	public static function stats() {
		global $wpdb;
		$g = self::t( 'groups' );
		$r = self::t( 'requests' );
		return array(
			'heroes_waiting' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $r r JOIN $g g ON g.id=r.group_id WHERE r.status<>'withdrawn' AND g.status IN ('open','ready')" ),
			'groups_ready'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $g WHERE status='ready'" ),
			'scheduled'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $g WHERE status='assigned'" ),
			'helped'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $r r JOIN $g g ON g.id=r.group_id WHERE r.status<>'withdrawn' AND g.status='completed'" ),
			'supporters'     => count( get_users( array( 'role' => UH_Install::ROLE_SUPPORTER, 'fields' => 'ID' ) ) ),
		);
	}

	/* ---------- Supporters ---------- */

	/** Supporters whose profile covers this service and hospital (empty hospital list = everywhere). */
	public static function matching_supporters( $service_id, $hospital_id ) {
		$out   = array();
		$users = get_users( array( 'role__in' => array( UH_Install::ROLE_SUPPORTER ) ) );
		foreach ( $users as $u ) {
			$svc  = array_map( 'intval', (array) get_user_meta( $u->ID, 'uh_services', true ) );
			$hosp = array_map( 'intval', (array) get_user_meta( $u->ID, 'uh_hospitals', true ) );
			$hosp = array_filter( $hosp );
			if ( in_array( (int) $service_id, $svc, true ) && ( ! $hosp || in_array( (int) $hospital_id, $hosp, true ) ) ) {
				$out[] = $u;
			}
		}
		return $out;
	}
}
