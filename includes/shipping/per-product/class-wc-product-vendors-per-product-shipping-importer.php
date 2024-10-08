<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WP_Importer' ) ) :
	class WC_Product_Vendors_Per_Product_Shipping_Importer extends WP_Importer {

		public $id;
		public $import_page;
		public $delimiter;
		public $posts = array();
		public $imported;
		public $skipped;
		public $vendor_id;

		/**
		 * __construct function.
		 *
		 * @access public
		 * @return void
		 */
		public function __construct() {
			$this->import_page = 'wcpv_per_product_shipping_csv';
		}

		/**
		 * Registered callback function for the WordPress Importer
		 *
		 * Manages the three separate stages of the CSV import process
		 */
		public function dispatch() {
			$this->header();

			$this->delimiter = trim( wc_clean( wp_unslash( $_POST['delimiter'] ?? '' ) ) );
			if ( ! $this->delimiter ) {
				$this->delimiter = ',';
			}

			$this->vendor_id = absint( wp_unslash( $_POST['vendor_id'] ?? 0 ) );

			$step = absint( wp_unslash( $_GET['step'] ?? 0 ) );

			switch ( $step ) {
				case 0:
					$this->greet();
					break;

				case 1:
					check_admin_referer( 'import-upload' );

					if ( $this->handle_upload() ) {
						$file = get_attached_file( $this->id );

						add_filter( 'http_request_timeout', array( $this, 'bump_request_timeout' ) );

						if ( function_exists( 'gc_enable' ) ) {
							gc_enable();
						}

						@set_time_limit( 0 );
						@ob_flush();
						@flush();

						$this->import( $file );
					}

					break;
			}

			$this->footer();
		}

		/**
		 * format_data_from_csv function.
		 *
		 * @access public
		 * @param mixed $data
		 * @param mixed $enc
		 * @return void
		 */
		public function format_data_from_csv( $data, $enc ) {
			return ( 'UTF-8' === $enc ) ? $data : mb_convert_encoding( $data, 'UTF-8', $enc );
		}

		/**
		 * import function.
		 *
		 * @access public
		 * @since  2.1.72 Remove existing product shipping rules when "override_product_id" set.
		 *
		 * @param mixed $file
		 *
		 * @return void
		 */
		public function import( $file ) {
			global $wpdb;

			$this->imported = $this->skipped = 0;

			if ( ! is_file( $file ) ) {
				echo '<p><strong>' . esc_html__( 'Sorry, there has been an error.', 'woocommerce-product-vendors' ) . '</strong><br />';

				echo esc_html__( 'The file does not exist, please try again.', 'woocommerce-product-vendors' ) . '</p>';

				$this->footer();

				wp_die();
			}

			$new_rates = array();

			if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {

				$header = fgetcsv( $handle, 0, $this->delimiter );

				if ( sizeof( $header ) == 6 ) {

					$loop = 0;

					if ( ! empty( $_GET['override_product_id'] ) ) {
						$wpdb->delete(
							WC_PRODUCT_VENDORS_PER_PRODUCT_SHIPPING_TABLE,
							[ 'product_id' => absint( $_GET['override_product_id'] ) ],
							[ 'product_id' => '%d' ]
						);
					}

					while ( ( $row = fgetcsv( $handle, 0, $this->delimiter ) ) !== false ) {

						list( $post_id, $country, $state, $postcode, $cost, $item_cost ) = $row;

						$terms = wp_get_post_terms( $post_id, WC_PRODUCT_VENDORS_TAXONOMY );

						// Check if the vendor_id is still empty, if so get it from the current product.
						if ( empty( $this->vendor_id ) ) {
							$this->vendor_id = WC_Product_Vendors_Utils::get_vendor_id_from_product( $post_id );
						}

						// skip if user cannot manage this product
						foreach ( $terms as $term ) {
							if ( $term->term_id !== $this->vendor_id ) {
								$this->skipped++;

								continue 2;
							}
						}

						$country = trim( strtoupper( $country ) );
						$state   = trim( strtoupper( $state ) );

						if ( '*' === $country ) {
							$country = '';
						}

						if ( '*' === $state ) {
							$state = '';
						}

						if ( '*' === $postcode ) {
							$postcode = '';
						}

						$wpdb->insert(
							WC_PRODUCT_VENDORS_PER_PRODUCT_SHIPPING_TABLE,
							array(
								'rule_country' 		=> esc_attr( $country ),
								'rule_state' 		=> esc_attr( $state ),
								'rule_postcode' 	=> esc_attr( $postcode ),
								'rule_cost' 		=> esc_attr( $cost ),
								'rule_item_cost' 	=> esc_attr( $item_cost ),
								'rule_order'		=> $loop,
								'product_id'		=> absint( $post_id ),
							)
						);

						$loop++;

						$this->imported++;
				    }
				} else {

					echo '<p><strong>' . esc_html__( 'Sorry, there has been an error.', 'woocommerce-product-vendors' ) . '</strong><br />';

					echo esc_html__( 'The CSV is invalid.', 'woocommerce-product-vendors' ) . '</p>';

					$this->footer();

					wp_die();
				}

			    fclose( $handle );
			}

			// Show Result
			echo '<div class="updated settings-error below-h2"><p>';
			printf(
				// translators: 1, 2 - product count, 3 - <strong> tag, 4 - </strong> tag.
				esc_html__( 'Import complete - imported %3$s%1$d%4$s shipping rates and skipped %3$s%2$d%4$s.', 'woocommerce-product-vendors' ),
				absint( $this->imported ),
				absint( $this->skipped ),
				'<strong>',
				'</strong>'
			);
			echo '</p></div>';

			$this->import_end();
		}

		/**
		 * Performs post-import cleanup of files and the cache
		 */
		public function import_end() {
			echo '<p>' . esc_html__( 'All done!', 'woocommerce-product-vendors' ) . '</p>';

			do_action( 'import_end' );
		}

		/**
		 * Handles the CSV upload and initial parsing of the file to prepare for
		 * displaying author import options
		 *
		 * @return bool False if error uploading or invalid file, true otherwise
		 */
		public function handle_upload() {
			$file = wp_import_handle_upload();

			if ( isset( $file['error'] ) ) {
				echo '<p><strong>' . esc_html__( 'Sorry, there has been an error.', 'woocommerce-product-vendors' ) . '</strong><br />';
				echo esc_html( $file['error'] ) . '</p>';
				return false;
			}

			$this->id = absint( $file['id'] );
			return true;
		}

		/**
		 * header function.
		 *
		 * @access public
		 * @return void
		 */
		public function header() {
			echo '<div class="wrap">';
			echo '<h2>' . esc_html__( 'Import Per-product Shipping Rates', 'woocommerce-product-vendors' ) . '</h2>';
		}

		/**
		 * footer function.
		 *
		 * @access public
		 * @return void
		 */
		public function footer() {
			echo '</div>';
		}

		/**
		 * greet function.
		 *
		 * @access public
		 * @since  2.1.72 Add "override_product_id" query param to form action url and warning about shipping rule(s) override.
		 * @return void
		 */
		public function greet() {
			$can_override_shipping_rules = ! empty( $_GET['override_product_id'] );

			echo '<div class="narrow">';
			echo '<p>' . esc_html__( 'Hi there! Upload a CSV file containing per-product shipping rates to import the contents into your shop. Choose a .csv file to upload, then click "Upload file and import".', 'woocommerce-product-vendors' ) . '</p>';

			echo '<p>' . esc_html__( 'Rates need to be defined with columns in a specific order (6 columns). Product ID, Country Code, State Code, Postcode, Cost, Item Cost', 'woocommerce-product-vendors' ) . '</p>';

			$action = 'admin.php?import=' . $this->import_page . '&step=1';

			if ( $can_override_shipping_rules ) {
				$action .= '&override_product_id=' . absint( $_GET['override_product_id'] );
			}

			$bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );

			$size = size_format( $bytes );

			$upload_dir = wp_upload_dir();

			if ( ! empty( $upload_dir['error'] ) ) :
				?><div class="error"><p><?php esc_html_e( 'Before you can upload your import file, you will need to fix the following error:', 'woocommerce-product-vendors' ); ?></p>
				<p><strong><?php echo esc_html( $upload_dir['error'] ); ?></strong></p></div><?php
			else :
				?>
				<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr( wp_nonce_url( $action, 'import-upload' ) ); ?>">
					<table class="form-table">
						<tbody>
							<tr>
								<th>
									<label for="upload"><?php esc_html_e( 'Choose a file from your computer:', 'woocommerce-product-vendors' ); ?></label>
								</th>
								<td>
									<input type="file" id="upload" name="import" size="25" />
									<input type="hidden" name="action" value="save" />
									<input type="hidden" name="max_file_size" value="<?php echo esc_attr( $bytes ); ?>" />
									<small><?php printf( esc_html__( 'Maximum size: %s', 'woocommerce-product-vendors' ), esc_html( $size ) ); ?></small>
								</td>
							</tr>
							<tr>
								<th><label><?php esc_html_e( 'Vendor ID', 'woocommerce-product-vendors' ); ?></label><br /></th>
								<td><input type="text" name="vendor_id" /></td>
							<tr>
								<th><label><?php esc_html_e( 'Delimiter', 'woocommerce-product-vendors' ); ?></label><br/></th>
								<td><input type="text" name="delimiter" placeholder="," size="2" /></td>
							</tr>
						</tbody>
					</table>
					<p class="submit">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import', 'woocommerce-product-vendors' ); ?>" />
					</p>
				</form>
				<?php
			endif;

			if ( $can_override_shipping_rules ) {
				printf(
					'<p><strong>%1$s&nbsp;%2$s</strong></p>',
					esc_html__(
						'Warning:',
						'woocommerce-product-vendors' ),

					esc_html__(
						'All existing shipping rules will be replaced with the rule(s) in your imported CSV file.',
						'woocommerce-product-vendors'
					)
				);
			}

			echo '</div>';
		}

		/**
		 * Added to http_request_timeout filter to force timeout at 60 seconds during import
		 * @return int 60
		 */
		public function bump_request_timeout( $val ) {
			return 60;
		}
	}
endif;
