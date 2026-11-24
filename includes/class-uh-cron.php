<?php
/**
 * Hourly background job: visit reminders, alerts for groups nobody took,
 * and automatic deletion of old contact details.
 *
 * WordPress runs it when the site gets visits. For exact timing, a server
 * cron job can call wp-cron.php.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Cron {

	const HOOK = 'uh_hourly';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function run() {
		self::reminders();
		self::escalate();
		if ( (int) get_transient( 'uh_wiped_today' ) !== (int) gmdate( 'z' ) ) {
			UH_Privacy::wipe_old();
			set_transient( 'uh_wiped_today', (int) gmdate( 'z' ), DAY_IN_SECONDS );
		}
	}

	/** Remind Heroes and the Supporter once, within 24 hours before the visit. */
	public static function reminders() {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$soon = wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . UH_Repo::t( 'groups' ) . " WHERE status='assigned' AND reminded_at IS NULL AND appointment > %s AND appointment <= %s LIMIT 50",
				$now,
				$soon
			)
		);
		foreach ( $rows as $g ) {
			$wpdb->update( UH_Repo::t( 'groups' ), array( 'reminded_at' => $now ), array( 'id' => $g->id ) );
			UH_Notify::reminder( $g );
			UH_Repo::log( 'reminded', $g->id );
		}
		return count( $rows );
	}

	/** Tell the coordinators about ready groups that nobody accepted for `escalate_days`. */
	public static function escalate() {
		global $wpdb;
		$days = (int) UH_Install::settings()['escalate_days'];
		if ( $days < 1 ) {
			return 0;
		}
		$cut  = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$rows = UH_Repo::groups( array( 'status' => 'ready', 'limit' => 50 ) );
		$late = array();
		foreach ( $rows as $g ) {
			if ( ! $g->escalated_at && $g->ready_at && $g->ready_at < $cut ) {
				$late[] = $g;
				$wpdb->update( UH_Repo::t( 'groups' ), array( 'escalated_at' => current_time( 'mysql' ) ), array( 'id' => $g->id ) );
			}
		}
		if ( $late ) {
			$lines = array();
			foreach ( $late as $g ) {
				$lines[] = sprintf( '#%d %s — %s (%d/%d), %s', $g->id, $g->service_name, $g->hospital_name, $g->members, $g->min_group, sprintf( _n( '%d matching Supporter', '%d matching Supporters', count( UH_Repo::matching_supporters( $g->service_id, $g->hospital_id ) ), 'ukraine-health' ), count( UH_Repo::matching_supporters( $g->service_id, $g->hospital_id ) ) ) );
			}
			UH_Notify::coordinators(
				sprintf( _n( '%d group is still waiting for a Supporter', '%d groups are still waiting for a Supporter', count( $late ), 'ukraine-health' ), count( $late ) ),
				sprintf( __( "These groups have been ready for more than %d days and nobody has accepted them yet:", 'ukraine-health' ), $days ) . "\n" . implode( "\n", $lines ) . "\n\n" . admin_url( 'admin.php?page=ukraine-health' )
			);
		}
		return count( $late );
	}
}
