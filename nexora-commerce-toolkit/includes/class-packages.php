<?php
/**
 * Packages: a product with its own price that contains other products ("Package A: 2 x Tilapia + 1 x Rice").
 *
 * A package is an ordinary one-price product plus a list of contents in product meta, so it keeps
 * working when a store switches between WooCommerce and the built-in engine. It holds no stock of its own:
 * availability comes from its contents, and buying one takes stock from each of them. Orders keep a copy
 * of the contents so a later edit cannot change what a cancellation gives back.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Package products.
 */
class Nexora_Toolkit_Packages {

	const META     = '_nx_package_items';
	const SNAPSHOT = '_nx_package_contents';
	const REDUCED  = '_nx_package_reduced';
	const NOTICE   = 'nx_package_notice_';

	/**
	 * Stops our own product save from re-entering.
	 *
	 * @var bool
	 */
	private static $saving = false;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'box_register' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 99, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );

		if ( class_exists( 'WooCommerce' ) ) {
			add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'wc_in_stock' ), 10, 2 );
			add_filter( 'woocommerce_quantity_input_max', array( __CLASS__, 'wc_quantity_max' ), 10, 2 );
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'wc_validate_add' ), 10, 3 );
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'wc_check_cart' ) );
			add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'wc_item_data' ), 10, 2 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'wc_order_line' ), 10, 3 );
			add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'wc_reduce' ) );
			add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'wc_restore' ) );
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single' ), 22, 0 );
		}
	}

	/* ------------------------------------------------------------------ data */

	/**
	 * A product or variation from the active engine.
	 *
	 * @param int $id ID.
	 * @return WC_Product|Nexora_Store_Product|null
	 */
	public static function item( $id ) {
		$item = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : Nexora_Store_Product::get( $id );
		return $item ? $item : null;
	}

	/**
	 * Saved contents.
	 *
	 * @param int $id Product ID.
	 * @return array<int,array{id:int,qty:int}>
	 */
	public static function contents( $id ) {
		$rows = get_post_meta( $id, self::META, true );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Is this product a package?
	 *
	 * @param int $id Product ID.
	 * @return bool
	 */
	public static function is_package( $id ) {
		return (bool) self::contents( $id );
	}

	/**
	 * Display name, with the option for a variation.
	 *
	 * @param int $id Product or variation ID.
	 * @return string
	 */
	public static function name( $id ) {
		$item = self::item( $id );
		if ( ! $item ) {
			return __( 'Removed product', 'nexora-commerce-toolkit' );
		}
		if ( $item instanceof Nexora_Store_Product && $item->get_parent_id() ) {
			return $item->source()->get_name() . ' — ' . $item->option_label();
		}
		return wp_strip_all_tags( $item->get_name() );
	}

	/**
	 * One line such as "2 × Tilapia, 1 × Rice".
	 *
	 * @param array $rows Contents.
	 * @return string
	 */
	public static function summary( $rows ) {
		$parts = array();
		foreach ( $rows as $row ) {
			$parts[] = (int) $row['qty'] . ' × ' . self::name( (int) $row['id'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Can this content item be sold at all?
	 *
	 * @param WC_Product|Nexora_Store_Product|null $item Item.
	 * @return bool
	 */
	private static function sellable( $item ) {
		if ( ! $item || 'publish' !== get_post_status( $item->get_id() ) || ! $item->is_in_stock() ) {
			return false;
		}
		return ! $item->get_parent_id() || 'publish' === get_post_status( $item->get_parent_id() );
	}

	/**
	 * How many of this package can be sold now: 0 when something inside is unavailable, null for no limit.
	 *
	 * @param int $id Package ID.
	 * @return int|null
	 */
	public static function max_qty( $id ) {
		$max = null;
		foreach ( self::contents( $id ) as $row ) {
			$item = self::item( (int) $row['id'] );
			if ( ! self::sellable( $item ) ) {
				return 0;
			}
			if ( $item->managing_stock() && ! $item->backorders_allowed() ) {
				$can = (int) floor( (float) $item->get_stock_quantity() / max( 1, (int) $row['qty'] ) );
				$max = null === $max ? $can : min( $max, $can );
			}
		}
		return $max;
	}

	/**
	 * Is at least one available?
	 *
	 * @param int $id Package ID.
	 * @return bool
	 */
	public static function available( $id ) {
		$max = self::max_qty( $id );
		return null === $max || $max > 0;
	}

	/**
	 * Money for display.
	 *
	 * @param float $amount Amount.
	 * @return string HTML.
	 */
	public static function money( $amount ) {
		return function_exists( 'wc_price' ) ? wc_price( $amount ) : esc_html( Nexora_Store::money( $amount ) );
	}

	/**
	 * What the contents cost when bought one by one.
	 *
	 * @param int $id Package ID.
	 * @return float
	 */
	public static function separate_total( $id ) {
		$total = 0.0;
		foreach ( self::contents( $id ) as $row ) {
			$item   = self::item( (int) $row['id'] );
			$total += $item ? (float) $item->get_price() * (int) $row['qty'] : 0.0;
		}
		return $total;
	}

	/**
	 * Published, visible packages in menu order, then by name.
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public static function ids( $limit = 12 ) {
		return get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'fields'           => 'ids',
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Packages that contain this product or one of its options.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	public static function containing( $product_id ) {
		$item = self::item( $product_id );
		if ( ! $item ) {
			return array();
		}
		$own = array( (int) $product_id );
		foreach ( get_children( array( 'post_parent' => $product_id, 'post_type' => 'product_variation', 'fields' => 'ids' ) ) as $child ) {
			$own[] = (int) $child;
		}
		$found = array();
		foreach ( self::ids( 100 ) as $package ) {
			foreach ( self::contents( $package ) as $row ) {
				if ( in_array( (int) $row['id'], $own, true ) ) {
					$found[] = (int) $package;
					break;
				}
			}
		}
		return $found;
	}

	/* -------------------------------------------------------------- storefront */

	/**
	 * Styles on product pages.
	 */
	public static function assets() {
		if ( is_singular( 'product' ) ) {
			wp_enqueue_style( 'nexora-packages', NEXORA_TOOLKIT_URL . 'assets/css/packages.css', array(), NEXORA_TOOLKIT_VERSION );
		}
	}

	/**
	 * On a product page: what is inside a package, or which packages include this product.
	 */
	public static function render_single() {
		$id = get_the_ID();
		if ( ! self::is_package( $id ) ) {
			$packages = array_filter(
				self::containing( $id ),
				static function ( $package ) {
					return self::available( $package );
				}
			);
			if ( $packages ) {
				echo '<div class="nx-package-box"><p class="nx-package-box__title">' . esc_html__( 'Also in these packages', 'nexora-commerce-toolkit' ) . '</p><ul>';
				foreach ( $packages as $package ) {
					echo '<li><a href="' . esc_url( (string) get_permalink( $package ) ) . '">' . esc_html( get_the_title( $package ) ) . '</a></li>';
				}
				echo '</ul></div>';
			}
			return;
		}
		$item = self::item( $id );
		$save = $item ? self::separate_total( $id ) - (float) $item->get_price() : 0.0;
		?>
		<div class="nx-package-box">
			<p class="nx-package-box__title"><?php esc_html_e( "What's in this package", 'nexora-commerce-toolkit' ); ?></p>
			<ul>
				<?php foreach ( self::contents( $id ) as $row ) : ?>
					<?php
					$part = self::item( (int) $row['id'] );
					$link = $part ? (string) get_permalink( $part->get_parent_id() ? $part->get_parent_id() : $part->get_id() ) : '';
					?>
					<li>
						<span class="nx-package-box__qty"><?php echo (int) $row['qty']; ?> ×</span>
						<?php if ( $link ) : ?>
							<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( self::name( (int) $row['id'] ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( self::name( (int) $row['id'] ) ); ?>
						<?php endif; ?>
						<?php if ( ! self::sellable( $part ) ) : ?>
							<span class="nx-package-box__out"><?php esc_html_e( 'Unavailable', 'nexora-commerce-toolkit' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $save > 0.004 ) : ?>
				<?php /* translators: 1: total when bought separately, 2: amount saved */ ?>
				<p class="nx-package-box__save"><?php echo wp_kses_post( sprintf( __( 'Bought separately: %1$s. You save %2$s.', 'nexora-commerce-toolkit' ), self::money( self::separate_total( $id ) ), '<strong>' . self::money( $save ) . '</strong>' ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ admin */

	/**
	 * Register the box.
	 */
	public static function box_register() {
		add_meta_box( 'nx-package', __( 'Package', 'nexora-commerce-toolkit' ), array( __CLASS__, 'box' ), 'product', 'normal', 'default' );
	}

	/**
	 * Products and options that can go in a package.
	 *
	 * @param int $exclude The package being edited.
	 * @return array<int,string>
	 */
	private static function choices( $exclude ) {
		$choices = array();
		$posts   = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => 1000,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		foreach ( $posts as $post ) {
			if ( (int) $post->ID === (int) $exclude || self::is_package( $post->ID ) ) {
				continue;
			}
			$options = get_children(
				array(
					'post_parent' => $post->ID,
					'post_type'   => 'product_variation',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
				)
			);
			if ( ! $options ) {
				$choices[ (int) $post->ID ] = $post->post_title;
				continue;
			}
			foreach ( $options as $option ) {
				$choices[ (int) $option ] = self::name( (int) $option );
			}
		}
		return $choices;
	}

	/**
	 * One row of the contents table.
	 *
	 * @param array $choices Choices.
	 * @param int   $id      Selected product.
	 * @param int   $qty     Quantity.
	 */
	private static function row( $choices, $id, $qty ) {
		?>
		<tr data-nx-package-row>
			<td>
				<select name="nx_package_id[]" aria-label="<?php esc_attr_e( 'Product', 'nexora-commerce-toolkit' ); ?>">
					<option value="0"><?php esc_html_e( 'Choose a product…', 'nexora-commerce-toolkit' ); ?></option>
					<?php foreach ( $choices as $value => $label ) : ?>
						<option value="<?php echo (int) $value; ?>" <?php selected( $id, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="number" name="nx_package_qty[]" min="1" max="999" step="1" class="small-text" value="<?php echo (int) $qty; ?>" aria-label="<?php esc_attr_e( 'Quantity', 'nexora-commerce-toolkit' ); ?>"></td>
			<td><button type="button" class="button-link button-link-delete" data-nx-package-remove><?php esc_html_e( 'Remove', 'nexora-commerce-toolkit' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * The box.
	 *
	 * @param WP_Post $post Product.
	 */
	public static function box( $post ) {
		$rows    = self::contents( $post->ID );
		$choices = self::choices( $post->ID );
		wp_nonce_field( 'nexora_package', 'nexora_package_nonce' );
		?>
		<p><label><input type="checkbox" name="nx_package_on" value="1" data-nx-package-toggle <?php checked( (bool) $rows ); ?>> <strong><?php esc_html_e( 'This product is a package', 'nexora-commerce-toolkit' ); ?></strong></label></p>
		<p class="description"><?php esc_html_e( 'Sell several products together at the one price set on this product. Name it, for example, Package A. Stock is taken from the products inside, so the stock fields of this product are not used.', 'nexora-commerce-toolkit' ); ?></p>
		<div data-nx-package-fields>
			<table class="widefat striped" style="max-width:640px;margin-top:10px">
				<thead><tr><th><?php esc_html_e( 'Product', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Quantity', 'nexora-commerce-toolkit' ); ?></th><th></th></tr></thead>
				<tbody data-nx-package-rows>
					<?php
					foreach ( $rows as $row ) {
						self::row( $choices, (int) $row['id'], (int) $row['qty'] );
					}
					if ( ! $rows ) {
						self::row( $choices, 0, 1 );
					}
					?>
				</tbody>
			</table>
			<template data-nx-package-template><?php self::row( $choices, 0, 1 ); ?></template>
			<p><button type="button" class="button" data-nx-package-add><?php esc_html_e( 'Add another product', 'nexora-commerce-toolkit' ); ?></button></p>
			<?php if ( $rows ) : ?>
				<?php
				$max   = self::max_qty( $post->ID );
				$total = self::separate_total( $post->ID );
				?>
				<p>
					<?php /* translators: %s: amount */ ?>
					<?php echo wp_kses_post( sprintf( __( 'Bought separately: %s.', 'nexora-commerce-toolkit' ), self::money( $total ) ) ); ?>
					<?php
					if ( 0 === $max ) {
						echo ' <strong style="color:#a02b12">' . esc_html__( 'Not available now: something inside is out of stock or unpublished.', 'nexora-commerce-toolkit' ) . '</strong>';
					} elseif ( null !== $max ) {
						/* translators: %d: quantity */
						echo ' ' . esc_html( sprintf( __( 'Stock is enough for %d.', 'nexora-commerce-toolkit' ), $max ) );
					}
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the box. Runs after both engines have saved their own product fields.
	 *
	 * @param int     $post_id Product.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( self::$saving || 'product' !== $post->post_type || ! isset( $_POST['nexora_package_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_package_nonce'] ) ), 'nexora_package' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( empty( $_POST['nx_package_on'] ) ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		$ids  = isset( $_POST['nx_package_id'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['nx_package_id'] ) ) : array();
		$qtys = isset( $_POST['nx_package_qty'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['nx_package_qty'] ) ) : array();
		$rows = array();
		foreach ( $ids as $i => $id ) {
			$item = $id && $id !== (int) $post_id ? self::item( $id ) : null;
			if ( ! $item || self::is_package( $id ) || $item->is_type( 'variable' ) ) {
				continue;
			}
			$qty         = isset( $qtys[ $i ] ) ? min( 999, max( 1, $qtys[ $i ] ) ) : 1;
			$rows[ $id ] = array(
				'id'  => $id,
				'qty' => isset( $rows[ $id ] ) ? min( 999, $rows[ $id ]['qty'] + $qty ) : $qty,
			);
		}

		$product = self::item( $post_id );
		$problem = '';
		if ( ! $rows ) {
			$problem = __( 'Not saved as a package: please choose at least one product to go inside.', 'nexora-commerce-toolkit' );
		} elseif ( $product && $product->is_type( 'variable' ) ) {
			$problem = __( 'Not saved as a package: a package needs one price, not several options.', 'nexora-commerce-toolkit' );
		} elseif ( self::containing( $post_id ) ) {
			$problem = __( 'Not saved as a package: this product is already inside another package.', 'nexora-commerce-toolkit' );
		}
		if ( '' !== $problem ) {
			delete_post_meta( $post_id, self::META );
			set_transient( self::NOTICE . get_current_user_id(), $problem, MINUTE_IN_SECONDS );
			return;
		}
		update_post_meta( $post_id, self::META, array_values( $rows ) );
		if ( '' === (string) $product->get_price() ) {
			set_transient( self::NOTICE . get_current_user_id(), __( 'This package has no price yet, so customers cannot buy it.', 'nexora-commerce-toolkit' ), MINUTE_IN_SECONDS );
		}

		// The package holds no stock of its own.
		self::$saving = true;
		if ( $product instanceof WC_Product ) {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
			$product->save();
		} else {
			update_post_meta( $post_id, '_manage_stock', 'no' );
			update_post_meta( $post_id, '_stock_status', 'instock' );
			wp_remove_object_terms( $post_id, 'outofstock', 'product_visibility' );
			Nexora_Store_Catalog::flush();
		}
		self::$saving = false;
	}

	/**
	 * Script on product screens.
	 *
	 * @param string $hook Admin page.
	 */
	public static function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && 'product' === $screen->post_type ) {
			wp_enqueue_script( 'nexora-packages-admin', NEXORA_TOOLKIT_URL . 'assets/js/packages-admin.js', array(), NEXORA_TOOLKIT_VERSION, true );
		}
	}

	/**
	 * Show a save problem.
	 */
	public static function admin_notice() {
		$key     = self::NOTICE . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------ WooCommerce */

	/**
	 * Availability follows the contents.
	 *
	 * @param bool       $in      In stock.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function wc_in_stock( $in, $product ) {
		return self::is_package( $product->get_id() ) ? self::available( $product->get_id() ) : $in;
	}

	/**
	 * Quantity box limit.
	 *
	 * @param int        $max     Max.
	 * @param WC_Product $product Product.
	 * @return int
	 */
	public static function wc_quantity_max( $max, $product ) {
		if ( ! self::is_package( $product->get_id() ) ) {
			return $max;
		}
		$limit = self::max_qty( $product->get_id() );
		return null === $limit ? $max : $limit;
	}

	/**
	 * Stock needed by a WooCommerce cart, per stock holder.
	 *
	 * @return array<int,int>
	 */
	private static function wc_cart_demand() {
		$need = array();
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $line ) {
			$product = isset( $line['data'] ) ? $line['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$rows = self::contents( $product->get_id() );
			if ( ! $rows ) {
				$rows = array( array( 'id' => $product->get_id(), 'qty' => 1 ) );
			}
			foreach ( $rows as $row ) {
				$item = self::item( (int) $row['id'] );
				if ( $item && $item->managing_stock() ) {
					$owner          = (int) $item->get_stock_managed_by_id();
					$need[ $owner ] = ( isset( $need[ $owner ] ) ? $need[ $owner ] : 0 ) + (int) $row['qty'] * (int) $line['quantity'];
				}
			}
		}
		return $need;
	}

	/**
	 * Name of the first product short of stock for this demand, or ''.
	 *
	 * @param array<int,int> $need Demand.
	 * @return string
	 */
	private static function wc_shortfall( $need ) {
		foreach ( $need as $owner => $qty ) {
			$item = wc_get_product( $owner );
			if ( $item && $item->managing_stock() && ! $item->backorders_allowed() && $qty > (int) $item->get_stock_quantity() ) {
				return wp_strip_all_tags( $item->get_name() );
			}
		}
		return '';
	}

	/**
	 * Refuse an add that the stock of the contents cannot cover, counting what is already in the cart.
	 *
	 * @param bool $ok         Passed so far.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function wc_validate_add( $ok, $product_id, $qty ) {
		$rows = self::contents( $product_id );
		if ( ! $ok || ! $rows ) {
			return $ok;
		}
		$need = self::wc_cart_demand();
		foreach ( $rows as $row ) {
			$item = self::item( (int) $row['id'] );
			if ( $item && $item->managing_stock() ) {
				$owner          = (int) $item->get_stock_managed_by_id();
				$need[ $owner ] = ( isset( $need[ $owner ] ) ? $need[ $owner ] : 0 ) + (int) $row['qty'] * (int) $qty;
			}
		}
		$short = self::wc_shortfall( $need );
		if ( '' !== $short ) {
			/* translators: %s: product */
			wc_add_notice( sprintf( __( 'There is not enough %s left for this package.', 'nexora-commerce-toolkit' ), $short ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * The cart and checkout stop while packages and single items together need more than is in stock.
	 */
	public static function wc_check_cart() {
		$has = false;
		foreach ( WC()->cart->get_cart() as $line ) {
			$has = $has || self::is_package( $line['product_id'] );
		}
		$short = $has ? self::wc_shortfall( self::wc_cart_demand() ) : '';
		if ( '' !== $short ) {
			/* translators: %s: product */
			wc_add_notice( sprintf( __( 'There is not enough %s left for everything in your cart, including packages. Please lower a quantity.', 'nexora-commerce-toolkit' ), $short ), 'error' );
		}
	}

	/**
	 * Show the contents in the cart.
	 *
	 * @param array $data Item data.
	 * @param array $line Cart line.
	 * @return array
	 */
	public static function wc_item_data( $data, $line ) {
		$rows = self::contents( (int) $line['product_id'] );
		if ( $rows ) {
			$data[] = array(
				'name'  => __( 'Includes', 'nexora-commerce-toolkit' ),
				'value' => self::summary( $rows ),
			);
		}
		return $data;
	}

	/**
	 * Keep the contents on the order: visibly for packing, and as data for stock.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $key  Cart key.
	 * @param array                 $line Cart line.
	 */
	public static function wc_order_line( $item, $key, $line ) {
		unset( $key );
		$rows = self::contents( (int) $line['product_id'] );
		if ( $rows ) {
			$item->add_meta_data( __( 'Includes', 'nexora-commerce-toolkit' ), self::summary( $rows ), true );
			$item->add_meta_data( self::SNAPSHOT, $rows, true );
		}
	}

	/**
	 * Take stock from the contents. The mark on each order line means this can only happen once.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function wc_reduce( $order ) {
		foreach ( $order->get_items() as $line ) {
			$rows = $line->get_meta( self::SNAPSHOT );
			if ( ! is_array( $rows ) ) {
				$rows = self::contents( $line->get_product_id() ); // Orders added by staff in wp-admin.
			}
			if ( ! $rows || $line->get_meta( self::REDUCED ) ) {
				continue;
			}
			$done = array();
			foreach ( $rows as $row ) {
				$part = wc_get_product( (int) $row['id'] );
				if ( $part && $part->managing_stock() ) {
					$qty = (int) $row['qty'] * (int) $line->get_quantity();
					wc_update_product_stock( $part, $qty, 'decrease' );
					$done[] = $qty . ' × ' . wp_strip_all_tags( $part->get_name() );
				}
			}
			$line->update_meta_data( self::SNAPSHOT, $rows );
			$line->update_meta_data( self::REDUCED, 1 );
			$line->save();
			if ( $done ) {
				/* translators: 1: package, 2: list of products */
				$order->add_order_note( sprintf( __( 'Stock taken for %1$s: %2$s.', 'nexora-commerce-toolkit' ), $line->get_name(), implode( ', ', $done ) ) );
			}
		}
	}

	/**
	 * Give stock back when an order is cancelled.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function wc_restore( $order ) {
		foreach ( $order->get_items() as $line ) {
			$rows = $line->get_meta( self::SNAPSHOT );
			if ( ! is_array( $rows ) || ! $line->get_meta( self::REDUCED ) ) {
				continue;
			}
			$done = array();
			foreach ( $rows as $row ) {
				$part = wc_get_product( (int) $row['id'] );
				if ( $part && $part->managing_stock() ) {
					$qty = (int) $row['qty'] * (int) $line->get_quantity();
					wc_update_product_stock( $part, $qty, 'increase' );
					$done[] = $qty . ' × ' . wp_strip_all_tags( $part->get_name() );
				}
			}
			$line->delete_meta_data( self::REDUCED );
			$line->save();
			if ( $done ) {
				/* translators: 1: package, 2: list of products */
				$order->add_order_note( sprintf( __( 'Stock returned for %1$s: %2$s.', 'nexora-commerce-toolkit' ), $line->get_name(), implode( ', ', $done ) ) );
			}
		}
	}
}
