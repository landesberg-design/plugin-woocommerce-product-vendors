<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Vendors_Order_Note_To_Customer extends WC_Email {
	/**
	 * Constructor
	 *
	 * @access public
	 * @return bool
	 */
	public function __construct() {
		$this->id               = 'order_note_to_customer';
		$this->title            = __( 'Order Note to Customer (Vendors)', 'woocommerce-product-vendors' );
		$this->description      = __( 'When vendor creates an order note for the customer, this email will be triggered.', 'woocommerce-product-vendors' );
		$this->customer_email   = true;
		$this->heading          = __( 'Order Note', 'woocommerce-product-vendors' );
		$this->subject          = __( '[{site_title}] Order note ({order_number}) - {order_date}', 'woocommerce-product-vendors' );

		$this->template_base    = WC_PRODUCT_VENDORS_TEMPLATES_PATH;
		$this->template_html    = 'emails/order-note-to-customer.php';
		$this->template_plain   = 'emails/plain/order-note-to-customer.php';

		// Triggers for this email
		add_action( 'wcpv_customer_order_note_notification', array( $this, 'trigger' ), 10, 3 );

		// Call parent constructor
		parent::__construct();

		return true;
	}

	/**
	 * trigger function.
	 *
	 * @access public
	 * @param int $order_id
	 * @param string $note
	 * @param int $vendor_id
	 * @return bool
	 */
	public function trigger( $order_id, $note, $vendor_id ) {
		$this->note = $note;
		$this->vendor = $vendor_id;

		if ( $order_id ) {

			$this->object = wc_get_order( $order_id );

			$order_date = $this->object->get_date_created();
			$recipient  = $this->object->get_billing_email();

			$this->recipient               = $recipient;
			$this->find['order-date']      = '{order_date}';
			$this->find['order-number']    = '{order_number}';
			$this->replace['order-date']   = date_i18n( wc_date_format(), strtotime( $order_date ) );
			$this->replace['order-number'] = $this->object->get_order_number();

			if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
				return;
			}

			if ( is_a( $this->object, 'WC_Order' ) && $items = $this->object->get_items( 'line_item' ) ) {

				add_filter( 'woocommerce_order_get_items', array( $this, 'filter_vendor_items' ), 10, 2 );

				$email_headers = $this->get_headers();
				$email_headers = $this->set_vendor_email_headers( $email_headers, $vendor_id );
				$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $email_headers, $this->get_attachments() );
			}
		}

		return true;
	}

	/**
	 * get_content_html function.
	 *
	 * @access public
	 * @return string
	 */
	public function get_content_html() {
		ob_start();
		wc_get_template( $this->template_html, array(
			'order'         => $this->object,
			'email_heading' => $this->get_heading(),
			'sent_to_admin' => true,
			'plain_text'    => false,
			'note'          => $this->note,
			'email'			=> $this
		), 'woocommerce-product-vendors/', $this->template_base );

		return ob_get_clean();
	}

	/**
	 * get_content_plain function.
	 *
	 * @access public
	 * @return string
	 */
	public function get_content_plain() {
		ob_start();
		wc_get_template( $this->template_plain, array(
			'order'         => $this->object,
			'email_heading' => $this->get_heading(),
			'sent_to_admin' => true,
			'plain_text'    => true,
			'note'          => $this->note,
			'email'			=> $this
		), 'woocommerce-product-vendors/', $this->template_base );

		return ob_get_clean();
	}

	/**
	 * Filters the order items for vendor items
	 *
	 * @access public
	 * @param array $items;
	 * @param object $order
	 * @return string
	 */
	public function filter_vendor_items( $items, $order ) {
		foreach ( $items as $item => $value ) {

			// only affect line_items
			if ( 'line_item' === $value['type'] ) {

				$vendor_id = WC_Product_Vendors_Utils::get_vendor_id_from_product( $value[ 'product_id' ] );

				// remove the order items that are not from this vendor
				if ( $this->vendor !== $vendor_id ) {
					unset( $items[ $item ] );

					continue;
				}
			}
		}

		return $items;
	}

	/**
	 * Initialize Settings Form Fields
	 *
	 * @access public
	 * @return bool
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'         => __( 'Enable/Disable', 'woocommerce-product-vendors' ),
				'type'          => 'checkbox',
				'label'         => __( 'Enable this email notification', 'woocommerce-product-vendors' ),
				'default'       => 'yes'
			),
			'subject' => array(
				'title'         => __( 'Subject', 'woocommerce-product-vendors' ),
				'type'          => 'text',
				'description'   => sprintf( __( 'This controls the email subject line. Leave blank to use the default subject: <code>%s</code>.', 'woocommerce-product-vendors' ), $this->subject ),
				'placeholder'   => '',
				'default'       => ''
			),
			'heading' => array(
				'title'         => __( 'Email Heading', 'woocommerce-product-vendors' ),
				'type'          => 'text',
				'description'   => sprintf( __( 'This controls the main heading contained within the email notification. Leave blank to use the default heading: <code>%s</code>.', 'woocommerce-product-vendors' ), $this->heading ),
				'placeholder'   => '',
				'default'       => ''
			),
			'email_type' => array(
				'title'         => __( 'Email type', 'woocommerce-product-vendors' ),
				'type'          => 'select',
				'description'   => __( 'Choose which format of email to send.', 'woocommerce-product-vendors' ),
				'default'       => 'html',
				'class'         => 'email_type wc-enhanced-select',
				'options'       => $this->get_email_type_options()
			)
		);

		return true;
	}

	/**
	 * Should set vendor email as "Reply-to" and returns email headers.
	 *
	 * @since 2.1.70
	 *
	 * @param string $headers   Email headers.
	 * @param int    $vendor_id Vendor Id.
	 */
	private function set_vendor_email_headers( string $headers, int $vendor_id ): string {
		$email_header_parts = explode( "\r\n", $headers );
		$vendor             = WC_Product_Vendors_Utils::get_vendor_data_by_id( $vendor_id );

		foreach ( $email_header_parts as $index => $email_header_part ) {
			if ( false !== stripos( $email_header_part, 'reply-to:' ) ) {
				$email_header_parts[ $index ] = sprintf(
					'Reply-to: %1$s <%2$s>',
					esc_attr( $vendor['name'] ),
					esc_attr( $vendor['email'] )
				);

				break;
			}
		}

		return implode( "\r\n", $email_header_parts );
	}
}

return new WC_Product_Vendors_Order_Note_To_Customer();
