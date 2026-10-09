<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function rlcc_uninstall_fs() {
	static $fs = null;
	if ( null === $fs ) {
		if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		}
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		$fs = new WP_Filesystem_Direct( null );
	}
	return $fs;
}

$rlcc_oc_dropin = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
if ( file_exists( $rlcc_oc_dropin ) ) {
	$rlcc_oc_head = @file_get_contents( $rlcc_oc_dropin, false, null, 0, 4096 );
	if ( $rlcc_oc_head && false !== strpos( $rlcc_oc_head, 'RLCC_PERSISTENT_OBJECT_CACHE_DROPIN' ) ) {
		rlcc_uninstall_fs()->delete( $rlcc_oc_dropin, false, 'f' );
	}
}
$rlcc_oc_config = trailingslashit( WP_CONTENT_DIR ) . 'cache/cachenova-config/object-cache-config.json';
if ( file_exists( $rlcc_oc_config ) ) {
	rlcc_uninstall_fs()->delete( $rlcc_oc_config, false, 'f' );
}
$rlcc_oc_config_legacy = trailingslashit( WP_CONTENT_DIR ) . 'cache/rlcc-object-cache-config.php';
if ( file_exists( $rlcc_oc_config_legacy ) ) {
	rlcc_uninstall_fs()->delete( $rlcc_oc_config_legacy, false, 'f' );
}
foreach ( [ 'apcu', 'redis', 'memcached' ] as $rlcc_oc_backend_name ) {
	$rlcc_oc_flag = trailingslashit( WP_CONTENT_DIR ) . 'cache/rlcc-oc-circuit-' . $rlcc_oc_backend_name . '.flag';
	if ( file_exists( $rlcc_oc_flag ) ) {
		rlcc_uninstall_fs()->delete( $rlcc_oc_flag, false, 'f' );
	}
	$rlcc_oc_flag_legacy = trailingslashit( WP_CONTENT_DIR ) . 'rlcc-oc-circuit-' . $rlcc_oc_backend_name . '.flag';
	if ( file_exists( $rlcc_oc_flag_legacy ) ) {
		rlcc_uninstall_fs()->delete( $rlcc_oc_flag_legacy, false, 'f' );
	}
}

$rlcc_ec_dropin = trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
if ( file_exists( $rlcc_ec_dropin ) ) {
	$rlcc_ec_head = @file_get_contents( $rlcc_ec_dropin, false, null, 0, 4096 );
	if ( $rlcc_ec_head && false !== strpos( $rlcc_ec_head, 'RLCC_ADVANCED_CACHE_DROPIN' ) ) {
		rlcc_uninstall_fs()->delete( $rlcc_ec_dropin, false, 'f' );
	}
}
$rlcc_ec_config = trailingslashit( WP_CONTENT_DIR ) . 'cache/cachenova-config/early-cache-config.json';
if ( file_exists( $rlcc_ec_config ) ) {
	rlcc_uninstall_fs()->delete( $rlcc_ec_config, false, 'f' );
}
$rlcc_ec_config_legacy = trailingslashit( WP_CONTENT_DIR ) . 'cache/rlcc-early-cache-config.php';
if ( file_exists( $rlcc_ec_config_legacy ) ) {
	rlcc_uninstall_fs()->delete( $rlcc_ec_config_legacy, false, 'f' );
}

$rlcc_wp_config_marker = 'Added by CacheNova — early page cache (rlcc_early_cache)';
$rlcc_wp_config_path   = ABSPATH . 'wp-config.php';
if ( ! file_exists( $rlcc_wp_config_path ) ) {
	$rlcc_wp_config_parent = dirname( ABSPATH ) . '/wp-config.php';
	if ( file_exists( $rlcc_wp_config_parent ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
		$rlcc_wp_config_path = $rlcc_wp_config_parent;
	}
}
if ( file_exists( $rlcc_wp_config_path ) && rlcc_uninstall_fs()->is_writable( $rlcc_wp_config_path ) ) {
	$rlcc_wp_config_contents = @file_get_contents( $rlcc_wp_config_path );
	if ( $rlcc_wp_config_contents && false !== strpos( $rlcc_wp_config_contents, $rlcc_wp_config_marker ) ) {
		$rlcc_wp_config_lines    = explode( "\n", $rlcc_wp_config_contents );
		$rlcc_wp_config_filtered = array_filter( $rlcc_wp_config_lines, function ( $line ) use ( $rlcc_wp_config_marker ) {
			return false === strpos( $line, $rlcc_wp_config_marker );
		} );
		@file_put_contents( $rlcc_wp_config_path, implode( "\n", $rlcc_wp_config_filtered ), LOCK_EX ); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
	}
}

$rlcc_settings = get_option( 'rlcc_settings' );
$rlcc_delete_all = is_array( $rlcc_settings )
	&& ! empty( $rlcc_settings['delete_data_on_uninstall'] );

if ( ! $rlcc_delete_all ) {
	return;
}

$rlcc_cron_hooks = [ 'rlcc_background_clear_event', 'rlcc_deferred_full_clear_event' ];
foreach ( $rlcc_cron_hooks as $rlcc_hook ) {
	$rlcc_timestamp = wp_next_scheduled( $rlcc_hook );
	while ( $rlcc_timestamp ) {
		wp_unschedule_event( $rlcc_timestamp, $rlcc_hook );
		$rlcc_timestamp = wp_next_scheduled( $rlcc_hook );
	}
	wp_clear_scheduled_hook( $rlcc_hook );
}

$rlcc_options = [
	'rlcc_settings',
	'rlcc_last_cleared',
	'rlcc_last_clear_type',
	'rlcc_asset_version',
];
foreach ( $rlcc_options as $rlcc_option ) {
	delete_option( $rlcc_option );
	
	delete_site_option( $rlcc_option );
}

if ( function_exists( 'rlcc_uninstall_rrmdir' ) === false ) {
	function rlcc_uninstall_rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir );
		if ( ! $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				rlcc_uninstall_rrmdir( $path );
			} else {
				rlcc_uninstall_fs()->delete( $path, false, 'f' );
			}
		}
		rlcc_uninstall_fs()->rmdir( $dir );
	}
}

$rlcc_cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/cachenova';
rlcc_uninstall_rrmdir( $rlcc_cache_dir );

if ( is_multisite() ) {
	$rlcc_site_ids = get_sites( [ 'fields' => 'ids' ] );
	foreach ( $rlcc_site_ids as $rlcc_site_id ) {
		switch_to_blog( $rlcc_site_id );
		foreach ( $rlcc_options as $rlcc_option ) {
			delete_option( $rlcc_option );
		}
		foreach ( $rlcc_cron_hooks as $rlcc_hook ) {
			wp_clear_scheduled_hook( $rlcc_hook );
		}
		restore_current_blog();
	}
}

wp_cache_flush();
if ( function_exists( 'opcache_reset' ) ) {
	opcache_reset();
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
