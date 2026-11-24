<?php
/**
 * Shortcodes and front-end form handling.
 *
 * [uh_request_form]  Heroes ask for help (guests allowed if enabled)
 * [uh_my_requests]   Heroes see / withdraw their requests (login or private link)
 * [uh_supporter]     Supporter profile + groups to accept + scheduled work
 * [uh_register]      Sign up as Hero, Supporter or both
 * [uh_stats]         Public impact counters
 * [uh_invite]        Invite someone you know (invite-only network)
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Frontend {

	const NONCE = 'uh_front';

	public function __construct() {
		add_shortcode( 'uh_request_form', array( $this, 'sc_request_form' ) );
		add_shortcode( 'uh_my_requests', array( $this, 'sc_my_requests' ) );
		add_shortcode( 'uh_supporter', array( $this, 'sc_supporter' ) );
		add_shortcode( 'uh_register', array( $this, 'sc_register' ) );
		add_shortcode( 'uh_stats', array( $this, 'sc_stats' ) );
		add_shortcode( 'uh_invite', array( $this, 'sc_invite' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		wp_register_style( 'uh', UH_URL . 'assets/uh.css', array(), UH_VERSION );
		wp_register_script( 'uh', UH_URL . 'assets/uh.js', array(), UH_VERSION, true );
		wp_localize_script(
			'uh',
			'UH',
			array(
				'count' => esc_url_raw( rest_url( 'ukraine-health/v1/count' ) ),
				'i18n'  => array(
					'waiting' => __( 'already waiting', 'ukraine-health' ),
					'ready'   => __( 'Group is full – a Supporter will be found soon', 'ukraine-health' ),
					'need'    => __( 'more needed to start', 'ukraine-health' ),
				),
			)
		);
	}

	private function enqueue() {
		wp_enqueue_style( 'uh' );
		wp_enqueue_script( 'uh' );
	}

	/* =====================================================================
	 * POST handling (Post/Redirect/Get)
	 * ===================================================================== */

	public function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['uh_action'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_uh'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_uh'] ), self::NONCE ) ) {
			$this->back( array( 'uh_err' => 'nonce' ) );
		}
		$action = sanitize_key( $_POST['uh_action'] );
		switch ( $action ) {
			case 'request':
				$this->do_request();
				break;
			case 'withdraw':
				$this->do_withdraw();
				break;
			case 'report':
				$this->do_report();
				break;
			case 'profile':
				$this->do_profile();
				break;
			case 'accept':
			case 'release':
			case 'complete':
				$this->do_supporter_action( $action );
				break;
			case 'register':
				$this->do_register();
				break;
			case 'invite_create':
				$this->do_invite_create();
				break;
			case 'invite_revoke':
				$this->do_invite_revoke();
				break;
		}
	}

	private function back( $args ) {
		// wp_get_referer() ignores a page that posts to itself, which is how every form here works,
		// so use the raw referer, limited to this site.
		$ref = wp_validate_redirect( wp_get_raw_referer(), home_url( '/' ) );
		$url = remove_query_arg( array( 'uh_ok', 'uh_err', 'uh_n', 'uh_new', 'uh_token' ), $ref );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	private function rate_limited() {
		$limit = (int) UH_Install::settings()['rate_limit'];
		if ( $limit < 1 || is_user_logged_in() ) {
			return false;
		}
		$key = 'uh_rl_' . md5( ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
	}

	private function do_request() {
		$set = UH_Install::settings();
		if ( ! is_user_logged_in() && empty( $set['allow_guests'] ) ) {
			$this->back( array( 'uh_err' => 'login' ) );
		}
		if ( ! empty( $_POST['uh_website'] ) ) { // Honeypot.
			$this->back( array( 'uh_ok' => 'request' ) );
		}
		if ( $this->rate_limited() ) {
			$this->back( array( 'uh_err' => 'rate' ) );
		}
		$services    = array_filter( array_map( 'absint', (array) ( $_POST['services'] ?? array() ) ) );
		$hospital_id = absint( $_POST['hospital'] ?? 0 );
		$phone       = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		if ( ! $services || ! $hospital_id ) {
			$this->back( array( 'uh_err' => 'missing' ) );
		}
		if ( ! is_user_logged_in() && ! preg_match( '/^\+?[0-9 ()\-]{6,20}$/', $phone ) ) {
			$this->back( array( 'uh_err' => 'phone' ) );
		}
		if ( empty( $_POST['consent'] ) ) {
			$this->back( array( 'uh_err' => 'consent' ) );
		}

		// Invite-only: a request must come from a connected member or carry a valid invite.
		$uid    = get_current_user_id();
		$invite = null;
		if ( ! ( $uid && UH_Network::is_connected( $uid ) ) && ! empty( $set['invite_guests'] ) ) {
			$invite = UH_Network::invite_by_code( wp_unslash( $_POST['invite'] ?? '' ) );
			$why    = UH_Network::invalid_reason( $invite, 'hero' );
			if ( $why ) {
				$this->back( array( 'uh_err' => $why ) );
			}
			if ( ! UH_Network::consume( $invite ) ) {
				$this->back( array( 'uh_err' => 'invite_used' ) );
			}
			if ( $uid && ! UH_Network::inviter( $uid ) ) {
				UH_Network::attach( $uid, $invite );
			}
		}

		$data = array(
			'user_id'   => $uid,
			'name'      => UH_Privacy::clean_nickname( wp_unslash( $_POST['name'] ?? '' ) ),
			'real_name' => mb_substr( sanitize_text_field( wp_unslash( $_POST['real_name'] ?? '' ) ), 0, 128 ),
			'phone'     => $phone,
			'email'     => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'messenger' => sanitize_text_field( wp_unslash( $_POST['messenger'] ?? '' ) ),
			'ward'      => sanitize_text_field( wp_unslash( $_POST['ward'] ?? '' ) ),
			'notes'     => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'sizes'     => array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['sizes'] ?? array() ) ) ),
		);
		if ( $invite ) {
			$data['invited_by'] = (int) $invite->inviter_id;
			$data['invite_id']  = (int) $invite->id;
		} elseif ( $uid ) {
			$data['invited_by'] = UH_Network::inviter( $uid ) ?: null;
		}

		if ( $uid ) {
			// Remember details for next time.
			foreach ( array( 'phone', 'messenger', 'ward' ) as $k ) {
				update_user_meta( $uid, 'uh_' . $k, $data[ $k ] );
			}
			update_user_meta( $uid, 'uh_hospital', $hospital_id );
			update_user_meta( $uid, 'uh_sizes', $data['sizes'] );
			$u = wp_get_current_user();
			if ( ! array_intersect( array( UH_Install::ROLE_HERO, 'administrator', UH_Install::ROLE_COORDINATOR ), $u->roles ) ) {
				$u->add_role( UH_Install::ROLE_HERO );
			}
		}

		$ok     = 0;
		$errors = array();
		$token  = '';
		foreach ( $services as $sid ) {
			$res = UH_Matching::join( $sid, $hospital_id, $data );
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
			} else {
				$ok++;
				$token = $token ?: $res['token'];
			}
		}
		if ( $errors ) {
			set_transient( 'uh_msg_' . md5( $token . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), $errors, 300 );
		}
		$args = array( 'uh_ok' => 'request', 'uh_n' => $ok );
		if ( ! $uid && $token ) {
			$args['uh_token'] = $token;
		}
		if ( ! $ok ) {
			$args = array( 'uh_err' => 'dup' );
		}
		$this->back( $args );
	}

	private function do_withdraw() {
		$req = UH_Repo::request( absint( $_POST['request_id'] ?? 0 ) );
		$tok = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! $req ) {
			$this->back( array( 'uh_err' => 'notfound' ) );
		}
		$owns = ( $req->user_id && (int) $req->user_id === get_current_user_id() ) || ( $tok && hash_equals( $req->token, $tok ) ) || current_user_can( UH_Install::CAP_MANAGE );
		if ( ! $owns ) {
			$this->back( array( 'uh_err' => 'forbidden' ) );
		}
		$res = UH_Matching::withdraw( $req );
		$this->back( is_wp_error( $res ) ? array( 'uh_err' => 'locked' ) : array( 'uh_ok' => 'withdrawn' ) );
	}

	/** A Hero reports a problem with a visit (logged in, or with the private link). */
	private function do_report() {
		$req = UH_Repo::request( absint( $_POST['request_id'] ?? 0 ) );
		$tok = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		if ( ! $req ) {
			$this->back( array( 'uh_err' => 'notfound' ) );
		}
		$owns = ( $req->user_id && (int) $req->user_id === get_current_user_id() ) || ( $tok && hash_equals( $req->token, $tok ) );
		if ( ! $owns ) {
			$this->back( array( 'uh_err' => 'forbidden' ) );
		}
		$msg = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		if ( '' === trim( $msg ) ) {
			$this->back( array( 'uh_err' => 'missing' ) );
		}
		$res = UH_Reports::create( $req, $msg );
		$this->back( is_wp_error( $res ) ? array( 'uh_err' => $res->get_error_code() ) : array( 'uh_ok' => 'reported' ) );
	}

	private function do_profile() {
		if ( ! is_user_logged_in() ) {
			$this->back( array( 'uh_err' => 'login' ) );
		}
		$uid = get_current_user_id();
		update_user_meta( $uid, 'uh_services', array_map( 'absint', (array) ( $_POST['services'] ?? array() ) ) );
		update_user_meta( $uid, 'uh_hospitals', array_map( 'absint', (array) ( $_POST['hospitals'] ?? array() ) ) );
		update_user_meta( $uid, 'uh_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
		update_user_meta( $uid, 'uh_whatsapp', sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ) );
		update_user_meta( $uid, 'uh_telegram', sanitize_text_field( wp_unslash( $_POST['telegram'] ?? '' ) ) );
		update_user_meta( $uid, 'uh_about', sanitize_textarea_field( wp_unslash( $_POST['about'] ?? '' ) ) );
		$nick = UH_Privacy::clean_nickname( wp_unslash( $_POST['nickname'] ?? '' ) );
		if ( '' !== $nick ) {
			wp_update_user( array( 'ID' => $uid, 'nickname' => $nick, 'display_name' => $nick ) );
		}
		$u = wp_get_current_user();
		if ( ! empty( $_POST['is_supporter'] ) ) {
			// Only people invited as Supporters (or allowed by a coordinator) can become one.
			if ( ! in_array( UH_Install::ROLE_SUPPORTER, $u->roles, true ) ) {
				if ( ! UH_Network::may_become_supporter( $uid ) ) {
					$this->back( array( 'uh_err' => 'need_supporter_invite' ) );
				}
				if ( empty( $_POST['coc'] ) && ! get_user_meta( $uid, UH_Network::META_COC, true ) ) {
					$this->back( array( 'uh_err' => 'coc', 'uh_tab' => 'profile' ) );
				}
				$u->add_role( UH_Install::ROLE_SUPPORTER );
				if ( ! get_user_meta( $uid, UH_Network::META_COC, true ) ) {
					update_user_meta( $uid, UH_Network::META_COC, current_time( 'mysql' ) );
				}
				self::tell_coordinators_new_supporter( $uid );
			}
		} elseif ( in_array( UH_Install::ROLE_SUPPORTER, $u->roles, true ) ) {
			$u->remove_role( UH_Install::ROLE_SUPPORTER );
			if ( ! $u->roles ) {
				$u->add_role( UH_Install::ROLE_HERO );
			}
		}
		$this->back( array( 'uh_ok' => 'profile' ) );
	}

	private function do_supporter_action( $action ) {
		if ( ! self::is_supporter() ) {
			$this->back( array( 'uh_err' => 'forbidden' ) );
		}
		$gid = absint( $_POST['group_id'] ?? 0 );
		$uid = get_current_user_id();
		if ( 'accept' === $action ) {
			$res = UH_Matching::accept( $gid, $uid, sanitize_text_field( wp_unslash( $_POST['appointment'] ?? '' ) ), sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) );
		} elseif ( 'release' === $action ) {
			$res = UH_Matching::release( $gid, $uid );
		} else {
			$res = UH_Matching::complete( $gid, $uid );
		}
		$this->back( is_wp_error( $res ) ? array( 'uh_err' => $res->get_error_code() ) : array( 'uh_ok' => $action ) );
	}

	private function do_register() {
		if ( is_user_logged_in() ) {
			$this->back( array( 'uh_ok' => 'registered' ) );
		}
		if ( ! empty( $_POST['uh_website'] ) || $this->rate_limited() ) {
			$this->back( array( 'uh_err' => 'rate' ) );
		}
		// Invite-only: the invite replaces WordPress's "Anyone can register" switch.
		$invite = UH_Network::invite_by_code( wp_unslash( $_POST['invite'] ?? '' ) );
		$email  = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$name   = UH_Privacy::clean_nickname( wp_unslash( $_POST['name'] ?? '' ) );
		$real   = mb_substr( sanitize_text_field( wp_unslash( $_POST['real_name'] ?? '' ) ), 0, 128 );
		$role   = sanitize_key( $_POST['role'] ?? '' );
		if ( 'hero' !== $role && empty( $_POST['coc'] ) ) {
			$this->back( array( 'uh_err' => 'coc', 'uh_invite' => $invite ? $invite->code : '' ) );
		}
		if ( ! is_email( $email ) || '' === $name || ! in_array( $role, array( 'hero', 'supporter', 'both' ), true ) ) {
			$this->back( array( 'uh_err' => 'missing', 'uh_invite' => $invite ? $invite->code : '' ) );
		}
		$why = UH_Network::invalid_reason( $invite, $role );
		if ( $why ) {
			$this->back( array( 'uh_err' => $why, 'uh_invite' => $invite ? $invite->code : '' ) );
		}
		if ( email_exists( $email ) ) {
			$this->back( array( 'uh_err' => 'exists' ) );
		}
		if ( ! UH_Network::consume( $invite ) ) {
			$this->back( array( 'uh_err' => 'invite_used' ) );
		}
		// The login name says nothing about the person; others only ever see the nickname.
		$uid = wp_insert_user(
			array(
				'user_login'   => UH_Privacy::random_login(),
				'user_email'   => $email,
				'nickname'     => $name,
				'display_name' => $name,
				'first_name'   => $real,
				'locale'       => 'en_US' !== UH_I18n::current() ? UH_I18n::current() : '',
				'user_pass'    => wp_generate_password( 20 ),
				'role'         => 'supporter' === $role ? UH_Install::ROLE_SUPPORTER : UH_Install::ROLE_HERO,
			)
		);
		if ( is_wp_error( $uid ) ) {
			$this->back( array( 'uh_err' => 'missing' ) );
		}
		if ( 'both' === $role ) {
			( new WP_User( $uid ) )->add_role( UH_Install::ROLE_SUPPORTER );
		}
		UH_Network::attach( $uid, $invite );
		update_user_meta( $uid, 'uh_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
		if ( 'hero' !== $role ) {
			update_user_meta( $uid, UH_Network::META_COC, current_time( 'mysql' ) );
			self::tell_coordinators_new_supporter( $uid );
		}
		wp_new_user_notification( $uid, null, 'user' ); // Sends "set your password" link.
		$this->back( array( 'uh_ok' => 'registered' ) );
	}

	/* =====================================================================
	 * Helpers
	 * ===================================================================== */

	/** A Supporter who is connected to the network (or a coordinator). */
	public static function is_supporter() {
		return is_user_logged_in() && UH_Network::can_support( get_current_user_id() );
	}

	/** Ask coordinators to approve a new Supporter (no personal details in the message). */
	public static function tell_coordinators_new_supporter( $uid ) {
		if ( UH_Network::is_approved( $uid ) ) {
			return;
		}
		$by = UH_Network::inviter( $uid );
		UH_Notify::coordinators(
			__( 'New Supporter to approve', 'ukraine-health' ),
			sprintf(
				/* translators: 1: nickname, 2: inviter nickname, 3: link */
				__( "%1\$s (invited by %2\$s) wants to help as a Supporter and is waiting for your approval:\n%3\$s", 'ukraine-health' ),
				UH_Privacy::nickname( $uid ),
				$by ? UH_Privacy::nickname( $by ) : '—',
				admin_url( 'admin.php?page=ukraine-health-network&tab=review' )
			)
		);
	}

	private function do_invite_create() {
		if ( ! is_user_logged_in() ) {
			$this->back( array( 'uh_err' => 'login' ) );
		}
		$uid  = get_current_user_id();
		$role = sanitize_key( $_POST['role'] ?? 'hero' );
		$res  = UH_Network::create_invite(
			$uid,
			$role,
			wp_unslash( $_POST['note'] ?? '' ),
			absint( $_POST['max_uses'] ?? 1 ),
			absint( $_POST['days'] ?? 0 )
		);
		if ( is_wp_error( $res ) ) {
			$this->back( array( 'uh_err' => $res->get_error_code() ) );
		}
		$this->back( array( 'uh_ok' => 'invite', 'uh_new' => (int) $res->id ) );
	}

	private function do_invite_revoke() {
		if ( ! is_user_logged_in() ) {
			$this->back( array( 'uh_err' => 'login' ) );
		}
		$ok = UH_Network::revoke( absint( $_POST['invite_id'] ?? 0 ), get_current_user_id() );
		$this->back( $ok ? array( 'uh_ok' => 'invite_revoked' ) : array( 'uh_err' => 'forbidden' ) );
	}

	private function notice() {
		$ok  = sanitize_key( $_GET['uh_ok'] ?? '' );
		$err = sanitize_key( $_GET['uh_err'] ?? '' );
		$oks = array(
			'request'    => sprintf( _n( 'Thank you! Your request was received.', 'Thank you! Your %d requests were received.', max( 1, absint( $_GET['uh_n'] ?? 1 ) ), 'ukraine-health' ), absint( $_GET['uh_n'] ?? 1 ) ),
			'withdrawn'  => __( 'Your request was withdrawn.', 'ukraine-health' ),
			'profile'    => __( 'Profile saved.', 'ukraine-health' ),
			'accept'     => __( 'You accepted the group. The Heroes have been notified – thank you!', 'ukraine-health' ),
			'release'    => __( 'Group released back to the queue.', 'ukraine-health' ),
			'complete'   => __( 'Marked as completed. Thank you for helping!', 'ukraine-health' ),
			'registered' => __( 'Account created. Check your email to set a password.', 'ukraine-health' ),
			'invite'         => __( 'Invite created. Send the link below to the person – it works once.', 'ukraine-health' ),
			'invite_revoked' => __( 'Invite cancelled.', 'ukraine-health' ),
			'reported'       => __( 'Thank you for telling us. A coordinator will contact you. The Supporter will not get new groups until this is reviewed.', 'ukraine-health' ),
		);
		$errs = array(
			'nonce'      => __( 'The form expired. Please try again.', 'ukraine-health' ),
			'login'      => __( 'Please log in first.', 'ukraine-health' ),
			'rate'       => __( 'Too many submissions. Please try again later.', 'ukraine-health' ),
			'missing'    => __( 'Please fill in all required fields.', 'ukraine-health' ),
			'phone'      => __( 'Please enter a valid phone number.', 'ukraine-health' ),
			'consent'    => __( 'Please agree to share your contact details.', 'ukraine-health' ),
			'dup'        => __( 'You are already on the list for the selected services.', 'ukraine-health' ),
			'notfound'   => __( 'Request not found.', 'ukraine-health' ),
			'forbidden'  => __( 'You are not allowed to do that.', 'ukraine-health' ),
			'locked'     => __( 'This request can no longer be changed.', 'ukraine-health' ),
			'uh_taken'   => __( 'Someone else already took this group.', 'ukraine-health' ),
			'uh_date'    => __( 'Please choose an appointment date and time.', 'ukraine-health' ),
			'exists'     => __( 'An account with this email already exists. Please log in.', 'ukraine-health' ),
			'closed'     => __( 'Registration is currently closed.', 'ukraine-health' ),
			'invite'                => __( 'This site is invite-only. Please open the invite link you received, or ask a Supporter or coordinator you know for one.', 'ukraine-health' ),
			'invite_used'           => __( 'This invite has already been used, has expired or was cancelled. Please ask the person who invited you for a new one.', 'ukraine-health' ),
			'invite_role'           => __( 'This invite is not for that role. Please choose the role your invite is for.', 'ukraine-health' ),
			'not_connected'         => __( 'Your account is not connected to the network yet, so you cannot invite others. Please contact the coordinator.', 'ukraine-health' ),
			'too_many'              => __( 'You have too many unused invites. Cancel some or wait until they are used.', 'ukraine-health' ),
			'coc'                   => __( 'Please read and accept the code of conduct.', 'ukraine-health' ),
			'report_none'           => __( 'There is no visit to report for this request.', 'ukraine-health' ),
			'report_dup'            => __( 'You already reported this visit. A coordinator will contact you.', 'ukraine-health' ),
			'need_supporter_invite' => __( 'To help as a Supporter you need a Supporter invite from a Supporter or coordinator who knows you.', 'ukraine-health' ),
		);
		if ( $ok && isset( $oks[ $ok ] ) ) {
			return '<div class="uh-notice uh-ok" role="status">' . esc_html( $oks[ $ok ] ) . '</div>';
		}
		if ( $err ) {
			return '<div class="uh-notice uh-err" role="alert">' . esc_html( $errs[ $err ] ?? __( 'Something went wrong.', 'ukraine-health' ) ) . '</div>';
		}
		return '';
	}

	private function hidden( $action ) {
		return wp_nonce_field( self::NONCE, '_uh', false, false ) . '<input type="hidden" name="uh_action" value="' . esc_attr( $action ) . '">';
	}

	private function hospital_select( $name, $selected = 0, $required = true ) {
		$by = array();
		foreach ( UH_Repo::hospitals() as $h ) {
			$by[ $h->country ][] = $h;
		}
		$html = '<select name="' . esc_attr( $name ) . '" id="uh-hospital" ' . ( $required ? 'required' : '' ) . '><option value="">' . esc_html__( '— Choose hospital —', 'ukraine-health' ) . '</option>';
		foreach ( $by as $country => $list ) {
			$html .= '<optgroup label="' . esc_attr( $country ) . '">';
			foreach ( $list as $h ) {
				$html .= '<option value="' . (int) $h->id . '" ' . selected( (int) $selected, (int) $h->id, false ) . '>' . esc_html( $h->name . ( $h->city ? ' — ' . $h->city : '' ) ) . '</option>';
			}
			$html .= '</optgroup>';
		}
		return $html . '</select>';
	}

	/** "Map" link for a group row (has hospital_name, hospital_address, city, place_id, maps_url). */
	private static function map_link( $g ) {
		$h = (object) array(
			'name'     => $g->hospital_name,
			'address'  => $g->hospital_address ?? '',
			'city'     => $g->city,
			'country'  => $g->country ?? '',
			'place_id' => $g->place_id ?? '',
			'maps_url' => $g->maps_url ?? '',
			'lat'      => $g->lat ?? null,
			'lng'      => $g->lng ?? null,
		);
		return '<a href="' . esc_url( UH_Repo::maps_url( $h ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'ukraine-health' ) . '</a>';
	}

	private static function fmt_date( $mysql ) {
		return $mysql ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mysql ) : '—';
	}

	private static function badge( $status ) {
		$all = UH_Install::group_statuses();
		return '<span class="uh-badge uh-' . esc_attr( $status ) . '">' . esc_html( $all[ $status ] ?? $status ) . '</span>';
	}

	/* =====================================================================
	 * [uh_request_form]
	 * ===================================================================== */

	public function sc_request_form() {
		$this->enqueue();
		$set = UH_Install::settings();
		$out = '<div class="uh uh-request">' . UH_I18n::switcher() . $this->notice();

		if ( isset( $_GET['uh_ok'] ) && ! empty( $_GET['uh_token'] ) ) {
			$link = add_query_arg( 'uh_token', sanitize_text_field( wp_unslash( $_GET['uh_token'] ) ), UH_Notify::page_url( 'status' ) );
			$out .= '<p class="uh-card">' . esc_html__( 'Save this private link to check or cancel your request:', 'ukraine-health' ) . '<br><a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>';
			$tg   = UH_Telegram::request_link( UH_Repo::request_by_token( sanitize_text_field( wp_unslash( $_GET['uh_token'] ) ) ) );
			if ( $tg ) {
				$out .= '<p><a class="uh-btn uh-btn-tg" href="' . esc_url( $tg ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get updates on Telegram', 'ukraine-health' ) . '</a></p>';
			}
		}

		if ( ! is_user_logged_in() && empty( $set['allow_guests'] ) ) {
			return $out . '<p>' . sprintf( wp_kses_post( __( 'Please <a href="%s">log in</a> to request help.', 'ukraine-health' ) ), esc_url( wp_login_url( get_permalink() ) ) ) . '</p></div>';
		}

		$services  = UH_Repo::services();
		$hospitals = UH_Repo::hospitals();
		if ( ! $services || ! $hospitals ) {
			return $out . '<p>' . esc_html__( 'The system is not configured yet.', 'ukraine-health' ) . '</p></div>';
		}

		$uid   = get_current_user_id();
		$user  = $uid ? wp_get_current_user() : null;
		$sizes = (array) ( $uid ? get_user_meta( $uid, 'uh_sizes', true ) : array() );

		// Invite-only: people who are not yet connected need an invite code.
		$need_code = ! empty( $set['invite_guests'] ) && ! ( $uid && UH_Network::is_connected( $uid ) );
		$code      = UH_Network::clean_code( wp_unslash( $_GET['uh_invite'] ?? '' ) );
		$code_inv  = $code ? UH_Network::invite_by_code( $code ) : null;
		$code_bad  = $code && UH_Network::invalid_reason( $code_inv, 'hero' );
		if ( $need_code && ! $code ) {
			$out .= '<div class="uh-card uh-invite-only"><strong>' . esc_html__( 'Invite-only', 'ukraine-health' ) . '</strong><br>' . esc_html__( 'To keep Heroes safe, everyone here is invited by someone they know. Ask a Supporter, a coordinator or another Hero for an invite link, or enter your invite code below.', 'ukraine-health' ) . '</div>';
		} elseif ( $need_code && $code_bad ) {
			$out .= '<div class="uh-notice uh-err" role="alert">' . esc_html__( 'This invite has already been used, has expired or was cancelled. Please ask the person who invited you for a new one.', 'ukraine-health' ) . '</div>';
		} elseif ( $need_code && $code_inv ) {
			$by   = get_userdata( (int) $code_inv->inviter_id );
			$out .= '<div class="uh-notice uh-ok" role="status">' . esc_html( sprintf( __( 'You were invited by %s.', 'ukraine-health' ), $by ? UH_Privacy::nickname( $by ) : '—' ) ) . '</div>';
		}
		$cats  = UH_Install::categories();
		$by    = array();
		foreach ( $services as $s ) {
			$by[ $s->category ][] = $s;
		}

		ob_start();
		?>
		<form method="post" class="uh-form" id="uh-request-form">
			<?php echo $this->hidden( 'request' ); // phpcs:ignore ?>
			<p class="uh-hp" aria-hidden="true"><label>Website <input type="text" name="uh_website" tabindex="-1" autocomplete="off"></label></p>

			<h3><span class="uh-step">1</span> <?php esc_html_e( 'Where are you?', 'ukraine-health' ); ?></h3>
			<label class="uh-field"><?php esc_html_e( 'Hospital', 'ukraine-health' ); ?> *
				<?php echo $this->hospital_select( 'hospital', $uid ? (int) get_user_meta( $uid, 'uh_hospital', true ) : 0 ); // phpcs:ignore ?>
			</label>
			<?php
			$vh = array();
			foreach ( $hospitals as $h ) {
				if ( $h->visiting_hours ) {
					$vh[] = $h->name . ': ' . $h->visiting_hours;
				}
			}
			if ( $vh ) {
				echo '<p class="uh-help">' . esc_html__( 'Visiting hours', 'ukraine-health' ) . ' — ' . esc_html( implode( ' · ', $vh ) ) . '</p>';
			}
			?>
			<label class="uh-field"><?php esc_html_e( 'Ward / room', 'ukraine-health' ); ?>
				<input type="text" name="ward" maxlength="64" value="<?php echo esc_attr( $uid ? get_user_meta( $uid, 'uh_ward', true ) : '' ); ?>">
			</label>

			<h3><span class="uh-step">2</span> <?php esc_html_e( 'What do you need?', 'ukraine-health' ); ?></h3>
			<p class="uh-help"><?php esc_html_e( 'Choose one or more. Some services start when enough people at your hospital sign up.', 'ukraine-health' ); ?></p>
			<?php foreach ( $by as $cat => $list ) : ?>
				<fieldset class="uh-cat"><legend><?php echo esc_html( $cats[ $cat ] ?? ucfirst( $cat ) ); ?></legend>
				<div class="uh-tiles">
				<?php foreach ( $list as $s ) : ?>
					<label class="uh-tile" data-service="<?php echo (int) $s->id; ?>" data-min="<?php echo (int) $s->min_group; ?>" data-sizes="<?php echo (int) $s->needs_sizes; ?>">
						<input type="checkbox" name="services[]" value="<?php echo (int) $s->id; ?>">
						<span class="uh-tile-name"><?php echo esc_html( UH_Install::svc( $s->name ) ); ?></span>
						<?php if ( $s->min_group > 1 ) : ?>
							<span class="uh-tile-min"><?php echo esc_html( sprintf( __( 'group of %d', 'ukraine-health' ), $s->min_group ) ); ?></span>
						<?php endif; ?>
						<span class="uh-tile-count" aria-live="polite"></span>
					</label>
				<?php endforeach; ?>
				</div></fieldset>
			<?php endforeach; ?>

			<fieldset class="uh-sizes" hidden><legend><?php esc_html_e( 'Your sizes', 'ukraine-health' ); ?></legend>
				<div class="uh-row">
				<?php foreach ( array( 'shirt' => __( 'Top', 'ukraine-health' ), 'pants' => __( 'Trousers', 'ukraine-health' ), 'shoes' => __( 'Shoes', 'ukraine-health' ), 'jacket' => __( 'Jacket', 'ukraine-health' ) ) as $k => $label ) : ?>
					<label class="uh-field uh-small"><?php echo esc_html( $label ); ?> <input type="text" name="sizes[<?php echo esc_attr( $k ); ?>]" maxlength="12" value="<?php echo esc_attr( $sizes[ $k ] ?? '' ); ?>"></label>
				<?php endforeach; ?>
				</div>
			</fieldset>

			<h3><span class="uh-step">3</span> <?php esc_html_e( 'How can we reach you?', 'ukraine-health' ); ?></h3>
			<div class="uh-row">
				<label class="uh-field"><?php esc_html_e( 'Nickname', 'ukraine-health' ); ?> *
					<input type="text" name="name" maxlength="40" required autocomplete="nickname" value="<?php echo esc_attr( $user ? UH_Privacy::nickname( $user ) : '' ); ?>">
					<small class="uh-help"><?php esc_html_e( 'What the Supporter will call you. Do not use your full name.', 'ukraine-health' ); ?></small>
				</label>
				<label class="uh-field"><?php esc_html_e( 'Phone', 'ukraine-health' ); ?> <?php echo $uid ? '' : '*'; ?>
					<input type="tel" name="phone" maxlength="20" placeholder="+380… / +371…" <?php echo $uid ? '' : 'required'; ?> value="<?php echo esc_attr( $uid ? get_user_meta( $uid, 'uh_phone', true ) : '' ); ?>">
				</label>
			</div>
			<div class="uh-row">
				<label class="uh-field"><?php esc_html_e( 'Messenger', 'ukraine-health' ); ?>
					<select name="messenger"><option value=""><?php esc_html_e( 'Phone call / SMS', 'ukraine-health' ); ?></option>
					<?php
					$cur = $uid ? get_user_meta( $uid, 'uh_messenger', true ) : '';
					foreach ( (array) $set['messengers'] as $m ) {
						echo '<option ' . selected( $cur, $m, false ) . '>' . esc_html( $m ) . '</option>';
					}
					?>
					</select>
				</label>
				<?php if ( ! $uid ) : ?>
				<label class="uh-field"><?php esc_html_e( 'Email (optional, for updates)', 'ukraine-health' ); ?>
					<input type="email" name="email" maxlength="191">
				</label>
				<?php endif; ?>
			</div>
			<details class="uh-more"><summary><?php esc_html_e( 'Real name (optional)', 'ukraine-health' ); ?></summary>
				<label class="uh-field"><?php esc_html_e( 'Real name – seen only by coordinators, never by Supporters', 'ukraine-health' ); ?>
					<input type="text" name="real_name" maxlength="128" autocomplete="name">
				</label>
			</details>
			<label class="uh-field"><?php esc_html_e( 'Notes (mobility, preferences, anything we should know)', 'ukraine-health' ); ?>
				<textarea name="notes" rows="3" maxlength="1000"></textarea>
			</label>
			<?php if ( $need_code ) : ?>
			<label class="uh-field"><?php esc_html_e( 'Invite code', 'ukraine-health' ); ?> *
				<input type="text" name="invite" required maxlength="32" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $code ); ?>">
			</label>
			<?php endif; ?>
			<label class="uh-check"><input type="checkbox" name="consent" value="1" required> <?php echo esc_html( $set['privacy_notice'] ); ?></label>
			<p><button type="submit" class="uh-btn uh-btn-primary"><?php esc_html_e( 'Send request', 'ukraine-health' ); ?></button></p>
		</form>
		<?php
		return $out . ob_get_clean() . '</div>';
	}

	/* =====================================================================
	 * [uh_my_requests]
	 * ===================================================================== */

	public function sc_my_requests() {
		$this->enqueue();
		$out   = '<div class="uh uh-mine">' . UH_I18n::switcher() . $this->notice();
		$token = sanitize_text_field( wp_unslash( $_GET['uh_token'] ?? '' ) );
		$rows  = array();
		if ( $token ) {
			$r = UH_Repo::request_by_token( $token );
			if ( $r ) {
				global $wpdb;
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT r.*, g.status AS group_status, g.appointment, s.name AS service_name, s.min_group, h.name AS hospital_name,
							(SELECT COUNT(*) FROM ' . UH_Repo::t( 'requests' ) . " r2 WHERE r2.group_id=g.id AND r2.status<>'withdrawn') AS members
						FROM " . UH_Repo::t( 'requests' ) . ' r JOIN ' . UH_Repo::t( 'groups' ) . ' g ON g.id=r.group_id
						JOIN ' . UH_Repo::t( 'services' ) . ' s ON s.id=g.service_id JOIN ' . UH_Repo::t( 'hospitals' ) . ' h ON h.id=g.hospital_id
						WHERE r.id=%d OR (r.user_id IS NULL AND r.phone=%s AND r.phone<>%s AND r.created_at BETWEEN %s AND %s) ORDER BY r.id',
						$r->id,
						$r->phone,
						'',
						gmdate( 'Y-m-d H:i:s', strtotime( $r->created_at ) - 60 ),
						gmdate( 'Y-m-d H:i:s', strtotime( $r->created_at ) + 60 )
					)
				);
				// The private link covers every service chosen in that same submission (same phone, same minute).
			}
		} elseif ( is_user_logged_in() ) {
			$rows = UH_Repo::user_requests( get_current_user_id() );
		} else {
			return $out . '<p>' . sprintf( wp_kses_post( __( 'Please <a href="%s">log in</a> or open the private link you received.', 'ukraine-health' ) ), esc_url( wp_login_url( get_permalink() ) ) ) . '</p></div>';
		}

		$invite_link = '';
		if ( is_user_logged_in() && UH_Network::is_connected( get_current_user_id() ) ) {
			$invite_link = '<p class="uh-help"><a href="' . esc_url( add_query_arg( 'uh_tab', 'invite', UH_Notify::page_url( 'supporter' ) ) ) . '">' . esc_html__( 'Invite another Hero you know', 'ukraine-health' ) . '</a></p>';
		}
		if ( ! $rows ) {
			return $out . '<p>' . esc_html__( 'No requests yet.', 'ukraine-health' ) . ' <a href="' . esc_url( UH_Notify::page_url( 'request' ) ) . '">' . esc_html__( 'Ask for help', 'ukraine-health' ) . '</a></p>' . $invite_link . '</div>';
		}

		$out .= '<div class="uh-list">';
		foreach ( $rows as $r ) {
			$status = 'withdrawn' === $r->status ? 'cancelled' : $r->group_status;
			$out   .= '<div class="uh-card"><div class="uh-card-head"><strong>' . esc_html( UH_Install::svc( $r->service_name ) ) . '</strong> ' . self::badge( $status ) . '</div>';
			$out   .= '<div class="uh-meta">' . esc_html( $r->hospital_name ) . '</div>';
			if ( in_array( $r->group_status, array( 'open', 'ready' ), true ) && 'withdrawn' !== $r->status ) {
				$pct  = min( 100, round( 100 * $r->members / max( 1, $r->min_group ) ) );
				$out .= '<div class="uh-progress" role="progressbar" aria-valuenow="' . (int) $r->members . '" aria-valuemax="' . (int) $r->min_group . '"><span style="width:' . (int) $pct . '%"></span></div>';
				$out .= '<div class="uh-meta">' . esc_html( sprintf( __( '%1$d of %2$d people signed up', 'ukraine-health' ), $r->members, $r->min_group ) ) . '</div>';
			}
			$grp = UH_Repo::group( $r->group_id );
			if ( 'assigned' === $r->group_status && 'withdrawn' !== $r->status ) {
				$out .= '<div class="uh-when">📅 ' . esc_html( self::fmt_date( $r->appointment ) ) . '</div>';
				if ( $grp && $grp->supporter_id ) {
					$out .= '<div class="uh-meta">' . esc_html( sprintf( __( 'Supporter: %s', 'ukraine-health' ), UH_Privacy::nickname( (int) $grp->supporter_id ) ) ) . '</div>';
				}
			}
			if ( $grp && $grp->supporter_id && in_array( $r->group_status, array( 'assigned', 'completed' ), true ) && 'withdrawn' !== $r->status && ! $r->wiped_at ) {
				if ( UH_Reports::exists_for_request( $r->id ) ) {
					$out .= '<div class="uh-meta">' . esc_html__( 'You reported a problem with this visit. A coordinator will contact you.', 'ukraine-health' ) . '</div>';
				} else {
					$out .= '<details class="uh-more uh-report"><summary>' . esc_html__( 'Report a problem with this visit', 'ukraine-health' ) . '</summary><form method="post">' . $this->hidden( 'report' ) . '<input type="hidden" name="request_id" value="' . (int) $r->id . '"><input type="hidden" name="token" value="' . esc_attr( $token ? $r->token : '' ) . '">';
					$out .= '<p class="uh-help">' . esc_html__( 'Only coordinators see this. The Supporter is paused right away and gets no new groups until a coordinator has looked at it.', 'ukraine-health' ) . '</p>';
					$out .= '<label class="uh-field">' . esc_html__( 'What happened?', 'ukraine-health' ) . ' <textarea name="message" rows="3" maxlength="3000" required></textarea></label><button class="uh-btn uh-btn-primary">' . esc_html__( 'Send to coordinator', 'ukraine-health' ) . '</button></form></details>';
				}
			}
			if ( in_array( $r->group_status, array( 'open', 'ready', 'assigned' ), true ) && 'withdrawn' !== $r->status ) {
				$out .= '<form method="post" class="uh-inline">' . $this->hidden( 'withdraw' ) . '<input type="hidden" name="request_id" value="' . (int) $r->id . '"><input type="hidden" name="token" value="' . esc_attr( $token ? $r->token : '' ) . '"><button class="uh-btn uh-btn-link" onclick="return confirm(\'' . esc_js( __( 'Cancel this request?', 'ukraine-health' ) ) . '\')">' . esc_html__( 'Cancel request', 'ukraine-health' ) . '</button></form>';
			}
			$out .= '</div>';
		}
		$tg = '';
		if ( $token && $rows ) {
			$tg = UH_Telegram::request_link( UH_Repo::request( (int) $rows[0]->id ) );
		} elseif ( is_user_logged_in() && ! get_user_meta( get_current_user_id(), 'uh_telegram', true ) ) {
			$tg = UH_Telegram::user_link( get_current_user_id() );
		}
		if ( $tg ) {
			$tg = '<p><a class="uh-btn uh-btn-tg" href="' . esc_url( $tg ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get updates on Telegram', 'ukraine-health' ) . '</a></p>';
		}
		return $out . '</div>' . $tg . $invite_link . '</div>';
	}

	/* =====================================================================
	 * [uh_supporter]
	 * ===================================================================== */

	public function sc_supporter() {
		$this->enqueue();
		$out = '<div class="uh uh-supporter">' . UH_I18n::switcher() . $this->notice();
		if ( ! is_user_logged_in() ) {
			$reg = UH_Notify::page_url( 'register' );
			return $out . '<p>' . sprintf( wp_kses_post( __( 'Want to help? <a href="%1$s">Log in</a> or <a href="%2$s">sign up as a Supporter</a>.', 'ukraine-health' ) ), esc_url( wp_login_url( get_permalink() ) ), esc_url( $reg ) ) . '</p></div>';
		}
		$uid       = get_current_user_id();
		$is_sup    = self::is_supporter();
		$my_svc    = array_map( 'intval', (array) get_user_meta( $uid, 'uh_services', true ) );
		$my_hosp   = array_filter( array_map( 'intval', (array) get_user_meta( $uid, 'uh_hospitals', true ) ) );
		$cats      = UH_Install::categories();
		$tab       = sanitize_key( $_GET['uh_tab'] ?? ( $is_sup && $my_svc ? 'groups' : 'profile' ) );
		$base      = remove_query_arg( array( 'uh_ok', 'uh_err', 'uh_tab' ) );

		$out .= '<nav class="uh-tabs">';
		foreach ( array( 'groups' => __( 'Groups to help', 'ukraine-health' ), 'mine' => __( 'My schedule', 'ukraine-health' ), 'invite' => __( 'Invite', 'ukraine-health' ), 'profile' => __( 'My profile', 'ukraine-health' ) ) as $k => $label ) {
			$out .= '<a class="' . ( $tab === $k ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'uh_tab', $k, $base ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= '</nav>';

		if ( 'invite' === $tab ) {
			return $out . $this->invite_panel( $uid ) . '</div>';
		}

		if ( 'profile' === $tab || ! $is_sup ) {
			$has_role  = in_array( UH_Install::ROLE_SUPPORTER, wp_get_current_user()->roles, true );
			$may_help  = $has_role || UH_Network::may_become_supporter( $uid );
			$connected = UH_Network::is_connected( $uid );
			ob_start();
			if ( ! $connected ) {
				echo '<div class="uh-notice uh-err" role="alert">' . esc_html__( 'Your account is not connected to the network yet (nobody has vouched for it, or the person who invited you was suspended). A coordinator needs to review it before you can help or invite others.', 'ukraine-health' ) . '</div>';
			}
			?>
			<form method="post" class="uh-form">
				<?php echo $this->hidden( 'profile' ); // phpcs:ignore ?>
				<?php
				if ( $has_role && $connected && UH_Network::on_hold( $uid ) ) {
					echo '<div class="uh-notice uh-err" role="alert">' . esc_html__( 'Your Supporter account is paused while a coordinator reviews a report. You will hear from them.', 'ukraine-health' ) . '</div>';
				} elseif ( $has_role && $connected && ! UH_Network::is_approved( $uid ) ) {
					echo '<div class="uh-notice uh-ok" role="status">' . esc_html__( 'Thank you! A coordinator will review and approve your Supporter account. Until then you will not see groups.', 'ukraine-health' ) . '</div>';
				}
				?>
				<label class="uh-field"><?php esc_html_e( 'Nickname', 'ukraine-health' ); ?> * <input type="text" name="nickname" maxlength="40" required value="<?php echo esc_attr( UH_Privacy::nickname( $uid ) ); ?>"><small class="uh-help"><?php esc_html_e( 'The only name Heroes and other members see.', 'ukraine-health' ); ?></small></label>
				<?php if ( $may_help ) : ?>
				<label class="uh-check"><input type="checkbox" name="is_supporter" value="1" <?php checked( $has_role ); ?>> <strong><?php esc_html_e( 'I want to help as a Supporter', 'ukraine-health' ); ?></strong></label>
				<?php if ( ! get_user_meta( $uid, UH_Network::META_COC, true ) ) : ?>
				<?php echo self::code_of_conduct(); // phpcs:ignore ?>
				<?php endif; ?>
				<?php else : ?>
				<p class="uh-help"><?php esc_html_e( 'To help as a Supporter you need a Supporter invite from a Supporter or coordinator who knows you.', 'ukraine-health' ); ?></p>
				<?php endif; ?>
				<fieldset><legend><?php esc_html_e( 'What can you offer?', 'ukraine-health' ); ?></legend><div class="uh-tiles">
				<?php foreach ( UH_Repo::services() as $s ) : ?>
					<label class="uh-tile"><input type="checkbox" name="services[]" value="<?php echo (int) $s->id; ?>" <?php checked( in_array( (int) $s->id, $my_svc, true ) ); ?>> <span class="uh-tile-name"><?php echo esc_html( UH_Install::svc( $s->name ) ); ?></span><span class="uh-tile-min"><?php echo esc_html( $cats[ $s->category ] ?? '' ); ?></span></label>
				<?php endforeach; ?>
				</div></fieldset>
				<fieldset><legend><?php esc_html_e( 'Which hospitals? (none = all)', 'ukraine-health' ); ?></legend>
				<?php foreach ( UH_Repo::hospitals() as $h ) : ?>
					<label class="uh-check"><input type="checkbox" name="hospitals[]" value="<?php echo (int) $h->id; ?>" <?php checked( in_array( (int) $h->id, $my_hosp, true ) ); ?>> <?php echo esc_html( $h->name . ' — ' . $h->city ); ?></label>
				<?php endforeach; ?>
				</fieldset>
				<fieldset><legend><?php esc_html_e( 'Notifications', 'ukraine-health' ); ?></legend>
				<div class="uh-row">
					<label class="uh-field"><?php esc_html_e( 'Phone', 'ukraine-health' ); ?> <input type="tel" name="phone" value="<?php echo esc_attr( get_user_meta( $uid, 'uh_phone', true ) ); ?>"></label>
					<label class="uh-field"><?php esc_html_e( 'WhatsApp number', 'ukraine-health' ); ?> <input type="tel" name="whatsapp" value="<?php echo esc_attr( get_user_meta( $uid, 'uh_whatsapp', true ) ); ?>"></label>
					<label class="uh-field"><?php esc_html_e( 'Telegram chat ID', 'ukraine-health' ); ?> <input type="text" name="telegram" value="<?php echo esc_attr( get_user_meta( $uid, 'uh_telegram', true ) ); ?>"></label>
				</div>
				<?php $tgl = UH_Telegram::user_link( $uid ); ?>
				<?php if ( $tgl ) : ?>
				<p><a class="uh-btn uh-btn-tg" href="<?php echo esc_url( $tgl ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Connect Telegram in one tap', 'ukraine-health' ); ?></a></p>
				<?php endif; ?>
				<p class="uh-help"><?php esc_html_e( 'Email notifications are always on.', 'ukraine-health' ); ?></p>
				</fieldset>
				<label class="uh-field"><?php esc_html_e( 'About you (shown to coordinators)', 'ukraine-health' ); ?><textarea name="about" rows="3"><?php echo esc_textarea( get_user_meta( $uid, 'uh_about', true ) ); ?></textarea></label>
				<p><button class="uh-btn uh-btn-primary"><?php esc_html_e( 'Save profile', 'ukraine-health' ); ?></button></p>
			</form>
			<?php
			return $out . ob_get_clean() . '</div>';
		}

		if ( 'mine' === $tab ) {
			$groups = UH_Repo::groups( array( 'supporter_id' => $uid, 'status' => array( 'assigned', 'completed' ), 'limit' => 50 ) );
			if ( ! $groups ) {
				return $out . '<p>' . esc_html__( 'Nothing scheduled yet.', 'ukraine-health' ) . '</p></div>';
			}
			foreach ( $groups as $g ) {
				$out .= '<div class="uh-card"><div class="uh-card-head"><strong>' . esc_html( UH_Install::svc( $g->service_name ) ) . '</strong> ' . self::badge( $g->status ) . '</div>';
				$out .= '<div class="uh-meta">' . esc_html( $g->hospital_name . ', ' . $g->city ) . ' · 📅 ' . esc_html( self::fmt_date( $g->appointment ) ) . ' · ' . self::map_link( $g ) . '</div>';
				$open = UH_Privacy::contact_open( $g );
				if ( 'assigned' === $g->status && ! $open ) {
					$out .= '<p class="uh-help">' . esc_html( sprintf( __( 'To protect the Heroes, their wards and phone numbers appear here from %s.', 'ukraine-health' ), self::fmt_date( UH_Privacy::contact_opens_at( $g ) ) ) ) . '</p>';
				}
				$out .= '<ol class="uh-members">';
				foreach ( UH_Repo::group_members( $g->id ) as $m ) {
					if ( ! $open ) {
						// After the visit the Supporter no longer needs the Heroes' contact details.
						$out .= '<li><strong>' . esc_html( $m->name ?: '—' ) . '</strong></li>';
						continue;
					}
					$sizes = $m->sizes ? UH_Notify::sizes_text( json_decode( $m->sizes, true ) ) : '';
					$out  .= '<li><strong>' . esc_html( $m->name ?: '—' ) . '</strong> · ' . esc_html__( 'ward', 'ukraine-health' ) . ' ' . esc_html( $m->ward ?: '—' ) . ' · <a href="tel:' . esc_attr( $m->phone ) . '">' . esc_html( $m->phone ) . '</a> ' . esc_html( $m->messenger ) . ( $sizes ? ' · ' . esc_html( $sizes ) : '' ) . ( $m->notes ? '<br><small>' . esc_html( $m->notes ) . '</small>' : '' ) . '</li>';
				}
				$out .= '</ol>';
				if ( 'assigned' === $g->status ) {
					$out .= '<div class="uh-actions"><form method="post" class="uh-inline">' . $this->hidden( 'complete' ) . '<input type="hidden" name="group_id" value="' . (int) $g->id . '"><button class="uh-btn uh-btn-primary">' . esc_html__( 'Mark done', 'ukraine-health' ) . '</button></form>';
					$out .= '<form method="post" class="uh-inline">' . $this->hidden( 'release' ) . '<input type="hidden" name="group_id" value="' . (int) $g->id . '"><button class="uh-btn uh-btn-link" onclick="return confirm(\'' . esc_js( __( 'Give this group back to the queue?', 'ukraine-health' ) ) . '\')">' . esc_html__( 'I can’t make it', 'ukraine-health' ) . '</button></form></div>';
				}
				$out .= '</div>';
			}
			return $out . '</div>';
		}

		// Groups to help.
		if ( ! $my_svc ) {
			return $out . '<p>' . esc_html__( 'Choose the services you offer in your profile to see groups.', 'ukraine-health' ) . '</p></div>';
		}
		$groups = UH_Repo::groups( array( 'status' => array( 'ready', 'open' ), 'service_ids' => $my_svc, 'hospital_ids' => $my_hosp ) );
		if ( ! $groups ) {
			return $out . '<p>' . esc_html__( 'No groups waiting right now. We will notify you when one is ready.', 'ukraine-health' ) . '</p></div>';
		}
		$min_dt = wp_date( 'Y-m-d\TH:i', time() + HOUR_IN_SECONDS );
		foreach ( $groups as $g ) {
			$pct  = min( 100, round( 100 * $g->members / max( 1, $g->min_group ) ) );
			$out .= '<div class="uh-card ' . ( 'ready' === $g->status ? 'uh-card-ready' : '' ) . '"><div class="uh-card-head"><strong>' . esc_html( UH_Install::svc( $g->service_name ) ) . '</strong> ' . self::badge( $g->status ) . '</div>';
			$out .= '<div class="uh-meta">' . esc_html( $g->hospital_name . ', ' . $g->city ) . ( $g->visiting_hours ? ' · ' . esc_html( sprintf( __( 'Visiting hours: %s', 'ukraine-health' ), $g->visiting_hours ) ) : '' ) . ' · ' . self::map_link( $g ) . '</div>';
			$out .= '<div class="uh-progress"><span style="width:' . (int) $pct . '%"></span></div><div class="uh-meta">' . esc_html( sprintf( __( '%1$d of %2$d Heroes', 'ukraine-health' ), $g->members, $g->min_group ) ) . '</div>';
			$out .= '<form method="post" class="uh-accept">' . $this->hidden( 'accept' ) . '<input type="hidden" name="group_id" value="' . (int) $g->id . '">';
			$out .= '<label class="uh-field uh-small">' . esc_html__( 'Date & time', 'ukraine-health' ) . ' <input type="datetime-local" name="appointment" min="' . esc_attr( $min_dt ) . '" required></label>';
			$out .= '<label class="uh-field">' . esc_html__( 'Message to Heroes (optional)', 'ukraine-health' ) . ' <input type="text" name="note" maxlength="300"></label>';
			$out .= '<button class="uh-btn uh-btn-primary">' . ( 'ready' === $g->status ? esc_html__( 'Accept group', 'ukraine-health' ) : esc_html__( 'Accept anyway', 'ukraine-health' ) ) . '</button></form></div>';
		}
		return $out . '</div>';
	}

	/* =====================================================================
	 * [uh_register]
	 * ===================================================================== */

	public function sc_register( $atts ) {
		$this->enqueue();
		$atts = shortcode_atts( array( 'role' => 'hero' ), $atts );
		$out  = '<div class="uh uh-register">' . UH_I18n::switcher() . $this->notice();
		if ( is_user_logged_in() ) {
			return $out . '<p>' . esc_html__( 'You are logged in.', 'ukraine-health' ) . ' <a href="' . esc_url( UH_Notify::page_url( 'supporter' ) ) . '">' . esc_html__( 'Supporter area', 'ukraine-health' ) . '</a> · <a href="' . esc_url( UH_Notify::page_url( 'status' ) ) . '">' . esc_html__( 'My requests', 'ukraine-health' ) . '</a></p></div>';
		}
		$code = UH_Network::clean_code( wp_unslash( $_GET['uh_invite'] ?? '' ) );
		$inv  = $code ? UH_Network::invite_by_code( $code ) : null;
		if ( ! $code ) {
			ob_start();
			?>
			<div class="uh-card uh-invite-only">
				<p><strong><?php esc_html_e( 'Ukraine Health is invite-only.', 'ukraine-health' ); ?></strong></p>
				<p><?php esc_html_e( 'To keep wounded Heroes safe, every Supporter and every Hero is invited by someone already in the network who knows them. That way we always know who vouched for whom.', 'ukraine-health' ); ?></p>
				<p><?php esc_html_e( 'Ask a Supporter or coordinator you know for an invite link. Already have a code? Enter it here:', 'ukraine-health' ); ?></p>
				<form method="get" class="uh-row">
					<label class="uh-field"><?php esc_html_e( 'Invite code', 'ukraine-health' ); ?> <input type="text" name="uh_invite" required maxlength="32" autocomplete="off" spellcheck="false"></label>
					<p><button class="uh-btn uh-btn-primary"><?php esc_html_e( 'Continue', 'ukraine-health' ); ?></button></p>
				</form>
			</div>
			<?php
			return $out . ob_get_clean() . '</div>';
		}
		if ( UH_Network::invalid_reason( $inv ) ) {
			return $out . '<div class="uh-notice uh-err" role="alert">' . esc_html__( 'This invite has already been used, has expired or was cancelled. Please ask the person who invited you for a new one.', 'ukraine-health' ) . '</div></div>';
		}
		$by      = get_userdata( (int) $inv->inviter_id );
		$choices = array(
			'hero'      => __( 'Hero – I need help', 'ukraine-health' ),
			'supporter' => __( 'Supporter – I can help', 'ukraine-health' ),
			'both'      => __( 'Both', 'ukraine-health' ),
		);
		if ( 'both' !== $inv->role ) {
			$choices = array( $inv->role => $choices[ $inv->role ] );
		}
		$pick = isset( $choices[ $atts['role'] ] ) ? $atts['role'] : key( $choices );
		ob_start();
		?>
		<div class="uh-notice uh-ok" role="status"><?php echo esc_html( sprintf( __( 'You were invited by %s.', 'ukraine-health' ), $by ? UH_Privacy::nickname( $by ) : '—' ) ); ?></div>
		<form method="post" class="uh-form">
			<?php echo $this->hidden( 'register' ); // phpcs:ignore ?>
			<input type="hidden" name="invite" value="<?php echo esc_attr( $inv->code ); ?>">
			<p class="uh-hp" aria-hidden="true"><label>Website <input type="text" name="uh_website" tabindex="-1" autocomplete="off"></label></p>
			<fieldset><legend><?php esc_html_e( 'I am joining as', 'ukraine-health' ); ?></legend>
				<div class="uh-tiles">
				<?php foreach ( $choices as $k => $label ) : ?>
					<label class="uh-tile"><input type="radio" name="role" value="<?php echo esc_attr( $k ); ?>" <?php checked( $pick, $k ); ?> required> <span class="uh-tile-name"><?php echo esc_html( $label ); ?></span></label>
				<?php endforeach; ?>
				</div>
			</fieldset>
			<div class="uh-row">
				<label class="uh-field"><?php esc_html_e( 'Nickname', 'ukraine-health' ); ?> * <input type="text" name="name" required maxlength="40" autocomplete="nickname"><small class="uh-help"><?php esc_html_e( 'The only name other members see. Do not use your full name.', 'ukraine-health' ); ?></small></label>
				<label class="uh-field"><?php esc_html_e( 'Real name (optional)', 'ukraine-health' ); ?> <input type="text" name="real_name" maxlength="128" autocomplete="name"><small class="uh-help"><?php esc_html_e( 'Seen only by coordinators.', 'ukraine-health' ); ?></small></label>
				<label class="uh-field"><?php esc_html_e( 'Email', 'ukraine-health' ); ?> * <input type="email" name="email" required maxlength="191"></label>
				<label class="uh-field"><?php esc_html_e( 'Phone', 'ukraine-health' ); ?> <input type="tel" name="phone" maxlength="20"></label>
			</div>
			<?php if ( 'hero' !== $inv->role ) : ?>
			<div class="uh-coc-wrap" data-uh-coc><?php echo self::code_of_conduct( 'hero' === $pick ); // phpcs:ignore ?></div>
			<?php endif; ?>
			<p><button class="uh-btn uh-btn-primary"><?php esc_html_e( 'Create account', 'ukraine-health' ); ?></button></p>
			<p class="uh-help"><?php esc_html_e( 'We will email you a link to set your password.', 'ukraine-health' ); ?></p>
		</form>
		<?php
		return $out . ob_get_clean() . '</div>';
	}

	/** The Supporter code of conduct with its required checkbox. */
	public static function code_of_conduct( $hidden = false ) {
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) UH_Install::settings()['code_of_conduct'] ) ) );
		$out   = '<fieldset class="uh-coc"' . ( $hidden ? ' hidden' : '' ) . '><legend>' . esc_html__( 'Supporter code of conduct', 'ukraine-health' ) . '</legend><ul>';
		foreach ( $lines as $l ) {
			$out .= '<li>' . esc_html( $l ) . '</li>';
		}
		return $out . '</ul><label class="uh-check"><input type="checkbox" name="coc" value="1"' . ( $hidden ? '' : ' required' ) . '> <strong>' . esc_html__( 'I have read the code of conduct and I agree to follow it.', 'ukraine-health' ) . '</strong></label></fieldset>';
	}

	/* =====================================================================
	 * [uh_invite]
	 * ===================================================================== */

	public function sc_invite() {
		$this->enqueue();
		$out = '<div class="uh uh-invite">' . UH_I18n::switcher() . $this->notice();
		if ( ! is_user_logged_in() ) {
			return $out . '<p>' . sprintf( wp_kses_post( __( 'Please <a href="%s">log in</a> to invite someone.', 'ukraine-health' ) ), esc_url( wp_login_url( get_permalink() ) ) ) . '</p></div>';
		}
		return $out . $this->invite_panel( get_current_user_id() ) . '</div>';
	}

	/** Create invites, see who you invited and cancel unused invites. */
	private function invite_panel( $uid ) {
		$roles = UH_Network::roles_for( $uid );
		if ( ! $roles ) {
			return '<div class="uh-notice uh-err" role="alert">' . esc_html__( 'Your account is not connected to the network yet, so you cannot invite others. Please contact the coordinator.', 'ukraine-health' ) . '</div>';
		}
		$manager = UH_Network::is_root( $uid );
		$labels  = UH_Network::roles();
		$new     = absint( $_GET['uh_new'] ?? 0 );
		$out     = '';

		if ( $new ) {
			$inv = UH_Network::invite( $new );
			if ( $inv && (int) $inv->inviter_id === $uid ) {
				$link = UH_Network::invite_url( $inv );
				$out .= '<div class="uh-card uh-card-ready"><strong>' . esc_html__( 'Your invite link', 'ukraine-health' ) . '</strong>';
				$out .= '<p class="uh-help">' . esc_html__( 'Send it only to the person you know – by message or in person. Anyone with the link can use it.', 'ukraine-health' ) . '</p>';
				$out .= '<input type="text" class="uh-copy" readonly value="' . esc_attr( $link ) . '" onclick="this.select()" aria-label="' . esc_attr__( 'Invite link', 'ukraine-health' ) . '">';
				$out .= '<div class="uh-meta">' . esc_html( sprintf( __( 'Code: %1$s · for: %2$s · valid until %3$s', 'ukraine-health' ), $inv->code, $labels[ $inv->role ] ?? $inv->role, self::fmt_date( $inv->expires_at ) ) ) . '</div></div>';
			}
		}

		ob_start();
		?>
		<p class="uh-help"><?php esc_html_e( 'Everyone in Ukraine Health is invited by someone who knows them. You are responsible for the people you invite, so invite only people you know and trust.', 'ukraine-health' ); ?></p>
		<form method="post" class="uh-form uh-panel-form">
			<?php echo $this->hidden( 'invite_create' ); // phpcs:ignore ?>
			<div class="uh-row">
				<label class="uh-field"><?php esc_html_e( 'Invite as', 'ukraine-health' ); ?>
					<select name="role">
					<?php foreach ( $roles as $r ) : ?>
						<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $labels[ $r ] ); ?></option>
					<?php endforeach; ?>
					</select>
				</label>
				<label class="uh-field"><?php esc_html_e( 'Who is it for? (only you and coordinators see this)', 'ukraine-health' ); ?>
					<input type="text" name="note" maxlength="128" required placeholder="<?php esc_attr_e( 'e.g. Olena, barber from Rīga', 'ukraine-health' ); ?>">
				</label>
			</div>
			<?php if ( $manager ) : ?>
			<div class="uh-row">
				<label class="uh-field uh-small"><?php esc_html_e( 'Uses', 'ukraine-health' ); ?> <input type="number" name="max_uses" min="1" max="<?php echo (int) UH_Network::MANAGER_MAX_USES; ?>" value="1"></label>
				<label class="uh-field uh-small"><?php esc_html_e( 'Valid days', 'ukraine-health' ); ?> <input type="number" name="days" min="1" max="<?php echo (int) UH_Network::MANAGER_MAX_DAYS; ?>" value="<?php echo (int) UH_Network::MEMBER_DAYS; ?>"></label>
			</div>
			<?php endif; ?>
			<p><button class="uh-btn uh-btn-primary"><?php esc_html_e( 'Create invite link', 'ukraine-health' ); ?></button></p>
		</form>
		<?php
		$out .= ob_get_clean();

		$people = UH_Network::invitees( $uid );
		$out   .= '<h3>' . esc_html__( 'People you invited', 'ukraine-health' ) . '</h3>';
		if ( ! $people ) {
			$out .= '<p class="uh-help">' . esc_html__( 'Nobody yet.', 'ukraine-health' ) . '</p>';
		} else {
			$out .= '<ul class="uh-members">';
			foreach ( $people as $p ) {
				$out .= '<li><strong>' . esc_html( UH_Privacy::nickname( $p ) ) . '</strong> · ' . esc_html( mysql2date( get_option( 'date_format' ), $p->user_registered ) ) . ( UH_Network::is_suspended( $p->ID ) ? ' · <em>' . esc_html__( 'suspended', 'ukraine-health' ) . '</em>' : '' ) . '</li>';
			}
			$out .= '</ul>';
		}

		$invites = UH_Network::user_invites( $uid, 30 );
		if ( $invites ) {
			$now  = strtotime( current_time( 'mysql' ) );
			$out .= '<h3>' . esc_html__( 'Your invites', 'ukraine-health' ) . '</h3>';
			foreach ( $invites as $i ) {
				$open = ! $i->revoked && $i->uses < $i->max_uses && strtotime( $i->expires_at ) > $now;
				$out .= '<div class="uh-card"><div class="uh-card-head"><strong>' . esc_html( $i->note ?: '—' ) . '</strong> <span class="uh-badge">' . esc_html( $open ? __( 'open', 'ukraine-health' ) : ( $i->revoked ? __( 'cancelled', 'ukraine-health' ) : ( $i->uses >= $i->max_uses ? __( 'used', 'ukraine-health' ) : __( 'expired', 'ukraine-health' ) ) ) ) . '</span></div>';
				$out .= '<div class="uh-meta">' . esc_html( sprintf( __( 'For: %1$s · used %2$d of %3$d · until %4$s', 'ukraine-health' ), $labels[ $i->role ] ?? $i->role, $i->uses, $i->max_uses, self::fmt_date( $i->expires_at ) ) ) . '</div>';
				if ( $open ) {
					$out .= '<input type="text" class="uh-copy" readonly value="' . esc_attr( UH_Network::invite_url( $i ) ) . '" onclick="this.select()" aria-label="' . esc_attr__( 'Invite link', 'ukraine-health' ) . '">';
					$out .= '<form method="post" class="uh-inline">' . $this->hidden( 'invite_revoke' ) . '<input type="hidden" name="invite_id" value="' . (int) $i->id . '"><button class="uh-btn uh-btn-link">' . esc_html__( 'Cancel invite', 'ukraine-health' ) . '</button></form>';
				}
				$out .= '</div>';
			}
		}
		return $out;
	}

	/* =====================================================================
	 * [uh_stats]
	 * ===================================================================== */

	public function sc_stats() {
		$this->enqueue();
		$s     = UH_Repo::stats();
		$items = array(
			'helped'         => __( 'Heroes helped', 'ukraine-health' ),
			'heroes_waiting' => __( 'Waiting now', 'ukraine-health' ),
			'scheduled'      => __( 'Visits scheduled', 'ukraine-health' ),
			'supporters'     => __( 'Supporters', 'ukraine-health' ),
		);
		$out = '<div class="uh uh-stats">';
		foreach ( $items as $k => $label ) {
			$out .= '<div class="uh-stat"><span class="uh-stat-n">' . (int) $s[ $k ] . '</span><span class="uh-stat-l">' . esc_html( $label ) . '</span></div>';
		}
		return $out . '</div>';
	}
}
