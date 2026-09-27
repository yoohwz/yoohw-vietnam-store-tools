<?php
/** Fill missing Vietnamese strings in partial WordPress language packs. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoohw_Vietnam_Store_Tools_Translation_Fallback {
	private const DOMAIN = 'yoohw-vietnam-store-tools';
	private static $catalogs = [];
	private static $pack_keys = [];

	public static function register() {
		add_filter( 'gettext_' . self::DOMAIN, [ __CLASS__, 'singular' ], 10, 3 );
		add_filter( 'gettext_with_context_' . self::DOMAIN, [ __CLASS__, 'contextual' ], 10, 4 );
		add_filter( 'ngettext_' . self::DOMAIN, [ __CLASS__, 'plural' ], 10, 5 );
		add_filter( 'ngettext_with_context_' . self::DOMAIN, [ __CLASS__, 'contextual_plural' ], 10, 6 );
		add_filter( 'load_script_translations', [ __CLASS__, 'script' ], 10, 4 );
	}

	private static function catalog( $locale ) {
		if ( ! array_key_exists( $locale, self::$catalogs ) ) {
			$path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo';
			$mo   = new MO();
			self::$catalogs[ $locale ] = is_readable( $path ) && $mo->import_from_file( $path ) ? $mo : null;
		}

		return self::$catalogs[ $locale ];
	}

	private static function locale() {
		$locale = determine_locale();
		return in_array( $locale, [ 'vi', 'vi_VN' ], true ) ? $locale : null;
	}

	private static function pack_has( $locale, $key, $plural = null ) {
		if ( ! array_key_exists( $locale, self::$pack_keys ) ) {
			global $wp_version;

			$base = WP_LANG_DIR . '/plugins/' . self::DOMAIN . '-' . $locale;
			$keys = [];
			$php_catalog = version_compare( (string) $wp_version, '6.5', '>=' )
				&& is_readable( $base . '.l10n.php' );
			if ( $php_catalog ) {
				$data = include $base . '.l10n.php';
				if ( is_array( $data ) && isset( $data['messages'] ) && is_array( $data['messages'] ) ) {
					$keys = $data['messages'];
				}
			} elseif ( is_readable( $base . '.mo' ) ) {
				$mo = new MO();
				if ( $mo->import_from_file( $base . '.mo' ) ) {
					$keys = $mo->entries;
				}
			}
			self::$pack_keys[ $locale ] = $keys;
		}

		return array_key_exists( $key, self::$pack_keys[ $locale ] )
			|| ( null !== $plural && array_key_exists( $key . "\0" . $plural, self::$pack_keys[ $locale ] ) );
	}

	private static function fallback( $translation, $source, $context = '', $plural = null, $number = null ) {
		$locale = self::locale();
		if ( null === $locale ) {
			return $translation;
		}

		$original = null === $plural || 1 === (int) $number ? $source : $plural;
		$key      = '' === $context ? $source : $context . "\4" . $source;
		$catalog  = self::catalog( $locale );
		if ( $translation !== $original || null === $catalog || self::pack_has( $locale, $key, $plural ) || ! isset( $catalog->entries[ $key ] ) ) {
			return $translation;
		}

		return null === $plural
			? $catalog->translate( $source, $context )
			: $catalog->translate_plural( $source, $plural, $number, $context );
	}

	public static function singular( $translation, $source, $domain ) {
		return self::fallback( $translation, $source );
	}

	public static function contextual( $translation, $source, $context, $domain ) {
		return self::fallback( $translation, $source, $context );
	}

	public static function plural( $translation, $source, $plural, $number, $domain ) {
		return self::fallback( $translation, $source, '', $plural, $number );
	}

	public static function contextual_plural( $translation, $source, $plural, $number, $context, $domain ) {
		return self::fallback( $translation, $source, $context, $plural, $number );
	}

	public static function script( $translations, $file, $handle, $domain ) {
		$locale = self::locale();
		if ( self::DOMAIN !== $domain || null === $locale || ! is_string( $file ) ) {
			return $translations;
		}

		$name = basename( $file );
		if ( 0 !== strpos( $name, self::DOMAIN . '-' . $locale . '-' ) || '.json' !== substr( $name, -5 ) ) {
			return $translations;
		}

		$bundled_path = YOOHW_VIETNAM_STORE_TOOLS_PLUGIN_DIR . 'languages/' . $name;
		$pack_path    = WP_LANG_DIR . '/plugins/' . $name;
		if ( ! is_readable( $bundled_path ) || ( realpath( $file ) !== realpath( $bundled_path ) && realpath( $file ) !== realpath( $pack_path ) ) ) {
			return $translations;
		}

		$bundled = json_decode( file_get_contents( $bundled_path ), true );
		$pack    = is_readable( $pack_path ) ? json_decode( file_get_contents( $pack_path ), true ) : null;
		if ( ! is_array( $bundled ) || ! isset( $bundled['locale_data']['messages'] ) || ! is_array( $bundled['locale_data']['messages'] ) ) {
			return $translations;
		}

		if ( is_array( $pack ) && isset( $pack['locale_data']['messages'] ) && is_array( $pack['locale_data']['messages'] ) ) {
			$bundled['locale_data']['messages'] = array_replace( $bundled['locale_data']['messages'], $pack['locale_data']['messages'] );
		}

		return wp_json_encode( $bundled );
	}
}
