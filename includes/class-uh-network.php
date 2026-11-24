<?php
/**
 * Invite-only membership: who invited whom.
 *
 * Every account must come from an invite made by an existing member, so each
 * person is connected to the site's administrators through a chain of people
 * who vouched for them. A member is "connected" when that chain reaches an
 * administrator / coordinator without passing through a suspended account.
 * Suspending someone freezes everyone they brought in until a coordinator
 * re-attaches them to another member.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Network {

	const META_INVITER   = 'uh_invited_by';
	const META_INVITE    = 'uh_invite_id';
	const META_SUSPENDED = 'uh_suspended';
	const META_CAN_HELP  = 'uh_supporter_ok';

	const MAX_OPEN_INVITES = 10;   // Per ordinary member.
	const MEMBER_DAYS      = 14;   // Invite lifetime for ordinary members.
	const MANAGER_MAX_DAYS = 90;
	const MANAGER_MAX_USES = 50;

	/** @var array<int,bool> Per-request cache of connection checks. */
	private static $cache = array();

	public static function init() {
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_suspended_login' ), 20 );
		add_action( 'user_register', array( __CLASS__, 'on_admin_created_user' ), 20 );
		add_action( 'deleted_user', array( __CLASS__, 'on_deleted_user' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Who is who
	 * ------------------------------------------------------------------- */

	/** Administrators and coordinators are the roots of the network. */
	public static function is_root( $user_id ) {
		return $user_id && user_can( $user_id, UH_Install::CAP_MANAGE );
	}

	public static function is_suspended( $user_id ) {
		return $user_id && (bool) get_user_meta( $user_id, self::META_SUSPENDED, true );
	}

	public static function inviter( $user_id ) {
		return (int) get_user_meta( $user_id, self::META_INVITER, true );
	}

	/**
	 * True when the invite chain from this user reaches a root through
	 * accounts that exist and are not suspended.
	 */
	public static function is_connected( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}
		$seen = array();
		$id   = $user_id;
		$ok   = false;
		while ( $id && ! isset( $seen[ $id ] ) && count( $seen ) < 100 ) {
			$seen[ $id ] = true;
			if ( ! get_userdata( $id ) || self::is_suspended( $id ) ) {
				break;
			}
			if ( self::is_root( $id ) ) {
				$ok = true;
				break;
			}
			$id = self::inviter( $id );
		}
		self::$cache[ $user_id ] = $ok;
		return $ok;
	}

	public static function flush() {
		self::$cache = array();
	}

	/** May this user act as a Supporter (see Heroes' details, accept groups)? */
	public static function can_support( $user_id ) {
		if ( self::is_root( $user_id ) && ! self::is_suspended( $user_id ) ) {
			return true;
		}
		$u = get_userdata( $user_id );
		return $u && in_array( UH_Install::ROLE_SUPPORTER, (array) $u->roles, true ) && self::is_connected( $user_id );
	}

	/** May this user tick "I want to help as a Supporter" in their profile? */
	public static function may_become_supporter( $user_id ) {
		return self::is_connected( $user_id ) && ( self::is_root( $user_id ) || get_user_meta( $user_id, self::META_CAN_HELP, true ) );
	}

	/** People this user invited directly. */
	public static function invitees( $user_id ) {
		return get_users(
			array(
				'meta_key'   => self::META_INVITER, // phpcs:ignore
				'meta_value' => (int) $user_id,     // phpcs:ignore
				'orderby'    => 'registered',
				'order'      => 'ASC',
			)
		);
	}

	/** Everyone below this user in the tree (ids), depth-first. */
	public static function branch( $user_id, $limit = 2000 ) {
		$out   = array();
		$queue = array( (int) $user_id );
		while ( $queue && count( $out ) < $limit ) {
			$id = array_shift( $queue );
			foreach ( self::invitees( $id ) as $u ) {
				if ( ! isset( $out[ $u->ID ] ) && $u->ID !== (int) $user_id ) {
					$out[ $u->ID ] = $u->ID;
					$queue[]       = $u->ID;
				}
			}
		}
		return array_values( $out );
	}

	/** The chain from this user up to the root: [user, inviter, inviter's inviter, …]. */
	public static function chain( $user_id ) {
		$out  = array();
		$id   = (int) $user_id;
		while ( $id && ! in_array( $id, $out, true ) && count( $out ) < 100 ) {
			$out[] = $id;
			$id    = self::inviter( $id );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Invites
	 * ------------------------------------------------------------------- */

	public static function roles() {
		return array(
			'hero'      => __( 'Hero (needs help)', 'ukraine-health' ),
			'supporter' => __( 'Supporter (gives help)', 'ukraine-health' ),
			'both'      => __( 'Hero or Supporter', 'ukraine-health' ),
		);
	}

	/** Which invite roles this user may hand out. */
	public static function roles_for( $user_id ) {
		if ( ! self::is_connected( $user_id ) ) {
			return array();
		}
		if ( self::is_root( $user_id ) || self::can_support( $user_id ) ) {
			return array_keys( self::roles() );
		}
		return array( 'hero' );
	}

	public static function invite( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . UH_Repo::t( 'invites' ) . ' WHERE id=%d', $id ) );
	}

	public static function invite_by_code( $code ) {
		global $wpdb;
		$code = self::clean_code( $code );
		if ( strlen( $code ) < 12 ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . UH_Repo::t( 'invites' ) . ' WHERE code=%s', $code ) );
	}

	public static function clean_code( $code ) {
		return substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ), 0, 32 );
	}

	public static function user_invites( $user_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . UH_Repo::t( 'invites' ) . ' WHERE inviter_id=%d ORDER BY id DESC LIMIT %d', $user_id, $limit ) );
	}

	public static function all_invites( $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . UH_Repo::t( 'invites' ) . ' ORDER BY id DESC LIMIT %d', $limit ) );
	}

	public static function open_invite_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . UH_Repo::t( 'invites' ) . ' WHERE inviter_id=%d AND revoked=0 AND uses<max_uses AND expires_at>%s',
				$user_id,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Why an invite cannot be used right now, or '' when it is valid.
	 *
	 * @param object|null $inv  Invite row.
	 * @param string      $role Role the newcomer wants (hero|supporter|both), or '' for any.
	 */
	public static function invalid_reason( $inv, $role = '' ) {
		if ( ! $inv ) {
			return 'invite';
		}
		if ( $inv->revoked || (int) $inv->uses >= (int) $inv->max_uses || strtotime( $inv->expires_at ) < strtotime( current_time( 'mysql' ) ) ) {
			return 'invite_used';
		}
		// The person who made the invite must still be connected.
		if ( ! self::is_connected( (int) $inv->inviter_id ) ) {
			return 'invite_used';
		}
		if ( $role && ! self::invite_allows( $inv, $role ) ) {
			return 'invite_role';
		}
		return '';
	}

	public static function invite_allows( $inv, $role ) {
		if ( 'both' === $inv->role ) {
			return true;
		}
		return $inv->role === $role;
	}

	/**
	 * Create an invite.
	 *
	 * @return object|WP_Error Invite row.
	 */
	public static function create_invite( $inviter_id, $role, $note = '', $max_uses = 1, $days = 0 ) {
		global $wpdb;
		$inviter_id = (int) $inviter_id;
		if ( self::is_suspended( $inviter_id ) || ! self::is_connected( $inviter_id ) ) {
			return new WP_Error( 'not_connected', __( 'Your account is not connected to the network yet, so you cannot invite others.', 'ukraine-health' ) );
		}
		if ( ! in_array( $role, self::roles_for( $inviter_id ), true ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot invite people for this role.', 'ukraine-health' ) );
		}
		$manager = self::is_root( $inviter_id );
		if ( ! $manager && self::open_invite_count( $inviter_id ) >= self::MAX_OPEN_INVITES ) {
			return new WP_Error( 'too_many', __( 'You have too many unused invites. Revoke or wait for some to be used first.', 'ukraine-health' ) );
		}
		$max_uses = $manager ? min( self::MANAGER_MAX_USES, max( 1, (int) $max_uses ) ) : 1;
		$days     = $manager ? min( self::MANAGER_MAX_DAYS, max( 1, (int) $days ?: self::MEMBER_DAYS ) ) : self::MEMBER_DAYS;
		$code     = wp_generate_password( 20, false, false );
		$wpdb->insert(
			UH_Repo::t( 'invites' ),
			array(
				'code'       => $code,
				'inviter_id' => $inviter_id,
				'role'       => $role,
				'note'       => mb_substr( sanitize_text_field( $note ), 0, 128 ),
				'max_uses'   => $max_uses,
				'uses'       => 0,
				'revoked'    => 0,
				'expires_at' => wp_date( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		$inv = self::invite( (int) $wpdb->insert_id );
		UH_Repo::log( 'invite_created', null, null, array( 'invite' => (int) $inv->id, 'role' => $role, 'uses' => $max_uses ) );
		return $inv;
	}

	/** Atomically count one use. False when it was used up in the meantime. */
	public static function consume( $inv ) {
		global $wpdb;
		return (bool) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . UH_Repo::t( 'invites' ) . ' SET uses=uses+1 WHERE id=%d AND revoked=0 AND uses<max_uses AND expires_at>%s',
				$inv->id,
				current_time( 'mysql' )
			)
		);
	}

	public static function revoke( $invite_id, $by_user_id ) {
		global $wpdb;
		$inv = self::invite( $invite_id );
		if ( ! $inv || ( (int) $inv->inviter_id !== (int) $by_user_id && ! user_can( $by_user_id, UH_Install::CAP_MANAGE ) ) ) {
			return false;
		}
		$wpdb->update( UH_Repo::t( 'invites' ), array( 'revoked' => 1 ), array( 'id' => $inv->id ) );
		UH_Repo::log( 'invite_revoked', null, null, array( 'invite' => (int) $inv->id ) );
		return true;
	}

	/** Link a newcomer should open: Heroes go to the request form, Supporters to the sign-up page. */
	public static function invite_url( $inv ) {
		$page = 'hero' === $inv->role ? 'request' : 'register';
		return add_query_arg( 'uh_invite', $inv->code, UH_Notify::page_url( $page ) );
	}

	/** Record that $user_id joined through $inv. */
	public static function attach( $user_id, $inv ) {
		update_user_meta( $user_id, self::META_INVITER, (int) $inv->inviter_id );
		update_user_meta( $user_id, self::META_INVITE, (int) $inv->id );
		if ( in_array( $inv->role, array( 'supporter', 'both' ), true ) ) {
			update_user_meta( $user_id, self::META_CAN_HELP, 1 );
		}
		self::flush();
		UH_Repo::log( 'joined_network', null, null, array( 'user' => (int) $user_id, 'invited_by' => (int) $inv->inviter_id, 'invite' => (int) $inv->id ) );
	}

	/* ---------------------------------------------------------------------
	 * Coordinator actions
	 * ------------------------------------------------------------------- */

	public static function suspend( $user_id, $branch = false ) {
		$ids = $branch ? array_merge( array( (int) $user_id ), self::branch( $user_id ) ) : array( (int) $user_id );
		foreach ( $ids as $id ) {
			if ( self::is_root( $id ) && get_current_user_id() === $id ) {
				continue; // Never lock yourself out.
			}
			update_user_meta( $id, self::META_SUSPENDED, current_time( 'mysql' ) );
			self::revoke_all( $id );
			if ( class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( $id )->destroy_all();
			}
		}
		self::flush();
		UH_Repo::log( $branch ? 'suspended_branch' : 'suspended', null, null, array( 'user' => (int) $user_id, 'count' => count( $ids ) ) );
		return count( $ids );
	}

	public static function unsuspend( $user_id ) {
		delete_user_meta( $user_id, self::META_SUSPENDED );
		self::flush();
		UH_Repo::log( 'unsuspended', null, null, array( 'user' => (int) $user_id ) );
	}

	/** Re-attach a member under another (connected) member. Refuses loops. */
	public static function reattach( $user_id, $new_inviter_id ) {
		$user_id        = (int) $user_id;
		$new_inviter_id = (int) $new_inviter_id;
		if ( ! $user_id || ! get_userdata( $new_inviter_id ) || $user_id === $new_inviter_id ) {
			return false;
		}
		if ( in_array( $user_id, self::chain( $new_inviter_id ), true ) ) {
			return false; // Would create a loop.
		}
		update_user_meta( $user_id, self::META_INVITER, $new_inviter_id );
		self::flush();
		UH_Repo::log( 'reattached', null, null, array( 'user' => $user_id, 'invited_by' => $new_inviter_id ) );
		return true;
	}

	public static function allow_supporter( $user_id ) {
		update_user_meta( $user_id, self::META_CAN_HELP, 1 );
		$u = get_userdata( $user_id );
		if ( $u ) {
			$u->add_role( UH_Install::ROLE_SUPPORTER );
		}
		UH_Repo::log( 'supporter_allowed', null, null, array( 'user' => (int) $user_id ) );
	}

	public static function revoke_all( $user_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UH_Repo::t( 'invites' ) . ' SET revoked=1 WHERE inviter_id=%d AND revoked=0', $user_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------- */

	public static function block_suspended_login( $user ) {
		if ( $user instanceof WP_User && self::is_suspended( $user->ID ) ) {
			return new WP_Error( 'uh_suspended', __( 'This account is suspended. Please contact the coordinator.', 'ukraine-health' ) );
		}
		return $user;
	}

	/** An account a coordinator creates in wp-admin is invited by that coordinator. */
	public static function on_admin_created_user( $user_id ) {
		if ( ! is_admin() || ! current_user_can( 'create_users' ) || self::inviter( $user_id ) ) {
			return;
		}
		$me = get_current_user_id();
		if ( $me && $me !== (int) $user_id ) {
			update_user_meta( $user_id, self::META_INVITER, $me );
			update_user_meta( $user_id, self::META_CAN_HELP, 1 );
		}
	}

	/** When an account is deleted, its invitees move up to the deleted person's inviter (or the reassign user). */
	public static function on_deleted_user( $user_id, $reassign = null, $user = null ) {
		$up = (int) ( $reassign ?: 0 );
		foreach ( self::invitees( $user_id ) as $u ) {
			if ( $up ) {
				update_user_meta( $u->ID, self::META_INVITER, $up );
			} else {
				delete_user_meta( $u->ID, self::META_INVITER );
			}
		}
		self::revoke_all( $user_id );
		self::flush();
	}
}
