<?php
/**
 * Shipping method driven by Vietnam address and cart rules.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Shipping_Rules_Method extends WC_Shipping_Method {

	const MAX_RULES = 500;

	private $rules = [];

	private $default_fee = '';

	private $default_free_threshold = '';

	private $default_cod = 'yes';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = Yoohw_Vietnam_Store_Tools_Shipping_Rules::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Vietnam shipping rules', 'yoohw-vietnam-store-tools' );
		$this->method_description = __( 'Calculate shipping by Vietnamese city/province, ward/commune, cart total, weight, and shipping class.', 'yoohw-vietnam-store-tools' );
		$this->supports           = [
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init() {
		$currency_symbol = get_woocommerce_currency_symbol();
		$weight_unit     = get_option( 'woocommerce_weight_unit', 'kg' );

		$this->instance_form_fields = [
			'title'                  => [
				'title'       => __( 'Method title', 'yoohw-vietnam-store-tools' ),
				'type'        => 'safe_text',
				'description' => __( 'Shown to customers during checkout.', 'yoohw-vietnam-store-tools' ),
				'default'     => __( 'Vietnam shipping', 'yoohw-vietnam-store-tools' ),
				'desc_tip'    => true,
			],
			'tax_status'             => [
				'title'   => __( 'Tax status', 'yoohw-vietnam-store-tools' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => [
					'taxable' => __( 'Taxable', 'yoohw-vietnam-store-tools' ),
					'none'    => _x( 'None', 'Tax status', 'yoohw-vietnam-store-tools' ),
				],
			],
			'default_fee'            => [
				'title'             => __( 'Default shipping fee', 'yoohw-vietnam-store-tools' ),
				'type'              => 'price',
				'description'       => sprintf(
					/* translators: %s: store currency symbol. */
					__( 'Used when no rule matches. Leave blank to hide this method when no rule matches. Amount in %s.', 'yoohw-vietnam-store-tools' ),
					$currency_symbol
				),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => [ 'min' => '0' ],
			],
			'default_free_threshold' => [
				'title'             => __( 'Default free shipping threshold', 'yoohw-vietnam-store-tools' ),
				'type'              => 'price',
				'description'       => __( 'Applies to the default fee when no rule matches. Leave blank to disable.', 'yoohw-vietnam-store-tools' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => [ 'min' => '0' ],
			],
			'default_cod'            => [
				'title'       => __( 'Default cash on delivery', 'yoohw-vietnam-store-tools' ),
				'type'        => 'select',
				'description' => __( 'Used by the default fee and by rules set to use the method default.', 'yoohw-vietnam-store-tools' ),
				'default'     => 'yes',
				'desc_tip'    => true,
				'options'     => [
					'yes' => __( 'Allow COD', 'yoohw-vietnam-store-tools' ),
					'no'  => __( 'Disallow COD', 'yoohw-vietnam-store-tools' ),
				],
			],
			'rules'                  => [
				'title'       => __( 'Shipping rules', 'yoohw-vietnam-store-tools' ),
				'type'        => 'shipping_rules',
				'description' => sprintf(
					/* translators: %1$s: currency symbol, %2$s: store weight unit. */
					__( 'Rules are checked from top to bottom; the first match is used. Cart totals are after discounts and exclude tax. Fees use %1$s and weights use %2$s.', 'yoohw-vietnam-store-tools' ),
					$currency_symbol,
					$weight_unit
				),
				'default'     => '[]',
				'desc_tip'    => false,
			],
		];

		$this->title                  = $this->get_option( 'title', __( 'Vietnam shipping', 'yoohw-vietnam-store-tools' ) );
		$this->tax_status             = $this->get_option( 'tax_status', 'taxable' );
		$this->default_fee            = $this->sanitize_non_negative_decimal( $this->get_option( 'default_fee', '' ) );
		$this->default_free_threshold = $this->sanitize_non_negative_decimal( $this->get_option( 'default_free_threshold', '' ) );
		$this->default_cod            = 'no' === $this->get_option( 'default_cod', 'yes' ) ? 'no' : 'yes';
		$this->rules                  = self::decode_rules( $this->get_option( 'rules', '[]' ) );
	}

	public function is_available( $package ) {
		if ( ! parent::is_available( $package ) ) {
			return false;
		}

		$country = isset( $package['destination']['country'] ) ? wc_strtoupper( wc_clean( $package['destination']['country'] ) ) : '';

		return '' === $country || 'VN' === $country;
	}

	public function calculate_shipping( $package = [] ) {
		$context      = $this->get_package_context( $package );
		$matched_rule = $this->find_matching_rule( $context );

		if ( $matched_rule ) {
			$cost          = $this->get_rule_cost( $matched_rule, $context['total'] );
			$cod_allowed   = 'inherit' === $matched_rule['cod'] ? $this->default_cod : $matched_rule['cod'];
			$rule_id       = $matched_rule['id'];
			$rule_name     = $matched_rule['name'];
		} else {
			if ( '' === $this->default_fee ) {
				return;
			}

			$cost        = $this->get_default_cost( $context['total'] );
			$cod_allowed = $this->default_cod;
			$rule_id     = 'default';
			$rule_name   = __( 'Default shipping fee', 'yoohw-vietnam-store-tools' );
		}

		$cost = (float) apply_filters( 'yoohw_vietnam_store_tools_shipping_rules_rate_cost', $cost, $matched_rule, $context, $package, $this );

		$this->add_rate(
			[
				'id'        => $this->get_rate_id(),
				'label'     => $this->title,
				'cost'      => max( 0, $cost ),
				'package'   => $package,
				'meta_data' => [
					'vck_shipping_rule_id'   => $rule_id,
					'vck_shipping_rule_name' => $rule_name,
					'vck_cod_allowed'        => 'no' === $cod_allowed ? 'no' : 'yes',
				],
			]
		);
	}

	public function find_matching_rule( $context ) {
		$is_context = is_array( $context )
			&& isset( $context['destination'], $context['total'], $context['weight'], $context['shipping_classes'] );
		$context    = $is_context ? $context : $this->get_package_context( is_array( $context ) ? $context : [] );

		foreach ( $this->rules as $rule ) {
			if ( 'yes' !== $rule['enabled'] || ! $this->rule_matches( $rule, $context ) ) {
				continue;
			}

			return apply_filters( 'yoohw_vietnam_store_tools_matched_shipping_rule', $rule, $context, $this );
		}

		return null;
	}

	public function generate_shipping_rules_html( $key, $data ) {
		$field_key   = $this->get_field_key( $key );
		$rules_value = wp_json_encode( $this->rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$description = $this->get_description_html( $data );
		$tooltip     = $this->get_tooltip_html( $data );

		ob_start();
		?>
		<tr valign="top" class="vck-shipping-rules-setting">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
				<?php echo wp_kses_post( $tooltip ); ?>
			</th>
			<td class="forminp">
				<div class="vck-shipping-rules-editor" data-field-key="<?php echo esc_attr( $field_key ); ?>">
					<input type="hidden" id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $rules_value ); ?>" />
					<div class="vck-shipping-rules-editor__toolbar">
						<button type="button" class="button button-primary" data-vck-add-rule>
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<?php esc_html_e( 'Add rule', 'yoohw-vietnam-store-tools' ); ?>
						</button>
						<button type="button" class="button" data-vck-import-csv>
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<?php esc_html_e( 'Import CSV', 'yoohw-vietnam-store-tools' ); ?>
						</button>
						<button type="button" class="button" data-vck-export-csv>
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php esc_html_e( 'Export CSV', 'yoohw-vietnam-store-tools' ); ?>
						</button>
						<input type="file" accept=".csv,text/csv" data-vck-csv-file hidden />
					</div>
					<p class="vck-shipping-rules-editor__status" data-vck-status aria-live="polite"></p>
					<div class="vck-shipping-rules-editor__list" data-vck-rule-list></div>
				</div>
				<?php echo wp_kses_post( $description ); ?>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	public function validate_rules_field( $key, $value ) {
		unset( $key );

		$value = is_string( $value ) ? wp_unslash( $value ) : '[]';
		$rules = json_decode( $value, true );
		$rules = self::sanitize_rules( is_array( $rules ) ? $rules : [] );

		return wp_json_encode( $rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function decode_rules( $value ) {
		if ( is_array( $value ) ) {
			return self::sanitize_rules( $value );
		}

		$decoded = json_decode( (string) $value, true );

		return self::sanitize_rules( is_array( $decoded ) ? $decoded : [] );
	}

	public static function sanitize_rules( $rules ) {
		$sanitized = [];
		$rules     = is_array( $rules ) ? array_slice( $rules, 0, self::MAX_RULES ) : [];

		foreach ( $rules as $index => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$province = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( isset( $rule['province'] ) ? $rule['province'] : '' );
			$ward     = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( isset( $rule['ward'] ) ? $rule['ward'] : '' );

			if ( '' !== $province && ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $province ) ) {
				continue;
			}

			if ( '' !== $ward && ( '' === $province || ! Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::ward_exists( $ward, $province ) ) ) {
				continue;
			}

			$id             = isset( $rule['id'] ) ? sanitize_key( $rule['id'] ) : '';
			$name           = isset( $rule['name'] ) ? sanitize_text_field( $rule['name'] ) : '';
			$shipping_class = isset( $rule['shipping_class'] ) ? sanitize_title( $rule['shipping_class'] ) : '';
			$cod            = isset( $rule['cod'] ) ? sanitize_key( $rule['cod'] ) : 'inherit';

			if ( '__none__' === ( isset( $rule['shipping_class'] ) ? $rule['shipping_class'] : '' ) ) {
				$shipping_class = '__none__';
			}

			if ( ! in_array( $cod, [ 'inherit', 'yes', 'no' ], true ) ) {
				$cod = 'inherit';
			}

			$sanitized[] = [
				'id'             => '' !== $id ? $id : 'rule_' . ( $index + 1 ),
				'enabled'        => isset( $rule['enabled'] ) && in_array( $rule['enabled'], [ true, 1, '1', 'yes', 'on' ], true ) ? 'yes' : 'no',
				'name'           => '' !== $name ? $name : sprintf(
					/* translators: %d: shipping rule position. */
					__( 'Shipping rule %d', 'yoohw-vietnam-store-tools' ),
					$index + 1
				),
				'province'       => $province,
				'ward'           => $ward,
				'min_total'      => self::sanitize_decimal_value( isset( $rule['min_total'] ) ? $rule['min_total'] : '' ),
				'max_total'      => self::sanitize_decimal_value( isset( $rule['max_total'] ) ? $rule['max_total'] : '' ),
				'min_weight'     => self::sanitize_decimal_value( isset( $rule['min_weight'] ) ? $rule['min_weight'] : '' ),
				'max_weight'     => self::sanitize_decimal_value( isset( $rule['max_weight'] ) ? $rule['max_weight'] : '' ),
				'shipping_class' => $shipping_class,
				'fee'            => self::sanitize_decimal_value( isset( $rule['fee'] ) ? $rule['fee'] : '0', '0' ),
				'free_threshold' => self::sanitize_decimal_value( isset( $rule['free_threshold'] ) ? $rule['free_threshold'] : '' ),
				'cod'            => $cod,
			];
		}

		return $sanitized;
	}

	private function get_package_context( $package ) {
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : [];
		$classes     = [];
		$weight      = 0.0;

		foreach ( isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : [] as $item ) {
			$product  = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
			$quantity = isset( $item['quantity'] ) ? max( 0, (float) $item['quantity'] ) : 0;

			if ( ! $product || ! $product->needs_shipping() || $quantity <= 0 ) {
				continue;
			}

			$weight    += max( 0, (float) $product->get_weight() ) * $quantity;
			$classes[] = (string) $product->get_shipping_class();
		}

		return [
			'destination'      => [
				'country'  => isset( $destination['country'] ) ? wc_strtoupper( wc_clean( $destination['country'] ) ) : '',
				'province' => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( isset( $destination['state'] ) ? $destination['state'] : '' ),
				'ward'     => Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( isset( $destination['city'] ) ? $destination['city'] : '' ),
			],
			'total'            => isset( $package['contents_cost'] ) ? max( 0, (float) $package['contents_cost'] ) : 0.0,
			'weight'           => $weight,
			'shipping_classes' => array_values( array_unique( $classes ) ),
		];
	}

	private function rule_matches( $rule, $context ) {
		$destination = $context['destination'];

		$matches = ( '' === $rule['province'] || $rule['province'] === $destination['province'] )
			&& ( '' === $rule['ward'] || $rule['ward'] === $destination['ward'] )
			&& $this->number_meets_minimum( $context['total'], $rule['min_total'] )
			&& $this->number_meets_maximum( $context['total'], $rule['max_total'] )
			&& $this->number_meets_minimum( $context['weight'], $rule['min_weight'] )
			&& $this->number_meets_maximum( $context['weight'], $rule['max_weight'] )
			&& $this->shipping_class_matches( $rule['shipping_class'], $context['shipping_classes'] );

		return (bool) apply_filters( 'yoohw_vietnam_store_tools_shipping_rule_matches', $matches, $rule, $context, $this );
	}

	private function shipping_class_matches( $rule_class, $package_classes ) {
		if ( '' === $rule_class ) {
			return true;
		}

		if ( '__none__' === $rule_class ) {
			return in_array( '', $package_classes, true );
		}

		return in_array( $rule_class, $package_classes, true );
	}

	private function number_meets_minimum( $number, $minimum ) {
		return '' === $minimum || (float) $number >= (float) $minimum;
	}

	private function number_meets_maximum( $number, $maximum ) {
		return '' === $maximum || (float) $number <= (float) $maximum;
	}

	private function get_rule_cost( $rule, $total ) {
		if ( '' !== $rule['free_threshold'] && (float) $total >= (float) $rule['free_threshold'] ) {
			return 0.0;
		}

		return (float) $rule['fee'];
	}

	private function get_default_cost( $total ) {
		if ( '' !== $this->default_free_threshold && (float) $total >= (float) $this->default_free_threshold ) {
			return 0.0;
		}

		return (float) $this->default_fee;
	}

	private function sanitize_non_negative_decimal( $value, $default = '' ) {
		return self::sanitize_decimal_value( $value, $default );
	}

	private static function sanitize_decimal_value( $value, $default = '' ) {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return $default;
		}

		$value = wc_format_decimal( $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return $default;
		}

		return wc_format_decimal( max( 0, (float) $value ) );
	}
}
