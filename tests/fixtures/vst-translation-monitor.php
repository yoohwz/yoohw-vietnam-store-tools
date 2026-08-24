<?php
/**
 * Fail runtime smoke when VST triggers WordPress JIT translations too early.
 */

add_action(
	'doing_it_wrong_run',
	function ( $function_name, $message ) {
		if (
			'_load_textdomain_just_in_time' === $function_name
			&& false !== strpos( (string) $message, 'yoohw-vietnam-store-tools' )
		) {
			throw new RuntimeException( 'VST translation loading was triggered before init.' );
		}
	},
	10,
	2
);
