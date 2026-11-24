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
		$msg   = sprintf(
			"New request: %s at %s (%d/%d)\nName: %s\nWard: %s\nPhone: %s (%s)\nNotes: %s",
			$service->name,
			$hospital->name,
			$count,
			$service->min_group,
			$data['name'] ?? '',
			$data['ward'] ?? '',
			$data['phone'] ?? '',
			$data['messenger'] ?? '',
			$data['notes'] ?? ''
		);
		self::email( $set['admin_email'], '[Ukraine Health] ' . $service->name . ' – ' . $hospital->name, $msg );
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
			self::email( $set['admin_email'], '[Ukraine Health] READY: ' . $label, $msg );
			if ( $set['telegram_admin'] ) {
				self::telegram( $set['telegram_admin'], 'READY: ' . $msg );
			}
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
		$who       = $supporter ? $supporter->display_name : '';

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
			$lines = array();
			foreach ( UH_Repo::group_members( $group->id ) as $i => $r ) {
				$sizes   = $r->sizes ? json_decode( $r->sizes, true ) : array();
				$lines[] = sprintf(
					'%d. %s | %s %s | %s %s%s',
					$i + 1,
					$r->name ?: '—',
					__( 'ward', 'ukraine-health' ),
					$r->ward ?: '—',
					$r->phone,
					$r->messenger ? '(' . $r->messenger . ')' : '',
					$sizes ? ' | ' . self::sizes_text( $sizes ) : ''
				) . ( $r->notes ? "\n   " . $r->notes : '' );
			}
			$msg = sprintf( __( "You accepted: %1\$s on %2\$s\n%3\$s\n\nHeroes:\n%4\$s", 'ukraine-health' ), $label, $when, $h->address, implode( "\n", $lines ) );
			self::to_user( $supporter, __( 'Group confirmed', 'ukraine-health' ), $msg, $ics );
		}
	}

	public static function supporter_withdrawal( $group, $request ) {
		$supporter = get_userdata( $group->supporter_id );
		if ( $supporter ) {
			list( , , $label ) = self::label( $group );
			self::to_user( $supporter, __( 'A Hero withdrew', 'ukraine-health' ), sprintf( __( '%1$s withdrew from %2$s.', 'ukraine-health' ), $request->name ?: '#' . $request->id, $label ) );
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
