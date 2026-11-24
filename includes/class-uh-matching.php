<?php
/**
 * Group lifecycle: join → ready → assigned → completed (or withdrawn / cancelled).
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Matching {

	/**
	 * Add a request to the collecting group for service + hospital.
	 *
	 * @return array|WP_Error { request_id, group_id, token, members, min, status }
	 */
	public static function join( $service_id, $hospital_id, $data ) {
		global $wpdb;
		$service  = UH_Repo::service( $service_id );
		$hospital = UH_Repo::hospital( $hospital_id );
		if ( ! $service || ! $service->active || ! $hospital || ! $hospital->active ) {
			return new WP_Error( 'uh_invalid', __( 'Please choose a valid service and hospital.', 'ukraine-health' ) );
		}

		$group = UH_Repo::collecting_group( $service_id, $hospital_id );
		if ( ! $group ) {
			$wpdb->insert(
				UH_Repo::t( 'groups' ),
				array(
					'service_id'  => $service_id,
					'hospital_id' => $hospital_id,
					'status'      => 'open',
					'created_at'  => current_time( 'mysql' ),
				)
			);
			$group = UH_Repo::group( $wpdb->insert_id );
			UH_Repo::log( 'group_created', $group->id );
		}

		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( UH_Repo::duplicate( $group->id, $user_id, $data['phone'] ?? '' ) ) {
			return new WP_Error( 'uh_duplicate', sprintf( __( 'You are already on the list for %s.', 'ukraine-health' ), $service->name ) );
		}

		$token = wp_generate_password( 32, false, false );
		$wpdb->insert(
			UH_Repo::t( 'requests' ),
			array(
				'group_id'   => $group->id,
				'user_id'    => $user_id ?: null,
				'name'       => $data['name'] ?? '',
				'phone'      => $data['phone'] ?? '',
				'email'      => $data['email'] ?? '',
				'messenger'  => $data['messenger'] ?? '',
				'ward'       => $data['ward'] ?? '',
				'sizes'      => $service->needs_sizes ? wp_json_encode( $data['sizes'] ?? array() ) : null,
				'notes'      => $data['notes'] ?? '',
				'status'     => 'waiting',
				'token'      => $token,
				'created_at' => current_time( 'mysql' ),
			)
		);
		$request_id = (int) $wpdb->insert_id;
		UH_Repo::log( 'joined', $group->id, $request_id );

		self::recalc( $group->id );
		$group   = UH_Repo::group( $group->id );
		$members = UH_Repo::member_count( $group->id );

		UH_Notify::admin_new_request( $group, $service, $hospital, $data );

		return array(
			'request_id' => $request_id,
			'group_id'   => (int) $group->id,
			'token'      => $token,
			'members'    => $members,
			'min'        => (int) $service->min_group,
			'status'     => $group->status,
			'service'    => $service->name,
		);
	}

	/** Re-evaluate open ⇄ ready after a join or withdrawal. */
	public static function recalc( $group_id ) {
		global $wpdb;
		$group = UH_Repo::group( $group_id );
		if ( ! $group || ! in_array( $group->status, array( 'open', 'ready' ), true ) ) {
			return;
		}
		$service = UH_Repo::service( $group->service_id );
		$count   = UH_Repo::member_count( $group_id );
		$min     = max( 1, (int) $service->min_group );

		if ( $count >= $min && 'open' === $group->status ) {
			$wpdb->update( UH_Repo::t( 'groups' ), array( 'status' => 'ready', 'ready_at' => current_time( 'mysql' ) ), array( 'id' => $group_id ) );
			UH_Repo::log( 'ready', $group_id, null, "$count/$min" );
			UH_Notify::group_ready( UH_Repo::group( $group_id ) );
		} elseif ( $count < $min && 'ready' === $group->status ) {
			$wpdb->update( UH_Repo::t( 'groups' ), array( 'status' => 'open', 'ready_at' => null ), array( 'id' => $group_id ) );
			UH_Repo::log( 'reopened', $group_id, null, "$count/$min" );
		} elseif ( 0 === $count ) {
			$wpdb->update( UH_Repo::t( 'groups' ), array( 'status' => 'cancelled' ), array( 'id' => $group_id ) );
			UH_Repo::log( 'cancelled_empty', $group_id );
		}
	}

	public static function withdraw( $request ) {
		global $wpdb;
		$group = UH_Repo::group( $request->group_id );
		if ( ! $group || in_array( $group->status, array( 'completed', 'cancelled' ), true ) || 'withdrawn' === $request->status ) {
			return new WP_Error( 'uh_locked', __( 'This request can no longer be changed.', 'ukraine-health' ) );
		}
		$wpdb->update( UH_Repo::t( 'requests' ), array( 'status' => 'withdrawn' ), array( 'id' => $request->id ) );
		UH_Repo::log( 'withdrawn', $group->id, $request->id );
		if ( 'assigned' === $group->status && $group->supporter_id ) {
			UH_Notify::supporter_withdrawal( $group, $request );
		}
		self::recalc( $group->id );
		return true;
	}

	/** A Supporter takes a ready (or open, if they want to go early) group. */
	public static function accept( $group_id, $supporter_id, $appointment, $note = '' ) {
		global $wpdb;
		$group = UH_Repo::group( $group_id );
		if ( ! $group || ! in_array( $group->status, array( 'open', 'ready' ), true ) ) {
			return new WP_Error( 'uh_taken', __( 'This group has already been taken or closed.', 'ukraine-health' ) );
		}
		$ts = strtotime( $appointment );
		if ( ! $ts ) {
			return new WP_Error( 'uh_date', __( 'Please choose an appointment date and time.', 'ukraine-health' ) );
		}
		// Atomic claim so two Supporters cannot take the same group.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . UH_Repo::t( 'groups' ) . " SET status='assigned', supporter_id=%d, appointment=%s, supporter_note=%s, assigned_at=%s WHERE id=%d AND status IN ('open','ready')",
				$supporter_id,
				gmdate( 'Y-m-d H:i:s', $ts ),
				$note,
				current_time( 'mysql' ),
				$group_id
			)
		);
		if ( ! $updated ) {
			return new WP_Error( 'uh_taken', __( 'This group has already been taken.', 'ukraine-health' ) );
		}
		$wpdb->update( UH_Repo::t( 'requests' ), array( 'status' => 'scheduled' ), array( 'group_id' => $group_id, 'status' => 'waiting' ) );
		UH_Repo::log( 'accepted', $group_id, null, gmdate( 'Y-m-d H:i', $ts ) );
		UH_Notify::group_scheduled( UH_Repo::group( $group_id ) );
		return true;
	}

	public static function release( $group_id, $supporter_id ) {
		global $wpdb;
		$group = UH_Repo::group( $group_id );
		if ( ! $group || 'assigned' !== $group->status || ( (int) $group->supporter_id !== (int) $supporter_id && ! current_user_can( UH_Install::CAP_MANAGE ) ) ) {
			return new WP_Error( 'uh_forbidden', __( 'You cannot release this group.', 'ukraine-health' ) );
		}
		$wpdb->update( UH_Repo::t( 'groups' ), array( 'status' => 'open', 'supporter_id' => null, 'appointment' => null, 'assigned_at' => null ), array( 'id' => $group_id ) );
		$wpdb->update( UH_Repo::t( 'requests' ), array( 'status' => 'waiting' ), array( 'group_id' => $group_id, 'status' => 'scheduled' ) );
		UH_Repo::log( 'released', $group_id );
		self::recalc( $group_id );
		return true;
	}

	public static function complete( $group_id, $user_id ) {
		global $wpdb;
		$group = UH_Repo::group( $group_id );
		if ( ! $group || 'assigned' !== $group->status || ( (int) $group->supporter_id !== (int) $user_id && ! current_user_can( UH_Install::CAP_MANAGE ) ) ) {
			return new WP_Error( 'uh_forbidden', __( 'You cannot complete this group.', 'ukraine-health' ) );
		}
		$wpdb->update( UH_Repo::t( 'groups' ), array( 'status' => 'completed', 'completed_at' => current_time( 'mysql' ) ), array( 'id' => $group_id ) );
		$wpdb->update( UH_Repo::t( 'requests' ), array( 'status' => 'done' ), array( 'group_id' => $group_id, 'status' => 'scheduled' ) );
		if ( $group->supporter_id ) {
			$done = (int) get_user_meta( $group->supporter_id, 'uh_completed', true );
			update_user_meta( $group->supporter_id, 'uh_completed', $done + 1 );
		}
		UH_Repo::log( 'completed', $group_id );
		return true;
	}

	/** Coordinator override of status. */
	public static function set_status( $group_id, $status ) {
		global $wpdb;
		if ( ! array_key_exists( $status, UH_Install::group_statuses() ) ) {
			return;
		}
		$wpdb->update( UH_Repo::t( 'groups' ), array( 'status' => $status ), array( 'id' => $group_id ) );
		UH_Repo::log( 'status_' . $status, $group_id );
		if ( in_array( $status, array( 'open', 'ready' ), true ) ) {
			$wpdb->update( UH_Repo::t( 'groups' ), array( 'status' => 'open' ), array( 'id' => $group_id ) );
			self::recalc( $group_id );
		}
	}
}
