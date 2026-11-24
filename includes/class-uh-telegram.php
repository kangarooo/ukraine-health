<?php
/**
 * Telegram: one-tap linking through the bot, so Heroes without email (and
 * Supporters) get updates without looking up a chat ID.
 *
 * Setup: create a bot with @BotFather, paste its token in Ukraine Health →
 * Settings and press "Connect bot". That registers this site's webhook with a
 * secret that Telegram sends back on every update.
 *
 * Links: https://t.me/<bot>?start=r<code> (a request) or ?start=u<code> (an account).
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Telegram {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function ready() {
		$s = UH_Install::settings();
		return $s['telegram_token'] && $s['telegram_bot'] && $s['telegram_secret'];
	}

	/* ---------------------------------------------------------------------
	 * Links
	 * ------------------------------------------------------------------- */

	/** Link that connects this request (and the other services chosen in the same submission). */
	public static function request_link( $request ) {
		global $wpdb;
		if ( ! self::ready() || ! $request || $request->wiped_at ) {
			return '';
		}
		$code = $request->tg_code;
		if ( ! $code ) {
			$code = strtolower( wp_generate_password( 24, false, false ) );
			$wpdb->update( UH_Repo::t( 'requests' ), array( 'tg_code' => $code ), array( 'id' => $request->id ) );
		}
		return 'https://t.me/' . rawurlencode( UH_Install::settings()['telegram_bot'] ) . '?start=r' . $code;
	}

	public static function user_link( $user_id ) {
		if ( ! self::ready() || ! $user_id ) {
			return '';
		}
		$code = get_user_meta( $user_id, 'uh_tg_link', true );
		if ( ! $code ) {
			$code = strtolower( wp_generate_password( 24, false, false ) );
			update_user_meta( $user_id, 'uh_tg_link', $code );
		}
		return 'https://t.me/' . rawurlencode( UH_Install::settings()['telegram_bot'] ) . '?start=u' . $code;
	}

	/* ---------------------------------------------------------------------
	 * Setup
	 * ------------------------------------------------------------------- */

	/** Look up the bot's name and register the webhook. */
	public static function connect() {
		$s = UH_Install::settings();
		if ( ! $s['telegram_token'] ) {
			return new WP_Error( 'tg_token', __( 'Enter the bot token first.', 'ukraine-health' ) );
		}
		$base = 'https://api.telegram.org/bot' . $s['telegram_token'] . '/';
		$me   = wp_remote_get( $base . 'getMe', array( 'timeout' => 10 ) );
		$body = is_wp_error( $me ) ? null : json_decode( wp_remote_retrieve_body( $me ), true );
		if ( empty( $body['ok'] ) || empty( $body['result']['username'] ) ) {
			return new WP_Error( 'tg_token', __( 'Telegram did not accept the bot token.', 'ukraine-health' ) );
		}
		$secret = wp_generate_password( 40, false, false );
		$hook   = wp_remote_post(
			$base . 'setWebhook',
			array(
				'timeout' => 10,
				'body'    => array(
					'url'             => rest_url( 'ukraine-health/v1/telegram' ),
					'secret_token'    => $secret,
					'allowed_updates' => wp_json_encode( array( 'message' ) ),
				),
			)
		);
		$hb     = is_wp_error( $hook ) ? null : json_decode( wp_remote_retrieve_body( $hook ), true );
		if ( empty( $hb['ok'] ) ) {
			return new WP_Error( 'tg_hook', __( 'Telegram could not reach this site.', 'ukraine-health' ) );
		}
		$s['telegram_bot']    = sanitize_text_field( $body['result']['username'] );
		$s['telegram_secret'] = $secret;
		update_option( 'uh_settings', $s );
		return $s['telegram_bot'];
	}

	/* ---------------------------------------------------------------------
	 * Webhook
	 * ------------------------------------------------------------------- */

	public static function routes() {
		register_rest_route(
			'ukraine-health/v1',
			'/telegram',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
	}

	/** Only Telegram knows the secret it was given in setWebhook. */
	public static function verify( WP_REST_Request $req ) {
		$secret = (string) UH_Install::settings()['telegram_secret'];
		$sent   = (string) $req->get_header( 'x_telegram_bot_api_secret_token' );
		return '' !== $secret && '' !== $sent && hash_equals( $secret, $sent );
	}

	public static function webhook( WP_REST_Request $req ) {
		global $wpdb;
		$u    = $req->get_json_params();
		$chat = isset( $u['message']['chat']['id'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $u['message']['chat']['id'] ) : '';
		$text = isset( $u['message']['text'] ) ? trim( (string) $u['message']['text'] ) : '';
		if ( ! $chat || ! $text ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		if ( preg_match( '/^\/start\s+r([a-z0-9]{24})$/', $text, $m ) ) {
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . UH_Repo::t( 'requests' ) . ' WHERE tg_code=%s AND wiped_at IS NULL', $m[1] ) );
			if ( $r ) {
				// Also connect the other services chosen in the same submission (same phone, same minute).
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . UH_Repo::t( 'requests' ) . " SET tg_chat=%s WHERE wiped_at IS NULL AND ( id=%d OR ( user_id IS NULL AND phone=%s AND phone<>'' AND created_at BETWEEN %s AND %s ) )",
						$chat,
						$r->id,
						$r->phone,
						gmdate( 'Y-m-d H:i:s', strtotime( $r->created_at ) - 60 ),
						gmdate( 'Y-m-d H:i:s', strtotime( $r->created_at ) + 60 )
					)
				);
				UH_Repo::log( 'telegram_linked', $r->group_id, $r->id );
				UH_Notify::telegram( $chat, __( 'Connected. You will get updates about your request here. Send /stop to stop.', 'ukraine-health' ) );
			} else {
				UH_Notify::telegram( $chat, __( 'This link is no longer valid.', 'ukraine-health' ) );
			}
		} elseif ( preg_match( '/^\/start\s+u([a-z0-9]{24})$/', $text, $m ) ) {
			$users = get_users( array( 'meta_key' => 'uh_tg_link', 'meta_value' => $m[1], 'number' => 1 ) ); // phpcs:ignore
			if ( $users ) {
				update_user_meta( $users[0]->ID, 'uh_telegram', $chat );
				UH_Notify::telegram( $chat, __( 'Connected. You will get Ukraine Health notifications here. Send /stop to stop.', 'ukraine-health' ) );
			} else {
				UH_Notify::telegram( $chat, __( 'This link is no longer valid.', 'ukraine-health' ) );
			}
		} elseif ( '/stop' === $text ) {
			$wpdb->update( UH_Repo::t( 'requests' ), array( 'tg_chat' => '' ), array( 'tg_chat' => $chat ) );
			foreach ( get_users( array( 'meta_key' => 'uh_telegram', 'meta_value' => $chat ) ) as $usr ) { // phpcs:ignore
				delete_user_meta( $usr->ID, 'uh_telegram' );
			}
			UH_Notify::telegram( $chat, __( 'Stopped. You will not get messages here any more.', 'ukraine-health' ) );
		} else {
			UH_Notify::telegram( $chat, __( 'Hello! Open your Ukraine Health page and tap “Get updates on Telegram” to connect.', 'ukraine-health' ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
