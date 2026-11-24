<?php
/**
 * Shortcodes and front-end form handling.
 *
 * [uh_request_form]  Heroes ask for help (guests allowed if enabled)
 * [uh_my_requests]   Heroes see / withdraw their requests (login or private link)
 * [uh_supporter]     Supporter profile + groups to accept + scheduled work
 * [uh_register]      Sign up as Hero, Supporter or both
 * [uh_stats]         Public impact counters
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
		}
	}

	private function back( $args ) {
		$url = remove_query_arg( array( 'uh_ok', 'uh_err', 'uh_n' ), wp_get_referer() ?: home_url( '/' ) );
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

		$uid  = get_current_user_id();
		$data = array(
			'user_id'   => $uid,
			'name'      => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'phone'     => $phone,
			'email'     => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'messenger' => sanitize_text_field( wp_unslash( $_POST['messenger'] ?? '' ) ),
			'ward'      => sanitize_text_field( wp_unslash( $_POST['ward'] ?? '' ) ),
			'notes'     => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'sizes'     => array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['sizes'] ?? array() ) ) ),
		);

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
		$u = wp_get_current_user();
		if ( ! empty( $_POST['is_supporter'] ) ) {
			$u->add_role( UH_Install::ROLE_SUPPORTER );
		} elseif ( count( $u->roles ) > 1 ) {
			$u->remove_role( UH_Install::ROLE_SUPPORTER );
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
		if ( ! get_option( 'users_can_register' ) ) {
			$this->back( array( 'uh_err' => 'closed' ) );
		}
		if ( ! empty( $_POST['uh_website'] ) || $this->rate_limited() ) {
			$this->back( array( 'uh_err' => 'rate' ) );
		}
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$role  = sanitize_key( $_POST['role'] ?? '' );
		if ( ! is_email( $email ) || ! in_array( $role, array( 'hero', 'supporter', 'both' ), true ) ) {
			$this->back( array( 'uh_err' => 'missing' ) );
		}
		if ( email_exists( $email ) ) {
			$this->back( array( 'uh_err' => 'exists' ) );
		}
		$login = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = $login ?: 'user';
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		$uid = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'display_name' => $name ?: $login,
				'first_name'   => $name,
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
		update_user_meta( $uid, 'uh_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
		wp_new_user_notification( $uid, null, 'user' ); // Sends "set your password" link.
		$this->back( array( 'uh_ok' => 'registered' ) );
	}

	/* =====================================================================
	 * Helpers
	 * ===================================================================== */

	public static function is_supporter() {
		return is_user_logged_in() && ( current_user_can( UH_Install::CAP_MANAGE ) || in_array( UH_Install::ROLE_SUPPORTER, wp_get_current_user()->roles, true ) );
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
		$out = '<div class="uh uh-request">' . $this->notice();

		if ( isset( $_GET['uh_ok'] ) && ! empty( $_GET['uh_token'] ) ) {
			$link = add_query_arg( 'uh_token', sanitize_text_field( wp_unslash( $_GET['uh_token'] ) ), UH_Notify::page_url( 'status' ) );
			$out .= '<p class="uh-card">' . esc_html__( 'Save this private link to check or cancel your request:', 'ukraine-health' ) . '<br><a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>';
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
						<span class="uh-tile-name"><?php echo esc_html( $s->name ); ?></span>
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
				<label class="uh-field"><?php esc_html_e( 'Name', 'ukraine-health' ); ?>
					<input type="text" name="name" maxlength="128" value="<?php echo esc_attr( $user ? $user->display_name : '' ); ?>">
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
			<label class="uh-field"><?php esc_html_e( 'Notes (mobility, preferences, anything we should know)', 'ukraine-health' ); ?>
				<textarea name="notes" rows="3" maxlength="1000"></textarea>
			</label>
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
		$out   = '<div class="uh uh-mine">' . $this->notice();
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

		if ( ! $rows ) {
			return $out . '<p>' . esc_html__( 'No requests yet.', 'ukraine-health' ) . ' <a href="' . esc_url( UH_Notify::page_url( 'request' ) ) . '">' . esc_html__( 'Ask for help', 'ukraine-health' ) . '</a></p></div>';
		}

		$out .= '<div class="uh-list">';
		foreach ( $rows as $r ) {
			$status = 'withdrawn' === $r->status ? 'cancelled' : $r->group_status;
			$out   .= '<div class="uh-card"><div class="uh-card-head"><strong>' . esc_html( $r->service_name ) . '</strong> ' . self::badge( $status ) . '</div>';
			$out   .= '<div class="uh-meta">' . esc_html( $r->hospital_name ) . '</div>';
			if ( in_array( $r->group_status, array( 'open', 'ready' ), true ) && 'withdrawn' !== $r->status ) {
				$pct  = min( 100, round( 100 * $r->members / max( 1, $r->min_group ) ) );
				$out .= '<div class="uh-progress" role="progressbar" aria-valuenow="' . (int) $r->members . '" aria-valuemax="' . (int) $r->min_group . '"><span style="width:' . (int) $pct . '%"></span></div>';
				$out .= '<div class="uh-meta">' . esc_html( sprintf( __( '%1$d of %2$d people signed up', 'ukraine-health' ), $r->members, $r->min_group ) ) . '</div>';
			}
			if ( 'assigned' === $r->group_status && 'withdrawn' !== $r->status ) {
				$out .= '<div class="uh-when">📅 ' . esc_html( self::fmt_date( $r->appointment ) ) . '</div>';
			}
			if ( in_array( $r->group_status, array( 'open', 'ready', 'assigned' ), true ) && 'withdrawn' !== $r->status ) {
				$out .= '<form method="post" class="uh-inline">' . $this->hidden( 'withdraw' ) . '<input type="hidden" name="request_id" value="' . (int) $r->id . '"><input type="hidden" name="token" value="' . esc_attr( $token ? $r->token : '' ) . '"><button class="uh-btn uh-btn-link" onclick="return confirm(\'' . esc_js( __( 'Cancel this request?', 'ukraine-health' ) ) . '\')">' . esc_html__( 'Cancel request', 'ukraine-health' ) . '</button></form>';
			}
			$out .= '</div>';
		}
		return $out . '</div></div>';
	}

	/* =====================================================================
	 * [uh_supporter]
	 * ===================================================================== */

	public function sc_supporter() {
		$this->enqueue();
		$out = '<div class="uh uh-supporter">' . $this->notice();
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
		foreach ( array( 'groups' => __( 'Groups to help', 'ukraine-health' ), 'mine' => __( 'My schedule', 'ukraine-health' ), 'profile' => __( 'My profile', 'ukraine-health' ) ) as $k => $label ) {
			$out .= '<a class="' . ( $tab === $k ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'uh_tab', $k, $base ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= '</nav>';

		if ( 'profile' === $tab || ! $is_sup ) {
			ob_start();
			?>
			<form method="post" class="uh-form">
				<?php echo $this->hidden( 'profile' ); // phpcs:ignore ?>
				<label class="uh-check"><input type="checkbox" name="is_supporter" value="1" <?php checked( $is_sup ); ?>> <strong><?php esc_html_e( 'I want to help as a Supporter', 'ukraine-health' ); ?></strong></label>
				<fieldset><legend><?php esc_html_e( 'What can you offer?', 'ukraine-health' ); ?></legend><div class="uh-tiles">
				<?php foreach ( UH_Repo::services() as $s ) : ?>
					<label class="uh-tile"><input type="checkbox" name="services[]" value="<?php echo (int) $s->id; ?>" <?php checked( in_array( (int) $s->id, $my_svc, true ) ); ?>> <span class="uh-tile-name"><?php echo esc_html( $s->name ); ?></span><span class="uh-tile-min"><?php echo esc_html( $cats[ $s->category ] ?? '' ); ?></span></label>
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
				$out .= '<div class="uh-card"><div class="uh-card-head"><strong>' . esc_html( $g->service_name ) . '</strong> ' . self::badge( $g->status ) . '</div>';
				$out .= '<div class="uh-meta">' . esc_html( $g->hospital_name . ', ' . $g->city ) . ' · 📅 ' . esc_html( self::fmt_date( $g->appointment ) ) . '</div>';
				$out .= '<ol class="uh-members">';
				foreach ( UH_Repo::group_members( $g->id ) as $m ) {
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
			$out .= '<div class="uh-card ' . ( 'ready' === $g->status ? 'uh-card-ready' : '' ) . '"><div class="uh-card-head"><strong>' . esc_html( $g->service_name ) . '</strong> ' . self::badge( $g->status ) . '</div>';
			$out .= '<div class="uh-meta">' . esc_html( $g->hospital_name . ', ' . $g->city ) . '</div>';
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
		$out  = '<div class="uh uh-register">' . $this->notice();
		if ( is_user_logged_in() ) {
			return $out . '<p>' . esc_html__( 'You are logged in.', 'ukraine-health' ) . ' <a href="' . esc_url( UH_Notify::page_url( 'supporter' ) ) . '">' . esc_html__( 'Supporter area', 'ukraine-health' ) . '</a> · <a href="' . esc_url( UH_Notify::page_url( 'status' ) ) . '">' . esc_html__( 'My requests', 'ukraine-health' ) . '</a></p></div>';
		}
		if ( ! get_option( 'users_can_register' ) ) {
			return $out . '<p>' . esc_html__( 'Registration is currently closed.', 'ukraine-health' ) . '</p></div>';
		}
		ob_start();
		?>
		<form method="post" class="uh-form">
			<?php echo $this->hidden( 'register' ); // phpcs:ignore ?>
			<p class="uh-hp" aria-hidden="true"><label>Website <input type="text" name="uh_website" tabindex="-1" autocomplete="off"></label></p>
			<fieldset><legend><?php esc_html_e( 'I am joining as', 'ukraine-health' ); ?></legend>
				<div class="uh-tiles">
				<?php foreach ( array( 'hero' => __( 'Hero – I need help', 'ukraine-health' ), 'supporter' => __( 'Supporter – I can help', 'ukraine-health' ), 'both' => __( 'Both', 'ukraine-health' ) ) as $k => $label ) : ?>
					<label class="uh-tile"><input type="radio" name="role" value="<?php echo esc_attr( $k ); ?>" <?php checked( $atts['role'], $k ); ?> required> <span class="uh-tile-name"><?php echo esc_html( $label ); ?></span></label>
				<?php endforeach; ?>
				</div>
			</fieldset>
			<div class="uh-row">
				<label class="uh-field"><?php esc_html_e( 'Name', 'ukraine-health' ); ?> * <input type="text" name="name" required maxlength="128"></label>
				<label class="uh-field"><?php esc_html_e( 'Email', 'ukraine-health' ); ?> * <input type="email" name="email" required maxlength="191"></label>
				<label class="uh-field"><?php esc_html_e( 'Phone', 'ukraine-health' ); ?> <input type="tel" name="phone" maxlength="20"></label>
			</div>
			<p><button class="uh-btn uh-btn-primary"><?php esc_html_e( 'Create account', 'ukraine-health' ); ?></button></p>
			<p class="uh-help"><?php esc_html_e( 'We will email you a link to set your password.', 'ukraine-health' ); ?></p>
		</form>
		<?php
		return $out . ob_get_clean() . '</div>';
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
