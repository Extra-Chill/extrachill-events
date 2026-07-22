<?php
/** Test dbDelta capture. */
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $sql ) {
		$GLOBALS['ec_artist_test']['dbdelta'][] = $sql;
		return array();
	}
}
