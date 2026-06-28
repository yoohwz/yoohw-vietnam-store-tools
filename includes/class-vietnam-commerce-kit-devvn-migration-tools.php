<?php
/**
 * Le Van Toan plugin data sync tools.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_DevVN_Migration_Tools {

	private const DRY_RUN_TOOL_ID = 'yoohw_vietnam_store_tools_devvn_migration_dry_run';

	private const MIGRATE_TOOL_ID = 'yoohw_vietnam_store_tools_devvn_migration';

	private const ADDRESS_BATCH_SIZE = 200;

	private const CUSTOMER_ADDRESS_BATCH_SIZE = 200;

	private const TRACKING_BATCH_SIZE = 200;

	private $ward_to_province = null;

	private $legacy_ward_aliases = null;

	public function __construct() {
		add_filter( 'woocommerce_debug_tools', [ $this, 'register_tools' ], 30 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_yoohw_vietnam_store_tools_devvn_migration_step', [ $this, 'ajax_migration_step' ] );
	}

	public function register_tools( $tools ) {
		$tools[ self::DRY_RUN_TOOL_ID ] = [
			'name'     => __( 'Vietnam Store Toolkit for WooCommerce: scan data from Le Van Toan plugins', 'yoohw-vietnam-store-tools' ),
			'button'   => __( 'Scan data', 'yoohw-vietnam-store-tools' ),
			'desc'     => __( 'Scan order addresses, customer profile addresses, and shipment data created by Le Van Toan plugins without changing data. Use this before running the sync.', 'yoohw-vietnam-store-tools' ),
			'callback' => [ $this, 'run_dry_run' ],
		];

		$tools[ self::MIGRATE_TOOL_ID ] = [
			'name'     => __( 'Vietnam Store Toolkit for WooCommerce: sync data from Le Van Toan plugins', 'yoohw-vietnam-store-tools' ),
			'button'   => __( 'Sync all', 'yoohw-vietnam-store-tools' ),
			'desc'     => sprintf(
				/* translators: %d: batch size. */
				__( 'Sync all safe order addresses, customer profile addresses, and shipment data created by Le Van Toan plugins into Vietnam Store Toolkit for WooCommerce data. The browser will process the sync in chunks of up to %d address rows per data type. Address values are backed up before changes are saved.', 'yoohw-vietnam-store-tools' ),
				self::ADDRESS_BATCH_SIZE
			) . $this->get_progress_markup(),
			'callback' => [ $this, 'run_migration' ],
		];

		return $tools;
	}

	public function enqueue_assets() {
		if ( 'wc-status' !== sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'page' ) ) ) {
			return;
		}

		if ( 'tools' !== sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_query_text( 'tab' ) ) ) {
			return;
		}

		wp_enqueue_style(
			'vck-devvn-migration-tools',
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/css/admin/devvn-migration-tools.css',
			[],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION
		);

		wp_enqueue_script(
			'vck-devvn-migration-tools',
			YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_URL . 'assets/js/admin/devvn-migration-tools.js',
			[ 'jquery' ],
			YOOHW_VIETNAM_STORE_TOOLS_VERSION,
			true
		);

		wp_localize_script(
			'vck-devvn-migration-tools',
			'yoohwVietnamStoreToolsDevvnMigrationTools',
			[
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'yoohw_vietnam_store_tools_devvn_migration' ),
				'migrationTool' => self::MIGRATE_TOOL_ID,
				'strings'       => [
					'preparing'      => __( 'Preparing sync...', 'yoohw-vietnam-store-tools' ),
					'processing'     => __( 'Syncing...', 'yoohw-vietnam-store-tools' ),
					'completed'      => __( 'Sync completed.', 'yoohw-vietnam-store-tools' ),
					'noData'         => __( 'No safe data from Le Van Toan plugins is available to sync.', 'yoohw-vietnam-store-tools' ),
					'stopped'        => __( 'Sync stopped because no progress was made in the latest step. Please run the scan tool and review the remaining data.', 'yoohw-vietnam-store-tools' ),
					'requestFailed'  => __( 'Sync request failed. Please try again.', 'yoohw-vietnam-store-tools' ),
					'confirmMigrate' => __( 'This will sync all safe data from Le Van Toan plugins in the background. Continue?', 'yoohw-vietnam-store-tools' ),
					'orderAddresses' => __( 'order address rows', 'yoohw-vietnam-store-tools' ),
					'userAddresses'  => __( 'customer address rows', 'yoohw-vietnam-store-tools' ),
					'trackingOrders' => __( 'shipment orders', 'yoohw-vietnam-store-tools' ),
				],
			]
		);
	}

	public function run_dry_run() {
		$this->verify_permission();

		$address_report          = $this->analyze_legacy_addresses();
		$customer_address_report = $this->analyze_legacy_customer_addresses();
		$tracking_report         = $this->analyze_ghtk_tracking_meta();

		return $this->format_dry_run_message( $address_report, $customer_address_report, $tracking_report );
	}

	public function run_migration() {
		$this->verify_permission();

		$address_result          = $this->migrate_legacy_addresses( self::ADDRESS_BATCH_SIZE );
		$customer_address_result = $this->migrate_legacy_customer_addresses( self::CUSTOMER_ADDRESS_BATCH_SIZE );
		$tracking_result         = $this->sync_ghtk_tracking_meta( self::TRACKING_BATCH_SIZE );
		$remaining               = [
			'orders'    => $this->analyze_legacy_addresses(),
			'customers' => $this->analyze_legacy_customer_addresses(),
		];

		return $this->format_migration_message( $address_result, $customer_address_result, $tracking_result, $remaining );
	}

	public function ajax_migration_step() {
		try {
			if ( ! check_ajax_referer( 'yoohw_vietnam_store_tools_devvn_migration', 'nonce', false ) ) {
				wp_send_json_error(
					[
						'message' => __( 'Security check failed. Please reload the page and try again.', 'yoohw-vietnam-store-tools' ),
					],
					403
				);
				return;
			}

			$this->verify_permission();

			$mode = sanitize_key( Yoohw_Vietnam_Store_Tools_Request_Security::get_post_text( 'mode', 'step' ) );

			if ( 'start' === $mode ) {
				wp_send_json_success( $this->get_ajax_migration_status() );
				return;
			}

			$address_result          = $this->migrate_legacy_addresses( self::ADDRESS_BATCH_SIZE );
			$customer_address_result = $this->migrate_legacy_customer_addresses( self::CUSTOMER_ADDRESS_BATCH_SIZE );
			$tracking_result         = $this->sync_ghtk_tracking_meta( self::TRACKING_BATCH_SIZE );
			$status                  = $this->get_ajax_migration_status();
			$made_progress           = ( (int) $address_result['addresses_moved'] + (int) $customer_address_result['addresses_moved'] + (int) $tracking_result['orders_synced'] ) > 0;

			$status['step'] = [
				'ordersUpdated'           => (int) $address_result['orders_updated'],
				'usersUpdated'            => (int) $customer_address_result['users_updated'],
				'orderAddressesMoved'     => (int) $address_result['addresses_moved'],
				'customerAddressesMoved'  => (int) $customer_address_result['addresses_moved'],
				'addressesMoved'          => (int) $address_result['addresses_moved'] + (int) $customer_address_result['addresses_moved'],
				'trackingSynced'          => (int) $tracking_result['orders_synced'],
				'madeProgress'            => $made_progress,
				'addressErrors'           => array_slice( $address_result['errors'], 0, 5 ),
				'customerAddressErrors'   => array_slice( $customer_address_result['errors'], 0, 5 ),
				'trackingErrors'          => array_slice( $tracking_result['errors'], 0, 5 ),
			];

			if ( ! $made_progress && ! $status['done'] ) {
				$status['stopped'] = true;
				$status['done']    = true;
			}

			wp_send_json_success( $status );
			return;
		} catch ( Exception $exception ) {
			wp_send_json_error(
				[
					'message' => $exception->getMessage(),
				]
			);
			return;
		}
	}

	private function verify_permission() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			throw new Exception( esc_html__( 'You do not have permission to run this tool.', 'yoohw-vietnam-store-tools' ) );
		}
	}

	private function analyze_legacy_addresses() {
		$rows   = $this->get_legacy_address_rows();
		$report = [
			'total'              => 0,
			'exact_mappable'     => 0,
			'needs_review'       => 0,
			'billing_total'      => 0,
			'shipping_total'     => 0,
			'unmatched_samples'  => [],
			'mappable_order_ids' => [],
			'three_level_safe'    => 0,
			'two_level_safe'      => 0,
			'country_only_safe'   => 0,
		];

		foreach ( $rows as $row ) {
			$report['total']++;

			if ( 'billing' === $row['address_type'] ) {
				$report['billing_total']++;
			} elseif ( 'shipping' === $row['address_type'] ) {
				$report['shipping_total']++;
			}

			$migration = $this->get_address_migration( $row );

			if ( $migration ) {
				$report['exact_mappable']++;
				$report['mappable_order_ids'][ $row['order_id'] ] = true;

				if ( in_array( $migration['schema'], [ 'devvn_three_level', 'devvn_three_level_legacy_exact' ], true ) ) {
					$report['three_level_safe']++;
				} elseif ( in_array( $migration['schema'], [ 'devvn_two_level', 'devvn_two_level_legacy_exact' ], true ) ) {
					$report['two_level_safe']++;
				} elseif ( 'vck_already_normalized_missing_country' === $migration['schema'] ) {
					$report['country_only_safe']++;
				}
			} else {
				$report['needs_review']++;

				if ( count( $report['unmatched_samples'] ) < 8 ) {
					$report['unmatched_samples'][] = sprintf(
						/* translators: 1: order ID, 2: address type, 3: state, 4: city, 5: address line 2. */
						__( 'Order #%1$d %2$s: %3$s / %4$s / %5$s', 'yoohw-vietnam-store-tools' ),
						absint( $row['order_id'] ),
						$this->get_address_type_label( $row['address_type'] ),
						(string) $row['state'],
						(string) $row['city'],
						(string) $row['address_2']
					);
				}
			}
		}

		$report['mappable_orders'] = count( $report['mappable_order_ids'] );
		unset( $report['mappable_order_ids'] );

		return $report;
	}

	private function analyze_legacy_customer_addresses() {
		$rows   = $this->get_legacy_customer_address_rows();
		$report = [
			'total'             => 0,
			'exact_mappable'    => 0,
			'needs_review'      => 0,
			'billing_total'     => 0,
			'shipping_total'    => 0,
			'unmatched_samples' => [],
			'mappable_user_ids' => [],
			'three_level_safe'   => 0,
			'two_level_safe'     => 0,
			'country_only_safe'  => 0,
		];

		foreach ( $rows as $row ) {
			$report['total']++;

			if ( 'billing' === $row['address_type'] ) {
				$report['billing_total']++;
			} elseif ( 'shipping' === $row['address_type'] ) {
				$report['shipping_total']++;
			}

			$migration = $this->get_address_migration( $row );

			if ( $migration ) {
				$report['exact_mappable']++;
				$report['mappable_user_ids'][ $row['user_id'] ] = true;

				if ( in_array( $migration['schema'], [ 'devvn_three_level', 'devvn_three_level_legacy_exact' ], true ) ) {
					$report['three_level_safe']++;
				} elseif ( in_array( $migration['schema'], [ 'devvn_two_level', 'devvn_two_level_legacy_exact' ], true ) ) {
					$report['two_level_safe']++;
				} elseif ( 'vck_already_normalized_missing_country' === $migration['schema'] ) {
					$report['country_only_safe']++;
				}
			} else {
				$report['needs_review']++;

				if ( count( $report['unmatched_samples'] ) < 8 ) {
					$report['unmatched_samples'][] = sprintf(
						/* translators: 1: user ID, 2: address type, 3: state, 4: city, 5: address line 2. */
						__( 'User #%1$d %2$s: %3$s / %4$s / %5$s', 'yoohw-vietnam-store-tools' ),
						absint( $row['user_id'] ),
						$this->get_address_type_label( $row['address_type'] ),
						(string) $row['state'],
						(string) $row['city'],
						(string) $row['address_2']
					);
				}
			}
		}

		$report['mappable_users'] = count( $report['mappable_user_ids'] );
		unset( $report['mappable_user_ids'] );

		return $report;
	}

	private function get_ajax_migration_status() {
		$address_report          = $this->analyze_legacy_addresses();
		$customer_address_report = $this->analyze_legacy_customer_addresses();
		$tracking_report         = $this->analyze_ghtk_tracking_meta();
		$remaining               = (int) $address_report['exact_mappable'] + (int) $customer_address_report['exact_mappable'] + (int) $tracking_report['needs_sync_count'];

		return [
			'remaining'               => $remaining,
			'done'                    => 0 === $remaining,
			'addressesTotal'          => (int) $address_report['total'],
			'addressesSafe'           => (int) $address_report['exact_mappable'],
			'addressesReview'         => (int) $address_report['needs_review'],
			'customerAddressesTotal'  => (int) $customer_address_report['total'],
			'customerAddressesSafe'   => (int) $customer_address_report['exact_mappable'],
			'customerAddressesReview' => (int) $customer_address_report['needs_review'],
			'trackingTotal'           => (int) $tracking_report['total_orders'],
			'trackingRemaining'       => (int) $tracking_report['needs_sync_count'],
			'message'                 => $this->format_ajax_status_message( $address_report, $customer_address_report, $tracking_report ),
		];
	}

	private function migrate_legacy_addresses( $batch_size ) {
		$rows          = $this->get_legacy_address_rows();
		$grouped_rows  = [];
		$address_count = 0;
		$skipped_count = 0;

		foreach ( $rows as $row ) {
			if ( $address_count >= $batch_size ) {
				break;
			}

			$migration = $this->get_address_migration( $row );

			if ( ! $migration ) {
				$skipped_count++;
				continue;
			}

			$row['new_state'] = $migration['province_code'];
			$row['new_city']  = $migration['ward_code'];
			$row['schema']    = $migration['schema'];

			if ( ! isset( $grouped_rows[ $row['order_id'] ] ) ) {
				$grouped_rows[ $row['order_id'] ] = [];
			}

			$grouped_rows[ $row['order_id'] ][ $row['address_type'] ] = $row;
			$address_count++;
		}

		$result = [
			'orders_updated'  => 0,
			'addresses_moved' => 0,
			'skipped'         => $skipped_count,
			'errors'          => [],
		];

		foreach ( $grouped_rows as $order_id => $order_rows ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$result['errors'][] = sprintf(
					/* translators: %d: order ID. */
					__( 'Order #%d could not be loaded.', 'yoohw-vietnam-store-tools' ),
					absint( $order_id )
				);
				continue;
			}

			foreach ( $order_rows as $address_type => $row ) {
				$this->backup_legacy_address_values( $order, $address_type, $row );
				$this->set_order_address_field( $order, $address_type, 'state', $row['new_state'] );
				$this->set_order_address_field( $order, $address_type, 'city', $row['new_city'] );
				$this->set_order_address_field( $order, $address_type, 'address_2', '' );

				$order->update_meta_data( '_vck_address_migration_source', 'devvn-woo-ghtk' );
				$order->update_meta_data( '_vck_address_migration_status', 'migrated_exact_ward_code' );
				$order->update_meta_data( '_vck_address_migration_schema', $row['schema'] );
				$order->update_meta_data( '_vck_address_migration_last_run', gmdate( 'c' ) );

				$result['addresses_moved']++;
			}

			$order->save();
			$result['orders_updated']++;
		}

		return $result;
	}

	private function migrate_legacy_customer_addresses( $batch_size ) {
		$rows          = $this->get_legacy_customer_address_rows();
		$grouped_rows  = [];
		$address_count = 0;
		$skipped_count = 0;

		foreach ( $rows as $row ) {
			if ( $address_count >= $batch_size ) {
				break;
			}

			$migration = $this->get_address_migration( $row );

			if ( ! $migration ) {
				$skipped_count++;
				continue;
			}

			$row['new_state'] = $migration['province_code'];
			$row['new_city']  = $migration['ward_code'];
			$row['schema']    = $migration['schema'];

			if ( ! isset( $grouped_rows[ $row['user_id'] ] ) ) {
				$grouped_rows[ $row['user_id'] ] = [];
			}

			$grouped_rows[ $row['user_id'] ][ $row['address_type'] ] = $row;
			$address_count++;
		}

		$result = [
			'users_updated'    => 0,
			'addresses_moved'  => 0,
			'skipped'          => $skipped_count,
			'errors'           => [],
		];

		foreach ( $grouped_rows as $user_id => $user_rows ) {
			$user = get_userdata( $user_id );

			if ( ! $user ) {
				$result['errors'][] = sprintf(
					/* translators: %d: user ID. */
					__( 'User #%d could not be loaded.', 'yoohw-vietnam-store-tools' ),
					absint( $user_id )
				);
				continue;
			}

			foreach ( $user_rows as $address_type => $row ) {
				$this->backup_legacy_customer_address_values( $user_id, $address_type, $row );
				$this->set_customer_address_field( $user_id, $address_type, 'country', 'VN' );
				$this->set_customer_address_field( $user_id, $address_type, 'state', $row['new_state'] );
				$this->set_customer_address_field( $user_id, $address_type, 'city', $row['new_city'] );
				$this->set_customer_address_field( $user_id, $address_type, 'address_2', '' );

				update_user_meta( $user_id, '_vck_user_address_migration_source', 'devvn-woo-ghtk' );
				update_user_meta( $user_id, '_vck_user_address_migration_status', 'migrated_exact_ward_code' );
				update_user_meta( $user_id, '_vck_user_address_migration_schema', $row['schema'] );
				update_user_meta( $user_id, '_vck_user_address_migration_last_run', gmdate( 'c' ) );

				$result['addresses_moved']++;
			}

			clean_user_cache( $user_id );
			$result['users_updated']++;
		}

		return $result;
	}

	private function analyze_ghtk_tracking_meta() {
		$order_ids = $this->get_ghtk_order_ids( 0, false );

		return [
			'total_orders'     => count( $order_ids ),
			'needs_sync_count' => count( $this->get_ghtk_order_ids( 0, true ) ),
		];
	}

	private function sync_ghtk_tracking_meta( $batch_size ) {
		$order_ids = $this->get_ghtk_order_ids( $batch_size, true );
		$result    = [
			'orders_synced' => 0,
			'errors'        => [],
		];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$result['errors'][] = sprintf(
					/* translators: %d: order ID. */
					__( 'Order #%d could not be loaded.', 'yoohw-vietnam-store-tools' ),
					absint( $order_id )
				);
				continue;
			}

			$ghtk_order_code = (string) $order->get_meta( '_ghtk_ordercode', true );

			if ( '' === $ghtk_order_code ) {
				continue;
			}

			$ghtk_full = $order->get_meta( '_order_ghtk_full', true );
			$ghtk_full = is_array( $ghtk_full ) ? $ghtk_full : [];
			$ghtk_info = isset( $ghtk_full['order'] ) && is_array( $ghtk_full['order'] ) ? $ghtk_full['order'] : [];

			$order->update_meta_data( '_vck_shipping_provider', 'ghtk' );
			$order->update_meta_data( '_vck_shipping_provider_name', 'Giao Hàng Tiết Kiệm' );
			$order->update_meta_data( '_vck_shipping_label_id', $this->get_ghtk_label_id( $ghtk_order_code, $ghtk_info ) );
			$order->update_meta_data( '_vck_shipping_tracking_code', $this->get_ghtk_tracking_code( $ghtk_order_code, $ghtk_info ) );

			$this->update_order_meta_if_present( $order, '_vck_shipping_status_id', $ghtk_info, 'status_id' );
			$this->update_order_meta_if_present( $order, '_vck_shipping_status', $ghtk_info, 'status' );
			$this->update_order_meta_if_present( $order, '_vck_shipping_fee', $ghtk_info, 'fee' );
			$this->update_order_meta_if_present( $order, '_vck_shipping_insurance_fee', $ghtk_info, 'insurance_fee' );
			$this->update_order_meta_if_present( $order, '_vck_shipping_tracking_id', $ghtk_info, 'tracking_id' );
			$order->update_meta_data( '_vck_shipping_migration_source', 'devvn-woo-ghtk' );
			$order->update_meta_data( '_vck_shipping_migration_last_run', gmdate( 'c' ) );
			$order->save();

			$result['orders_synced']++;
		}

		return $result;
	}

	private function backup_legacy_address_values( $order, $address_type, $row ) {
		foreach ( [ 'state', 'city', 'address_2' ] as $field ) {
			$key = '_vck_legacy_devvn_' . $address_type . '_' . $field;

			if ( ! $order->meta_exists( $key ) ) {
				$order->add_meta_data( $key, (string) $row[ $field ], true );
			}
		}

		$migrated_at_key = '_vck_legacy_devvn_' . $address_type . '_migrated_at';

		if ( ! $order->meta_exists( $migrated_at_key ) ) {
			$order->add_meta_data( $migrated_at_key, gmdate( 'c' ), true );
		}
	}

	private function backup_legacy_customer_address_values( $user_id, $address_type, $row ) {
		foreach ( [ 'country', 'state', 'city', 'address_2' ] as $field ) {
			$key = '_vck_legacy_devvn_user_' . $address_type . '_' . $field;

			if ( ! metadata_exists( 'user', $user_id, $key ) ) {
				add_user_meta( $user_id, $key, (string) $row[ $field ], true );
			}
		}

		$migrated_at_key = '_vck_legacy_devvn_user_' . $address_type . '_migrated_at';

		if ( ! metadata_exists( 'user', $user_id, $migrated_at_key ) ) {
			add_user_meta( $user_id, $migrated_at_key, gmdate( 'c' ), true );
		}
	}

	private function set_order_address_field( $order, $address_type, $field, $value ) {
		$method = 'set_' . $address_type . '_' . $field;

		if ( is_callable( [ $order, $method ] ) ) {
			$order->$method( $value );
		}
	}

	private function set_customer_address_field( $user_id, $address_type, $field, $value ) {
		update_user_meta( $user_id, $address_type . '_' . $field, wc_clean( $value ) );
	}

	private function update_order_meta_if_present( $order, $meta_key, $source, $source_key ) {
		if ( isset( $source[ $source_key ] ) && '' !== (string) $source[ $source_key ] ) {
			$order->update_meta_data( $meta_key, wc_clean( $source[ $source_key ] ) );
		}
	}

	private function get_address_migration( $row ) {
		$state     = isset( $row['state'] ) ? trim( (string) $row['state'] ) : '';
		$city      = isset( $row['city'] ) ? trim( (string) $row['city'] ) : '';
		$address_2 = isset( $row['address_2'] ) ? trim( (string) $row['address_2'] ) : '';
		$map       = $this->get_ward_to_province_map();

		if ( preg_match( '/^\d{3}$/', $city ) && preg_match( '/^\d{5}$/', $address_2 ) ) {
			$legacy_migration = $this->get_legacy_ward_migration( $address_2, $city );

			if ( is_array( $legacy_migration ) ) {
				$legacy_migration['schema'] = 'devvn_three_level_legacy_exact';
				return $legacy_migration;
			}

			if ( false === $legacy_migration ) {
				return false;
			}

			$ward_code = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $address_2 );

			if ( isset( $map[ $ward_code ] ) ) {
				return [
					'province_code' => $map[ $ward_code ],
					'ward_code'     => $ward_code,
					'schema'        => 'devvn_three_level',
				];
			}
		}

		if ( preg_match( '/^\d{5}$/', $city ) && '' === trim( $address_2 ) && ! $this->is_official_province_code( $state ) ) {
			$legacy_migration = $this->get_legacy_ward_migration( $city );

			if ( is_array( $legacy_migration ) ) {
				$legacy_migration['schema'] = 'devvn_two_level_legacy_exact';
				return $legacy_migration;
			}

			if ( false === $legacy_migration ) {
				return false;
			}

			$ward_code = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $city );

			if ( isset( $map[ $ward_code ] ) ) {
				return [
					'province_code' => $map[ $ward_code ],
					'ward_code'     => $ward_code,
					'schema'        => 'devvn_two_level',
				];
			}
		}

		if ( preg_match( '/^\d{5}$/', $city ) && '' === trim( $address_2 ) && $this->is_official_province_code( $state ) ) {
			$ward_code = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $city );

			if ( isset( $map[ $ward_code ] ) && (string) $state === (string) $map[ $ward_code ] ) {
				return [
					'province_code' => (string) $state,
					'ward_code'     => $ward_code,
					'schema'        => 'vck_already_normalized_missing_country',
				];
			}
		}

		return false;
	}

	private function get_legacy_ward_migration( $ward_code, $district_code = '' ) {
		$ward_code     = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $ward_code );
		$district_code = trim( (string) $district_code );
		$aliases       = $this->get_legacy_ward_aliases();

		if ( isset( $aliases['exact'][ $ward_code ] ) && is_array( $aliases['exact'][ $ward_code ] ) ) {
			$alias = $aliases['exact'][ $ward_code ];

			if ( '' !== $district_code && isset( $alias['old_district_code'] ) && (string) $alias['old_district_code'] !== str_pad( (string) absint( $district_code ), 3, '0', STR_PAD_LEFT ) ) {
				return false;
			}

			$new_ward     = isset( $alias['ward_code'] ) ? Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_ward_code_value( $alias['ward_code'] ) : '';
			$new_province = isset( $alias['province_code'] ) ? Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::normalize_province_code_value( $alias['province_code'] ) : '';
			$map          = $this->get_ward_to_province_map();

			if ( '' !== $new_ward && '' !== $new_province && isset( $map[ $new_ward ] ) && (string) $new_province === (string) $map[ $new_ward ] ) {
				return [
					'province_code' => $new_province,
					'ward_code'     => $new_ward,
				];
			}

			return false;
		}

		if ( isset( $aliases['non_exact'][ $ward_code ] ) ) {
			return false;
		}

		return null;
	}

	private function is_official_province_code( $state ) {
		return preg_match( '/^\d{2}$/', (string) $state ) && Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::province_exists( $state );
	}

	private function get_address_type_label( $address_type ) {
		if ( 'billing' === $address_type ) {
			return __( 'Billing', 'yoohw-vietnam-store-tools' );
		}

		if ( 'shipping' === $address_type ) {
			return __( 'Shipping', 'yoohw-vietnam-store-tools' );
		}

		return ucfirst( (string) $address_type );
	}

	private function get_ward_to_province_map() {
		if ( null !== $this->ward_to_province ) {
			return $this->ward_to_province;
		}

		$this->ward_to_province = [];
		$raw_data               = Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data::get_raw_data();

		foreach ( $raw_data['provinces'] as $province_code => $province ) {
			if ( empty( $province['wards'] ) || ! is_array( $province['wards'] ) ) {
				continue;
			}

			foreach ( $province['wards'] as $ward_code => $ward ) {
				$this->ward_to_province[ (string) $ward_code ] = (string) $province_code;
			}
		}

		return $this->ward_to_province;
	}

	private function get_legacy_ward_aliases() {
		if ( null !== $this->legacy_ward_aliases ) {
			return $this->legacy_ward_aliases;
		}

		$path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'data/vietnam-legacy-ward-aliases.php';
		$data = file_exists( $path ) ? include $path : [];
		$data = is_array( $data ) ? $data : [];

		$this->legacy_ward_aliases = [
			'exact'     => isset( $data['exact'] ) && is_array( $data['exact'] ) ? $data['exact'] : [],
			'non_exact' => isset( $data['non_exact'] ) && is_array( $data['non_exact'] ) ? $data['non_exact'] : [],
		];

		return $this->legacy_ward_aliases;
	}

	private function get_legacy_address_rows() {
		if ( $this->is_hpos_authoritative() ) {
			return $this->get_hpos_legacy_address_rows();
		}

		return $this->get_postmeta_legacy_address_rows();
	}

	private function get_hpos_legacy_address_rows() {
		global $wpdb;

		$addresses_table = $wpdb->prefix . 'wc_order_addresses';
		$orders_table    = $wpdb->prefix . 'wc_orders';

		if ( ! $this->table_exists( $addresses_table ) || ! $this->table_exists( $orders_table ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.order_id, a.address_type, a.country, COALESCE(a.state, '') AS state, a.city, COALESCE(a.address_2, '') AS address_2
				FROM %i a
				INNER JOIN %i o ON o.id = a.order_id
				WHERE o.type = %s
					AND a.address_type IN (%s, %s)
					AND a.country = %s
					AND (
						(TRIM(a.city) REGEXP '^[0-9]{3}$' AND TRIM(a.address_2) REGEXP '^[0-9]{5}$')
						OR (
							TRIM(a.city) REGEXP '^[0-9]{5}$'
							AND (a.address_2 IS NULL OR TRIM(a.address_2) = '')
							AND (a.state IS NULL OR TRIM(a.state) = '' OR TRIM(a.state) NOT REGEXP '^[0-9]{2}$')
						)
					)
				ORDER BY a.order_id ASC, a.address_type ASC",
				$addresses_table,
				$orders_table,
				'shop_order',
				'billing',
				'shipping',
				'VN'
			),
			ARRAY_A
		);
	}


	private function get_postmeta_legacy_address_rows() {
		global $wpdb;

		$rows = [];

		foreach ( [ 'billing', 'shipping' ] as $address_type ) {
			$country_key   = '_' . $address_type . '_country';
			$state_key     = '_' . $address_type . '_state';
			$city_key      = '_' . $address_type . '_city';
			$address_2_key = '_' . $address_type . '_address_2';

			$rows = array_merge(
				$rows,
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
				$wpdb->get_results(
					$wpdb->prepare(
						"SELECT country.post_id AS order_id, %s AS address_type, country.meta_value AS country, COALESCE(state.meta_value, '') AS state, city.meta_value AS city, COALESCE(address_2.meta_value, '') AS address_2
						FROM %i country
						INNER JOIN %i posts ON posts.ID = country.post_id
						LEFT JOIN %i state ON state.post_id = country.post_id AND state.meta_key = %s
						INNER JOIN %i city ON city.post_id = country.post_id AND city.meta_key = %s
						LEFT JOIN %i address_2 ON address_2.post_id = country.post_id AND address_2.meta_key = %s
						WHERE posts.post_type = %s
							AND country.meta_key = %s
							AND country.meta_value = %s
							AND (
								(TRIM(city.meta_value) REGEXP '^[0-9]{3}$' AND TRIM(address_2.meta_value) REGEXP '^[0-9]{5}$')
								OR (
									TRIM(city.meta_value) REGEXP '^[0-9]{5}$'
									AND (address_2.meta_id IS NULL OR TRIM(address_2.meta_value) = '')
									AND (state.meta_id IS NULL OR TRIM(state.meta_value) = '' OR TRIM(state.meta_value) NOT REGEXP '^[0-9]{2}$')
								)
							)
						ORDER BY country.post_id ASC",
						$address_type,
						$wpdb->postmeta,
						$wpdb->posts,
						$wpdb->postmeta,
						$state_key,
						$wpdb->postmeta,
						$city_key,
						$wpdb->postmeta,
						$address_2_key,
						'shop_order',
						$country_key,
						'VN'
					),
					ARRAY_A
				)
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a['order_id'] <=> (int) $b['order_id'];
			}
		);

		return $rows;
	}

	private function get_legacy_customer_address_rows() {
		global $wpdb;

		$rows = [];

		foreach ( [ 'billing', 'shipping' ] as $address_type ) {
			$country_key   = $address_type . '_country';
			$state_key     = $address_type . '_state';
			$city_key      = $address_type . '_city';
			$address_2_key = $address_type . '_address_2';

			$rows = array_merge(
				$rows,
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce customer profile data on demand; caching would return stale migration state.
				$wpdb->get_results(
					$wpdb->prepare(
						"SELECT city.user_id, %s AS address_type, COALESCE(country.meta_value, '') AS country, COALESCE(state.meta_value, '') AS state, city.meta_value AS city, COALESCE(address_2.meta_value, '') AS address_2
						FROM %i city
						INNER JOIN %i users ON users.ID = city.user_id
						LEFT JOIN %i country ON country.user_id = city.user_id AND country.meta_key = %s
						LEFT JOIN %i state ON state.user_id = city.user_id AND state.meta_key = %s
						LEFT JOIN %i address_2 ON address_2.user_id = city.user_id AND address_2.meta_key = %s
						WHERE city.meta_key = %s
							AND (country.umeta_id IS NULL OR TRIM(country.meta_value) = '' OR TRIM(country.meta_value) = %s)
							AND (
								(TRIM(city.meta_value) REGEXP '^[0-9]{3}$' AND TRIM(address_2.meta_value) REGEXP '^[0-9]{5}$')
								OR (
									TRIM(city.meta_value) REGEXP '^[0-9]{5}$'
									AND (address_2.umeta_id IS NULL OR TRIM(address_2.meta_value) = '')
									AND (state.umeta_id IS NULL OR TRIM(state.meta_value) = '' OR TRIM(state.meta_value) NOT REGEXP '^[0-9]{2}$')
								)
								OR (
									(country.umeta_id IS NULL OR TRIM(country.meta_value) = '')
									AND TRIM(city.meta_value) REGEXP '^[0-9]{5}$'
									AND (address_2.umeta_id IS NULL OR TRIM(address_2.meta_value) = '')
									AND state.umeta_id IS NOT NULL
									AND TRIM(state.meta_value) REGEXP '^[0-9]{2}$'
								)
							)
						ORDER BY city.user_id ASC",
						$address_type,
						$wpdb->usermeta,
						$wpdb->users,
						$wpdb->usermeta,
						$country_key,
						$wpdb->usermeta,
						$state_key,
						$wpdb->usermeta,
						$address_2_key,
						$city_key,
						'VN'
					),
					ARRAY_A
				)
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a['user_id'] <=> (int) $b['user_id'];
			}
		);

		return $rows;
	}


	private function get_ghtk_order_ids( $limit = 0, $only_missing_normalized_meta = false ) {
		if ( $this->is_hpos_authoritative() ) {
			return $this->get_hpos_ghtk_order_ids( $limit, $only_missing_normalized_meta );
		}

		return $this->get_postmeta_ghtk_order_ids( $limit, $only_missing_normalized_meta );
	}

	private function get_hpos_ghtk_order_ids( $limit, $only_missing_normalized_meta ) {
		global $wpdb;

		$meta_table = $wpdb->prefix . 'wc_orders_meta';

		if ( ! $this->table_exists( $meta_table ) ) {
			return [];
		}

		if ( $only_missing_normalized_meta && $limit > 0 ) {
			return array_map(
				'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT ghtk.order_id
						FROM %i ghtk
						LEFT JOIN %i vck ON vck.order_id = ghtk.order_id AND vck.meta_key = %s
						WHERE ghtk.meta_key = %s
							AND ghtk.meta_value <> ''
							AND (vck.id IS NULL OR vck.meta_value <> %s)
						ORDER BY ghtk.order_id ASC
						LIMIT %d",
						$meta_table,
						$meta_table,
						'_vck_shipping_provider',
						'_ghtk_ordercode',
						'ghtk',
						absint( $limit )
					)
				)
			);
		}

		if ( $only_missing_normalized_meta ) {
			return array_map(
				'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT ghtk.order_id
						FROM %i ghtk
						LEFT JOIN %i vck ON vck.order_id = ghtk.order_id AND vck.meta_key = %s
						WHERE ghtk.meta_key = %s
							AND ghtk.meta_value <> ''
							AND (vck.id IS NULL OR vck.meta_value <> %s)
						ORDER BY ghtk.order_id ASC",
						$meta_table,
						$meta_table,
						'_vck_shipping_provider',
						'_ghtk_ordercode',
						'ghtk'
					)
				)
			);
		}

		if ( $limit > 0 ) {
			return array_map(
				'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT ghtk.order_id
						FROM %i ghtk
						WHERE ghtk.meta_key = %s
							AND ghtk.meta_value <> ''
						ORDER BY ghtk.order_id ASC
						LIMIT %d",
						$meta_table,
						'_ghtk_ordercode',
						absint( $limit )
					)
				)
			);
		}

		return array_map(
			'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT ghtk.order_id
					FROM %i ghtk
					WHERE ghtk.meta_key = %s
						AND ghtk.meta_value <> ''
					ORDER BY ghtk.order_id ASC",
					$meta_table,
					'_ghtk_ordercode'
				)
			)
		);
	}


	private function get_postmeta_ghtk_order_ids( $limit, $only_missing_normalized_meta ) {
		global $wpdb;

		if ( $only_missing_normalized_meta && $limit > 0 ) {
			return array_map(
				'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT ghtk.post_id
						FROM %i ghtk
						INNER JOIN %i posts ON posts.ID = ghtk.post_id
						LEFT JOIN %i vck ON vck.post_id = ghtk.post_id AND vck.meta_key = %s
						WHERE ghtk.meta_key = %s
							AND ghtk.meta_value <> ''
							AND (vck.meta_id IS NULL OR vck.meta_value <> %s)
							AND posts.post_type = %s
						ORDER BY ghtk.post_id ASC
						LIMIT %d",
						$wpdb->postmeta,
						$wpdb->posts,
						$wpdb->postmeta,
						'_vck_shipping_provider',
						'_ghtk_ordercode',
						'ghtk',
						'shop_order',
						absint( $limit )
					)
				)
			);
		}

		if ( $only_missing_normalized_meta ) {
			return array_map(
				'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT ghtk.post_id
						FROM %i ghtk
						INNER JOIN %i posts ON posts.ID = ghtk.post_id
						LEFT JOIN %i vck ON vck.post_id = ghtk.post_id AND vck.meta_key = %s
						WHERE ghtk.meta_key = %s
							AND ghtk.meta_value <> ''
							AND (vck.meta_id IS NULL OR vck.meta_value <> %s)
							AND posts.post_type = %s
						ORDER BY ghtk.post_id ASC",
						$wpdb->postmeta,
						$wpdb->posts,
						$wpdb->postmeta,
						'_vck_shipping_provider',
						'_ghtk_ordercode',
						'ghtk',
						'shop_order'
					)
				)
			);
		}

		if ( $limit > 0 ) {
			return array_map(
				'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT ghtk.post_id
						FROM %i ghtk
						INNER JOIN %i posts ON posts.ID = ghtk.post_id
						WHERE ghtk.meta_key = %s
							AND ghtk.meta_value <> ''
							AND posts.post_type = %s
						ORDER BY ghtk.post_id ASC
						LIMIT %d",
						$wpdb->postmeta,
						$wpdb->posts,
						'_ghtk_ordercode',
						'shop_order',
						absint( $limit )
					)
				)
			);
		}

		return array_map(
			'absint',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration scans current WooCommerce order data on demand; caching would return stale migration state.
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT ghtk.post_id
					FROM %i ghtk
					INNER JOIN %i posts ON posts.ID = ghtk.post_id
					WHERE ghtk.meta_key = %s
						AND ghtk.meta_value <> ''
						AND posts.post_type = %s
					ORDER BY ghtk.post_id ASC",
					$wpdb->postmeta,
					$wpdb->posts,
					'_ghtk_ordercode',
					'shop_order'
				)
			)
		);
	}


	private function get_ghtk_label_id( $ghtk_order_code, $ghtk_info ) {
		foreach ( [ 'label_id', 'label' ] as $key ) {
			if ( ! empty( $ghtk_info[ $key ] ) ) {
				return wc_clean( $ghtk_info[ $key ] );
			}
		}

		return wc_clean( $ghtk_order_code );
	}

	private function get_ghtk_tracking_code( $ghtk_order_code, $ghtk_info ) {
		if ( ! empty( $ghtk_info['tracking_id'] ) ) {
			return wc_clean( $ghtk_info['tracking_id'] );
		}

		$tracking_code = strrchr( $ghtk_order_code, '.' );

		return false !== $tracking_code ? wc_clean( substr( $tracking_code, 1 ) ) : wc_clean( $ghtk_order_code );
	}

	private function format_dry_run_message( $address_report, $customer_address_report, $tracking_report ) {
		$message = sprintf(
			/* translators: 1: total rows, 2: mappable rows, 3: review rows, 4: billing rows, 5: shipping rows, 6: orders, 7: old three-level rows, 8: new two-level rows. */
			__( 'Le Van Toan plugin data scan complete. Order address rows: %1$d total (%4$d billing, %5$d shipping). Safe order sync: %2$d address rows across %6$d orders (%7$d old three-level rows, %8$d new two-level rows). Order address rows needing official mapping/manual review: %3$d.', 'yoohw-vietnam-store-tools' ),
			(int) $address_report['total'],
			(int) $address_report['exact_mappable'],
			(int) $address_report['needs_review'],
			(int) $address_report['billing_total'],
			(int) $address_report['shipping_total'],
			(int) $address_report['mappable_orders'],
			(int) $address_report['three_level_safe'],
			(int) $address_report['two_level_safe']
		);

		$message .= ' ' . sprintf(
			/* translators: 1: total rows, 2: mappable rows, 3: review rows, 4: billing rows, 5: shipping rows, 6: users, 7: old three-level rows, 8: new two-level rows, 9: already-normalized rows with missing country. */
			__( 'Customer address rows: %1$d total (%4$d billing, %5$d shipping). Safe customer sync: %2$d address rows across %6$d users (%7$d old three-level rows, %8$d new two-level rows, %9$d already-normalized rows with missing country). Customer address rows needing official mapping/manual review: %3$d.', 'yoohw-vietnam-store-tools' ),
			(int) $customer_address_report['total'],
			(int) $customer_address_report['exact_mappable'],
			(int) $customer_address_report['needs_review'],
			(int) $customer_address_report['billing_total'],
			(int) $customer_address_report['shipping_total'],
			(int) $customer_address_report['mappable_users'],
			(int) $customer_address_report['three_level_safe'],
			(int) $customer_address_report['two_level_safe'],
			(int) $customer_address_report['country_only_safe']
		);

		$message .= ' ' . sprintf(
			/* translators: 1: tracking total, 2: tracking remaining. */
			__( 'Shipment orders: %1$d total; %2$d still need normalized VCK shipment data.', 'yoohw-vietnam-store-tools' ),
			(int) $tracking_report['total_orders'],
			(int) $tracking_report['needs_sync_count']
		);

		if ( ! empty( $address_report['unmatched_samples'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: samples. */
				__( 'Order address review samples: %s.', 'yoohw-vietnam-store-tools' ),
				implode( '; ', $address_report['unmatched_samples'] )
			);
		}

		if ( ! empty( $customer_address_report['unmatched_samples'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: samples. */
				__( 'Customer address review samples: %s.', 'yoohw-vietnam-store-tools' ),
				implode( '; ', $customer_address_report['unmatched_samples'] )
			);
		}

		return $message;
	}

	private function format_ajax_status_message( $address_report, $customer_address_report, $tracking_report ) {
		if ( 0 === (int) $address_report['exact_mappable'] && 0 === (int) $customer_address_report['exact_mappable'] && 0 === (int) $tracking_report['needs_sync_count'] ) {
			return __( 'All safe data from Le Van Toan plugins has been synced. Any remaining address rows require official mapping or manual review.', 'yoohw-vietnam-store-tools' );
		}

		return sprintf(
			/* translators: 1: safe order address rows, 2: safe customer address rows, 3: tracking orders, 4: order review rows, 5: customer review rows. */
			__( 'Remaining: %1$d safe order address rows, %2$d safe customer address rows, %3$d shipment orders. Manual review still needed for %4$d order address rows and %5$d customer address rows.', 'yoohw-vietnam-store-tools' ),
			(int) $address_report['exact_mappable'],
			(int) $customer_address_report['exact_mappable'],
			(int) $tracking_report['needs_sync_count'],
			(int) $address_report['needs_review'],
			(int) $customer_address_report['needs_review']
		);
	}

	private function format_migration_message( $address_result, $customer_address_result, $tracking_result, $remaining ) {
		$message = sprintf(
			/* translators: 1: orders updated, 2: order addresses migrated, 3: users updated, 4: customer addresses migrated, 5: tracking orders synced, 6: remaining safe order rows, 7: remaining safe customer rows, 8: remaining order review rows, 9: remaining customer review rows. */
			__( 'Le Van Toan plugin data sync batch complete. Updated %1$d orders and synced %2$d order address rows. Updated %3$d users and synced %4$d customer address rows. Synced %5$d shipment orders. Remaining safe order address rows: %6$d. Remaining safe customer address rows: %7$d. Remaining address rows needing mapping/manual review: %8$d order rows and %9$d customer rows.', 'yoohw-vietnam-store-tools' ),
			(int) $address_result['orders_updated'],
			(int) $address_result['addresses_moved'],
			(int) $customer_address_result['users_updated'],
			(int) $customer_address_result['addresses_moved'],
			(int) $tracking_result['orders_synced'],
			(int) $remaining['orders']['exact_mappable'],
			(int) $remaining['customers']['exact_mappable'],
			(int) $remaining['orders']['needs_review'],
			(int) $remaining['customers']['needs_review']
		);

		$errors = array_merge( $address_result['errors'], $customer_address_result['errors'], $tracking_result['errors'] );

		if ( ! empty( $errors ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: error messages. */
				__( 'Errors: %s', 'yoohw-vietnam-store-tools' ),
				implode( '; ', array_slice( $errors, 0, 5 ) )
			);
		}

		return $message;
	}

	private function get_progress_markup() {
		return '<div class="vck-devvn-migration-progress" hidden>
			<div class="vck-devvn-migration-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<span></span>
			</div>
			<p class="vck-devvn-migration-progress__status"></p>
			<p class="vck-devvn-migration-progress__detail"></p>
		</div>';
	}

	private function is_hpos_authoritative() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}

	private function table_exists( $table_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence checks must read the database schema directly.
		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
	}
}
