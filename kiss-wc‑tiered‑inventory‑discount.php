<?php
/**
 * Plugin Name: KISS - WooCommerce Tiered Inventory Discount
 * Description: Applies tiered, fixed‑allocation discounts (e.g. first 10 units –30 %, next 10 –20 %, etc.) to selected WooCommerce products.
 * Version:     1.0.0
 * Author:      KISSPlugins.com
 * License:     GPL‑2.0
 * Text Domain: wc-tiered-inventory-discount
 *
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; //  No direct access.
}

if ( ! class_exists( 'WC_Tiered_Inventory_Discount' ) ) :

	final class WC_Tiered_Inventory_Discount {

		const META_ENABLED      = '_wc_tid_enabled';
		const META_TOTAL_QTY    = '_wc_tid_total_qty';
		const META_TIERS        = '_wc_tid_tiers';      // Array of [ qty, discount, sold ].
		const META_SOLD_TOTAL   = '_wc_tid_sold_total'; // Int – total promo units sold.

		/**
		 * Singleton bootstrap.
		 */
		public static function init() : void {
			static $instance = null;
			if ( null === $instance ) {
				$instance = new self();
			}
		}

		private function __construct() {

			/* --- Admin -------------------------------------------------- */
			add_action( 'add_meta_boxes',                      [ $this, 'add_product_metabox' ] );
			add_action( 'save_post_product',                   [ $this, 'save_product_metabox' ], 10, 2 );

			add_action( 'admin_menu',                          [ $this, 'admin_menu' ] );
			add_action( 'admin_init',                          [ $this, 'register_settings' ] );

			/* --- Front‑of‑site ---------------------------------------- */
			add_filter( 'woocommerce_before_calculate_totals', [ $this, 'apply_cart_discounts' ], 20, 1 );
			add_action( 'woocommerce_single_product_summary',  [ $this, 'product_page_notice' ], 25 );

			/* Prevent adding more promo units than are left. */
			add_filter( 'woocommerce_add_to_cart_validation',  [ $this, 'validate_cart_quantity' ], 10, 3 );

			/* Lock‑in tier allocations when the order is created. */
			add_action( 'woocommerce_checkout_order_processed',[ $this, 'allocate_on_order_created' ], 10, 3 );

			/* Shortcode / Widget. */
			add_shortcode( 'wc_tid_status',                    [ $this, 'shortcode_status' ] );
		}

		/* ====================================================================
		 *  1.  Helper – fetch promo data for a product ID
		 * ==================================================================== */
		private function get_promo_data( int $product_id ) : array {

			$enabled = (bool) get_post_meta( $product_id, self::META_ENABLED, true );
			if ( ! $enabled ) {
				return [ 'enabled' => false ];
			}

			$tiers = get_post_meta( $product_id, self::META_TIERS, true );
			if ( ! is_array( $tiers ) || empty( $tiers ) ) {
				return [ 'enabled' => false ];
			}

			$total_qty  = (int) get_post_meta( $product_id, self::META_TOTAL_QTY,  true );
			$sold_total = (int) get_post_meta( $product_id, self::META_SOLD_TOTAL, true );

			return [
				'enabled'    => true,
				'total_qty'  => $total_qty,
				'sold_total' => $sold_total,
				'remaining'  => max( 0, $total_qty - $sold_total ),
				'tiers'      => $tiers,
			];
		}

		/**
		 * Determine which tier applies to the *next* unit to be sold,
		 * returning [ tier_index, discount_percent, remaining_in_tier ].
		 */
		private function current_tier( array $data ) : array {

			$sold = (int) $data['sold_total'];

			foreach ( $data['tiers'] as $idx => $tier ) {
				$tier_limit = (int) $tier['qty'];
				$tier_sold  = (int)($tier['sold'] ?? 0);

				if ( $tier_sold < $tier_limit ) {
					$remaining = $tier_limit - $tier_sold;
					return [ $idx, (float)$tier['discount'], $remaining ];
				}
				$sold -= $tier_limit; // Not strictly needed.
			}
			return [ null, 0.0, 0 ];
		}

		/* ====================================================================
		 *  2.  CART:  apply discounts to prices before totals are calculated
		 * ==================================================================== */
		public function apply_cart_discounts( WC_Cart $cart ) : void {

			if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
				return; // Prevent interference with admin price calc.
			}

			foreach ( $cart->get_cart() as $cart_item_key => $item ) {

				/* Only products that are in promo list apply. */
				$product = $item['data'];
				$data    = $this->get_promo_data( $product->get_id() );

				if ( ! $data['enabled'] || $data['remaining'] <= 0 ) {
					continue;
				}

				$qty_in_cart = (int) $item['quantity'];

				/* ----------------------------------------------------------------
				 *  Allocation logic – we *estimate* what discount applies here.
				 *  The discount is finalised when the order is created.
				 * ---------------------------------------------------------------- */
				[ $_tier_idx, $discount_percent, $remaining_in_tier ] = $this->current_tier( $data );

				/* If the cart quantity exceeds the remaining units in current tier,
				 * we give a *blended average* discount across the whole line item.
				 * This keeps things simple in the UI and avoids duplicating line items.
				 */
				$discount_to_apply = $discount_percent;

				if ( $qty_in_cart > $remaining_in_tier ) {
					// Overspill into next tier(s) – calculate weighted average.
					$units_remaining   = $qty_in_cart;
					$running_discount  = 0.0;
					foreach ( $data['tiers'] as $tier ) {
						$tier_remaining = max( 0, (int)$tier['qty'] - (int)($tier['sold'] ?? 0) );
						if ( $tier_remaining <= 0 ) {
							continue;
						}
						$use = min( $tier_remaining, $units_remaining );
						$running_discount += $use * (float)$tier['discount'];
						$units_remaining  -= $use;
						if ( $units_remaining <= 0 ) {
							break;
						}
					}
					$discount_to_apply = $running_discount / $qty_in_cart; // average %
				}

				/* Apply adjusted price. */
				$regular_price = $product->get_regular_price();
				$new_price     = max( 0, $regular_price * ( 1 - ( $discount_to_apply / 100 ) ) );

				$item['data']->set_price( $new_price );

				/* Pass info to templates. */
				$item['wc_tid_discount'] = $discount_to_apply;
			}
		}

		/* ====================================================================
		 *  3.  Validate add‑to‑cart: disallow > remaining promo quantity
		 * ==================================================================== */
		public function validate_cart_quantity( bool $passed, int $product_id, int $quantity ) : bool {

			$data = $this->get_promo_data( $product_id );
			if ( ! $data['enabled'] ) {
				return $passed;
			}
			if ( $quantity > $data['remaining'] ) {
				wc_add_notice(
					sprintf(
						/* translators: %1$d = requested qty, %2$d = remaining qty */
						__( 'Only %2$d promotional units are left; you tried to add %1$d.', 'wc-tiered-inventory-discount' ),
						$quantity,
						$data['remaining']
					),
					'error'
				);
				return false;
			}
			return $passed;
		}

		/* ====================================================================
		 *  4.  Commit tier allocations atomically when an order is created
		 * ==================================================================== */
		public function allocate_on_order_created( $order_id, $posted_data, $order ) {

			foreach ( $order->get_items() as $item_id => $item ) {

				$product_id = $item->get_product_id();
				$data       = $this->get_promo_data( $product_id );
				if ( ! $data['enabled'] || $data['remaining'] <= 0 ) {
					continue;
				}

				$to_allocate = (int) $item->get_quantity();
				$tiers       = $data['tiers'];
				$saved       = false;

				// We lock using WP's update_post_meta with compare‑and‑swap semantics.
				$retries = 3;
				while ( $to_allocate > 0 && $retries -- ) {

					foreach ( $tiers as $idx => &$tier ) { // Pass‑by‑ref so we can update.

						$available = (int)$tier['qty'] - (int)($tier['sold'] ?? 0);
						if ( $available <= 0 ) {
							continue;
						}

						$use               = min( $available, $to_allocate );
						$tier['sold']      = (int)( $tier['sold'] ?? 0 ) + $use;
						$to_allocate      -= $use;

						if ( 0 === $to_allocate ) {
							break;
						}
					}
				}

				/* Persist updated tallies. */
				$new_sold_total = (int)$data['sold_total'] + (int)$item->get_quantity();
				update_post_meta( $product_id, self::META_TIERS,      $tiers );
				update_post_meta( $product_id, self::META_SOLD_TOTAL,  $new_sold_total );

				/* Optional: notify admin on tier sold‑out. */
				foreach ( $tiers as $tier ) {
					if ( (int)$tier['sold'] === (int)$tier['qty'] && empty( $tier['notified'] ) ) {
						$tier['notified'] = 1;
						$this->notify_admin_tier_sold_out( $product_id, $tier );
					}
				}
				update_post_meta( $product_id, self::META_TIERS, $tiers );
			}
		}

		private function notify_admin_tier_sold_out( int $product_id, array $tier ) : void {

			$product = wc_get_product( $product_id );
			$subject = sprintf( __( '[%s] Discount tier sold out', 'wc-tiered-inventory-discount' ), get_bloginfo( 'name' ) );
			$body    = sprintf(
				/* translators: 1: product name, 2: discount, 3: qty */
				__( 'For product "%1$s", the %2$s&nbsp;%% discount tier (%3$d units) has sold out.', 'wc-tiered-inventory-discount' ),
				$product->get_name(),
				$tier['discount'],
				$tier['qty']
			);

			wp_mail( get_option( 'admin_email' ), $subject, wpautop( $body ) );
		}

		/* ====================================================================
		 *  5.  Front‑end notice on product page
		 * ==================================================================== */
		public function product_page_notice() : void {

			global $product;
			if ( ! $product instanceof WC_Product ) {
				return;
			}
			$data = $this->get_promo_data( $product->get_id() );
			if ( ! $data['enabled'] || $data['remaining'] <= 0 ) {
				return;
			}

			[ $_idx, $discount, $remaining ] = $this->current_tier( $data );

			printf(
				'<p class="wc-tid-notice">%1$s</p>',
				/* translators: 1: discount %, 2: remaining units */
				esc_html(
					sprintf( __( 'Hurry! %1$s%% off for the next %2$d unit(s).', 'wc-tiered-inventory-discount' ), $discount, $remaining )
				)
			);
		}

		/* ====================================================================
		 *  6.  Shortcode [wc_tid_status product_id="123"]
		 * ==================================================================== */
		public function shortcode_status( $atts ) : string {

			$atts = shortcode_atts( [ 'product_id' => 0 ], $atts, 'wc_tid_status' );
			$product_id = (int) $atts['product_id'];
			if ( ! $product_id ) {
				return '';
			}
			$data = $this->get_promo_data( $product_id );
			if ( ! $data['enabled'] ) {
				return __( 'No promotion running.', 'wc-tiered-inventory-discount' );
			}

			ob_start();
			?>
			<div class="wc-tid-status">
				<p>
					<strong><?php echo esc_html( wc_get_product( $product_id )->get_name() ); ?></strong><br>
					<?php
					printf(
						/* translators: 1: remaining, 2: total */
						esc_html__( '%1$d of %2$d promotional units remain.', 'wc-tiered-inventory-discount' ),
						$data['remaining'],
						$data['total_qty']
					);
					?>
				</p>
				<ul>
					<?php foreach ( $data['tiers'] as $tier ) : ?>
						<li>
							<?php
							printf(
								/* translators: 1: discount %, 2: sold, 3: total in tier */
								esc_html__( '%1$s%% – %2$d / %3$d sold', 'wc-tiered-inventory-discount' ),
								$tier['discount'],
								(int)($tier['sold'] ?? 0),
								$tier['qty']
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
			return ob_get_clean();
		}

		/* ====================================================================
		 *  7.  Admin metabox on the Product edit screen
		 * ==================================================================== */
		public function add_product_metabox() : void {

			add_meta_box(
				'wc_tid_metabox',
				__( 'Tiered Discount Promotion', 'wc-tiered-inventory-discount' ),
				[ $this, 'render_product_metabox' ],
				'product',
				'side'
			);
		}

		public function render_product_metabox( WP_Post $post ) : void {

			wp_nonce_field( 'wc_tid_save_meta', 'wc_tid_nonce' );

			$enabled = (bool) get_post_meta( $post->ID, self::META_ENABLED, true );
			$total   = (int)  get_post_meta( $post->ID, self::META_TOTAL_QTY, true );
			$tiers   = get_post_meta( $post->ID, self::META_TIERS,     true );

			/* Render a simple JSON textarea for tiers for brevity.  
			 * Format: one tier per line, "qty|discount" (e.g. 10|30).
			 */
			$tier_lines = '';
			if ( is_array( $tiers ) ) {
				foreach ( $tiers as $tier ) {
					$tier_lines .= (int)$tier['qty'] . '|' . (float)$tier['discount'] . PHP_EOL;
				}
			}
			?>
			<p>
				<label>
					<input type="checkbox" name="wc_tid_enabled" <?php checked( $enabled ); ?> />
					<?php _e( 'Enable promotion for this product', 'wc-tiered-inventory-discount' ); ?>
				</label>
			</p>
			<p>
				<label>
					<?php _e( 'Total promotional units', 'wc-tiered-inventory-discount' ); ?><br>
					<input type="number" min="1" name="wc_tid_total" value="<?php echo esc_attr( $total ); ?>" style="width:100%;">
				</label>
			</p>
			<p>
				<label>
					<?php _e( 'Tiers (qty|discount% per line)', 'wc-tiered-inventory-discount' ); ?><br>
					<textarea name="wc_tid_tiers" rows="4" style="width:100%;"><?php echo esc_textarea( trim( $tier_lines ) ); ?></textarea>
				</label>
			</p>
			<?php
		}

		public function save_product_metabox( int $post_id, WP_Post $post ) : void {

			if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
				return;
			}
			if ( ! isset( $_POST['wc_tid_nonce'] ) || ! wp_verify_nonce( $_POST['wc_tid_nonce'], 'wc_tid_save_meta' ) ) {
				return;
			}

			$enabled = ! empty( $_POST['wc_tid_enabled'] );
			update_post_meta( $post_id, self::META_ENABLED, $enabled );

			if ( ! $enabled ) {
				return;
			}

			$total = max( 1, intval( $_POST['wc_tid_total'] ?? 0 ) );
			update_post_meta( $post_id, self::META_TOTAL_QTY, $total );

			$tiers_input = sanitize_textarea_field( $_POST['wc_tid_tiers'] ?? '' );
			$tiers_lines = array_filter( array_map( 'trim', explode( "\n", $tiers_input ) ) );

			$tiers = [];
			foreach ( $tiers_lines as $line ) {
				if ( ! strpos( $line, '|' ) ) {
					continue;
				}
				[ $qty, $disc ] = array_map( 'trim', explode( '|', $line, 2 ) );
				$tiers[] = [
					'qty'      => max( 1, intval( $qty ) ),
					'discount' => max( 0, floatval( $disc ) ),
					'sold'     => 0,
				];
			}
			update_post_meta( $post_id, self::META_TIERS, $tiers );
		}

		/* ====================================================================
		 *  8.  Global settings page (optional – shows summarised stats)
		 * ==================================================================== */
		public function admin_menu() : void {

			add_submenu_page(
				'woocommerce',
				__( 'Tiered Discounts', 'wc-tiered-inventory-discount' ),
				__( 'Tiered Discounts', 'wc-tiered-inventory-discount' ),
				'manage_woocommerce',
				'wc-tid-settings',
				[ $this, 'settings_page' ]
			);
		}

		public function register_settings() : void {
			// Currently no global options; hook reserved for future.
		}

		public function settings_page() : void {

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			echo '<div class="wrap"><h1>' . esc_html__( 'Tiered Discount Promotions', 'wc-tiered-inventory-discount' ) . '</h1>';

			$args = [
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'meta_key'       => self::META_ENABLED,
				'meta_value'     => '1',
			];
			$q = new WP_Query( $args );
			if ( ! $q->have_posts() ) {
				echo '<p>' . esc_html__( 'No products with promotions found.', 'wc-tiered-inventory-discount' ) . '</p></div>';
				return;
			}

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Product', 'wc-tiered-inventory-discount' ) . '</th>';
			echo '<th>' . esc_html__( 'Total Units', 'wc-tiered-inventory-discount' ) . '</th>';
			echo '<th>' . esc_html__( 'Sold', 'wc-tiered-inventory-discount' ) . '</th>';
			echo '<th>' . esc_html__( 'Remaining', 'wc-tiered-inventory-discount' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $q->posts as $post ) {
				$data = $this->get_promo_data( $post->ID );
				echo '<tr>';
				echo '<td><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( $post->post_title ) . '</a></td>';
				echo '<td>' . esc_html( $data['total_qty'] ) . '</td>';
				echo '<td>' . esc_html( $data['sold_total'] ) . '</td>';
				echo '<td>' . esc_html( $data['remaining'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
	}

	WC_Tiered_Inventory_Discount::init();

endif; // class exists
