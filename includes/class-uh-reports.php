<?php
/**
 * "Report a problem": a Hero flags a visit privately to the coordinators.
 * The Supporter is put on hold at once (no new groups, no Heroes' details)
 * until a coordinator resolves the report.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Reports {

	public static function report( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . UH_Repo::t( 'reports' ) . ' WHERE id=%d', $id ) );
	}

	public static function all( $status = '', $limit = 200 ) {
		global $wpdb;
		$t = UH_Repo::t( 'reports' );
		return $status
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status=%s ORDER BY id DESC LIMIT %d", $status, $limit ) ) // phpcs:ignore
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	public static function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . UH_Repo::t( 'reports' ) . " WHERE status='open'" ); // phpcs:ignore
	}

	/** One report per request, so a single Hero cannot flood the list. */
	public static function exists_for_request( $request_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . UH_Repo::t( 'reports' ) . ' WHERE request_id=%d', $request_id ) );
	}

	/**
	 * File a report about the visit a request belongs to.
	 *
	 * @return int|WP_Error Report id.
	 */
	public static function create( $request, $message ) {
		global $wpdb;
		$group = UH_Repo::group( $request->group_id );
		if ( ! $group || ! $group->supporter_id || ! in_array( $group->status, array( 'assigned', 'completed' ), true ) ) {
			return new WP_Error( 'report_none', __( 'There is no visit to report for this request.', 'ukraine-health' ) );
		}
		if ( self::exists_for_request( $request->id ) ) {
			return new WP_Error( 'report_dup', __( 'You already reported this visit. A coordinator will contact you.', 'ukraine-health' ) );
		}
		$wpdb->insert(
			UH_Repo::t( 'reports' ),
			array(
				'group_id'     => (int) $group->id,
				'request_id'   => (int) $request->id,
				'supporter_id' => (int) $group->supporter_id,
				'reporter_id'  => get_current_user_id() ?: null,
				'message'      => mb_substr( $message, 0, 3000 ),
				'status'       => 'open',
				'created_at'   => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		update_user_meta( (int) $group->supporter_id, UH_Network::META_HOLD, current_time( 'mysql' ) );
		UH_Network::flush();
		UH_Repo::log( 'reported', $group->id, $request->id, array( 'report' => $id ) );
		UH_Notify::coordinators(
			__( 'Problem reported – Supporter on hold', 'ukraine-health' ),
			sprintf(
				/* translators: 1: report id, 2: supporter nickname, 3: link */
				__( "A Hero reported a problem with a visit (report #%1\$d). Supporter %2\$s is on hold and gets no new groups until you review it:\n%3\$s", 'ukraine-health' ),
				$id,
				UH_Privacy::nickname( (int) $group->supporter_id ),
				admin_url( 'admin.php?page=ukraine-health-network&tab=reports' )
			)
		);
		return $id;
	}

	/**
	 * Close a report.
	 *
	 * @param string $outcome 'cleared' lifts the hold, 'suspended' suspends the Supporter.
	 */
	public static function resolve( $id, $outcome, $note = '' ) {
		global $wpdb;
		$r = self::report( $id );
		if ( ! $r || 'open' !== $r->status ) {
			return false;
		}
		$wpdb->update(
			UH_Repo::t( 'reports' ),
			array(
				'status'      => 'suspended' === $outcome ? 'suspended' : 'cleared',
				'resolution'  => $note,
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => $r->id )
		);
		if ( 'suspended' === $outcome ) {
			UH_Network::suspend( (int) $r->supporter_id );
		}
		// Lift the hold only when no other open report is about the same Supporter.
		$others = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . UH_Repo::t( 'reports' ) . " WHERE supporter_id=%d AND status='open'", $r->supporter_id ) );
		if ( ! $others ) {
			delete_user_meta( (int) $r->supporter_id, UH_Network::META_HOLD );
		}
		UH_Network::flush();
		UH_Repo::log( 'report_' . ( 'suspended' === $outcome ? 'suspended' : 'cleared' ), $r->group_id, $r->request_id, array( 'report' => (int) $r->id ) );
		return true;
	}
}
