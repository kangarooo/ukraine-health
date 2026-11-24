<?php
/**
 * Admin screens: Dashboard, Groups, Network, Hospitals, Services, Settings.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Admin {

	const NONCE = 'uh_admin';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( $hook ) {
		if ( false !== strpos( $hook, 'ukraine-health' ) ) {
			wp_enqueue_style( 'uh-admin', UH_URL . 'assets/uh-admin.css', array(), UH_VERSION );
		}
		if ( false !== strpos( $hook, 'ukraine-health-hospitals' ) ) {
			wp_enqueue_script( 'uh-admin-maps', UH_URL . 'assets/uh-admin-maps.js', array(), UH_VERSION, true );
			wp_localize_script(
				'uh-admin-maps',
				'UHMaps',
				array(
					'locale'    => substr( get_user_locale(), 0, 2 ),
					'searching' => __( 'Searching OpenStreetMap…', 'ukraine-health' ),
					'noMatch'   => __( 'No place found. Try another spelling, add the city, or type the details yourself.', 'ukraine-health' ),
					'failed'    => __( 'OpenStreetMap could not be reached. Please try again in a moment.', 'ukraine-health' ),
					'pick'      => __( 'Use this', 'ukraine-health' ),
					'hospital'  => __( 'hospital', 'ukraine-health' ),
					'picked'    => __( 'Filled in from OpenStreetMap. Check the details before you save.', 'ukraine-health' ),
				)
			);
		}
	}

	public function menu() {
		$cap = UH_Install::CAP_MANAGE;
		add_menu_page( 'Ukraine Health', 'Ukraine Health', $cap, 'ukraine-health', array( $this, 'page_dashboard' ), 'dashicons-heart', 26 );
		add_submenu_page( 'ukraine-health', __( 'Dashboard', 'ukraine-health' ), __( 'Dashboard', 'ukraine-health' ), $cap, 'ukraine-health', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'ukraine-health', __( 'Groups', 'ukraine-health' ), __( 'Groups', 'ukraine-health' ), $cap, 'ukraine-health-groups', array( $this, 'page_groups' ) );
		add_submenu_page( 'ukraine-health', __( 'Network', 'ukraine-health' ), __( 'Network', 'ukraine-health' ), $cap, 'ukraine-health-network', array( $this, 'page_network' ) );
		add_submenu_page( 'ukraine-health', __( 'Hospitals', 'ukraine-health' ), __( 'Hospitals', 'ukraine-health' ), $cap, 'ukraine-health-hospitals', array( $this, 'page_hospitals' ) );
		add_submenu_page( 'ukraine-health', __( 'Services', 'ukraine-health' ), __( 'Services', 'ukraine-health' ), $cap, 'ukraine-health-services', array( $this, 'page_services' ) );
		add_submenu_page( 'ukraine-health', __( 'Settings', 'ukraine-health' ), __( 'Settings', 'ukraine-health' ), 'manage_options', 'ukraine-health-settings', array( $this, 'page_settings' ) );
	}

	private function field( $action ) {
		return wp_nonce_field( self::NONCE, '_uha', true, false ) . '<input type="hidden" name="uh_admin_action" value="' . esc_attr( $action ) . '">';
	}

	private function redirect( $page, $msg = 'saved', $extra = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'uh_msg' => $msg ), $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function msg() {
		$m = sanitize_key( $_GET['uh_msg'] ?? '' );
		if ( $m ) {
			$text = array(
				'saved'   => __( 'Saved.', 'ukraine-health' ),
				'deleted' => __( 'Deleted.', 'ukraine-health' ),
				'pages'   => __( 'Pages created. You can find them under Pages.', 'ukraine-health' ),
				'inuse'   => __( 'Cannot delete: it is used by groups. Deactivate it instead.', 'ukraine-health' ),
				'error'   => __( 'Could not save.', 'ukraine-health' ),
				'loop'    => __( 'Not changed: that would put the member under someone they invited.', 'ukraine-health' ),
				'tg_ok'   => __( 'Telegram bot connected.', 'ukraine-health' ),
				'tg_fail' => __( 'Could not connect the Telegram bot. Check the token and that the site is reachable over HTTPS.', 'ukraine-health' ),
				'twofa_sent' => __( 'Test email sent. Check your inbox (and spam folder) before you switch two-step login on.', 'ukraine-health' ),
			);
			echo '<div class="notice notice-' . ( in_array( $m, array( 'inuse', 'error', 'loop', 'tg_fail' ), true ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $text[ $m ] ?? $m ) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Actions
	 * ===================================================================== */

	public function handle() {
		// The export holds everyone's contact details: administrators only.
		if ( isset( $_GET['uh_export'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'uh_export' ) ) {
			$this->export_csv();
		}
		if ( empty( $_POST['uh_admin_action'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE, '_uha' );
		if ( ! current_user_can( UH_Install::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ukraine-health' ) );
		}
		global $wpdb;
		$a = sanitize_key( $_POST['uh_admin_action'] );
		$p = wp_unslash( $_POST );

		switch ( $a ) {
			case 'hospital_save':
				$row = array(
					'name'    => sanitize_text_field( $p['name'] ?? '' ),
					'country' => sanitize_text_field( $p['country'] ?? '' ),
					'city'    => sanitize_text_field( $p['city'] ?? '' ),
					'address' => sanitize_text_field( $p['address'] ?? '' ),
					'contact' => sanitize_text_field( $p['contact'] ?? '' ),
					'contact_email'  => sanitize_email( $p['contact_email'] ?? '' ),
					'visiting_hours' => sanitize_text_field( $p['visiting_hours'] ?? '' ),
					'notify_contact' => empty( $p['notify_contact'] ) ? 0 : 1,
					'place_id'       => preg_replace( '#[^a-z0-9:/]#', '', (string) ( $p['place_id'] ?? '' ) ), // e.g. "osm:way/123".
					'maps_url'       => esc_url_raw( $p['maps_url'] ?? '' ),
					'lat'            => is_numeric( $p['lat'] ?? '' ) ? max( -90, min( 90, (float) $p['lat'] ) ) : null,
					'lng'            => is_numeric( $p['lng'] ?? '' ) ? max( -180, min( 180, (float) $p['lng'] ) ) : null,
					'active'  => empty( $p['active'] ) ? 0 : 1,
				);
				if ( '' === $row['name'] ) {
					$this->redirect( 'ukraine-health-hospitals', 'error' );
				}
				$id = absint( $p['id'] ?? 0 );
				$id ? $wpdb->update( UH_Repo::t( 'hospitals' ), $row, array( 'id' => $id ) ) : $wpdb->insert( UH_Repo::t( 'hospitals' ), $row );
				$this->redirect( 'ukraine-health-hospitals' );
				break;

			case 'hospital_delete':
				$id = absint( $p['id'] ?? 0 );
				if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . UH_Repo::t( 'groups' ) . ' WHERE hospital_id=%d', $id ) ) ) {
					$this->redirect( 'ukraine-health-hospitals', 'inuse' );
				}
				$wpdb->delete( UH_Repo::t( 'hospitals' ), array( 'id' => $id ) );
				$this->redirect( 'ukraine-health-hospitals', 'deleted' );
				break;

			case 'service_save':
				$row = array(
					'name'         => sanitize_text_field( $p['name'] ?? '' ),
					'category'     => sanitize_key( $p['category'] ?? 'other' ),
					'description'  => sanitize_textarea_field( $p['description'] ?? '' ),
					'min_group'    => max( 1, absint( $p['min_group'] ?? 1 ) ),
					'needs_sizes'  => empty( $p['needs_sizes'] ) ? 0 : 1,
					'chat_url'     => esc_url_raw( $p['chat_url'] ?? '' ),
					'minutes_each' => max( 5, absint( $p['minutes_each'] ?? 30 ) ),
					'sort'         => (int) ( $p['sort'] ?? 0 ),
					'active'       => empty( $p['active'] ) ? 0 : 1,
				);
				if ( '' === $row['name'] ) {
					$this->redirect( 'ukraine-health-services', 'error' );
				}
				$id = absint( $p['id'] ?? 0 );
				$id ? $wpdb->update( UH_Repo::t( 'services' ), $row, array( 'id' => $id ) ) : $wpdb->insert( UH_Repo::t( 'services' ), $row );
				// A lower minimum may make waiting groups ready.
				if ( $id ) {
					foreach ( UH_Repo::groups( array( 'status' => 'open', 'service_ids' => array( $id ) ) ) as $g ) {
						UH_Matching::recalc( $g->id );
					}
				}
				$this->redirect( 'ukraine-health-services' );
				break;

			case 'service_delete':
				$id = absint( $p['id'] ?? 0 );
				if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . UH_Repo::t( 'groups' ) . ' WHERE service_id=%d', $id ) ) ) {
					$this->redirect( 'ukraine-health-services', 'inuse' );
				}
				$wpdb->delete( UH_Repo::t( 'services' ), array( 'id' => $id ) );
				$this->redirect( 'ukraine-health-services', 'deleted' );
				break;

			case 'group_update':
				$gid    = absint( $p['group_id'] ?? 0 );
				$sup    = absint( $p['supporter_id'] ?? 0 );
				$appt   = sanitize_text_field( $p['appointment'] ?? '' );
				$status = sanitize_key( $p['status'] ?? '' );
				$group  = UH_Repo::group( $gid );
				if ( $group && $sup && $appt && in_array( $group->status, array( 'open', 'ready' ), true ) ) {
					UH_Matching::accept( $gid, $sup, $appt, sanitize_textarea_field( $p['note'] ?? '' ) );
				} elseif ( $group && 'completed' === $status && 'assigned' === $group->status ) {
					UH_Matching::complete( $gid, get_current_user_id() );
				} elseif ( $group && 'open' === $status && 'assigned' === $group->status ) {
					UH_Matching::release( $gid, get_current_user_id() );
				} elseif ( $group && $status && $status !== $group->status ) {
					UH_Matching::set_status( $gid, $status );
				}
				$this->redirect( 'ukraine-health-groups', 'saved', array( 'view' => $gid ) );
				break;

			case 'remove_member':
				$req = UH_Repo::request( absint( $p['request_id'] ?? 0 ) );
				if ( $req ) {
					UH_Matching::withdraw( $req );
				}
				$this->redirect( 'ukraine-health-groups', 'saved', array( 'view' => $req ? $req->group_id : 0 ) );
				break;

			case 'net_suspend':
			case 'net_suspend_branch':
				$uid = absint( $p['user_id'] ?? 0 );
				if ( $uid && $uid !== get_current_user_id() && ! user_can( $uid, 'manage_options' ) ) {
					UH_Network::suspend( $uid, 'net_suspend_branch' === $a );
				}
				$this->redirect( 'ukraine-health-network' );
				break;

			case 'net_unsuspend':
				UH_Network::unsuspend( absint( $p['user_id'] ?? 0 ) );
				$this->redirect( 'ukraine-health-network' );
				break;

			case 'net_reattach':
				$ok = UH_Network::reattach( absint( $p['user_id'] ?? 0 ), absint( $p['inviter_id'] ?? 0 ) );
				$this->redirect( 'ukraine-health-network', $ok ? 'saved' : 'loop' );
				break;

			case 'net_allow_supporter':
				UH_Network::allow_supporter( absint( $p['user_id'] ?? 0 ) );
				$this->redirect( 'ukraine-health-network' );
				break;

			case 'net_revoke':
				UH_Network::revoke( absint( $p['invite_id'] ?? 0 ), get_current_user_id() );
				$this->redirect( 'ukraine-health-network', 'saved', array( 'tab' => 'invites' ) );
				break;

			case 'net_invite':
				$res = UH_Network::create_invite( get_current_user_id(), sanitize_key( $p['role'] ?? 'supporter' ), $p['note'] ?? '', absint( $p['max_uses'] ?? 1 ), absint( $p['days'] ?? 14 ) );
				$this->redirect( 'ukraine-health-network', is_wp_error( $res ) ? 'error' : 'saved', array( 'tab' => 'invites', 'new' => is_wp_error( $res ) ? 0 : (int) $res->id ) );
				break;

			case 'settings':
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die();
				}
				$s = UH_Install::settings();
				foreach ( array( 'telegram_token', 'telegram_admin', 'twilio_sid', 'twilio_token', 'twilio_from', 'meta_token', 'meta_phone_id' ) as $k ) {
					$s[ $k ] = sanitize_text_field( $p[ $k ] ?? '' );
				}
				$s['wa_gateway']     = in_array( $p['wa_gateway'] ?? '', array( 'disabled', 'twilio', 'meta' ), true ) ? $p['wa_gateway'] : 'disabled';
				$s['admin_email']    = sanitize_email( $p['admin_email'] ?? '' );
				$s['notify_admin']   = empty( $p['notify_admin'] ) ? 0 : 1;
				$s['allow_guests']   = empty( $p['allow_guests'] ) ? 0 : 1;
				$s['invite_guests']  = empty( $p['invite_guests'] ) ? 0 : 1;
				$s['rate_limit']     = absint( $p['rate_limit'] ?? 5 );
				$s['messengers']     = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $p['messengers'] ?? '' ) ) ) ) );
				$s['privacy_notice'] = sanitize_textarea_field( $p['privacy_notice'] ?? '' );
				$s['contact_hours']      = min( 720, absint( $p['contact_hours'] ?? 48 ) );
				$s['retention_days']     = min( 3650, absint( $p['retention_days'] ?? 30 ) );
				$s['escalate_days']      = min( 60, absint( $p['escalate_days'] ?? 3 ) );
				$s['approve_supporters'] = empty( $p['approve_supporters'] ) ? 0 : 1;
				$s['code_of_conduct']    = sanitize_textarea_field( $p['code_of_conduct'] ?? '' );
				$s['twofa']              = empty( $p['twofa'] ) ? 0 : 1;
				if ( $s['telegram_token'] !== UH_Install::settings()['telegram_token'] ) {
					$s['telegram_bot']    = '';
					$s['telegram_secret'] = '';
				}
				update_option( 'uh_settings', $s );
				$this->redirect( 'ukraine-health-settings' );
				break;

			case 'telegram_connect':
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die();
				}
				$r = UH_Telegram::connect();
				$this->redirect( 'ukraine-health-settings', is_wp_error( $r ) ? 'tg_fail' : 'tg_ok' );
				break;

			case 'twofa_test':
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die();
				}
				$this->redirect( 'ukraine-health-settings', UH_TwoFA::send_test( wp_get_current_user() ) ? 'twofa_sent' : 'error' );
				break;

			case 'net_approve':
				UH_Network::approve( absint( $p['user_id'] ?? 0 ) );
				$this->redirect( 'ukraine-health-network', 'saved', array( 'tab' => 'review' ) );
				break;

			case 'report_resolve':
				UH_Reports::resolve( absint( $p['report_id'] ?? 0 ), 'suspend' === ( $p['outcome'] ?? '' ) ? 'suspended' : 'cleared', sanitize_textarea_field( $p['note'] ?? '' ) );
				$this->redirect( 'ukraine-health-network', 'saved', array( 'tab' => 'reports' ) );
				break;

			case 'create_pages':
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die();
				}
				self::create_pages();
				$this->redirect( 'ukraine-health-settings', 'pages' );
				break;
		}
	}

	/** Creates the front-end pages once (skips any that already exist). */
	public static function create_pages() {
		$pages = (array) get_option( 'uh_pages', array() );
		$defs  = array(
			'request'   => array( __( 'Request help', 'ukraine-health' ), 'request-help', '[uh_request_form]' ),
			'status'    => array( __( 'My requests', 'ukraine-health' ), 'my-requests', '[uh_my_requests]' ),
			'supporter' => array( __( 'Supporters', 'ukraine-health' ), 'supporters', '[uh_supporter]' ),
			'register'  => array( __( 'Join', 'ukraine-health' ), 'join', '[uh_register]' ),
		);
		foreach ( $defs as $key => $d ) {
			if ( ! empty( $pages[ $key ] ) && get_post( $pages[ $key ] ) && 'trash' !== get_post_status( $pages[ $key ] ) ) {
				continue;
			}
			$pages[ $key ] = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $d[0],
					'post_name'    => $d[1],
					'post_content' => '<!-- wp:shortcode -->' . $d[2] . '<!-- /wp:shortcode -->',
				)
			);
		}
		update_option( 'uh_pages', $pages );
		return $pages;
	}

	private function export_csv() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT r.id, r.created_at, s.name AS service, h.name AS hospital, r.name AS nickname, r.real_name, r.ward, r.phone, r.messenger, r.email, r.sizes, r.notes, r.status AS request_status, g.id AS group_id, g.status AS group_status, g.appointment, u.display_name AS supporter
			FROM ' . UH_Repo::t( 'requests' ) . ' r JOIN ' . UH_Repo::t( 'groups' ) . ' g ON g.id=r.group_id
			JOIN ' . UH_Repo::t( 'services' ) . ' s ON s.id=g.service_id JOIN ' . UH_Repo::t( 'hospitals' ) . ' h ON h.id=g.hospital_id
			LEFT JOIN ' . $wpdb->users . ' u ON u.ID=g.supporter_id WHERE r.wiped_at IS NULL ORDER BY r.id DESC',
			ARRAY_A
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ukraine-health-' . gmdate( 'Y-m-d' ) . '.csv' );
		$fh = fopen( 'php://output', 'w' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		if ( $rows ) {
			fputcsv( $fh, array_keys( $rows[0] ) );
			foreach ( $rows as $r ) {
				// Neutralise spreadsheet formulas.
				fputcsv(
					$fh,
					array_map(
						function ( $v ) {
							return is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
						},
						$r
					)
				);
			}
		}
		fclose( $fh );
		exit;
	}

	/* =====================================================================
	 * Pages
	 * ===================================================================== */

	private static function badge( $status ) {
		$all = UH_Install::group_statuses();
		return '<span class="uh-badge uh-' . esc_attr( $status ) . '">' . esc_html( $all[ $status ] ?? $status ) . '</span>';
	}

	public function page_dashboard() {
		$s = UH_Repo::stats();
		echo '<div class="wrap uh-admin"><h1>Ukraine Health</h1>';
		$this->msg();
		$open_reports = UH_Reports::open_count();
		$pending      = count( UH_Network::pending_supporters() );
		if ( $open_reports ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html( sprintf( _n( '%d open problem report', '%d open problem reports', $open_reports, 'ukraine-health' ), $open_reports ) ) . '</strong> — <a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-network&tab=reports' ) ) . '">' . esc_html__( 'Review now', 'ukraine-health' ) . '</a></p></div>';
		}
		if ( $pending ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( _n( '%d Supporter is waiting for approval', '%d Supporters are waiting for approval', $pending, 'ukraine-health' ), $pending ) ) . ' — <a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-network&tab=review' ) ) . '">' . esc_html__( 'Review', 'ukraine-health' ) . '</a></p></div>';
		}
		echo '<div class="uh-kpis">';
		foreach ( array(
			'heroes_waiting' => __( 'Heroes waiting', 'ukraine-health' ),
			'groups_ready'   => __( 'Groups ready', 'ukraine-health' ),
			'scheduled'      => __( 'Scheduled', 'ukraine-health' ),
			'helped'         => __( 'Heroes helped', 'ukraine-health' ),
			'supporters'     => __( 'Supporters', 'ukraine-health' ),
		) as $k => $l ) {
			echo '<div class="uh-kpi' . ( 'groups_ready' === $k && $s[ $k ] ? ' is-hot' : '' ) . '"><b>' . (int) $s[ $k ] . '</b><span>' . esc_html( $l ) . '</span></div>';
		}
		echo '</div>';

		$ready = UH_Repo::groups( array( 'status' => 'ready', 'limit' => 20 ) );
		echo '<h2>' . esc_html__( 'Ready groups without a Supporter', 'ukraine-health' ) . '</h2>';
		if ( ! $ready ) {
			echo '<p>' . esc_html__( 'None – well done!', 'ukraine-health' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Service', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Hospital', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Heroes', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Matching Supporters', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $ready as $g ) {
				$m = count( UH_Repo::matching_supporters( $g->service_id, $g->hospital_id ) );
				echo '<tr><td>' . esc_html( $g->service_name ) . '</td><td>' . esc_html( $g->hospital_name ) . '</td><td>' . (int) $g->members . '/' . (int) $g->min_group . '</td><td>' . ( $m ? (int) $m : '<strong style="color:#b32d2e">0</strong>' ) . '</td><td><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-groups&view=' . (int) $g->id ) ) . '">' . esc_html__( 'Open', 'ukraine-health' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}

		$pages = (array) get_option( 'uh_pages', array() );
		if ( ! $pages ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Next step: create the public pages under Ukraine Health → Settings.', 'ukraine-health' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Recent activity', 'ukraine-health' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( UH_Repo::recent_log( 25 ) as $l ) {
			$who = $l->actor_id ? get_userdata( $l->actor_id ) : null;
			echo '<tr><td>' . esc_html( $l->created_at ) . '</td><td>' . esc_html( $l->action ) . '</td><td>' . ( $l->group_id ? '<a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-groups&view=' . (int) $l->group_id ) ) . '">#' . (int) $l->group_id . '</a>' : '' ) . '</td><td>' . esc_html( $who ? $who->display_name : __( 'guest', 'ukraine-health' ) ) . '</td><td>' . esc_html( $l->detail ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public function page_groups() {
		echo '<div class="wrap uh-admin">';
		$this->msg();
		$view = absint( $_GET['view'] ?? 0 );
		if ( $view && ( $g = UH_Repo::group( $view ) ) ) {
			$this->group_detail( $g );
			echo '</div>';
			return;
		}
		$status = sanitize_key( $_GET['status'] ?? '' );
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Groups', 'ukraine-health' ) . '</h1>' . ( current_user_can( 'manage_options' ) ? ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=ukraine-health-groups&uh_export=1' ), 'uh_export' ) ) . '">' . esc_html__( 'Export CSV', 'ukraine-health' ) . '</a>' : '' );
		echo '<ul class="subsubsub">';
		$links = array( '' => __( 'All', 'ukraine-health' ) ) + UH_Install::group_statuses();
		$i     = 0;
		foreach ( $links as $k => $l ) {
			echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a class="' . ( $status === $k ? 'current' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-groups' . ( $k ? '&status=' . $k : '' ) ) ) . '">' . esc_html( $l ) . '</a></li>';
		}
		echo '</ul><table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Service', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Hospital', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Heroes', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Status', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Supporter', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Appointment', 'ukraine-health' ) . '</th></tr></thead><tbody>';
		$groups = UH_Repo::groups( array( 'status' => $status ) );
		if ( ! $groups ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No groups yet.', 'ukraine-health' ) . '</td></tr>';
		}
		foreach ( $groups as $g ) {
			$sup = $g->supporter_id ? get_userdata( $g->supporter_id ) : null;
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-groups&view=' . (int) $g->id ) ) . '">#' . (int) $g->id . '</a></td><td>' . esc_html( $g->service_name ) . '</td><td>' . esc_html( $g->hospital_name ) . '</td><td>' . (int) $g->members . '/' . (int) $g->min_group . '</td><td>' . self::badge( $g->status ) . '</td><td>' . esc_html( $sup ? $sup->display_name : '—' ) . '</td><td>' . esc_html( $g->appointment ?: '—' ) . '</td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table></div>';
	}

	private function group_detail( $g ) {
		$s   = UH_Repo::service( $g->service_id );
		$h   = UH_Repo::hospital( $g->hospital_id );
		$sup = $g->supporter_id ? get_userdata( $g->supporter_id ) : null;
		echo '<h1>' . esc_html( sprintf( '#%d %s — %s', $g->id, $s->name, $h->name ) ) . ' ' . self::badge( $g->status ) . '</h1>'; // phpcs:ignore
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-groups' ) ) . '">← ' . esc_html__( 'All groups', 'ukraine-health' ) . '</a></p>';
		echo '<p>' . esc_html( sprintf( __( 'Supporter: %1$s · Appointment: %2$s', 'ukraine-health' ), $sup ? UH_Privacy::nickname( $sup ) . ( $sup->first_name ? ' (' . trim( $sup->first_name . ' ' . $sup->last_name ) . ')' : '' ) . ' <' . $sup->user_email . '>' : '—', $g->appointment ?: '—' ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Ward', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Phone', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Sizes', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Notes', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Joined', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( UH_Repo::group_members( $g->id ) as $m ) {
			echo '<tr><td>' . esc_html( $m->name ?: '—' ) . ( $m->real_name ? '<br><small>' . esc_html( $m->real_name ) . '</small>' : '' ) . ( $m->user_id ? ' <span class="dashicons dashicons-admin-users" title="registered"></span>' : '' ) . ( $m->wiped_at ? ' <em>' . esc_html__( 'details deleted', 'ukraine-health' ) . '</em>' : '' ) . '</td><td>' . esc_html( $m->ward ) . '</td><td><a href="tel:' . esc_attr( $m->phone ) . '">' . esc_html( $m->phone ) . '</a> ' . esc_html( $m->messenger ) . ( $m->email ? '<br>' . esc_html( $m->email ) : '' ) . '</td><td>' . esc_html( $m->sizes ? UH_Notify::sizes_text( json_decode( $m->sizes, true ) ) : '' ) . '</td><td>' . esc_html( $m->notes ) . '</td><td>' . esc_html( $m->created_at ) . '</td><td>';
			if ( ! in_array( $g->status, array( 'completed', 'cancelled' ), true ) ) {
				echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Remove this Hero from the group?', 'ukraine-health' ) ) . '\')">' . $this->field( 'remove_member' ) . '<input type="hidden" name="request_id" value="' . (int) $m->id . '"><button class="button-link-delete">' . esc_html__( 'Remove', 'ukraine-health' ) . '</button></form>'; // phpcs:ignore
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Update', 'ukraine-health' ) . '</h2><form method="post" class="uh-admin-form">' . $this->field( 'group_update' ) . '<input type="hidden" name="group_id" value="' . (int) $g->id . '">'; // phpcs:ignore
		if ( in_array( $g->status, array( 'open', 'ready' ), true ) ) {
			$cands = UH_Repo::matching_supporters( $g->service_id, $g->hospital_id );
			$all   = array_filter( get_users( array( 'role__in' => array( UH_Install::ROLE_SUPPORTER ) ) ), function ( $u ) {
				return UH_Network::can_support( $u->ID );
			} );
			echo '<p><label>' . esc_html__( 'Assign Supporter', 'ukraine-health' ) . ' <select name="supporter_id"><option value="">—</option>';
			foreach ( $all as $u ) {
				$match = in_array( $u->ID, wp_list_pluck( $cands, 'ID' ), true );
				echo '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name . ( $match ? ' ★' : '' ) ) . '</option>';
			}
			echo '</select></label> <label>' . esc_html__( 'Appointment', 'ukraine-health' ) . ' <input type="datetime-local" name="appointment"></label> <label>' . esc_html__( 'Note', 'ukraine-health' ) . ' <input type="text" name="note"></label></p><p class="description">' . esc_html__( '★ = offers this service at this hospital.', 'ukraine-health' ) . '</p>';
		}
		echo '<p><label>' . esc_html__( 'Or change status', 'ukraine-health' ) . ' <select name="status"><option value="">—</option>';
		foreach ( UH_Install::group_statuses() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		submit_button( __( 'Save', 'ukraine-health' ) );
		echo '</form>';
	}

	public function page_hospitals() {
		$edit = absint( $_GET['edit'] ?? 0 );
		$e    = $edit ? UH_Repo::hospital( $edit ) : null;
		echo '<div class="wrap uh-admin"><h1>' . esc_html__( 'Hospitals', 'ukraine-health' ) . '</h1>';
		$this->msg();
		echo '<div class="uh-cols"><div><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'ukraine-health' ) . '</th><th>' . esc_html__( 'City', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Country', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Active', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( UH_Repo::hospitals( false ) as $h ) {
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-hospitals&edit=' . (int) $h->id ) ) . '">' . esc_html( $h->name ) . '</a><br><small>' . esc_html( $h->address ) . ' · <a href="' . esc_url( UH_Repo::maps_url( $h ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'ukraine-health' ) . '</a>' . ( $h->place_id ? ' ✓' : '' ) . '</small></td><td>' . esc_html( $h->city ) . '</td><td>' . esc_html( $h->country ) . '</td><td>' . ( $h->active ? '✓' : '—' ) . '</td><td><form method="post" onsubmit="return confirm(\'Delete?\')">' . $this->field( 'hospital_delete' ) . '<input type="hidden" name="id" value="' . (int) $h->id . '"><button class="button-link-delete">' . esc_html__( 'Delete', 'ukraine-health' ) . '</button></form></td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table></div><div class="uh-panel"><h2>' . ( $e ? esc_html__( 'Edit hospital', 'ukraine-health' ) : esc_html__( 'Add hospital', 'ukraine-health' ) ) . '</h2>';
		if ( $e ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-hospitals' ) ) . '">+ ' . esc_html__( 'Add a new hospital instead', 'ukraine-health' ) . '</a></p>';
		}
		echo '<form method="post" id="uh-hospital-form">' . $this->field( 'hospital_save' ) . '<input type="hidden" name="id" value="' . (int) ( $e->id ?? 0 ) . '">'; // phpcs:ignore
		echo '<div class="uh-finder"><p><label><strong>' . esc_html__( 'Find the hospital on OpenStreetMap', 'ukraine-health' ) . '</strong><br><input type="search" class="regular-text" id="uh-maps-q" placeholder="' . esc_attr__( 'e.g. Stradiņa slimnīca Rīga', 'ukraine-health' ) . '" value="' . esc_attr( $e ? trim( $e->name . ' ' . $e->city ) : '' ) . '" autocomplete="off"></label> <button type="button" class="button" id="uh-maps-search">' . esc_html__( 'Search', 'ukraine-health' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Pick the right place from the results: its official name, address, city and country are filled in and it is pinned on the map. No account or key needed.', 'ukraine-health' ) . '</p>';
		echo '<p class="uh-maps-msg" aria-live="polite"></p><ul class="uh-maps-results" id="uh-maps-results"></ul><div class="uh-maps-frame" id="uh-maps-frame"' . ( $e && null !== $e->lat ? ' data-lat="' . esc_attr( $e->lat ) . '" data-lng="' . esc_attr( $e->lng ) . '"' : '' ) . '></div>';
		echo '<p><a id="uh-maps-open" href="' . esc_url( $e ? UH_Repo::maps_url( $e ) : 'https://www.openstreetmap.org/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in OpenStreetMap', 'ukraine-health' ) . ' ↗</a> <span class="uh-maps-attrib">© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></span></p></div>';
		foreach ( array( 'place_id', 'maps_url', 'lat', 'lng' ) as $k ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $e->$k ?? '' ) . '">';
		}
		foreach ( array( 'name' => __( 'Name', 'ukraine-health' ), 'country' => __( 'Country', 'ukraine-health' ), 'city' => __( 'City', 'ukraine-health' ), 'address' => __( 'Address', 'ukraine-health' ), 'visiting_hours' => __( 'Visiting hours (shown to Heroes and Supporters)', 'ukraine-health' ), 'contact' => __( 'Contact person / phone', 'ukraine-health' ), 'contact_email' => __( 'Contact person email', 'ukraine-health' ) ) as $k => $l ) {
			echo '<p><label>' . esc_html( $l ) . '<br><input class="regular-text" type="text" name="' . esc_attr( $k ) . '" value="' . esc_attr( $e->$k ?? ( 'country' === $k ? 'Latvia' : '' ) ) . '" ' . ( 'name' === $k ? 'required' : '' ) . '></label></p>';
		}
		echo '<p><label><input type="checkbox" name="notify_contact" value="1" ' . checked( (int) ( $e->notify_contact ?? 0 ), 1, false ) . '> ' . esc_html__( 'Email the contact person when a visit is planned (service, time, number of patients, wards and the Supporter’s nickname – no patient names)', 'ukraine-health' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="active" value="1" ' . checked( $e ? (int) $e->active : 1, 1, false ) . '> ' . esc_html__( 'Active', 'ukraine-health' ) . '</label></p>';
		submit_button( $e ? __( 'Update', 'ukraine-health' ) : __( 'Add', 'ukraine-health' ) );
		echo '</form></div></div></div>';
	}

	public function page_services() {
		$edit = absint( $_GET['edit'] ?? 0 );
		$e    = $edit ? UH_Repo::service( $edit ) : null;
		$cats = UH_Install::categories();
		echo '<div class="wrap uh-admin"><h1>' . esc_html__( 'Services', 'ukraine-health' ) . '</h1>';
		$this->msg();
		echo '<div class="uh-cols"><div><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Service', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Category', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Min. group', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Sizes', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Active', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( UH_Repo::services( false ) as $s ) {
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-services&edit=' . (int) $s->id ) ) . '">' . esc_html( $s->name ) . '</a></td><td>' . esc_html( $cats[ $s->category ] ?? $s->category ) . '</td><td>' . (int) $s->min_group . '</td><td>' . ( $s->needs_sizes ? '✓' : '' ) . '</td><td>' . ( $s->active ? '✓' : '—' ) . '</td><td><form method="post" onsubmit="return confirm(\'Delete?\')">' . $this->field( 'service_delete' ) . '<input type="hidden" name="id" value="' . (int) $s->id . '"><button class="button-link-delete">' . esc_html__( 'Delete', 'ukraine-health' ) . '</button></form></td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table></div><div class="uh-panel"><h2>' . ( $e ? esc_html__( 'Edit service', 'ukraine-health' ) : esc_html__( 'Add service', 'ukraine-health' ) ) . '</h2><form method="post">' . $this->field( 'service_save' ) . '<input type="hidden" name="id" value="' . (int) ( $e->id ?? 0 ) . '">'; // phpcs:ignore
		echo '<p><label>' . esc_html__( 'Name', 'ukraine-health' ) . '<br><input class="regular-text" name="name" required value="' . esc_attr( $e->name ?? '' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Category', 'ukraine-health' ) . '<br><select name="category">';
		foreach ( $cats as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $e->category ?? '', $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Minimum Heroes before Supporters are notified', 'ukraine-health' ) . '<br><input type="number" min="1" name="min_group" value="' . (int) ( $e->min_group ?? 1 ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Minutes per Hero (for calendar length)', 'ukraine-health' ) . '<br><input type="number" min="5" name="minutes_each" value="' . (int) ( $e->minutes_each ?? 30 ) . '"></label></p>';
		echo '<p><label><input type="checkbox" name="needs_sizes" value="1" ' . checked( (int) ( $e->needs_sizes ?? 0 ), 1, false ) . '> ' . esc_html__( 'Ask for clothing / shoe sizes', 'ukraine-health' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Group chat link (optional)', 'ukraine-health' ) . '<br><input class="regular-text" type="url" name="chat_url" value="' . esc_attr( $e->chat_url ?? '' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Description', 'ukraine-health' ) . '<br><textarea class="large-text" name="description" rows="2">' . esc_textarea( $e->description ?? '' ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Sort order', 'ukraine-health' ) . '<br><input type="number" name="sort" value="' . (int) ( $e->sort ?? 0 ) . '"></label></p>';
		echo '<p><label><input type="checkbox" name="active" value="1" ' . checked( $e ? (int) $e->active : 1, 1, false ) . '> ' . esc_html__( 'Active', 'ukraine-health' ) . '</label></p>';
		submit_button( $e ? __( 'Update', 'ukraine-health' ) : __( 'Add', 'ukraine-health' ) );
		echo '</form></div></div></div>';
	}

	public function page_settings() {
		$s = UH_Install::settings();
		echo '<div class="wrap uh-admin"><h1>' . esc_html__( 'Ukraine Health settings', 'ukraine-health' ) . '</h1>';
		$this->msg();

		$pages = (array) get_option( 'uh_pages', array() );
		echo '<div class="uh-panel"><h2>' . esc_html__( 'Public pages', 'ukraine-health' ) . '</h2>';
		if ( $pages ) {
			echo '<ul>';
			foreach ( $pages as $k => $id ) {
				if ( get_post( $id ) ) {
					echo '<li>' . esc_html( get_the_title( $id ) ) . ' — <a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_permalink( $id ) ) . '</a></li>';
				}
			}
			echo '</ul>';
		}
		echo '<form method="post">' . $this->field( 'create_pages' ); // phpcs:ignore
		submit_button( $pages ? __( 'Re-create missing pages', 'ukraine-health' ) : __( 'Create pages', 'ukraine-health' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Sign-up is invite-only: people join through an invite link from a member (see Ukraine Health → Network). Leave WordPress’s “Anyone can register” switched off.', 'ukraine-health' ) . '</p>';
		if ( get_option( 'users_can_register' ) ) {
			echo '<p class="description" style="color:#b32d2e">' . wp_kses_post( sprintf( __( '“Anyone can register” is on in <a href="%s">Settings → General</a>. Accounts made through the WordPress sign-up form are not connected to anyone and get no access until a coordinator reviews them, but it is safer to switch it off.', 'ukraine-health' ), esc_url( admin_url( 'options-general.php' ) ) ) ) . '</p>';
		}
		echo '</div>';

		echo '<form method="post" class="uh-panel">' . $this->field( 'settings' ); // phpcs:ignore
		echo '<h2>' . esc_html__( 'Requests', 'ukraine-health' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Guests', 'ukraine-health' ) . '</th><td><label><input type="checkbox" name="allow_guests" value="1" ' . checked( $s['allow_guests'], 1, false ) . '> ' . esc_html__( 'Heroes can request help without an account (phone required)', 'ukraine-health' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Invite-only requests', 'ukraine-health' ) . '</th><td><label><input type="checkbox" name="invite_guests" value="1" ' . checked( $s['invite_guests'], 1, false ) . '> ' . esc_html__( 'Heroes without a connected account need an invite code to request help (recommended)', 'ukraine-health' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Rate limit', 'ukraine-health' ) . '</th><td><input type="number" min="0" name="rate_limit" value="' . (int) $s['rate_limit'] . '"> ' . esc_html__( 'guest submissions per hour per device (0 = off)', 'ukraine-health' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Messengers', 'ukraine-health' ) . '</th><td><input class="regular-text" name="messengers" value="' . esc_attr( implode( ', ', $s['messengers'] ) ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Consent text', 'ukraine-health' ) . '</th><td><textarea class="large-text" rows="2" name="privacy_notice">' . esc_textarea( $s['privacy_notice'] ) . '</textarea></td></tr>';
		echo '</table><h2>' . esc_html__( 'Privacy and safety', 'ukraine-health' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Contact details for Supporters', 'ukraine-health' ) . '</th><td><input type="number" min="0" max="720" name="contact_hours" value="' . (int) $s['contact_hours'] . '"> ' . esc_html__( 'hours before the visit the Supporter sees the Heroes’ wards and phone numbers (0 = as soon as they accept)', 'ukraine-health' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Delete contact details', 'ukraine-health' ) . '</th><td><input type="number" min="0" max="3650" name="retention_days" value="' . (int) $s['retention_days'] . '"> ' . esc_html__( 'days after a visit is completed or cancelled, or a request withdrawn (0 = never). Only anonymous counts are kept.', 'ukraine-health' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Waiting groups', 'ukraine-health' ) . '</th><td><input type="number" min="0" max="60" name="escalate_days" value="' . (int) $s['escalate_days'] . '"> ' . esc_html__( 'days a ready group may wait before coordinators are alerted (0 = off)', 'ukraine-health' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Approve Supporters', 'ukraine-health' ) . '</th><td><label><input type="checkbox" name="approve_supporters" value="1" ' . checked( $s['approve_supporters'], 1, false ) . '> ' . esc_html__( 'A coordinator approves every new Supporter before they see any Hero (Supporters you invite yourself are approved automatically)', 'ukraine-health' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Code of conduct', 'ukraine-health' ) . '</th><td><textarea class="large-text" rows="7" name="code_of_conduct">' . esc_textarea( $s['code_of_conduct'] ) . '</textarea><p class="description">' . esc_html__( 'One rule per line. Supporters must accept it before they can help.', 'ukraine-health' ) . '</p></td></tr>';
		$locked = defined( 'UH_DISABLE_2FA' ) && UH_DISABLE_2FA;
		echo '<tr><th>' . esc_html__( 'Two-step login', 'ukraine-health' ) . '</th><td><label><input type="checkbox" name="twofa" value="1" ' . checked( $s['twofa'], 1, false ) . ( $locked ? ' disabled' : '' ) . '> ' . esc_html__( 'Administrators and coordinators enter a code sent by email after their password', 'ukraine-health' ) . '</label><p class="description">' . esc_html__( 'Send yourself a test email first (button below). If login codes do not arrive you would be locked out. Emergency switch: add define( \'UH_DISABLE_2FA\', true ); to wp-config.php.', 'ukraine-health' ) . ( $locked ? ' <strong>' . esc_html__( 'Currently switched off in wp-config.php.', 'ukraine-health' ) . '</strong>' : '' ) . '</p></td></tr>';
		echo '</table><h2>' . esc_html__( 'Coordinator notifications', 'ukraine-health' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Email', 'ukraine-health' ) . '</th><td><input class="regular-text" type="email" name="admin_email" value="' . esc_attr( $s['admin_email'] ) . '"> <label><input type="checkbox" name="notify_admin" value="1" ' . checked( $s['notify_admin'], 1, false ) . '> ' . esc_html__( 'notify on every request', 'ukraine-health' ) . '</label></td></tr>';
		echo '<tr><th>Telegram</th><td><p><label>' . esc_html__( 'Bot token', 'ukraine-health' ) . '<br><input class="regular-text" name="telegram_token" value="' . esc_attr( $s['telegram_token'] ) . '" autocomplete="off"></label></p><p><label>' . esc_html__( 'Coordinator chat / group ID', 'ukraine-health' ) . '<br><input class="regular-text" name="telegram_admin" value="' . esc_attr( $s['telegram_admin'] ) . '"></label></p><p class="description">' . ( UH_Telegram::ready() ? esc_html( sprintf( __( 'Connected as @%s. Heroes and Supporters can link their chat in one tap.', 'ukraine-health' ), $s['telegram_bot'] ) ) : esc_html__( 'Create a bot with @BotFather in Telegram, paste its token here, save, then press “Connect Telegram bot” below.', 'ukraine-health' ) ) . '</p></td></tr>';
		echo '<tr><th>WhatsApp</th><td><select name="wa_gateway">';
		foreach ( array( 'disabled' => __( 'Disabled', 'ukraine-health' ), 'twilio' => 'Twilio', 'meta' => 'Meta Cloud API' ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['wa_gateway'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><p>Twilio SID <input name="twilio_sid" value="' . esc_attr( $s['twilio_sid'] ) . '"> Token <input type="password" name="twilio_token" value="' . esc_attr( $s['twilio_token'] ) . '" autocomplete="off"> From <input name="twilio_from" value="' . esc_attr( $s['twilio_from'] ) . '" placeholder="+1…"></p>';
		echo '<p>Meta token <input type="password" name="meta_token" value="' . esc_attr( $s['meta_token'] ) . '" autocomplete="off"> Phone ID <input name="meta_phone_id" value="' . esc_attr( $s['meta_phone_id'] ) . '"></p></td></tr></table>';
		submit_button();
		echo '</form>';

		echo '<div class="uh-panel"><h2>' . esc_html__( 'Tools', 'ukraine-health' ) . '</h2><p>';
		echo '<form method="post" style="display:inline">' . $this->field( 'twofa_test' ); // phpcs:ignore
		submit_button( __( 'Send me a test login-code email', 'ukraine-health' ), 'secondary', 'submit', false );
		echo '</form> ';
		if ( $s['telegram_token'] ) {
			echo '<form method="post" style="display:inline">' . $this->field( 'telegram_connect' ); // phpcs:ignore
			submit_button( __( 'Connect Telegram bot', 'ukraine-health' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</p><p class="description">' . wp_kses_post( sprintf( __( 'Personal data requests (copy or delete) are handled in <a href="%1$s">Tools → Export Personal Data</a> and <a href="%2$s">Erase Personal Data</a>. Suggested privacy policy text is in <a href="%3$s">Settings → Privacy</a>.', 'ukraine-health' ), esc_url( admin_url( 'export-personal-data.php' ) ), esc_url( admin_url( 'erase-personal-data.php' ) ), esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) ) ) . '</p></div>';

		echo '<div class="uh-panel"><h2>' . esc_html__( 'Shortcodes', 'ukraine-health' ) . '</h2><p><code>[uh_request_form]</code> <code>[uh_my_requests]</code> <code>[uh_supporter]</code> <code>[uh_register role="supporter"]</code> <code>[uh_stats]</code> <code>[uh_invite]</code></p></div></div>';
	}

	/* =====================================================================
	 * Network: who invited whom
	 * ===================================================================== */

	public function page_network() {
		$tab  = sanitize_key( $_GET['tab'] ?? 'tree' );
		$base = admin_url( 'admin.php?page=ukraine-health-network' );
		echo '<div class="wrap uh-admin"><h1>' . esc_html__( 'Network – who invited whom', 'ukraine-health' ) . '</h1>';
		$this->msg();
		echo '<p class="description">' . esc_html__( 'Everyone joins through an invite from a member. A member is connected when their chain of inviters reaches an administrator or coordinator through accounts that are not suspended. Only connected Supporters are notified about groups and can see Heroes’ details. Suspending someone also freezes everyone below them until you re-attach those people to another member.', 'ukraine-health' ) . '</p>';
		echo '<nav class="nav-tab-wrapper">';
		$n_review  = count( UH_Network::pending_supporters() );
		$n_reports = UH_Reports::open_count();
		foreach ( array( 'tree' => __( 'Tree', 'ukraine-health' ), 'review' => __( 'Needs review', 'ukraine-health' ) . ( $n_review ? " ($n_review)" : '' ), 'reports' => __( 'Problem reports', 'ukraine-health' ) . ( $n_reports ? " ($n_reports)" : '' ), 'invites' => __( 'Invites', 'ukraine-health' ) ) as $k => $l ) {
			echo '<a class="nav-tab' . ( $tab === $k ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $k, $base ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav>';

		$users = get_users( array( 'orderby' => 'registered', 'order' => 'ASC', 'number' => 2000 ) );
		$kids  = array();
		foreach ( $users as $u ) {
			$kids[ UH_Network::inviter( $u->ID ) ][] = $u;
		}

		if ( 'invites' === $tab ) {
			$this->network_invites();
		} elseif ( 'reports' === $tab ) {
			$this->network_reports();
		} elseif ( 'review' === $tab ) {
			$pending = UH_Network::pending_supporters();
			echo '<h2>' . esc_html__( 'Supporters waiting for approval', 'ukraine-health' ) . '</h2>';
			if ( ! $pending ) {
				echo '<p>' . esc_html__( 'Nobody is waiting.', 'ukraine-health' ) . '</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Supporter', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Invited by', 'ukraine-health' ) . '</th><th>' . esc_html__( 'About', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Code of conduct', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $pending as $u ) {
					$by = get_userdata( UH_Network::inviter( $u->ID ) );
					echo '<tr><td>' . $this->member_label( $u ) . '</td><td>' . esc_html( $by ? UH_Privacy::nickname( $by ) : '—' ) . '</td><td>' . esc_html( get_user_meta( $u->ID, 'uh_about', true ) ) . '</td><td>' . esc_html( get_user_meta( $u->ID, UH_Network::META_COC, true ) ?: '—' ) . '</td><td><form method="post" class="uh-inline-form">' . $this->field( 'net_approve' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><button class="button button-primary button-small">' . esc_html__( 'Approve', 'ukraine-health' ) . '</button></form></td></tr>'; // phpcs:ignore
				}
				echo '</tbody></table>';
			}
			echo '<h2>' . esc_html__( 'Members who are not connected', 'ukraine-health' ) . '</h2>';
			$list = array_filter(
				$users,
				function ( $u ) {
					return ! UH_Network::is_connected( $u->ID );
				}
			);
			if ( ! $list ) {
				echo '<p>' . esc_html__( 'Everyone is connected.', 'ukraine-health' ) . '</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Member', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Why', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Actions', 'ukraine-health' ) . '</th></tr></thead><tbody>';
				foreach ( $list as $u ) {
					$inv = UH_Network::inviter( $u->ID );
					if ( UH_Network::is_suspended( $u->ID ) ) {
						$why = __( 'Suspended', 'ukraine-health' );
					} elseif ( ! $inv ) {
						$why = __( 'Nobody invited them (e.g. signed up through the WordPress form)', 'ukraine-health' );
					} else {
						$by  = get_userdata( $inv );
						$why = sprintf( __( 'Their inviter chain is broken (%s is suspended, deleted or not connected)', 'ukraine-health' ), $by ? $by->display_name : '#' . $inv );
					}
					echo '<tr><td>' . $this->member_label( $u ) . '</td><td>' . esc_html( $why ) . '</td><td>' . $this->member_actions( $u, $users ) . '</td></tr>'; // phpcs:ignore
				}
				echo '</tbody></table>';
			}
		} else {
			$roots = array_filter(
				$users,
				function ( $u ) {
					return UH_Network::is_root( $u->ID ) && ! UH_Network::inviter( $u->ID );
				}
			);
			echo '<div class="uh-tree">';
			foreach ( $roots as $r ) {
				$this->tree_node( $r, $kids, $users, 0, array() );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private function tree_node( $u, $kids, $users, $depth, $seen ) {
		if ( isset( $seen[ $u->ID ] ) || $depth > 30 ) {
			return;
		}
		$seen[ $u->ID ] = true;
		echo '<details class="uh-node" ' . ( $depth < 2 ? 'open' : '' ) . '><summary>' . $this->member_label( $u ) . ' <span class="uh-node-actions">' . $this->member_actions( $u, $users ) . '</span></summary>'; // phpcs:ignore
		foreach ( $kids[ $u->ID ] ?? array() as $k ) {
			$this->tree_node( $k, $kids, $users, $depth + 1, $seen );
		}
		echo '</details>';
	}

	private function member_label( $u ) {
		$roles = array();
		if ( UH_Network::is_root( $u->ID ) ) {
			$roles[] = __( 'Coordinator', 'ukraine-health' );
		}
		if ( in_array( UH_Install::ROLE_SUPPORTER, (array) $u->roles, true ) ) {
			$roles[] = __( 'Supporter', 'ukraine-health' );
		}
		if ( in_array( UH_Install::ROLE_HERO, (array) $u->roles, true ) ) {
			$roles[] = __( 'Hero', 'ukraine-health' );
		}
		$state = UH_Network::is_suspended( $u->ID ) ? '<span class="uh-badge uh-cancelled">' . esc_html__( 'suspended', 'ukraine-health' ) . '</span>' : ( UH_Network::is_connected( $u->ID ) ? '' : '<span class="uh-badge uh-ready">' . esc_html__( 'not connected', 'ukraine-health' ) . '</span>' );
		$inv   = (int) get_user_meta( $u->ID, UH_Network::META_INVITE, true );
		$note  = $inv && ( $i = UH_Network::invite( $inv ) ) && $i->note ? ' · ' . esc_html( sprintf( __( 'invite note: “%s”', 'ukraine-health' ), $i->note ) ) : '';
		if ( in_array( UH_Install::ROLE_SUPPORTER, (array) $u->roles, true ) && ! UH_Network::is_root( $u->ID ) ) {
			if ( UH_Network::on_hold( $u->ID ) ) {
				$state .= ' <span class="uh-badge uh-ready">' . esc_html__( 'on hold (report)', 'ukraine-health' ) . '</span>';
			} elseif ( ! UH_Network::is_approved( $u->ID ) ) {
				$state .= ' <span class="uh-badge">' . esc_html__( 'waiting for approval', 'ukraine-health' ) . '</span>';
			}
		}
		$real = trim( $u->first_name . ' ' . $u->last_name );
		return '<strong><a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( UH_Privacy::nickname( $u ) ) . '</a></strong> <small>' . esc_html( ( $real ? $real . ' · ' : '' ) . $u->user_email . ' · ' . implode( ', ', $roles ) . ' · ' . mysql2date( get_option( 'date_format' ), $u->user_registered ) ) . $note . '</small> ' . $state;
	}

	private function member_actions( $u, $users ) {
		if ( (int) $u->ID === get_current_user_id() || user_can( $u->ID, 'manage_options' ) ) {
			return '';
		}
		$out = '';
		if ( UH_Network::is_suspended( $u->ID ) ) {
			$out .= '<form method="post" class="uh-inline-form">' . $this->field( 'net_unsuspend' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><button class="button button-small">' . esc_html__( 'Unsuspend', 'ukraine-health' ) . '</button></form>';
		} else {
			$out .= '<form method="post" class="uh-inline-form" onsubmit="return confirm(\'' . esc_js( __( 'Suspend this member? They are logged out and cannot log in, help or invite.', 'ukraine-health' ) ) . '\')">' . $this->field( 'net_suspend' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><button class="button button-small">' . esc_html__( 'Suspend', 'ukraine-health' ) . '</button></form>';
			if ( UH_Network::invitees( $u->ID ) ) {
				$out .= '<form method="post" class="uh-inline-form" onsubmit="return confirm(\'' . esc_js( __( 'Suspend this member AND everyone they invited, directly or indirectly?', 'ukraine-health' ) ) . '\')">' . $this->field( 'net_suspend_branch' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><button class="button button-small button-link-delete">' . esc_html__( 'Suspend with branch', 'ukraine-health' ) . '</button></form>';
			}
		}
		if ( ! in_array( UH_Install::ROLE_SUPPORTER, (array) $u->roles, true ) ) {
			$out .= '<form method="post" class="uh-inline-form">' . $this->field( 'net_allow_supporter' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><button class="button button-small">' . esc_html__( 'Make Supporter', 'ukraine-health' ) . '</button></form>';
		}
		$out .= '<form method="post" class="uh-inline-form">' . $this->field( 'net_reattach' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><select name="inviter_id" required><option value="">' . esc_html__( 'Re-attach under…', 'ukraine-health' ) . '</option>';
		foreach ( $users as $o ) {
			if ( $o->ID !== $u->ID && UH_Network::is_connected( $o->ID ) ) {
				$out .= '<option value="' . (int) $o->ID . '" ' . selected( UH_Network::inviter( $u->ID ), $o->ID, false ) . '>' . esc_html( $o->display_name ) . '</option>';
			}
		}
		$out .= '</select> <button class="button button-small">' . esc_html__( 'Save', 'ukraine-health' ) . '</button></form>';
		return $out;
	}

	private function network_invites() {
		$labels = UH_Network::roles();
		$new    = absint( $_GET['new'] ?? 0 );
		if ( $new && ( $inv = UH_Network::invite( $new ) ) ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Invite link:', 'ukraine-health' ) . '</strong> <input type="text" class="large-text code" readonly onclick="this.select()" value="' . esc_attr( UH_Network::invite_url( $inv ) ) . '"></p></div>';
		}
		echo '<div class="uh-panel"><h2>' . esc_html__( 'Create an invite', 'ukraine-health' ) . '</h2><form method="post">' . $this->field( 'net_invite' ); // phpcs:ignore
		echo '<p><label>' . esc_html__( 'For', 'ukraine-health' ) . ' <select name="role">';
		foreach ( $labels as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'Note (who is it for)', 'ukraine-health' ) . ' <input type="text" name="note" required maxlength="128"></label> <label>' . esc_html__( 'Uses', 'ukraine-health' ) . ' <input type="number" name="max_uses" min="1" max="' . (int) UH_Network::MANAGER_MAX_USES . '" value="1" style="width:5em"></label> <label>' . esc_html__( 'Valid days', 'ukraine-health' ) . ' <input type="number" name="days" min="1" max="' . (int) UH_Network::MANAGER_MAX_DAYS . '" value="14" style="width:5em"></label></p>';
		echo '<p class="description">' . esc_html__( 'Tip: a multi-use Hero invite can be given to hospital staff for one ward. Each person who uses it is recorded as invited by you.', 'ukraine-health' ) . '</p>';
		submit_button( __( 'Create invite', 'ukraine-health' ), 'primary', 'submit', false );
		echo '</form></div>';

		$now = strtotime( current_time( 'mysql' ) );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Invited by', 'ukraine-health' ) . '</th><th>' . esc_html__( 'For', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Note', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Used', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Valid until', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Status', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
		$rows = UH_Network::all_invites();
		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No invites yet.', 'ukraine-health' ) . '</td></tr>';
		}
		foreach ( $rows as $i ) {
			$by   = get_userdata( (int) $i->inviter_id );
			$open = ! $i->revoked && $i->uses < $i->max_uses && strtotime( $i->expires_at ) > $now;
			echo '<tr><td>' . (int) $i->id . '</td><td>' . esc_html( $by ? $by->display_name : '#' . (int) $i->inviter_id ) . '</td><td>' . esc_html( $labels[ $i->role ] ?? $i->role ) . '</td><td>' . esc_html( $i->note ) . '</td><td>' . (int) $i->uses . '/' . (int) $i->max_uses . '</td><td>' . esc_html( $i->expires_at ) . '</td><td>' . esc_html( $open ? __( 'open', 'ukraine-health' ) : ( $i->revoked ? __( 'cancelled', 'ukraine-health' ) : ( $i->uses >= $i->max_uses ? __( 'used', 'ukraine-health' ) : __( 'expired', 'ukraine-health' ) ) ) ) . '</td><td>';
			if ( $open ) {
				echo '<form method="post" class="uh-inline-form">' . $this->field( 'net_revoke' ) . '<input type="hidden" name="invite_id" value="' . (int) $i->id . '"><button class="button-link-delete">' . esc_html__( 'Cancel', 'ukraine-health' ) . '</button></form>'; // phpcs:ignore
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function network_reports() {
		$rows = UH_Reports::all();
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No problem reports.', 'ukraine-health' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'When a Hero reports a problem, the Supporter is put on hold at once: no new groups and no Heroes’ details. Clear the report to lift the hold, or suspend the Supporter.', 'ukraine-health' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'When', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Supporter', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Visit', 'ukraine-health' ) . '</th><th>' . esc_html__( 'What happened', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Status', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$sup = get_userdata( (int) $r->supporter_id );
			echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( $r->created_at ) . '</td><td>' . ( $sup ? $this->member_label( $sup ) : '—' ) . '</td><td>' . ( $r->group_id ? '<a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-groups&view=' . (int) $r->group_id ) ) . '">#' . (int) $r->group_id . '</a>' : '' ) . '</td><td>' . nl2br( esc_html( $r->message ) ) . ( $r->resolution ? '<p><em>' . esc_html( $r->resolution ) . '</em></p>' : '' ) . '</td><td>' . esc_html( $r->status ) . '</td><td>'; // phpcs:ignore
			if ( 'open' === $r->status ) {
				echo '<form method="post">' . $this->field( 'report_resolve' ) . '<input type="hidden" name="report_id" value="' . (int) $r->id . '"><p><textarea name="note" rows="2" placeholder="' . esc_attr__( 'What you found / did', 'ukraine-health' ) . '"></textarea></p><p><button class="button" name="outcome" value="clear">' . esc_html__( 'Clear and lift hold', 'ukraine-health' ) . '</button> <button class="button button-link-delete" name="outcome" value="suspend" onclick="return confirm(\'' . esc_js( __( 'Suspend this Supporter?', 'ukraine-health' ) ) . '\')">' . esc_html__( 'Suspend Supporter', 'ukraine-health' ) . '</button></p></form>'; // phpcs:ignore
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
