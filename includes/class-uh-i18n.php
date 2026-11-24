<?php
/**
 * Language for the plugin's pages: English, Ukrainian, Latvian.
 *
 * Order: the visitor's choice (?uh_lang=…, remembered in a cookie), then a
 * logged-in member's profile language, then the browser's language.
 * Only the plugin's own texts change; the rest of the site keeps its language.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_I18n {

	const COOKIE = 'uh_lang';

	/** @var string|null */
	private static $locale = null;

	public static function languages() {
		return array(
			'en_US' => 'English',
			'uk'    => 'Українська',
			'lv'    => 'Latviešu',
		);
	}

	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return; // wp-admin follows each user's profile language.
		}
		$pick = isset( $_GET['uh_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['uh_lang'] ) ) : ''; // phpcs:ignore
		if ( $pick && isset( self::languages()[ $pick ] ) && ! headers_sent() ) {
			setcookie( self::COOKIE, $pick, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::COOKIE ] = $pick;
		}
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_locale' ), 10, 2 );
		// WordPress 6.7+ loads translations "just in time" in the site language and resets them
		// during init, so load ours explicitly once init has run.
		add_action( 'init', array( __CLASS__, 'load' ), 99 );
	}

	public static function load() {
		$loc = self::current();
		if ( 'en_US' === $loc ) {
			return;
		}
		unload_textdomain( 'ukraine-health', true );
		load_textdomain( 'ukraine-health', UH_DIR . 'languages/ukraine-health-' . $loc . '.mo', $loc );
	}

	public static function filter_locale( $locale, $domain ) {
		return 'ukraine-health' === $domain ? self::current() : $locale;
	}

	public static function current() {
		if ( null !== self::$locale ) {
			return self::$locale;
		}
		$langs = self::languages();
		$c     = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( isset( $langs[ $c ] ) ) {
			return self::$locale = $c;
		}
		if ( is_user_logged_in() ) {
			$ul = get_user_meta( get_current_user_id(), 'locale', true );
			if ( isset( $langs[ $ul ] ) ) {
				return self::$locale = $ul;
			}
		}
		$accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) );
		foreach ( explode( ',', $accept ) as $part ) {
			$code = substr( trim( $part ), 0, 2 );
			if ( 'uk' === $code || 'ru' === $code ) {
				return self::$locale = 'uk'; // Many Ukrainians use Russian-language phones.
			}
			if ( 'lv' === $code ) {
				return self::$locale = 'lv';
			}
			if ( 'en' === $code ) {
				break;
			}
		}
		return self::$locale = 'en_US';
	}

	/** Small language links shown at the top of every plugin page. */
	public static function switcher() {
		$cur = self::current();
		$out = '<nav class="uh-lang" aria-label="' . esc_attr__( 'Language', 'ukraine-health' ) . '">';
		foreach ( self::languages() as $code => $label ) {
			$out .= $code === $cur
				? '<span aria-current="true">' . esc_html( $label ) . '</span>'
				: '<a href="' . esc_url( add_query_arg( 'uh_lang', $code ) ) . '" hreflang="' . esc_attr( substr( $code, 0, 2 ) ) . '" lang="' . esc_attr( substr( $code, 0, 2 ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $out . '</nav>';
	}
}
