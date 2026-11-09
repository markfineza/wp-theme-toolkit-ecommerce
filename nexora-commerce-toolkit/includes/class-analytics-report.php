<?php
/**
 * The daily report: built on demand from live orders, and emailed each morning if switched on.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily report.
 */
class Nexora_Toolkit_Analytics_Report {

	const OPTION = 'nexora_daily_report';
	const HOOK   = 'nexora_daily_report';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'keep_scheduled' ) );
		add_action( 'admin_post_nexora_report_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_nexora_report_send', array( __CLASS__, 'handle_send' ) );
	}

	/**
	 * Saved settings.
	 *
	 * @return array
	 */
	public static function settings() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'enabled'    => false,
				'recipients' => (string) get_option( 'admin_email' ),
				'hour'       => 6,
				'last_day'   => '',
				'last_at'    => 0,
				'last_error' => '',
			)
		);
	}

	/**
	 * Next time the report is due, in the site's timezone.
	 *
	 * @param int $hour Hour of the day.
	 * @return int Timestamp.
	 */
	private static function next_run( $hour ) {
		$now = current_datetime();
		$at  = $now->setTime( $hour, 0 );
		return ( $at > $now ? $at : $at->modify( '+1 day' ) )->getTimestamp();
	}

	/**
	 * Replace the scheduled send.
	 */
	private static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$s = self::settings();
		if ( $s['enabled'] ) {
			wp_schedule_single_event( self::next_run( (int) $s['hour'] ), self::HOOK );
		}
	}

	/**
	 * Put the send back if something removed it.
	 */
	public static function keep_scheduled() {
		$s = self::settings();
		if ( $s['enabled'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( self::next_run( (int) $s['hour'] ), self::HOOK );
		}
	}

	/**
	 * Email yesterday's report. A failed send tries again in an hour.
	 */
	public static function run() {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return;
		}
		$day = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
		if ( $s['last_day'] === $day ) {
			self::reschedule();
			return;
		}
		if ( self::send( $day, $s['recipients'] ) ) {
			self::reschedule();
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::HOOK );
	}

	/**
	 * Build and email one day's report, and record the result.
	 *
	 * @param string $day        Y-m-d.
	 * @param string $recipients Addresses.
	 * @return bool
	 */
	public static function send( $day, $recipients ) {
		$date = Nexora_Toolkit_Analytics_Data::parse_day( $day );
		$to   = self::clean_recipients( $recipients );
		$s    = self::settings();
		$ok   = false;
		if ( $date && $to ) {
			$report = self::build( $date );
			$sum    = $report['summary'];
			$m      = Nexora_Toolkit_Emails::compose(
				'daily_report',
				array(
					'date'   => $report['label'],
					'sales'  => Nexora_Toolkit_Analytics_Data::money( $sum['sales'] ),
					/* translators: %s: number of orders */
					'orders' => sprintf( _n( '%s order', '%s orders', $sum['orders'], 'nexora-commerce-toolkit' ), number_format_i18n( $sum['orders'] ) ),
				)
			);
			$ok     = Nexora_Store_Orders::send_mail( $to, $m['subject'], $m['heading'], $m['message'] . self::email_body( $report ) );
		}
		$s['last_error'] = $ok ? '' : ( $to ? __( 'The email could not be sent. Check that your site can send email.', 'nexora-commerce-toolkit' ) : __( 'There is no valid email address to send to.', 'nexora-commerce-toolkit' ) );
		if ( $ok ) {
			$s['last_day'] = $day;
			$s['last_at']  = time();
		}
		update_option( self::OPTION, $s, false );
		return $ok;
	}

	/**
	 * Valid addresses from a comma-separated list.
	 *
	 * @param string $list List.
	 * @return string[]
	 */
	public static function clean_recipients( $list ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $list ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( array_slice( $out, 0, 10 ) ) );
	}

	/**
	 * Everything in a daily report.
	 *
	 * @param DateTimeImmutable $date Day.
	 * @return array
	 */
	public static function build( $date ) {
		list( $start, $end ) = Nexora_Toolkit_Analytics_Data::day_bounds( $date );
		$reviews             = Nexora_Toolkit_Analytics_Data::reviews( $start, $end );
		$before              = $start->modify( '-1 day' );
		$last_week           = $start->modify( '-7 days' );
		$next                = $end->format( 'Y-m-d' );
		return array(
			'date'      => $start,
			'label'     => wp_date( get_option( 'date_format' ), $start->getTimestamp() ),
			'summary'   => Nexora_Toolkit_Analytics_Data::summary( $start, $end, 'hour' ),
			'before'    => Nexora_Toolkit_Analytics_Data::summary( $before, $start, 'hour' ),
			'last_week' => Nexora_Toolkit_Analytics_Data::summary( $last_week, $last_week->modify( '+1 day' ), 'hour' ),
			'reviews'   => $reviews,
			'waiting'   => Nexora_Toolkit_Analytics_Data::reviews_waiting(),
			'stock'     => Nexora_Toolkit_Analytics_Data::stock_alerts( 15 ),
			'next_day'  => $next,
			'scheduled' => $next >= current_datetime()->format( 'Y-m-d' ) ? Nexora_Toolkit_Analytics_Data::scheduled( $next ) : null,
		);
	}

	/**
	 * Email body. It holds totals and product names, with no customer details.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function email_body( $report ) {
		$s     = $report['summary'];
		$cell  = 'padding:8px;border-bottom:1px solid #e6e8f0;';
		$right = $cell . 'text-align:right;white-space:nowrap;';
		$h     = 'margin:22px 0 6px;font-size:16px;';
		$m     = array( 'Nexora_Toolkit_Analytics_Data', 'money' );
		$row   = static function ( $label, $value ) use ( $cell, $right ) {
			return '<tr><td style="' . $cell . '">' . esc_html( $label ) . '</td><td style="' . $right . '">' . esc_html( $value ) . '</td></tr>';
		};
		$table = static function ( $rows ) {
			return '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">' . $rows . '</table>';
		};
		$out   = '<p style="margin:0 0 4px;"><strong>' . esc_html( $report['label'] ) . '</strong></p>';
		$rows  = $row( __( 'Total sales', 'nexora-commerce-toolkit' ), $m( $s['sales'] ) )
			. $row( __( 'Orders', 'nexora-commerce-toolkit' ), number_format_i18n( $s['orders'] ) )
			. $row( __( 'Average order', 'nexora-commerce-toolkit' ), $m( $s['average'] ) )
			. $row( __( 'Items sold', 'nexora-commerce-toolkit' ), number_format_i18n( $s['items'] ) )
			. $row( __( 'Discounts', 'nexora-commerce-toolkit' ), $m( $s['discount'] ) )
			. $row( __( 'Refunds', 'nexora-commerce-toolkit' ), $m( $s['refunds'] ) )
			. $row( __( 'Cancelled orders', 'nexora-commerce-toolkit' ), number_format_i18n( $s['cancelled'] ) )
			. $row( __( 'Total sales the day before', 'nexora-commerce-toolkit' ), $m( $report['before']['sales'] ) )
			. $row( __( 'Total sales the same day last week', 'nexora-commerce-toolkit' ), $m( $report['last_week']['sales'] ) );
		$out  .= $table( $rows );
		if ( $s['products'] ) {
			$rows = '';
			foreach ( array_slice( $s['products'], 0, 5 ) as $p ) {
				/* translators: 1: units sold, 2: amount */
				$rows .= $row( $p['name'], sprintf( __( '%1$s sold, %2$s', 'nexora-commerce-toolkit' ), number_format_i18n( $p['units'] ), $m( $p['sales'] ) ) );
			}
			$out .= '<h3 style="' . $h . '">' . esc_html__( 'Top products', 'nexora-commerce-toolkit' ) . '</h3>' . $table( $rows );
		}
		if ( $s['payments'] || $s['fulfilment'] ) {
			$rows = '';
			foreach ( $s['payments'] as $name => $g ) {
				$rows .= $row( $name, $m( $g['sales'] ) );
			}
			foreach ( $s['fulfilment'] as $type => $g ) {
				$rows .= $row( Nexora_Toolkit_Analytics_Data::fulfilment_label( $type ), $m( $g['sales'] ) );
			}
			$out .= '<h3 style="' . $h . '">' . esc_html__( 'Payment and pickup', 'nexora-commerce-toolkit' ) . '</h3>' . $table( $rows );
		}
		$rows = $row( __( 'New reviews', 'nexora-commerce-toolkit' ), number_format_i18n( $report['reviews']['total'] ) )
			. $row( __( 'Waiting for approval', 'nexora-commerce-toolkit' ), number_format_i18n( $report['waiting'] ) );
		$out .= '<h3 style="' . $h . '">' . esc_html__( 'Reviews', 'nexora-commerce-toolkit' ) . '</h3>' . $table( $rows );
		if ( $report['stock']['rows'] ) {
			$rows = '';
			foreach ( $report['stock']['rows'] as $line ) {
				$rows .= $row( $line->post_title, 'outofstock' === $line->status ? __( 'Sold out', 'nexora-commerce-toolkit' ) : number_format_i18n( (int) $line->stock ) );
			}
			$out .= '<h3 style="' . $h . '">' . esc_html__( 'Low stock', 'nexora-commerce-toolkit' ) . '</h3>' . $table( $rows );
		}
		if ( null !== $report['scheduled'] ) {
			/* translators: 1: number of orders, 2: date */
			$out .= '<p style="margin:18px 0 0;">' . esc_html( sprintf( _n( '%1$s order is scheduled for pickup or rider collection on %2$s.', '%1$s orders are scheduled for pickup or rider collection on %2$s.', $report['scheduled'], 'nexora-commerce-toolkit' ), number_format_i18n( $report['scheduled'] ), wp_date( get_option( 'date_format' ), $report['date']->modify( '+1 day' )->getTimestamp() ) ) ) . '</p>';
		}
		$url  = add_query_arg(
			array(
				'page' => Nexora_Toolkit_Analytics::PAGE,
				'tab'  => 'daily',
				'date' => $report['date']->format( 'Y-m-d' ),
			),
			admin_url( 'admin.php' )
		);
		$out .= '<p style="margin:18px 0 0;"><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the full report', 'nexora-commerce-toolkit' ) . '</a></p>';
		return $out;
	}

	/**
	 * Save the settings.
	 */
	public static function handle_settings() {
		if ( ! current_user_can( Nexora_Toolkit_Analytics::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_report_settings' );
		$s               = self::settings();
		$s['recipients'] = implode( ', ', self::clean_recipients( isset( $_POST['recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['recipients'] ) ) : '' ) );
		$s['enabled']    = ! empty( $_POST['enabled'] ) && '' !== $s['recipients'];
		$s['hour']       = isset( $_POST['hour'] ) ? min( 23, absint( $_POST['hour'] ) ) : 6;
		update_option( self::OPTION, $s, false );
		self::reschedule();
		$msg = ! empty( $_POST['enabled'] ) && '' === $s['recipients'] ? 'norecipient' : 'saved';
		self::back( array( 'nx_msg' => $msg ) );
	}

	/**
	 * Send a report now.
	 */
	public static function handle_send() {
		if ( ! current_user_can( Nexora_Toolkit_Analytics::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_report_send' );
		$day = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$s   = self::settings();
		$to  = '' !== $s['recipients'] ? $s['recipients'] : wp_get_current_user()->user_email;
		$ok  = self::send( $day, $to );
		// A manual resend of an older day must not stop this morning's automatic report.
		if ( $ok && $day !== current_datetime()->modify( '-1 day' )->format( 'Y-m-d' ) ) {
			$now             = self::settings();
			$now['last_day'] = $s['last_day'];
			update_option( self::OPTION, $now, false );
		}
		self::back(
			array(
				'nx_msg' => $ok ? 'sent' : 'failed',
				'date'   => $day,
			)
		);
	}

	/**
	 * Return to the screen.
	 *
	 * @param array $args Query arguments.
	 */
	private static function back( $args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page' => Nexora_Toolkit_Analytics::PAGE,
						'tab'  => 'daily',
					),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
