<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
	return;
}

$rlcc_ec_config_file = WP_CONTENT_DIR . '/cache/cachenova-config/early-cache-config.json';
if ( ! is_readable( $rlcc_ec_config_file ) ) {
	return;
}
$rlcc_ec_config = json_decode( (string) file_get_contents( $rlcc_ec_config_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
if ( ! is_array( $rlcc_ec_config ) ) {
	return;
}
if ( ! defined( 'RLCC_EARLY_CACHE_ENABLED' ) ) {
	define( 'RLCC_EARLY_CACHE_ENABLED', ! empty( $rlcc_ec_config['enabled'] ) );
}
if ( ! defined( 'RLCC_EARLY_CACHE_DIR' ) && ! empty( $rlcc_ec_config['dir'] ) ) {
	define( 'RLCC_EARLY_CACHE_DIR', (string) $rlcc_ec_config['dir'] );
}

if ( ! defined( 'RLCC_EARLY_CACHE_ENABLED' ) || ! RLCC_EARLY_CACHE_ENABLED ) {
	return;
}
if ( ! defined( 'RLCC_EARLY_CACHE_DIR' ) || ! is_dir( RLCC_EARLY_CACHE_DIR ) ) {
	return;
}

if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}
if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
	return;
}

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$rlcc_ec_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
if ( '' === $rlcc_ec_uri ) {
	return;
}

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$rlcc_ec_script = isset( $_SERVER['SCRIPT_NAME'] ) ? $_SERVER['SCRIPT_NAME'] : '';
if (
	false !== strpos( $rlcc_ec_uri, '/wp-admin' )
	|| false !== strpos( $rlcc_ec_uri, '/wp-login.php' )
	|| false !== strpos( $rlcc_ec_uri, '/wp-cron.php' )
	|| false !== strpos( $rlcc_ec_uri, '/wp-json/' )
	|| false !== strpos( $rlcc_ec_script, 'wp-admin' )
	|| false !== strpos( $rlcc_ec_script, 'wp-login.php' )
	|| false !== strpos( $rlcc_ec_script, 'wp-cron.php' )
	|| ( defined( 'DOING_CRON' ) && DOING_CRON )
	|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
	|| ( defined( 'WP_CLI' ) && WP_CLI )
	|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
	|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
) {
	return;
}

if ( ! empty( $_COOKIE ) ) {
	foreach ( $_COOKIE as $rlcc_ec_cookie_name => $rlcc_ec_cookie_value ) {
		if (
			0 === strpos( $rlcc_ec_cookie_name, 'wordpress_logged_in_' )
			|| 0 === strpos( $rlcc_ec_cookie_name, 'wordpress_sec_' )
			|| 0 === strpos( $rlcc_ec_cookie_name, 'comment_author_' )
			|| 0 === strpos( $rlcc_ec_cookie_name, 'wp-postpass_' )
			|| 0 === strpos( $rlcc_ec_cookie_name, 'woocommerce_' )
		) {
			return;
		}
	}
}

$rlcc_ec_key  = md5( $rlcc_ec_uri );
$rlcc_ec_file = rtrim( RLCC_EARLY_CACHE_DIR, '/' ) . '/' . $rlcc_ec_key . '.html';

if ( ! is_file( $rlcc_ec_file ) ) {
	return;
}

$rlcc_ec_mtime = @filemtime( $rlcc_ec_file );
$rlcc_ec_age   = $rlcc_ec_mtime ? max( 0, time() - $rlcc_ec_mtime ) : 0;

header( 'X-Cache: HIT' );
header( 'X-Cache-Engine: ACC-Early-Bypass' );
header( 'Age: ' . $rlcc_ec_age );
header( 'Cache-Control: public, max-age=0, must-revalidate' );
$rlcc_ec_etag = null;
if ( $rlcc_ec_mtime ) {
	$rlcc_ec_etag = '"' . md5( $rlcc_ec_key . $rlcc_ec_mtime ) . '"';
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $rlcc_ec_mtime ) . ' GMT' );
	header( 'ETag: ' . $rlcc_ec_etag );
}

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$rlcc_ec_inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$rlcc_ec_ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : '';
$rlcc_ec_not_modified = ( $rlcc_ec_etag && $rlcc_ec_inm === $rlcc_ec_etag )
	|| ( $rlcc_ec_mtime && $rlcc_ec_ims && strtotime( $rlcc_ec_ims ) >= $rlcc_ec_mtime );
if ( $rlcc_ec_not_modified ) {
	header( 'HTTP/1.1 304 Not Modified' );
	exit;
}

header( 'Content-Type: text/html; charset=UTF-8' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
readfile( $rlcc_ec_file );
exit;
