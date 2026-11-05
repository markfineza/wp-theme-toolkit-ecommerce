<?php
/**
 * Shipping methods: courier delivery booked by the customer, and store pickup.
 *
 * Both are zone methods (WooCommerce → Settings → Shipping → zone → Add shipping method) with their
 * own schedule: lead time, days ahead, closed weekdays and time slots. The checkout fields, validation
 * and order details live in Nexora_Toolkit_Fulfilment.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared settings and scheduling.
 */
abstract class Nexora_Toolkit_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Fulfilment type: courier|pickup.
	 *
	 * @var string
	 */
	public $kind = '';

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->instance_id = absint( $instance_id );
		$this->supports    = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}

	/**
	 * Load settings.
	 */
	public function init() {
		$this->instance_form_fields = array_merge( $this->method_fields(), $this->schedule_fields() );
		$this->title                = $this->get_option( 'title', $this->method_title );
		$this->tax_status           = $this->get_option( 'tax_status', 'none' );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Method-specific settings.
	 *
	 * @return array
	 */
	abstract protected function method_fields();

	/**
	 * Schedule settings shared by both methods.
	 *
	 * @return array
	 */
	protected function schedule_fields() {
		global $wp_locale;
		$days = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$days[ (string) $i ] = $wp_locale->get_weekday( $i );
		}
		return array(
			'fee'          => array(
				'title'       => __( 'Fee (₱)', 'nexora-commerce-toolkit' ),
				'type'        => 'price',
				'default'     => '0',
				'placeholder' => '0',
				'description' => __( 'Charged by the store, for example packing. Leave 0 for free.', 'nexora-commerce-toolkit' ),
				'desc_tip'    => true,
			),
			'tax_status'   => array(
				'title'   => __( 'Tax status', 'nexora-commerce-toolkit' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'none',
				'options' => array(
					'taxable' => __( 'Taxable', 'nexora-commerce-toolkit' ),
					'none'    => _x( 'None', 'Tax status', 'nexora-commerce-toolkit' ),
				),
			),
			'min_amount'   => array(
				'title'       => __( 'Minimum order (₱)', 'nexora-commerce-toolkit' ),
				'type'        => 'price',
				'default'     => '',
				'placeholder' => __( 'No minimum', 'nexora-commerce-toolkit' ),
				'description' => __( 'Hide this option when the cart subtotal is below this amount.', 'nexora-commerce-toolkit' ),
				'desc_tip'    => true,
			),
			'lead_hours'   => array(
				'title'             => __( 'Preparation time (hours)', 'nexora-commerce-toolkit' ),
				'type'              => 'number',
				'default'           => '2',
				'custom_attributes' => array( 'min' => '0', 'max' => '168', 'step' => '1' ),
				'description'       => __( 'The earliest time slot offered starts at least this many hours after the order is placed.', 'nexora-commerce-toolkit' ),
				'desc_tip'          => true,
			),
			'days_ahead'   => array(
				'title'             => __( 'Days customers can book ahead', 'nexora-commerce-toolkit' ),
				'type'              => 'number',
				'default'           => '6',
				'custom_attributes' => array( 'min' => '0', 'max' => '30', 'step' => '1' ),
			),
			'closed_days'  => array(
				'title'   => __( 'Closed on', 'nexora-commerce-toolkit' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'default' => array(),
				'options' => $days,
			),
			'slots'        => array(
				'title'       => __( 'Time slots', 'nexora-commerce-toolkit' ),
				'type'        => 'textarea',
				'default'     => "08:00-10:00\n10:00-12:00\n13:00-15:00\n15:00-17:00",
				'description' => __( 'One per line, 24-hour time, for example 08:00-10:00.', 'nexora-commerce-toolkit' ),
				'css'         => 'min-height:110px',
			),
			'instructions' => array(
				'title'       => __( 'Instructions at checkout', 'nexora-commerce-toolkit' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'Optional note shown under the schedule, for example parking or which gate to use.', 'nexora-commerce-toolkit' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Sanitize the slots textarea on save.
	 *
	 * @param string $key   Field key.
	 * @param string $value Raw value.
	 * @return string
	 */
	public function validate_slots_field( $key, $value ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$slot = self::parse_slot( $line );
			if ( $slot ) {
				$lines[] = $slot['key'];
			}
		}
		return implode( "\n", array_unique( $lines ) );
	}

	/**
	 * Parse "08:00-10:00" into start/end minutes.
	 *
	 * @param string $line Raw line.
	 * @return array|null
	 */
	public static function parse_slot( $line ) {
		if ( ! preg_match( '/^\s*(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})\s*$/u', (string) $line, $m ) ) {
			return null;
		}
		$start = (int) $m[1] * 60 + (int) $m[2];
		$end   = (int) $m[3] * 60 + (int) $m[4];
		if ( $start >= $end || $end > 24 * 60 || (int) $m[2] > 59 || (int) $m[4] > 59 ) {
			return null;
		}
		return array(
			'key'   => sprintf( '%02d:%02d-%02d:%02d', $m[1], $m[2], $m[3], $m[4] ),
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Human label for a slot key such as "08:00-10:00".
	 *
	 * @param string $key Slot key.
	 * @return string
	 */
	public static function slot_label( $key ) {
		$slot = self::parse_slot( $key );
		if ( ! $slot ) {
			return (string) $key;
		}
		$format = get_option( 'time_format', 'g:i a' );
		$base   = new DateTimeImmutable( '2000-01-01 00:00', wp_timezone() );
		return sprintf(
			/* translators: 1: start time, 2: end time */
			__( '%1$s – %2$s', 'nexora-commerce-toolkit' ),
			wp_date( $format, $base->modify( '+' . $slot['start'] . ' minutes' )->getTimestamp() ),
			wp_date( $format, $base->modify( '+' . $slot['end'] . ' minutes' )->getTimestamp() )
		);
	}

	/**
	 * Bookable dates and slots, respecting preparation time and closed days.
	 *
	 * @return array<string,array{label:string,slots:array<string,string>}> Keyed by Y-m-d.
	 */
	public function schedule() {
		$slots = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $this->get_option( 'slots' ) ) as $line ) {
			$slot = self::parse_slot( $line );
			if ( $slot ) {
				$slots[ $slot['key'] ] = $slot;
			}
		}
		uasort(
			$slots,
			static function ( $a, $b ) {
				return $a['start'] - $b['start'];
			}
		);

		$now      = current_datetime();
		$earliest = $now->modify( '+' . max( 0, (int) $this->get_option( 'lead_hours', 2 ) ) . ' hours' );
		$closed   = array_map( 'strval', (array) $this->get_option( 'closed_days', array() ) );
		$ahead    = min( 30, max( 0, (int) $this->get_option( 'days_ahead', 6 ) ) );
		$today    = $now->setTime( 0, 0 );
		$out      = array();

		// Look a little past the window so a long preparation time still leaves bookable days.
		for ( $d = 0; $d <= $ahead + 7 && count( $out ) <= $ahead; $d++ ) {
			$day = $today->modify( '+' . $d . ' days' );
			if ( in_array( $day->format( 'w' ), $closed, true ) ) {
				continue;
			}
			$open = array();
			foreach ( $slots as $key => $slot ) {
				if ( $day->modify( '+' . $slot['start'] . ' minutes' ) >= $earliest ) {
					$open[ $key ] = self::slot_label( $key );
				}
			}
			if ( $open ) {
				$out[ $day->format( 'Y-m-d' ) ] = array(
					'label' => self::date_label( $day->format( 'Y-m-d' ) ),
					'slots' => $open,
				);
			}
		}

		return apply_filters( 'nexora_fulfilment_schedule', $out, $this );
	}

	/**
	 * "Today · Tue, Oct 27" style date label.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	public static function date_label( $ymd ) {
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, wp_timezone() );
		if ( ! $day ) {
			return (string) $ymd;
		}
		$label = wp_date( _x( 'D, M j', 'pickup date format', 'nexora-commerce-toolkit' ), $day->getTimestamp() );
		$today = current_datetime()->setTime( 0, 0 );
		if ( $day == $today ) { // phpcs:ignore Universal.Operators.StrictComparisons -- compares DateTime values.
			/* translators: %s: date */
			return sprintf( __( 'Today · %s', 'nexora-commerce-toolkit' ), $label );
		}
		if ( $day == $today->modify( '+1 day' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
			/* translators: %s: date */
			return sprintf( __( 'Tomorrow · %s', 'nexora-commerce-toolkit' ), $label );
		}
		return $label;
	}

	/**
	 * Hide when the cart is below the minimum.
	 *
	 * @param array $package Package.
	 * @return bool
	 */
	public function is_available( $package ) {
		$min = (float) $this->get_option( 'min_amount', 0 );
		if ( $min > 0 && WC()->cart && (float) WC()->cart->get_displayed_subtotal() < $min ) {
			return false;
		}
		return parent::is_available( $package );
	}

	/**
	 * Offer one rate.
	 *
	 * @param array $package Package.
	 */
	public function calculate_shipping( $package = array() ) {
		$this->add_rate(
			array(
				'label'     => $this->title,
				'cost'      => max( 0, (float) $this->get_option( 'fee', 0 ) ),
				'package'   => $package,
				'meta_data' => array( 'nexora_fulfilment' => $this->kind ),
			)
		);
	}

	/**
	 * One-line description for the checkout option card.
	 *
	 * @return string
	 */
	abstract public function summary();
}

/**
 * Courier delivery booked and paid by the customer (Lalamove, Grab Express and so on).
 */
class Nexora_Toolkit_Shipping_Courier extends Nexora_Toolkit_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = Nexora_Toolkit_Fulfilment::COURIER;
		$this->kind               = 'courier';
		$this->method_title       = __( 'Courier delivery (customer books)', 'nexora-commerce-toolkit' );
		$this->method_description = __( 'The customer books and pays a courier such as Lalamove or Grab Express to collect the order from your store at a chosen time. The store charges nothing for delivery unless you set a fee.', 'nexora-commerce-toolkit' );
		parent::__construct( $instance_id );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	protected function method_fields() {
		return array(
			'title'         => array(
				'title'   => __( 'Name at checkout', 'nexora-commerce-toolkit' ),
				'type'    => 'text',
				'default' => __( 'Courier delivery — you book the rider', 'nexora-commerce-toolkit' ),
			),
			'couriers'      => array(
				'title'       => __( 'Couriers customers can choose', 'nexora-commerce-toolkit' ),
				'type'        => 'textarea',
				'default'     => "Lalamove\nGrab Express\nTransportify\nBorzo\nJoyRide\nMove It",
				'description' => __( 'One per line. Customers can also choose “Other”.', 'nexora-commerce-toolkit' ),
				'css'         => 'min-height:110px',
			),
			'pickup_point'  => array(
				'title'       => __( 'Rider pickup address', 'nexora-commerce-toolkit' ),
				'type'        => 'textarea',
				'default'     => '',
				'placeholder' => Nexora_Toolkit_Fulfilment::store_address(),
				'description' => __( 'Where the customer tells the rider to collect the order. Leave empty to use the store address from WooCommerce → Settings → General.', 'nexora-commerce-toolkit' ),
				'desc_tip'    => true,
			),
			'contact_phone' => array(
				'title'       => __( 'Store contact number for riders', 'nexora-commerce-toolkit' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '+63 9XX XXX XXXX',
			),
		);
	}

	/**
	 * Courier names.
	 *
	 * @return string[]
	 */
	public function couriers() {
		$list = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $this->get_option( 'couriers' ) ) ) );
		return array_values( array_unique( array_map( 'sanitize_text_field', $list ) ) );
	}

	/**
	 * Where the rider collects the order.
	 *
	 * @return string
	 */
	public function pickup_point() {
		$point = trim( (string) $this->get_option( 'pickup_point' ) );
		return '' !== $point ? $point : Nexora_Toolkit_Fulfilment::store_address();
	}

	/**
	 * Card description.
	 *
	 * @return string
	 */
	public function summary() {
		return __( 'Book Lalamove, Grab or any courier yourself. We pack the order and hand it to your rider at the time you pick.', 'nexora-commerce-toolkit' );
	}
}

/**
 * Store pickup with one or more pickup points.
 */
class Nexora_Toolkit_Shipping_Pickup extends Nexora_Toolkit_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = Nexora_Toolkit_Fulfilment::PICKUP;
		$this->kind               = 'pickup';
		$this->method_title       = __( 'Store pickup (scheduled)', 'nexora-commerce-toolkit' );
		$this->method_description = __( 'Customers collect the order themselves at a pickup point and time they choose at checkout. Works with the classic checkout used by the Freshtastic theme.', 'nexora-commerce-toolkit' );
		parent::__construct( $instance_id );
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	protected function method_fields() {
		return array(
			'title'     => array(
				'title'   => __( 'Name at checkout', 'nexora-commerce-toolkit' ),
				'type'    => 'text',
				'default' => __( 'Store pickup', 'nexora-commerce-toolkit' ),
			),
			'locations' => array(
				'title'       => __( 'Pickup points', 'nexora-commerce-toolkit' ),
				'type'        => 'textarea',
				'default'     => '',
				'placeholder' => __( 'Main stall | Stall 12, Farmers Market, Cubao, Quezon City | Mon–Sat 6am–5pm', 'nexora-commerce-toolkit' ),
				'description' => __( 'One per line: Name | Address | Opening hours. Leave empty to use the store address.', 'nexora-commerce-toolkit' ),
				'css'         => 'min-height:110px',
			),
		);
	}

	/**
	 * Pickup points.
	 *
	 * @return array<int,array{name:string,address:string,hours:string}>
	 */
	public function locations() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $this->get_option( 'locations' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$out[] = array(
				'name'    => sanitize_text_field( $parts[0] ),
				'address' => isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '',
				'hours'   => isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : '',
			);
		}
		if ( ! $out ) {
			$out[] = array(
				'name'    => get_bloginfo( 'name' ),
				'address' => Nexora_Toolkit_Fulfilment::store_address(),
				'hours'   => '',
			);
		}
		return $out;
	}

	/**
	 * Card description.
	 *
	 * @return string
	 */
	public function summary() {
		$locations = $this->locations();
		if ( 1 === count( $locations ) && $locations[0]['address'] ) {
			/* translators: %s: pickup address */
			return sprintf( __( 'Collect it yourself at %s.', 'nexora-commerce-toolkit' ), $locations[0]['address'] );
		}
		/* translators: %d: number of pickup points */
		return sprintf( _n( 'Collect it yourself at our pickup point.', 'Collect it yourself at any of our %d pickup points.', count( $locations ), 'nexora-commerce-toolkit' ), count( $locations ) );
	}
}
