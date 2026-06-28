<?php
/**
 * Vietnam address data.
 *
 * @package VietnamCommerceKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Vietnam_Address_Data {

	private static $provinces = null;

	private static $wards = null;

	private static $raw_data = null;

	public static function get_provinces() {
		if ( null === self::$provinces ) {
			self::$provinces = [];

				foreach ( self::get_raw_data()['provinces'] as $province_code => $province ) {
					self::$provinces[ $province_code ] = self::get_administrative_unit_label( $province );
				}
			}

		return apply_filters( 'yoohw_vietnam_store_tools_vietnam_provinces', self::$provinces );
	}

	public static function get_wards() {
		if ( null === self::$wards ) {
			self::$wards = [];

			foreach ( self::get_raw_data()['provinces'] as $province_code => $province ) {
				self::$wards[ $province_code ] = [];

					foreach ( $province['wards'] as $ward_code => $ward ) {
						self::$wards[ $province_code ][ $ward_code ] = self::get_administrative_unit_label( $ward );
					}
				}
			}

		return apply_filters( 'yoohw_vietnam_store_tools_vietnam_wards', self::$wards );
	}

	public static function get_raw_data() {
		if ( null === self::$raw_data ) {
			self::$raw_data = self::load_data_file( 'vietnam-administrative-units.php' );
		}

		if ( ! isset( self::$raw_data['provinces'] ) || ! is_array( self::$raw_data['provinces'] ) ) {
			self::$raw_data['provinces'] = [];
		}

		if ( ! isset( self::$raw_data['source'] ) || ! is_array( self::$raw_data['source'] ) ) {
			self::$raw_data['source'] = [];
		}

		return apply_filters( 'yoohw_vietnam_store_tools_vietnam_address_raw_data', self::$raw_data );
	}

	public static function get_data_source() {
		$raw_data = self::get_raw_data();

		return $raw_data['source'];
	}

	public static function get_wards_for_province( $province_code ) {
		$province_code = self::normalize_province_code( $province_code );
		$wards         = self::get_wards();

		return isset( $wards[ $province_code ] ) && is_array( $wards[ $province_code ] ) ? $wards[ $province_code ] : [];
	}

	public static function province_exists( $province_code ) {
		$province_code = self::normalize_province_code( $province_code );
		$provinces     = self::get_provinces();

		return isset( $provinces[ $province_code ] );
	}

	public static function ward_exists( $ward_code, $province_code = '' ) {
		$ward_code     = self::normalize_ward_code( $ward_code );
		$province_code = self::normalize_province_code( $province_code );

		if ( '' !== $province_code ) {
			$wards = self::get_wards_for_province( $province_code );
			return isset( $wards[ $ward_code ] );
		}

		foreach ( self::get_wards() as $wards ) {
			if ( isset( $wards[ $ward_code ] ) ) {
				return true;
			}
		}

		return false;
	}

	public static function get_province_name( $province_code ) {
		$province_code = self::normalize_province_code( $province_code );
		$provinces     = self::get_provinces();

		return isset( $provinces[ $province_code ] ) ? $provinces[ $province_code ] : $province_code;
	}

	public static function get_ward_name( $ward_code, $province_code = '' ) {
		$ward_code     = self::normalize_ward_code( $ward_code );
		$province_code = self::normalize_province_code( $province_code );

		if ( '' !== $province_code ) {
			$wards = self::get_wards_for_province( $province_code );

			if ( isset( $wards[ $ward_code ] ) ) {
				return $wards[ $ward_code ];
			}
		}

		foreach ( self::get_wards() as $wards ) {
			if ( isset( $wards[ $ward_code ] ) ) {
				return $wards[ $ward_code ];
			}
		}

		return $ward_code;
	}

	public static function normalize_province_code_value( $province_code ) {
		return self::normalize_province_code( $province_code );
	}

	public static function normalize_ward_code_value( $ward_code ) {
		return self::normalize_ward_code( $ward_code );
	}

	private static function normalize_province_code( $province_code ) {
		return self::normalize_administrative_code( $province_code, 2 );
	}

	private static function normalize_ward_code( $ward_code ) {
		return self::normalize_administrative_code( $ward_code, 5 );
	}

	private static function normalize_administrative_code( $code, $length ) {
		$code = wc_clean( $code );
		$code = is_scalar( $code ) ? (string) $code : '';
		$code = trim( $code );

		if ( '' === $code ) {
			return '';
		}

		if ( ctype_digit( $code ) ) {
			return str_pad( (string) absint( $code ), $length, '0', STR_PAD_LEFT );
		}

		return wc_strtoupper( $code );
	}

	private static function load_data_file( $file ) {
		$path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'data/' . $file;

		if ( ! file_exists( $path ) ) {
			return [];
		}

		$data = include $path;

		return is_array( $data ) ? $data : [];
	}

	private static function get_administrative_unit_label( $unit ) {
		$unit = is_array( $unit ) ? $unit : [];

		if ( self::is_vietnamese_locale() && ! empty( $unit['name'] ) ) {
			return (string) $unit['name'];
		}

		if ( ! empty( $unit['label'] ) ) {
			return (string) $unit['label'];
		}

		return ! empty( $unit['name'] ) ? (string) $unit['name'] : '';
	}

	private static function is_vietnamese_locale() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return 0 === strpos( strtolower( (string) $locale ), 'vi' );
	}
}
