<?php
/**
 * Plugin Name: WooCommerce Product Vendors
 * Requires Plugins: woocommerce
 * Version: 2.3.0
 * Plugin URI: https://woocommerce.com/products/product-vendors/
 * Description: Set up a multi-vendor marketplace that allows vendors to manage their own products and earn commissions. Run stores similar to Amazon or Etsy.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com
 * Requires at least: 6.4
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * PHP tested up to: 8.3
 * WC requires at least: 8.9
 * WC tested up to: 9.1
 * Text Domain: woocommerce-product-vendors
 * Domain Path: /languages
 *
 * @package WordPress
 * @author WooCommerce
 *
 * Woo: 219982:a97d99fccd651bbdd728f4d67d492c31
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Product_Vendors' ) ) {
	define( 'WC_PRODUCT_VENDORS_VERSION', '2.3.0' ); // WRCS: DEFINED_VERSION.

	/**
	 * Main class.
	 *
	 * @package WC_Product_Vendors
	 * @since 2.0.0
	 * @version 2.0.0
	 */
	class WC_Product_Vendors {
		private static $_instance = null;

		/**
		 * Get the single instance aka Singleton
		 *
		 * @since 2.0.0
		 * @version 2.0.0
		 * @return WC_Product_Vendors
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		/**
		 * Prevent cloning
		 *
		 * @since 2.0.0
		 * @version 2.0.0
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'woocommerce-product-vendors' ), WC_PRODUCT_VENDORS_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Prevent unserializing instances
		 *
		 * @since 2.0.0
		 * @version 2.0.0
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'woocommerce-product-vendors' ), WC_PRODUCT_VENDORS_VERSION ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Construct
		 *
		 * @since 2.0.0
		 * @version 2.0.0
		 * @return bool
		 */
		private function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ), 0 );
			add_action( 'before_woocommerce_init', array( $this, 'declare_feature_compatibility' ) );
			add_action( 'init', array( $this, 'init_cli' ) );

			// Subscribe to automated translations.
			add_filter( 'woocommerce_translations_updates_for_woocommerce-product-vendors', '__return_true' );

			do_action( 'wcpv_loaded' );

			return true;
		}

		/**
		 * Define constants
		 *
		 * @since 2.0.0
		 * @version 2.0.0
		 * @return bool
		 */
		private function define_constants() {
			global $wpdb;
			define( 'WC_PRODUCT_VENDORS_COMMISSION_TABLE', $wpdb->prefix . 'wcpv_commissions' );
			define( 'WC_PRODUCT_VENDORS_PER_PRODUCT_SHIPPING_TABLE', $wpdb->prefix . 'wcpv_per_product_shipping_rules' );

			define( 'WC_PRODUCT_VENDORS_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
			define( 'WC_PRODUCT_VENDORS_TEMPLATES_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/templates/' );
			define( 'WC_PRODUCT_VENDORS_PLUGIN_URL', untrailingslashit( plugins_url( '/', __FILE__ ) ) );
			define( 'WC_PRODUCT_VENDORS_TAXONOMY', 'wcpv_product_vendors' );

			return true;
		}

		/**
		 * Include all files needed
		 *
		 * @since 2.0.0
		 * @version 2.1.0
		 * @return bool
		 */
		public function dependencies() {
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendor-transient-manager.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-logger.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-taxonomy.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-utils.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-roles-caps.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-install.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-deactivation.php' );
			require_once( dirname( __FILE__ ) . '/includes/gateways/class-wc-product-vendors-vendor-payments-interface.php' );
			require_once( dirname( __FILE__ ) . '/includes/gateways/class-wc-product-vendors-webhook-handler.php' );
			require_once( dirname( __FILE__ ) . '/includes/gateways/class-wc-product-vendors-paypal-masspay.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-commission.php' );

			if ( is_admin() ) {
				require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-privacy.php' );
				require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-vendor-order-detail-list.php' );
				require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-vendor-orders-list.php' );
				require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-store-commission-list.php' );
				require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-vendor-order-notes.php' );
				require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-product-list-filters.php' );

				if ( WC_Product_Vendors_Utils::is_vendor() && ! current_user_can( 'manage_options' ) ) {
					require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-vendor-dashboard.php' );
					require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-vendor-admin.php' );
				} else {
					require_once( dirname( __FILE__ ) . '/includes/admin/class-wc-product-vendors-store-admin.php' );
				}

				require_once( dirname( __FILE__ ) . '/includes/admin/reports/vendor/class-wc-product-vendors-vendor-reports.php' );
				require_once( dirname( __FILE__ ) . '/includes/admin/reports/store/class-wc-product-vendors-store-reports.php' );
			}

			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-vendor-frontend.php' );
			require_once( dirname( __FILE__ ) . '/includes/widgets/class-wc-product-vendors-vendor-widget.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-widgets.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-registration.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-shortcodes.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-authentication.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-order.php' );
			new WC_Product_Vendors_Order( new WC_Product_Vendors_Commission( new WC_Product_Vendors_PayPal_MassPay() ) );

			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-emails.php' );
			require_once( dirname( __FILE__ ) . '/includes/shipping/per-product/class-wc-product-vendors-per-product-shipping.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-payout-scheduler.php' );

			require_once( dirname( __FILE__ ) . '/includes/compatibility/class-wc-product-vendors-admin-storage-compatibility.php' );

			// check for bookings.
			if ( class_exists( 'WC_Bookings' ) ) {
				require_once __DIR__ . '/includes/integrations/class-wc-product-vendors-bookings.php';
				$bookings_integration = new WC_Product_Vendors_Bookings();
				$bookings_integration->init();
			}

			// check for product enquiry.
			if ( function_exists( 'woocommerce_product_enquiry_form_init' ) ) {
				require_once( dirname( __FILE__ ) . '/includes/integrations/class-wc-product-vendors-product-enquiry.php' );
			}

			// COT Compatibility.
			require_once( __DIR__ . '/includes/compatibility/class-wc-product-vendors-cot-compatibility.php' );
			require_once __DIR__ . '/includes/compatibility/class-wc-product-vendors-product-editor-compatibility.php';

			return true;
		}

		/**
		 * Initializes hooks
		 *
		 * @access private
		 * @since 2.0.0
		 * @version 2.0.0
		 * @return bool
		 */
		private function init_hooks() {
			register_activation_hook( __FILE__, array( 'WC_Product_Vendors_Install', 'init' ) );
			register_deactivation_hook( __FILE__, array( 'WC_Product_Vendors_Deactivation', 'deactivate' ) );

			return true;
		}

		/**
		 * Initializes the CLI
		 *
		 * @access private
		 * @since 2.0.0
		 * @version 2.0.0
		 * @return bool
		 */
		public function init_cli() {
			require_once( dirname( __FILE__ ) . '/includes/class-wc-product-vendors-cli.php' );
		}

		/**
		 * Init
		 *
		 * @access public
		 * @since 2.0.0
		 * @version 2.1.5
		 * @return bool
		 */
		public function init() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
				return;
			}

			if ( ! function_exists( 'phpversion' ) ||  version_compare( phpversion(), '7.4', '<' ) ) {
				add_action( 'admin_notices', array( $this, 'php_version_notice' ) );
				return;
			}

			$this->define_constants();
			$this->load_plugin_textdomain();
			$this->dependencies();
			$this->init_hooks();
		}

		/**
		 * Load the plugin text domain for translation.
		 *
		 * @access public
		 * @since 2.0.0
		 * @version 2.0.0
		 * @return bool
		 */
		public function load_plugin_textdomain() {
			$locale = apply_filters( 'wcpv_plugin_locale', get_locale(), 'woocommerce-product-vendors' );

			load_textdomain( 'woocommerce-product-vendors', trailingslashit( WP_LANG_DIR ) . 'woocommerce-product-vendors/woocommerce-product-vendors' . '-' . $locale . '.mo' );

			load_plugin_textdomain( 'woocommerce-product-vendors', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			return true;
		}

		/**
		 * WooCommerce fallback notice.
		 *
		 * @access public
		 * @since 2.0.0
		 * @version 2.0.0
		 * @return string
		 */
		public function woocommerce_missing_notice() {
			echo '<div class="error"><p>' . sprintf( esc_html__( 'WooCommerce Product Vendors requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-product-vendors' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</p></div>';
		}

		/**
		 * PHP version fallback notice.
		 *
		 * @access public
		 * @since 2.1.5
		 * @version 2.1.5
		 * @return string
		 */
		public function php_version_notice() {
			echo '<div class="error"><p>' . wp_kses( sprintf( __( 'WooCommerce Product Vendors requires PHP 7.4 and above. <a href="%s">How to update your PHP version</a>', 'woocommerce' ), 'https://docs.woocommerce.com/document/how-to-update-your-php-version/' ), array(
				'a' => array(
					'href'  => array(),
					'title' => array(),
				),
			) ) . '</p></div>';
		}

		/**
		 * Declares feature compatibility
		 *
		 * @since 2.1.68
		 */
		public function declare_feature_compatibility() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__ );
			}
		}
	}

	WC_Product_Vendors::instance();
}
