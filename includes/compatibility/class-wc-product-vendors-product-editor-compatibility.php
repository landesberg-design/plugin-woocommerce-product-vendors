<?php
/**
 * Product Editor Compatibility
 *
 * @package WC_Product_Vendors
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\BlockRegistry;
use Automattic\WooCommerce\Internal\Admin\Features\ProductBlockEditor\ProductTemplates;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\ProductFormTemplateInterface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product vendors Product Editor Compatibility
 *
 * Add Product vendors support to product editor.
 *
 * @since 2.2.7
 */
class WC_Product_Vendors_Product_Editor_Compatibility {
	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! FeaturesUtil::feature_is_enabled( 'product_block_editor' ) ) {
			return;
		}

		add_filter(
			'option_woocommerce_feature_product_block_editor_enabled',
			array( $this, 'enable_classic_editor_for_vendor_roles' )
		);

		add_action(
			'init',
			array( $this, 'register_custom_blocks' )
		);

		add_action(
			'woocommerce_block_template_area_product-form_after_add_block_variations',
			array( $this, 'add_product_vendors_section' )
		);

		add_filter(
			'woocommerce_rest_prepare_product_object',
			array( $this, 'add_meta_to_response' ),
			10,
			2
		);

		add_action(
			'woocommerce_rest_insert_product_object',
			array( $this, 'save_custom_data_to_product_metadata' ),
			10,
			2
		);
	}

	/**
	 * Enable classic editor for vendor roles.
	 *
	 * @param boolean $is_block_editor_enabled Whether the block editor is enabled.
	 */
	public function enable_classic_editor_for_vendor_roles( $is_block_editor_enabled ) {
		if ( ! is_admin() ) {
			return $is_block_editor_enabled;
		}

		if ( WC_Product_Vendors_Utils::is_admin_vendor() ) {
			return false;
		}

		return $is_block_editor_enabled;
	}

	/**
	 * Registers the custom product field blocks.
	 */
	public function register_custom_blocks() {
		if ( isset( $_GET['page'] ) && 'wc-admin' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			BlockRegistry::get_instance()->register_block_type_from_metadata( WC_PRODUCT_VENDORS_PATH . '/build/commission-field' );
			BlockRegistry::get_instance()->register_block_type_from_metadata( WC_PRODUCT_VENDORS_PATH . '/build/vendors-field' );
		}
	}

	/**
	 * Adds product vendors meta to the product response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Product       $product  The product object.
	 *
	 * @return WP_REST_Response
	 */
	public function add_meta_to_response( $response, $product ) {
		$product_id       = $product->get_id();
		$vendor_id        = WC_Product_Vendors_Utils::get_vendor_id_from_product( $product_id );
		$vendor_data      = WC_Product_Vendors_Utils::get_vendor_data_by_id( $vendor_id );
		$product_settings = WC_Product_Vendors_Utils::get_product_vendor_settings( $product, $vendor_data );
		$commission_data  = WC_Product_Vendors_Utils::get_product_commission( $product_id, $vendor_data );
		$commission_type  = __( 'Fixed', 'woocommerce-product-vendors' );

		if ( 'percentage' === $commission_data['type'] ) {
			$commission_type = '%';
		}

		$data                           = $response->get_data();
		$data['commission_value']       = get_post_meta( $product_id, '_wcpv_product_commission', true );
		$data['commission_placeholder'] = $commission_data['commission'];
		$data['commission_type']        = $commission_type;
		$data['create_vendors_url']     = sprintf(
			wp_kses_post(
				// translators: %1$s and %2$s are the opening and closing anchor tags, %3$s is the link to the create vendors page.
				__( 'No vendors found. Please create vendors by going %1$shere%2$s.', 'woocommerce-product-vendors' )
			),
			'<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=wcpv_product_vendors&post_type=product' ) ) . '" title="' . esc_attr__( 'Vendors', 'woocommerce-product-vendors' ) . '">',
			'</a>'
		);

		$args = array(
			'taxonomy'     => WC_PRODUCT_VENDORS_TAXONOMY,
			'hide_empty'   => false,
			'hierarchical' => false,
		);

		$terms               = get_terms( $args );
		$data['has_vendors'] = true;

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$data['has_vendors'] = false;
		}

		$data['vendors']          = $this->get_vendor_list( $product_id );
		$data['override_setting'] = get_post_meta( $product_id, '_wcpv_customize_product_vendor_settings', true );
		$data['pass_shipping']    = $product_settings['pass_shipping'];
		$data['taxes']            = $product_settings['taxes'];

		if ( $vendor_id ) {
			if ( ! $data['override_setting'] ) {
				$data['override_setting'] = 'yes';

				$pass_shipping = $product->get_meta( '_wcpv_product_pass_shipping', true, 'edit' );
				$taxes         = $product->get_meta( '_wcpv_product_taxes', true, 'edit' );

				if ( $pass_shipping === $vendor_data['taxes'] && ( empty( $taxes ) || $taxes === $vendor_data['taxes'] ) ) {
					$data['override_setting'] = 'no';
				}
			}
		}

		$term = wp_get_object_terms( $product_id, WC_PRODUCT_VENDORS_TAXONOMY, array( 'fields' => 'ids' ) );

		if ( ! ( is_wp_error( $term ) || empty( $term ) ) ) {
			$data['selected_vendor'] = $term[0];
		}

		$data['auth_vendor_user'] = WC_Product_Vendors_Utils::auth_vendor_user();

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Saves custom data to product metadata.
	 *
	 * @param WC_Product      $product The product object.
	 * @param WP_REST_Request $request The request object.
	 */
	public function save_custom_data_to_product_metadata( $product, $request ) {
		$product_id       = $product->get_id();
		$vendor_id        = (int) $request->get_param( 'selected_vendor' );
		$override         = $request->get_param( 'override_setting' );
		$commission_value = $request->get_param( 'commission_value' );
		$pass_shipping    = $request->get_param( 'pass_shipping' );
		$taxes            = $request->get_param( 'taxes' );
		$product_id       = $product->get_id();

		// Save the vendor ID.
		if ( is_numeric( $vendor_id ) && 0 !== $vendor_id ) {
			wp_set_object_terms( $product_id, $vendor_id, WC_PRODUCT_VENDORS_TAXONOMY );
		}

		// Update/delete the override global settings preference.
		if ( ! is_null( $override ) ) {
			update_post_meta( $product_id, '_wcpv_customize_product_vendor_settings', sanitize_text_field( $override ) );
		}

		if ( ! is_null( $commission_value ) ) {
			update_post_meta( $product_id, '_wcpv_product_commission', WC_Product_Vendors_Utils::sanitize_commission( wp_unslash( $commission_value ) ) );
		}

		if ( ! is_null( $pass_shipping ) ) {
			update_post_meta( $product_id, '_wcpv_product_pass_shipping', sanitize_text_field( wp_unslash( $pass_shipping ) ) );
		}

		if ( ! is_null( $taxes ) ) {
			update_post_meta( $product_id, '_wcpv_product_taxes', sanitize_text_field( wp_unslash( $taxes ) ) );
		}

		if ( 'no' === $override ) {
			update_post_meta( $product_id, '_wcpv_product_pass_shipping', 'no' );
			update_post_meta( $product_id, '_wcpv_product_taxes', 'no' );
		}
	}

	/**
	 * Adds a Product Vendors section to the product editor under the 'General' group.
	 *
	 * @since 2.2.7
	 *
	 * @param ProductTemplates\Group $variation_group The group instance.
	 */
	public function add_product_vendors_section( $variation_group ) {
		/**
		 * Template instance.
		 *
		 * @var ProductFormTemplateInterface $parent
		 */
		$parent = $variation_group->get_parent();
		$group  = $parent->add_group(
			array(
				'id'         => 'woocommerce-product-vendors-group-tab',
				'attributes' => array(
					'title' => __( 'Product Vendors', 'woocommerce-product-vendors' ),
				),
			)
		);

		$section = $group->add_section(
			array(
				'id'         => 'woocommerce-product-vendors-main-section',
				'attributes' => array(
					'title' => __( '', 'woocommerce-product-vendors' ), // phpcs:ignore WordPress.WP.I18n.NoEmptyStrings
				),
			)
		);

		$section->add_block(
			array(
				'id'        => '_wcpv_vendor_list',
				'blockName' => 'woocommerce-product-vendors/vendors-field',
			)
		);

		$section->add_block(
			array(
				'id'        => '_wcpv_product_commission',
				'blockName' => 'woocommerce-product-vendors/commission-field',
			),
		);

		$section->add_block(
			array(
				'id'             => '_wcpv_customize_product_vendor_settings',
				'blockName'      => 'woocommerce/product-checkbox-field',
				'attributes'     => array(
					'label'          => __( 'Override product vendor settings.', 'woocommerce-product-vendors' ),
					'property'       => 'override_setting',
					'checkedValue'   => 'yes',
					'uncheckedValue' => 'no',
				),
				'hideConditions' => array(
					array(
						'expression' => '!editedProduct.has_vendors',
					),
				),
			)
		);

		$section->add_block(
			array(
				'id'             => '_wcpv_product_pass_shipping',
				'blockName'      => 'woocommerce/product-checkbox-field',
				'attributes'     => array(
					'title'          => __( 'Pass Shipping', 'woocommerce-product-vendors' ),
					'label'          => __( 'Check box to pass the shipping charges for this product to the vendor.', 'woocommerce-product-vendors' ),
					'property'       => 'pass_shipping',
					'checkedValue'   => 'yes',
					'uncheckedValue' => 'no',
				),
				'hideConditions' => array(
					array(
						'expression' => '!editedProduct.has_vendors || "yes"!==editedProduct.override_setting',
					),
				),
			)
		);

		$section = $group->add_section(
			array(
				'id'             => 'woocommerce-product-vendors-tax-handling-section',
				'attributes'     => array(
					'title' => __( 'Tax Handling', 'woocommerce-product-vendors' ),
				),
				'hideConditions' => array(
					array(
						'expression' => '!editedProduct.has_vendors || "yes"!==editedProduct.override_setting',
					),
				),
			)
		);

		$section->add_block(
			array(
				'id'         => '_wcpv_product_taxes',
				'blockName'  => 'woocommerce/product-radio-field',
				'attributes' => array(
					'property' => 'taxes',
					'options'  => array(
						array(
							'label' => __( 'Keep Taxes (Calculate commission based on product price only.)', 'woocommerce-product-vendors' ),
							'value' => 'keep-tax',
						),
						array(
							'label' => __( "Pass Taxes (All tax charges will be included in the vendor's commission.)", 'woocommerce-product-vendors' ),
							'value' => 'pass-tax',
						),
						array(
							'label' => __( 'Split Taxes (The full price including taxes will be used to calculate commission.)', 'woocommerce-product-vendors' ),
							'value' => 'split-tax',
						),
					),
				),
			)
		);
	}

	/**
	 * Returns an array of vendors to be used for the
	 * <SelectControl /> component.
	 *
	 * @param int $product_id The product ID.
	 *
	 * @return array
	 */
	private function get_vendor_list( $product_id = 0 ) {
		$args = array(
			'taxonomy'     => WC_PRODUCT_VENDORS_TAXONOMY,
			'hide_empty'   => false,
			'hierarchical' => false,
		);

		$commission_type = __( 'Fixed', 'woocommerce-product-vendors' );

		$result = array(
			array(
				'label'           => __( 'Select a vendor', 'woocommerce-product-vendors' ),
				'value'           => '',
				'commission_type' => $commission_type,
			),
		);

		$terms = get_terms( $args );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$post_term = wp_get_post_terms( $product_id, WC_PRODUCT_VENDORS_TAXONOMY );
			$post_term = ! empty( $post_term ) ? $post_term[0]->term_id : '';

			foreach ( $terms as $term ) {
				$vendor_data     = WC_Product_Vendors_Utils::get_vendor_data_by_id( $term->term_id );
				$commission_data = WC_Product_Vendors_Utils::get_product_commission( $product_id, $vendor_data );
				$commission_type = __( 'Fixed', 'woocommerce-product-vendors' );

				if ( 'percentage' === $commission_data['type'] ) {
					$commission_type = '%';
				}

				$result[] = array(
					'label'           => esc_html( $term->name ),
					'value'           => esc_attr( $term->term_id ),
					'commission_type' => $commission_type,
				);
			}
		}

		return $result;
	}
}

new WC_Product_Vendors_Product_Editor_Compatibility();
