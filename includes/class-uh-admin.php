<?php
/**
 * Admin screens: Dashboard, Groups, Hospitals, Services, Settings.
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
	}

	public function menu() {
		$cap = UH_Install::CAP_MANAGE;
		add_menu_page( 'Ukraine Health', 'Ukraine Health', $cap, 'ukraine-health', array( $this, 'page_dashboard' ), 'dashicons-heart', 26 );
		add_submenu_page( 'ukraine-health', __( 'Dashboard', 'ukraine-health' ), __( 'Dashboard', 'ukraine-health' ), $cap, 'ukraine-health', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'ukraine-health', __( 'Groups', 'ukraine-health' ), __( 'Groups', 'ukraine-health' ), $cap, 'ukraine-health-groups', array( $this, 'page_groups' ) );
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
			);
			echo '<div class="notice notice-' . ( in_array( $m, array( 'inuse', 'error' ), true ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $text[ $m ] ?? $m ) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Actions
	 * ===================================================================== */

	public function handle() {
		if ( isset( $_GET['uh_export'] ) && current_user_can( UH_Install::CAP_MANAGE ) && check_admin_referer( 'uh_export' ) ) {
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
				$s['rate_limit']     = absint( $p['rate_limit'] ?? 5 );
				$s['messengers']     = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $p['messengers'] ?? '' ) ) ) ) );
				$s['privacy_notice'] = sanitize_textarea_field( $p['privacy_notice'] ?? '' );
				update_option( 'uh_settings', $s );
				$this->redirect( 'ukraine-health-settings' );
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
			'SELECT r.id, r.created_at, s.name AS service, h.name AS hospital, r.name, r.ward, r.phone, r.messenger, r.email, r.sizes, r.notes, r.status AS request_status, g.id AS group_id, g.status AS group_status, g.appointment, u.display_name AS supporter
			FROM ' . UH_Repo::t( 'requests' ) . ' r JOIN ' . UH_Repo::t( 'groups' ) . ' g ON g.id=r.group_id
			JOIN ' . UH_Repo::t( 'services' ) . ' s ON s.id=g.service_id JOIN ' . UH_Repo::t( 'hospitals' ) . ' h ON h.id=g.hospital_id
			LEFT JOIN ' . $wpdb->users . ' u ON u.ID=g.supporter_id ORDER BY r.id DESC',
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
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Groups', 'ukraine-health' ) . '</h1> <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=ukraine-health-groups&uh_export=1' ), 'uh_export' ) ) . '">' . esc_html__( 'Export CSV', 'ukraine-health' ) . '</a>';
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
		echo '<p>' . esc_html( sprintf( __( 'Supporter: %1$s · Appointment: %2$s', 'ukraine-health' ), $sup ? $sup->display_name . ' <' . $sup->user_email . '>' : '—', $g->appointment ?: '—' ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Ward', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Phone', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Sizes', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Notes', 'ukraine-health' ) . '</th><th>' . esc_html__( 'Joined', 'ukraine-health' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( UH_Repo::group_members( $g->id ) as $m ) {
			echo '<tr><td>' . esc_html( $m->name ?: '—' ) . ( $m->user_id ? ' <span class="dashicons dashicons-admin-users" title="registered"></span>' : '' ) . '</td><td>' . esc_html( $m->ward ) . '</td><td><a href="tel:' . esc_attr( $m->phone ) . '">' . esc_html( $m->phone ) . '</a> ' . esc_html( $m->messenger ) . ( $m->email ? '<br>' . esc_html( $m->email ) : '' ) . '</td><td>' . esc_html( $m->sizes ? UH_Notify::sizes_text( json_decode( $m->sizes, true ) ) : '' ) . '</td><td>' . esc_html( $m->notes ) . '</td><td>' . esc_html( $m->created_at ) . '</td><td>';
			if ( ! in_array( $g->status, array( 'completed', 'cancelled' ), true ) ) {
				echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Remove this Hero from the group?', 'ukraine-health' ) ) . '\')">' . $this->field( 'remove_member' ) . '<input type="hidden" name="request_id" value="' . (int) $m->id . '"><button class="button-link-delete">' . esc_html__( 'Remove', 'ukraine-health' ) . '</button></form>'; // phpcs:ignore
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Update', 'ukraine-health' ) . '</h2><form method="post" class="uh-admin-form">' . $this->field( 'group_update' ) . '<input type="hidden" name="group_id" value="' . (int) $g->id . '">'; // phpcs:ignore
		if ( in_array( $g->status, array( 'open', 'ready' ), true ) ) {
			$cands = UH_Repo::matching_supporters( $g->service_id, $g->hospital_id );
			$all   = get_users( array( 'role__in' => array( UH_Install::ROLE_SUPPORTER ) ) );
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
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=ukraine-health-hospitals&edit=' . (int) $h->id ) ) . '">' . esc_html( $h->name ) . '</a><br><small>' . esc_html( $h->address ) . '</small></td><td>' . esc_html( $h->city ) . '</td><td>' . esc_html( $h->country ) . '</td><td>' . ( $h->active ? '✓' : '—' ) . '</td><td><form method="post" onsubmit="return confirm(\'Delete?\')">' . $this->field( 'hospital_delete' ) . '<input type="hidden" name="id" value="' . (int) $h->id . '"><button class="button-link-delete">' . esc_html__( 'Delete', 'ukraine-health' ) . '</button></form></td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table></div><div class="uh-panel"><h2>' . ( $e ? esc_html__( 'Edit hospital', 'ukraine-health' ) : esc_html__( 'Add hospital', 'ukraine-health' ) ) . '</h2><form method="post">' . $this->field( 'hospital_save' ) . '<input type="hidden" name="id" value="' . (int) ( $e->id ?? 0 ) . '">'; // phpcs:ignore
		foreach ( array( 'name' => __( 'Name', 'ukraine-health' ), 'country' => __( 'Country', 'ukraine-health' ), 'city' => __( 'City', 'ukraine-health' ), 'address' => __( 'Address', 'ukraine-health' ), 'contact' => __( 'Contact person / phone', 'ukraine-health' ) ) as $k => $l ) {
			echo '<p><label>' . esc_html( $l ) . '<br><input class="regular-text" type="text" name="' . esc_attr( $k ) . '" value="' . esc_attr( $e->$k ?? ( 'country' === $k ? 'Latvia' : '' ) ) . '" ' . ( 'name' === $k ? 'required' : '' ) . '></label></p>';
		}
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
		if ( ! get_option( 'users_can_register' ) ) {
			echo '<p class="description">' . wp_kses_post( sprintf( __( 'Note: account sign-up is off. Turn on “Anyone can register” in <a href="%s">Settings → General</a> so Supporters can join.', 'ukraine-health' ), esc_url( admin_url( 'options-general.php' ) ) ) ) . '</p>';
		}
		echo '</div>';

		echo '<form method="post" class="uh-panel">' . $this->field( 'settings' ); // phpcs:ignore
		echo '<h2>' . esc_html__( 'Requests', 'ukraine-health' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Guests', 'ukraine-health' ) . '</th><td><label><input type="checkbox" name="allow_guests" value="1" ' . checked( $s['allow_guests'], 1, false ) . '> ' . esc_html__( 'Heroes can request help without an account (phone required)', 'ukraine-health' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Rate limit', 'ukraine-health' ) . '</th><td><input type="number" min="0" name="rate_limit" value="' . (int) $s['rate_limit'] . '"> ' . esc_html__( 'guest submissions per hour per device (0 = off)', 'ukraine-health' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Messengers', 'ukraine-health' ) . '</th><td><input class="regular-text" name="messengers" value="' . esc_attr( implode( ', ', $s['messengers'] ) ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Consent text', 'ukraine-health' ) . '</th><td><textarea class="large-text" rows="2" name="privacy_notice">' . esc_textarea( $s['privacy_notice'] ) . '</textarea></td></tr>';
		echo '</table><h2>' . esc_html__( 'Coordinator notifications', 'ukraine-health' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Email', 'ukraine-health' ) . '</th><td><input class="regular-text" type="email" name="admin_email" value="' . esc_attr( $s['admin_email'] ) . '"> <label><input type="checkbox" name="notify_admin" value="1" ' . checked( $s['notify_admin'], 1, false ) . '> ' . esc_html__( 'notify on every request', 'ukraine-health' ) . '</label></td></tr>';
		echo '<tr><th>Telegram</th><td><p><label>' . esc_html__( 'Bot token', 'ukraine-health' ) . '<br><input class="regular-text" name="telegram_token" value="' . esc_attr( $s['telegram_token'] ) . '" autocomplete="off"></label></p><p><label>' . esc_html__( 'Coordinator chat / group ID', 'ukraine-health' ) . '<br><input class="regular-text" name="telegram_admin" value="' . esc_attr( $s['telegram_admin'] ) . '"></label></p></td></tr>';
		echo '<tr><th>WhatsApp</th><td><select name="wa_gateway">';
		foreach ( array( 'disabled' => __( 'Disabled', 'ukraine-health' ), 'twilio' => 'Twilio', 'meta' => 'Meta Cloud API' ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['wa_gateway'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><p>Twilio SID <input name="twilio_sid" value="' . esc_attr( $s['twilio_sid'] ) . '"> Token <input type="password" name="twilio_token" value="' . esc_attr( $s['twilio_token'] ) . '" autocomplete="off"> From <input name="twilio_from" value="' . esc_attr( $s['twilio_from'] ) . '" placeholder="+1…"></p>';
		echo '<p>Meta token <input type="password" name="meta_token" value="' . esc_attr( $s['meta_token'] ) . '" autocomplete="off"> Phone ID <input name="meta_phone_id" value="' . esc_attr( $s['meta_phone_id'] ) . '"></p></td></tr></table>';
		submit_button();
		echo '</form>';

		echo '<div class="uh-panel"><h2>' . esc_html__( 'Shortcodes', 'ukraine-health' ) . '</h2><p><code>[uh_request_form]</code> <code>[uh_my_requests]</code> <code>[uh_supporter]</code> <code>[uh_register role="supporter"]</code> <code>[uh_stats]</code></p></div></div>';
	}
}
