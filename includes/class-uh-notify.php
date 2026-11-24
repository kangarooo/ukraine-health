<?php
/**
 * Email, Telegram and WhatsApp notifications.
 *
 * @package UkraineHealth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UH_Notify {

	private static function label( $group ) {
		$s = UH_Repo::service( $group->service_id );
		$h = UH_Repo::hospital( $group->hospital_id );
		return array( $s, $h, sprintf( '%s — %s', $s ? $s->name : '?', $h ? $h->name : '?' ) );
	}

	/* ---------- Events ---------- */

	public static function admin_new_request( $group, $service, $hospital, $data ) {
		$set = UH_Install::settings();
		if ( empty( $set['notify_admin'] ) ) {
			return;
		}
		$count = UH_Repo::member_count( $group->id );
		// No names, phones or wards in messages: they stay in mailboxes and chats for good.
		$msg = sprintf(
			/* translators: 1: service, 2: hospital, 3: members, 4: minimum, 5: link */
			__( "New request: %1\$s at %2\$s (%3\$d/%4\$d).\nDetails (login needed): %5\$s", 'ukraine-health' ),
			$service->name,
			$hospital->name,
			$count,
			$service->min_group,
			admin_url( 'admin.php?page=ukraine-health-groups&view=' . (int) $group->id )
		);
		self::coordinators( $service->name . ' – ' . $hospital->name, $msg );
	}

	/** Message the coordinators (settings email + Telegram chat). Never put personal details in $msg. */
	public static function coordinators( $subject, $msg ) {
		$set = UH_Install::settings();
		self::email( $set['admin_email'], '[Ukraine Health] ' . $subject, $msg );
		if ( $set['telegram_admin'] ) {
			self::telegram( $set['telegram_admin'], $msg );
		}
	}

	public static function group_ready( $group ) {
		list( $s, $h, $label ) = self::label( $group );
		$count = UH_Repo::member_count( $group->id );
		$link  = self::page_url( 'supporter' );
		$msg   = sprintf(
			/* translators: 1: service — hospital, 2: number of heroes, 3: link */
			__( "A group is ready: %1\$s (%2\$d Heroes). Accept it and pick a date: %3\$s", 'ukraine-health' ),
			$label,
			$count,
			$link
		);
		foreach ( UH_Repo::matching_supporters( $group->service_id, $group->hospital_id ) as $u ) {
			self::to_user( $u, __( 'A group needs you', 'ukraine-health' ), $msg );
		}
		$set = UH_Install::settings();
		if ( ! empty( $set['notify_admin'] ) ) {
			self::coordinators( 'READY: ' . $label, 'READY: ' . $msg );
		}
		// Let the Heroes know too.
		foreach ( UH_Repo::group_members( $group->id ) as $r ) {
			self::to_hero( $r, sprintf( __( 'Your %s group is full. We are finding a Supporter now.', 'ukraine-health' ), $s->name ) );
		}
	}

	public static function group_scheduled( $group ) {
		list( $s, $h, $label ) = self::label( $group );
		$supporter = get_userdata( $group->supporter_id );
		$when      = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $group->appointment );
		$who       = $supporter ? UH_Privacy::nickname( $supporter ) : '';

		$hero_msg = sprintf(
			/* translators: 1: service, 2: date, 3: hospital, 4: supporter */
			__( 'Good news! %1$s is scheduled for %2$s at %3$s. Supporter: %4$s.', 'ukraine-health' ),
			$s->name,
			$when,
			$h->name,
			$who
		);
		if ( $group->supporter_note ) {
			$hero_msg .= "\n" . $group->supporter_note;
		}
		$ics = self::ics( $s->name, $group->appointment, (int) $s->minutes_each * max( 1, UH_Repo::member_count( $group->id ) ), $h->name . ', ' . $h->address );
		foreach ( UH_Repo::group_members( $group->id ) as $r ) {
			self::to_hero( $r, $hero_msg, $ics );
		}

		if ( $supporter ) {
			// The Heroes' wards and phones are shown only in the Supporter area, and only shortly before the visit.
			$msg = sprintf(
				/* translators: 1: service — hospital, 2: date, 3: address, 4: number of heroes, 5: link */
				__( "You accepted: %1\$s on %2\$s\n%3\$s\n%4\$d Heroes. Their wards and phone numbers appear in your Supporter area shortly before the visit: %5\$s", 'ukraine-health' ),
				$label,
				$when,
				$h->address,
				UH_Repo::member_count( $group->id ),
				add_query_arg( 'uh_tab', 'mine', self::page_url( 'supporter' ) )
			);
			if ( $h->visiting_hours ) {
				$msg .= "\n" . sprintf( __( 'Visiting hours: %s', 'ukraine-health' ), $h->visiting_hours );
			}
			self::to_user( $supporter, __( 'Group confirmed', 'ukraine-health' ), $msg, $ics );
		}
		self::hospital_contact( $group, $s, $h, $who, $when );
	}

	/** Tell the hospital's contact person that a visit is planned: no Hero names, only wards and the number of people. */
	public static function hospital_contact( $group, $s, $h, $who, $when ) {
		if ( ! $h || empty( $h->notify_contact ) || ! is_email( $h->contact_email ) ) {
			return;
		}
		$wards = array_unique( array_filter( wp_list_pluck( UH_Repo::group_members( $group->id ), 'ward' ) ) );
		$msg   = sprintf(
			/* translators: 1: service, 2: date, 3: number of patients, 4: wards, 5: supporter nickname */
			__( "A volunteer visit is planned at your hospital.\nService: %1\$s\nWhen: %2\$s\nPatients: %3\$d\nWards: %4\$s\nVolunteer: %5\$s (will show ID at the entrance)\n\nPlease reply to this email if the visit is not possible.", 'ukraine-health' ),
			$s->name,
			$when,
			UH_Repo::member_count( $group->id ),
			$wards ? implode( ', ', $wards ) : '—',
			$who
		);
		$set     = UH_Install::settings();
		$headers = is_email( $set['admin_email'] ) ? array( 'Reply-To: ' . $set['admin_email'] ) : array();
		wp_mail( $h->contact_email, '[Ukraine Health] ' . sprintf( __( 'Planned visit: %s', 'ukraine-health' ), $s->name ), $msg, $headers );
		UH_Repo::log( 'hospital_notified', $group->id );
	}

	/** Reminder the day before a visit. */
	public static function reminder( $group ) {
		list( $s, $h, $label ) = self::label( $group );
		$when = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $group->appointment );
		foreach ( UH_Repo::group_members( $group->id ) as $r ) {
			self::to_hero( $r, sprintf( __( 'Reminder: %1$s is planned for %2$s at %3$s.', 'ukraine-health' ), $s->name, $when, $h->name ) );
		}
		$sup = get_userdata( $group->supporter_id );
		if ( $sup ) {
			self::to_user( $sup, __( 'Visit reminder', 'ukraine-health' ), sprintf( __( 'Reminder: your visit %1$s is on %2$s. The Heroes’ details are in your Supporter area: %3$s', 'ukraine-health' ), $label, $when, add_query_arg( 'uh_tab', 'mine', self::page_url( 'supporter' ) ) ) );
		}
	}

	public static function supporter_withdrawal( $group, $request ) {
		$supporter = get_userdata( $group->supporter_id );
		if ( $supporter ) {
			list( , , $label ) = self::label( $group );
			self::to_user( $supporter, __( 'A Hero withdrew', 'ukraine-health' ), sprintf( __( 'A Hero withdrew from %s.', 'ukraine-health' ), $label ) );
		}
	}

	/* ---------- Channels ---------- */

	public static function to_user( $user, $subject, $msg, $ics = '' ) {
		self::email( $user->user_email, '[Ukraine Health] ' . $subject, $msg, $ics );
		$tg = get_user_meta( $user->ID, 'uh_telegram', true );
		if ( $tg ) {
			self::telegram( $tg, $msg );
		}
		$wa = get_user_meta( $user->ID, 'uh_whatsapp', true );
		if ( $wa ) {
			self::whatsapp( $wa, $msg );
		}
	}

	public static function to_hero( $request, $msg, $ics = '' ) {
		if ( ! empty( $request->wiped_at ) ) {
			return;
		}
		if ( ! empty( $request->tg_chat ) ) {
			self::telegram( $request->tg_chat, $msg );
		}
		$email = $request->email;
		if ( $request->user_id ) {
			$u = get_userdata( $request->user_id );
			if ( $u ) {
				$email = $email ?: $u->user_email;
				$tg    = get_user_meta( $u->ID, 'uh_telegram', true );
				if ( $tg ) {
					self::telegram( $tg, $msg );
				}
			}
		}
		if ( $email ) {
			self::email( $email, '[Ukraine Health] ' . __( 'Your request', 'ukraine-health' ), $msg . "\n\n" . self::status_link( $request ), $ics );
		}
		if ( 'whatsapp' === strtolower( $request->messenger ) && $request->phone ) {
			self::whatsapp( $request->phone, $msg );
		}
	}

	public static function email( $to, $subject, $body, $ics = '' ) {
		if ( ! is_email( $to ) ) {
			return;
		}
		$attachments = array();
		if ( $ics ) {
			$dir  = wp_upload_dir();
			$path = trailingslashit( $dir['basedir'] ) . 'uh-' . wp_generate_password( 12, false ) . '.ics';
			if ( file_put_contents( $path, $ics ) ) { // phpcs:ignore
				$attachments[] = $path;
			}
		}
		wp_mail( $to, $subject, $body, array(), $attachments );
		foreach ( $attachments as $a ) {
			wp_delete_file( $a );
		}
	}

	public static function telegram( $chat_id, $msg ) {
		$token = UH_Install::settings()['telegram_token'];
		if ( ! $token || ! $chat_id ) {
			return;
		}
		wp_remote_post(
			'https://api.telegram.org/bot' . rawurlencode( $token ) . '/sendMessage',
			array(
				'timeout'  => 8,
				'blocking' => false,
				'body'     => array( 'chat_id' => $chat_id, 'text' => $msg ),
			)
		);
	}

	public static function whatsapp( $to, $msg ) {
		$s  = UH_Install::settings();
		$to = preg_replace( '/[^0-9+]/', '', $to );
		if ( ! $to ) {
			return;
		}
		if ( 'twilio' === $s['wa_gateway'] && $s['twilio_sid'] ) {
			wp_remote_post(
				'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $s['twilio_sid'] ) . '/Messages.json',
				array(
					'timeout'  => 8,
					'blocking' => false,
					'headers'  => array( 'Authorization' => 'Basic ' . base64_encode( $s['twilio_sid'] . ':' . $s['twilio_token'] ) ),
					'body'     => array(
						'From' => 'whatsapp:' . $s['twilio_from'],
						'To'   => 'whatsapp:' . $to,
						'Body' => $msg,
					),
				)
			);
		} elseif ( 'meta' === $s['wa_gateway'] && $s['meta_token'] ) {
			wp_remote_post(
				'https://graph.facebook.com/v19.0/' . rawurlencode( $s['meta_phone_id'] ) . '/messages',
				array(
					'timeout'  => 8,
					'blocking' => false,
					'headers'  => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $s['meta_token'],
					),
					'body'     => wp_json_encode(
						array(
							'messaging_product' => 'whatsapp',
							'to'                => ltrim( $to, '+' ),
							'type'              => 'text',
							'text'              => array( 'body' => $msg ),
						)
					),
				)
			);
		}
	}

	/* ---------- Helpers ---------- */

	public static function sizes_text( $sizes ) {
		$out = array();
		foreach ( (array) $sizes as $k => $v ) {
			if ( '' !== $v ) {
				$out[] = $k . ': ' . $v;
			}
		}
		return implode( ', ', $out );
	}

	public static function status_link( $request ) {
		return add_query_arg( 'uh_token', $request->token, self::page_url( 'status' ) );
	}

	/** URL of the page holding a given shortcode (set by the setup tool), or home. */
	public static function page_url( $key ) {
		$pages = (array) get_option( 'uh_pages', array() );
		return ! empty( $pages[ $key ] ) ? get_permalink( $pages[ $key ] ) : home_url( '/' );
	}

	public static function ics( $summary, $start_mysql, $minutes, $location ) {
		$tz    = wp_timezone();
		$start = new DateTime( $start_mysql, $tz );
		$end   = ( clone $start )->modify( '+' . max( 15, (int) $minutes ) . ' minutes' );
		$fmt   = function ( $d ) {
			return gmdate( 'Ymd\THis\Z', $d->getTimestamp() );
		};
		$esc   = function ( $s ) {
			return addcslashes( wp_strip_all_tags( $s ), ",;\\" );
		};
		return implode(
			"\r\n",
			array(
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//Ukraine Health//EN',
				'METHOD:PUBLISH',
				'BEGIN:VEVENT',
				'UID:' . wp_generate_uuid4() . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
				'DTSTART:' . $fmt( $start ),
				'DTEND:' . $fmt( $end ),
				'SUMMARY:' . $esc( $summary ),
				'LOCATION:' . $esc( $location ),
				'END:VEVENT',
				'END:VCALENDAR',
			)
		);
	}
}
