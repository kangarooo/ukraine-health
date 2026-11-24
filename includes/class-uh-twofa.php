<?php
/**
 * Two-step login for administrators and coordinators: after the password, a
 * 6-digit code is emailed. A device can be trusted for 30 days.
 *
 * Off by default (Ukraine Health → Settings). Send yourself a test code first:
 * if email from the site does not arrive you would be locked out.
 * Emergency switch: define( 'UH_DISABLE_2FA', true ); in wp-config.php.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_TwoFA {

	const COOKIE   = 'uh_2fa_trust';
	const TTL      = 600;  // Code lifetime, seconds.
	const ATTEMPTS = 5;
	const TRUST    = 30;   // Days a trusted device skips the code.

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_login', array( __CLASS__, 'after_password' ), 1, 2 );
		add_action( 'login_form_uh_2fa', array( __CLASS__, 'screen' ) );
	}

	public static function enabled() {
		return ! ( defined( 'UH_DISABLE_2FA' ) && UH_DISABLE_2FA ) && ! empty( UH_Install::settings()['twofa'] );
	}

	public static function applies_to( $user ) {
		return $user instanceof WP_User && user_can( $user, UH_Install::CAP_MANAGE );
	}

	/* ---------------------------------------------------------------------
	 * Trusted devices
	 * ------------------------------------------------------------------- */

	private static function user_secret( $user_id ) {
		$s = get_user_meta( $user_id, 'uh_2fa_secret', true );
		if ( ! $s ) {
			$s = wp_generate_password( 32, false, false );
			update_user_meta( $user_id, 'uh_2fa_secret', $s );
		}
		return $s;
	}

	private static function sign( $user_id, $exp ) {
		return hash_hmac( 'sha256', $user_id . '|' . $exp, wp_salt( 'auth' ) . self::user_secret( $user_id ) );
	}

	private static function trusted( $user_id ) {
		$c = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$p = explode( '|', $c );
		return 3 === count( $p ) && (int) $p[0] === (int) $user_id && (int) $p[1] > time() && hash_equals( self::sign( (int) $p[0], (int) $p[1] ), $p[2] );
	}

	private static function trust( $user_id ) {
		$exp = time() + self::TRUST * DAY_IN_SECONDS;
		setcookie( self::COOKIE, $user_id . '|' . $exp . '|' . self::sign( $user_id, $exp ), $exp, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	/** Forget every trusted device of a user (e.g. after a lost laptop). */
	public static function forget_devices( $user_id ) {
		delete_user_meta( $user_id, 'uh_2fa_secret' );
	}

	/* ---------------------------------------------------------------------
	 * Codes
	 * ------------------------------------------------------------------- */

	/** Email a code; returns the pending-login key. */
	public static function send_code( $user, $remember = false, $redirect = '' ) {
		$code = (string) wp_rand( 100000, 999999 );
		$key  = wp_generate_password( 32, false, false );
		set_transient(
			'uh_2fa_' . $key,
			array(
				'user'     => (int) $user->ID,
				'hash'     => wp_hash_password( $code ),
				'tries'    => 0,
				'remember' => (bool) $remember,
				'redirect' => $redirect,
			),
			self::TTL
		);
		wp_mail(
			$user->user_email,
			'[Ukraine Health] ' . __( 'Your login code', 'ukraine-health' ),
			sprintf(
				/* translators: 1: code, 2: minutes */
				__( "Your login code is %1\$s\nIt works for %2\$d minutes.\n\nIf you did not try to log in, change your password now.", 'ukraine-health' ),
				$code,
				self::TTL / 60
			)
		);
		return $key;
	}

	public static function send_test( $user ) {
		return wp_mail( $user->user_email, '[Ukraine Health] ' . __( 'Test: login code email', 'ukraine-health' ), __( 'This is a test. When two-step login is on, your login codes will arrive like this email. If you received it, it is safe to switch two-step login on.', 'ukraine-health' ) );
	}

	/** Runs right after a correct password: swap the session for a code screen. */
	public static function after_password( $login, $user ) {
		if ( ! self::applies_to( $user ) || self::trusted( $user->ID ) ) {
			return;
		}
		wp_clear_auth_cookie();
		$remember = ! empty( $_POST['rememberme'] ); // phpcs:ignore
		$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_REQUEST['redirect_to'] ), admin_url() ) : admin_url(); // phpcs:ignore
		$key      = self::send_code( $user, $remember, $redirect );
		wp_safe_redirect( add_query_arg( array( 'action' => 'uh_2fa', 'uh_k' => $key ), wp_login_url() ) );
		exit;
	}

	public static function screen() {
		$key  = isset( $_REQUEST['uh_k'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_REQUEST['uh_k'] ) ) : ''; // phpcs:ignore
		$data = $key ? get_transient( 'uh_2fa_' . $key ) : false;
		$err  = '';
		if ( ! $data ) {
			wp_safe_redirect( add_query_arg( 'uh_2fa', 'expired', wp_login_url() ) );
			exit;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'uh_2fa_' . $key );
			$code = preg_replace( '/\D/', '', wp_unslash( $_POST['uh_code'] ?? '' ) ); // phpcs:ignore
			$data['tries']++;
			if ( $data['tries'] > self::ATTEMPTS ) {
				delete_transient( 'uh_2fa_' . $key );
				wp_safe_redirect( add_query_arg( 'uh_2fa', 'locked', wp_login_url() ) );
				exit;
			}
			if ( $code && wp_check_password( $code, $data['hash'] ) ) {
				delete_transient( 'uh_2fa_' . $key );
				wp_set_auth_cookie( $data['user'], $data['remember'] );
				if ( ! empty( $_POST['uh_trust'] ) ) { // phpcs:ignore
					self::trust( $data['user'] );
				}
				UH_Repo::log( 'login_2fa', null, null, array( 'user' => (int) $data['user'] ) );
				wp_safe_redirect( $data['redirect'] ?: admin_url() );
				exit;
			}
			set_transient( 'uh_2fa_' . $key, $data, self::TTL );
			$err = __( 'That code is not right. Please try again.', 'ukraine-health' );
		}
		login_header( __( 'Login code', 'ukraine-health' ), '', $err ? new WP_Error( 'uh_2fa', $err ) : null );
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( array( 'action' => 'uh_2fa', 'uh_k' => $key ), wp_login_url() ) ); ?>">
			<?php wp_nonce_field( 'uh_2fa_' . $key ); ?>
			<p class="message"><?php esc_html_e( 'We emailed you a 6-digit code. Enter it to finish logging in.', 'ukraine-health' ); ?></p>
			<p><label for="uh_code"><?php esc_html_e( 'Code', 'ukraine-health' ); ?></label>
			<input type="text" name="uh_code" id="uh_code" class="input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus></p>
			<p class="forgetmenot"><label><input type="checkbox" name="uh_trust" value="1"> <?php echo esc_html( sprintf( __( 'Trust this device for %d days', 'ukraine-health' ), self::TRUST ) ); ?></label></p>
			<p class="submit"><button class="button button-primary button-large"><?php esc_html_e( 'Log in', 'ukraine-health' ); ?></button></p>
		</form>
		<?php
		login_footer();
		exit;
	}
}
