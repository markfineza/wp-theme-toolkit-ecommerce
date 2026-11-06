<?php
/**
 * Orders list table.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orders table.
 */
class Nexora_Store_Orders_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox">',
			'order_number'  => __( 'Order', 'nexora-commerce-toolkit' ),
			'order_date'    => __( 'Date', 'nexora-commerce-toolkit' ),
			'order_status'  => __( 'Status', 'nexora-commerce-toolkit' ),
			'nx_fulfilment' => __( 'Fulfilment', 'nexora-commerce-toolkit' ),
			'order_total'   => __( 'Total', 'nexora-commerce-toolkit' ),
			'nx_actions'    => __( 'Actions', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$this->process_bulk_action();
		$where  = array( '1=1' );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( $status && isset( Nexora_Store_Orders::statuses()[ $status ] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		if ( in_array( $type, array( 'pickup', 'courier', 'delivery' ), true ) ) {
			$where[] = $wpdb->prepare( 'fulfilment_type = %s', $type );
		}
		$when  = isset( $_GET['when'] ) ? sanitize_key( wp_unslash( $_GET['when'] ) ) : '';
		$today = current_datetime();
		if ( 'today' === $when || 'tomorrow' === $when ) {
			$where[] = $wpdb->prepare( "fulfilment_date = %s AND status IN ('processing','nx-ready')", ( 'today' === $when ? $today : $today->modify( '+1 day' ) )->format( 'Y-m-d' ) );
		} elseif ( 'overdue' === $when ) {
			$where[] = $wpdb->prepare( "fulfilment_date < %s AND status IN ('processing','nx-ready')", $today->format( 'Y-m-d' ) );
		}
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(id = %d OR email LIKE %s OR phone LIKE %s OR address LIKE %s)', absint( ltrim( $search, '#' ) ), $like, $like, $like );
		}
		$sql_where = implode( ' AND ', $where );
		$per       = 20;
		$page      = $this->get_pagenum();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from prepared parts.
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nx_orders WHERE {$sql_where}" );
		$ids         = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nx_orders WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) );
		// phpcs:enable
		$this->items = array_filter( array_map( array( 'Nexora_Store_Orders', 'get' ), $ids ) );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		// phpcs:enable
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'processing' => __( 'Change status to processing', 'nexora-commerce-toolkit' ),
			'nx-ready'   => __( 'Change status to ready for pickup', 'nexora-commerce-toolkit' ),
			'completed'  => __( 'Change status to completed', 'nexora-commerce-toolkit' ),
			'cancelled'  => __( 'Change status to cancelled', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Run a bulk action sent from the list. WP_List_Table adds its own nonce (bulk-orders) to the form.
	 */
	public function process_bulk_action() {
		$action = $this->current_action();
		if ( ! $action || ! isset( Nexora_Store_Orders::statuses()[ $action ] ) || empty( $_REQUEST['ids'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-orders' );
		if ( ! current_user_can( Nexora_Store_Admin::CAP_ORDERS ) ) {
			return;
		}
		$done = 0;
		foreach ( array_slice( array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ), 0, 100 ) as $id ) {
			if ( ! is_wp_error( Nexora_Store_Orders::set_status( $id, $action ) ) ) {
				++$done;
			}
		}
		/* translators: %d: orders */
		echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d order updated.', '%d orders updated.', $done, 'nexora-commerce-toolkit' ), $done ) ) . '</p></div>';
	}

	/**
	 * Status tabs.
	 *
	 * @return array
	 */
	protected function get_views() {
		$k      = Nexora_Store_Admin::kpis();
		$base   = admin_url( 'admin.php?page=nexora-store-orders' );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$views  = array(
			'all' => sprintf( '<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>', esc_url( $base ), '' === $status ? ' class="current"' : '', esc_html__( 'All', 'nexora-commerce-toolkit' ), array_sum( $k['counts'] ) ),
		);
		foreach ( Nexora_Store_Orders::statuses() as $key => $label ) {
			if ( ! empty( $k['counts'][ $key ] ) ) {
				$views[ $key ] = sprintf( '<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>', esc_url( add_query_arg( 'status', $key, $base ) ), $status === $key ? ' class="current"' : '', esc_html( $label ), $k['counts'][ $key ] );
			}
		}
		return $views;
	}

	/**
	 * Filters.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$when = isset( $_GET['when'] ) ? sanitize_key( wp_unslash( $_GET['when'] ) ) : '';
		// phpcs:enable
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="nx-filter-type"><?php esc_html_e( 'Filter by fulfilment', 'nexora-commerce-toolkit' ); ?></label>
			<select name="type" id="nx-filter-type">
				<option value=""><?php esc_html_e( 'All fulfilment', 'nexora-commerce-toolkit' ); ?></option>
				<option value="pickup" <?php selected( $type, 'pickup' ); ?>><?php esc_html_e( 'Store pickup', 'nexora-commerce-toolkit' ); ?></option>
				<option value="courier" <?php selected( $type, 'courier' ); ?>><?php esc_html_e( 'Courier (customer books)', 'nexora-commerce-toolkit' ); ?></option>
				<option value="delivery" <?php selected( $type, 'delivery' ); ?>><?php esc_html_e( 'Store delivery', 'nexora-commerce-toolkit' ); ?></option>
			</select>
			<label class="screen-reader-text" for="nx-filter-when"><?php esc_html_e( 'Filter by schedule', 'nexora-commerce-toolkit' ); ?></label>
			<select name="when" id="nx-filter-when">
				<option value=""><?php esc_html_e( 'Any schedule', 'nexora-commerce-toolkit' ); ?></option>
				<option value="today" <?php selected( $when, 'today' ); ?>><?php esc_html_e( 'Scheduled today', 'nexora-commerce-toolkit' ); ?></option>
				<option value="tomorrow" <?php selected( $when, 'tomorrow' ); ?>><?php esc_html_e( 'Scheduled tomorrow', 'nexora-commerce-toolkit' ); ?></option>
				<option value="overdue" <?php selected( $when, 'overdue' ); ?>><?php esc_html_e( 'Overdue (not collected)', 'nexora-commerce-toolkit' ); ?></option>
			</select>
			<?php submit_button( __( 'Filter', 'nexora-commerce-toolkit' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Checkbox.
	 *
	 * @param object $item Order.
	 * @return string
	 */
	protected function column_cb( $item ) {
		/* translators: %d: order */
		return '<label class="screen-reader-text" for="nx-o-' . (int) $item->id . '">' . esc_html( sprintf( __( 'Select order %d', 'nexora-commerce-toolkit' ), $item->id ) ) . '</label><input type="checkbox" id="nx-o-' . (int) $item->id . '" name="ids[]" value="' . (int) $item->id . '">';
	}

	/**
	 * Order cell.
	 *
	 * @param object $item Order.
	 * @return string
	 */
	protected function column_order_number( $item ) {
		$a    = $item->address;
		$name = trim( ( isset( $a['first_name'] ) ? $a['first_name'] : '' ) . ' ' . ( isset( $a['last_name'] ) ? $a['last_name'] : '' ) );
		return '<a class="order-view" href="' . esc_url( admin_url( 'admin.php?page=nexora-store-orders&order=' . (int) $item->id ) ) . '"><strong>#' . (int) $item->id . ' ' . esc_html( $name ) . '</strong></a>';
	}

	/**
	 * Date cell.
	 *
	 * @param object $item Order.
	 * @return string
	 */
	protected function column_order_date( $item ) {
		$ts = strtotime( $item->created_at . ' UTC' );
		return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( time() - $ts < DAY_IN_SECONDS ? sprintf( /* translators: %s: time */ __( '%s ago', 'nexora-commerce-toolkit' ), human_time_diff( $ts ) ) : wp_date( get_option( 'date_format' ), $ts ) ) . '</time>';
	}

	/**
	 * Status cell.
	 *
	 * @param object $item Order.
	 * @return string
	 */
	protected function column_order_status( $item ) {
		return '<mark class="order-status status-' . esc_attr( $item->status ) . '"><span>' . esc_html( Nexora_Store_Orders::status_label( $item->status ) ) . '</span></mark>';
	}

	/**
	 * Fulfilment cell.
	 *
	 * @param object $item Order.
	 * @return string
	 */
	protected function column_nx_fulfilment( $item ) {
		$f = $item->fulfilment;
		if ( empty( $f['type'] ) ) {
			return '<span class="nx-ful nx-ful--none">—</span>';
		}
		$labels  = array(
			'pickup'   => __( 'Pickup', 'nexora-commerce-toolkit' ),
			'courier'  => __( 'Courier', 'nexora-commerce-toolkit' ),
			'delivery' => __( 'Delivery', 'nexora-commerce-toolkit' ),
		);
		$overdue = ! empty( $f['date'] ) && $f['date'] < current_datetime()->format( 'Y-m-d' ) && in_array( $item->status, array( 'processing', 'nx-ready' ), true );
		$when    = Nexora_Store_Orders::when( $item );
		$meta    = ! empty( $f['location'] ) ? $f['location'] : ( ! empty( $f['courier'] ) ? $f['courier'] : '' );
		return sprintf(
			'<span class="nx-ful nx-ful--%1$s%2$s"><span class="nx-ful__badge">%3$s</span>%4$s<span class="nx-ful__meta">%5$s</span></span>',
			esc_attr( $f['type'] ),
			$overdue ? ' is-overdue' : '',
			esc_html( isset( $labels[ $f['type'] ] ) ? $labels[ $f['type'] ] : $f['type'] ),
			$when ? '<span class="nx-ful__when">' . esc_html( $when ) . ( $overdue ? ' · ' . esc_html__( 'overdue', 'nexora-commerce-toolkit' ) : '' ) . '</span>' : '',
			esc_html( $meta )
		);
	}

	/**
	 * Total cell.
	 *
	 * @param object $item Order.
	 * @return string
	 */
	protected function column_order_total( $item ) {
		return esc_html( Nexora_Store::money( $item->total ) );
	}

	/**
	 * Quick actions.
	 *
	 * @param object $item Order.
	 * @return string
	 */
	protected function column_nx_actions( $item ) {
		$url  = static function ( $to ) use ( $item ) {
			return wp_nonce_url( add_query_arg( array( 'action' => 'nexora_store_bulk', 'ids[]' => (int) $item->id, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'nexora_store_bulk', '_nx_bulk' );
		};
		$out = '';
		if ( in_array( $item->status, array( 'on-hold', 'pending' ), true ) ) {
			$out .= '<a class="button button-small" href="' . esc_url( $url( 'processing' ) ) . '">' . esc_html__( 'Paid: start packing', 'nexora-commerce-toolkit' ) . '</a> ';
		}
		if ( 'processing' === $item->status ) {
			$out .= '<a class="button button-small" href="' . esc_url( $url( 'nx-ready' ) ) . '">' . esc_html__( 'Ready', 'nexora-commerce-toolkit' ) . '</a> ';
		}
		if ( in_array( $item->status, array( 'processing', 'nx-ready' ), true ) ) {
			$out .= '<a class="button button-small" href="' . esc_url( $url( 'completed' ) ) . '">' . esc_html__( 'Complete', 'nexora-commerce-toolkit' ) . '</a>';
		}
		return $out;
	}

	/**
	 * Fallback.
	 *
	 * @param object $item        Order.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}
}
