<?php
/** Force WordPress to use MO files for the plugin in translation runtime tests. */

add_filter(
	'translation_file_format',
	function ( $format, $domain ) {
		return 'yoohw-vietnam-store-tools' === $domain ? 'mo' : $format;
	},
	10,
	2
);
