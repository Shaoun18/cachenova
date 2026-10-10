<?php

/**
 * Plugin Name: CacheNova
 * Plugin URI:  https://wordpress.org/plugins/cachenova/
 * Description: Automatically clears and manages WordPress cache layers on a scheduled basis, helping keep the site fresh and optimized without impacting performance.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Author:            shaounchandrashill
 * Author URI:        https://shaoun18.github.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cachenova
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RLCC_PLUGIN_VERSION', '1.0.0' );

define( 'RLCC_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/integration/class-rlcc-divi-integration.php';
require_once __DIR__ . '/includes/core/class-cache-drop-in-manager.php';
require_once __DIR__ . '/includes/admin/admin.php';

class RLCC_Cache_Cleaner {
	const OPTION_KEY = 'rlcc_settings';
	const CRON_HOOK         = 'rlcc_background_clear_event';
	const CRON_SCHEDULE_ID  = 'rlcc_custom_interval';
	const REST_NAMESPACE    = 'rlcc/v1';

	public $divi;
	public $drop_in;
	private $admin;

	const DEFERRED_HOOK = 'rlcc_deferred_full_clear_event';

	const ALLOWED_INTERVALS = [ 1, 3, 5, 10, 20, 30, 60];

	const PUBLIC_CACHE_DIR  = 'public';
	const PRIVATE_CACHE_DIR = 'private';

	const OBJECT_CACHE_DROPIN_SIGNATURE = 'RLCC_PERSISTENT_OBJECT_CACHE_DROPIN';
	const OBJECT_CACHE_CONFIG_FILENAME  = 'object-cache-config.json';

	const ADVANCED_CACHE_DROPIN_SIGNATURE = 'RLCC_ADVANCED_CACHE_DROPIN';
	const EARLY_CACHE_CONFIG_FILENAME     = 'early-cache-config.json';

	const WP_CONFIG_MARKER = 'Added by CacheNova — early page cache (rlcc_early_cache)';

	private $rlcc_cache_write_path = null;
	private $rlcc_page_cache_buffer_open = false;

	private $fs = null;

	public function fs() {
		if ( null === $this->fs ) {
			if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			}
			if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$this->fs = new WP_Filesystem_Direct( null );
		}
		return $this->fs;
	}

	const PLUGIN_SLUG = 'cachenova';

	public function __construct() {
		$this->divi = new RLCC_Divi_Integration( $this );
		$this->drop_in = new RLCC_Cache_Drop_In_Manager( $this );
		$this->admin = new RLCC_Admin( $this );

		register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'on_deactivate' ] );

		$this->check_for_upgrade();

		$this->maybe_fix_local_loopback_ssl();

		add_filter( 'cron_schedules', [ $this, 'add_custom_interval' ] );

		add_action( self::CRON_HOOK, [ $this, 'light_clear' ] );

		add_action( 'save_post', [ $this, 'full_clear' ] );
		add_action( 'et_save_post', [ $this, 'full_clear' ] );
		add_action( 'customize_save_after', [ $this, 'full_clear' ] );
		add_action( 'switch_theme', [ $this, 'full_clear' ] );
		add_action( 'upgrader_process_complete', [ $this, 'schedule_deferred_full_clear' ] );
		add_action( 'et_builder_after_save', [ $this, 'full_clear' ] );

		add_action( self::DEFERRED_HOOK, [ $this, 'full_clear' ] );
		add_action( 'activated_plugin', [ $this, 'schedule_deferred_full_clear' ] );
		add_action( 'deactivated_plugin', [ $this, 'schedule_deferred_full_clear' ] );
		add_action( 'deleted_plugin', [ $this, 'schedule_deferred_full_clear' ] );

		add_action( 'template_redirect', [ $this, 'serve_or_start_page_cache' ], 0 );
		add_action( 'shutdown', [ $this, 'close_page_cache_buffer' ], PHP_INT_MAX );

		add_action( 'send_headers', [ $this, 'maybe_send_browser_cache_headers' ] );

		add_action( 'admin_menu', [ $this->admin, 'add_settings_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this->admin, 'enqueue_admin_assets' ] );
		add_action( 'rest_api_init', [ $this->admin, 'register_routes' ] );

		if ( $this->divi->is_active() ) {
			add_action( 'init', [ $this->divi, 'disable_static_css' ], 20 );

			add_action( 'shutdown', [ $this->divi, 'purge_et_cache_dir' ] );
		}
		add_action( 'init', [ $this, 'maybe_run_overdue_clear' ] );

		add_action( 'admin_post_rlcc_manual_clear', [ $this->admin, 'manual_clear_fallback' ] );
		add_action( 'admin_bar_menu', [ $this->admin, 'add_admin_bar_button' ], 100 );
	}

	private $settings_cache = null;

	public function get_settings() {
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}
		$defaults = [
			'interval_minutes' => 60,
			'toast_enabled'    => true,
			
			'cache_public'     => true,
			'cache_private'    => true,
			'cache_object'     => false,
			'cache_browser'    => false,
			
			'delete_data_on_uninstall' => false,
			
			'object_cache_enabled'        => false,
			'object_cache_backend'        => 'apcu',
			'object_cache_redis_host'     => '127.0.0.1',
			'object_cache_redis_port'     => 6379,
			'object_cache_memcached_host' => '127.0.0.1',
			'object_cache_memcached_port' => 11211,
			
			'early_cache_enabled' => false,
		];
		$saved = get_option( self::OPTION_KEY, [] );
		$this->settings_cache = wp_parse_args( $saved, $defaults );
		return $this->settings_cache;
	}

	public function update_settings_option( $settings ) {
		update_option( self::OPTION_KEY, $settings );
		$this->settings_cache = $settings;
		return $settings;
	}

	public function save_settings( $interval_minutes, $toast_enabled, $cache_public = null, $cache_private = null, $cache_object = null, $cache_browser = null, $delete_data_on_uninstall = null ) {
		$current = $this->get_settings();

		$interval_minutes = in_array( (int) $interval_minutes, self::ALLOWED_INTERVALS, true )
			? (int) $interval_minutes
			: 5;

		$settings = array_merge( $current, [
			'interval_minutes' => $interval_minutes,
			'toast_enabled'    => (bool) $toast_enabled,
			'cache_public'     => null === $cache_public ? (bool) $current['cache_public'] : (bool) $cache_public,
			'cache_private'    => null === $cache_private ? (bool) $current['cache_private'] : (bool) $cache_private,
			'cache_object'     => null === $cache_object ? (bool) $current['cache_object'] : (bool) $cache_object,
			'cache_browser'    => null === $cache_browser ? (bool) $current['cache_browser'] : (bool) $cache_browser,
			'delete_data_on_uninstall' => null === $delete_data_on_uninstall
				? (bool) $current['delete_data_on_uninstall']
				: (bool) $delete_data_on_uninstall,
		] );
		$this->update_settings_option( $settings );
		$this->reschedule_cron();

		if ( $current['cache_public'] && ! $settings['cache_public'] ) {
			$this->purge_page_cache( self::PUBLIC_CACHE_DIR );
			if ( ! empty( $current['early_cache_enabled'] ) ) {
				$this->drop_in->apply_early_cache_settings( false );
			}
		}
		if ( $current['cache_private'] && ! $settings['cache_private'] ) {
			$this->purge_page_cache( self::PRIVATE_CACHE_DIR );
		}
		if ( $current['cache_object'] && ! $settings['cache_object'] ) {
			wp_cache_flush();
		}

		return $settings;
	}

	public function reschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_schedule_event( time(), self::CRON_SCHEDULE_ID, self::CRON_HOOK );
	}

	private function maybe_fix_local_loopback_ssl() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( ! $host ) {
			$home = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : '';
			$host = $home ? strtolower( $home ) : '';
		}
		if ( ! $host ) {
			return;
		}
		
		$host_without_port = preg_replace( '/:\d+$/', '', $host );

		$is_local_domain = (bool) preg_match( '/\.(local|test|localhost)$/', $host_without_port )
			|| 'localhost' === $host_without_port;

		if ( ! $is_local_domain ) {
			return;
		}

		add_filter( 'https_local_ssl_verify', '__return_false' );
	}

	public function add_custom_interval( $schedules ) {
		$settings = $this->get_settings();
		$minutes  = max( 1, (int) $settings['interval_minutes'] );
		$schedules[ self::CRON_SCHEDULE_ID ] = [
			'interval' => $minutes * 60,
			'display'  => sprintf( 'Every %d Minute(s) (CacheNova)', $minutes ),
		];
		return $schedules;
	}

	public function pick_best_object_cache_backend() {
		$available = $this->drop_in->detect_available_object_cache_backends();

		if ( ! empty( $available['redis'] ) ) {
			$test = $this->drop_in->test_object_cache_connection( 'redis', '127.0.0.1', 6379, '', 0 );
			if ( ! empty( $test['success'] ) ) {
				return 'redis';
			}
		}
		if ( ! empty( $available['memcached'] ) ) {
			$test = $this->drop_in->test_object_cache_connection( 'memcached', '', 0, '127.0.0.1', 11211 );
			if ( ! empty( $test['success'] ) ) {
				return 'memcached';
			}
		}
		if ( ! empty( $available['apcu'] ) ) {
			$test = $this->drop_in->test_object_cache_connection( 'apcu', '', 0, '', 0 );
			if ( ! empty( $test['success'] ) ) {
				return 'apcu';
			}
		}
		return 'file'; 
	}

	public function check_for_upgrade() {
		$installed = get_option( 'rlcc_installed_version' );
		if ( RLCC_PLUGIN_VERSION === $installed ) {
			return; 
		}
		if ( false === $installed ) {
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['early_cache_enabled'] ) && $this->drop_in->is_our_advanced_cache_dropin_active() ) {
			$this->drop_in->install_advanced_cache_dropin();
		}

		if ( ! empty( $settings['object_cache_enabled'] ) && $this->drop_in->is_our_object_cache_dropin_active() ) {
			$this->drop_in->install_object_cache_dropin(
				$settings['object_cache_backend'],
				$settings['object_cache_redis_host'],
				$settings['object_cache_redis_port'],
				$settings['object_cache_memcached_host'],
				$settings['object_cache_memcached_port']
			);
		}

		$this->full_clear();

		update_option( 'rlcc_installed_version', RLCC_PLUGIN_VERSION );
	}

	public function on_activate() {
		$is_first_activation = ! get_option( self::OPTION_KEY );
		if ( $is_first_activation ) {
			$this->update_settings_option( $this->get_settings() );
		}
		
		update_option( 'rlcc_installed_version', RLCC_PLUGIN_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE_ID, self::CRON_HOOK );
		}
		$this->divi->disable_static_css();
		$this->divi->purge_et_cache_dir();
		$this->ensure_cache_dir( self::PUBLIC_CACHE_DIR );
		$this->ensure_cache_dir( self::PRIVATE_CACHE_DIR );

	}

	public function on_deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		$deferred = wp_next_scheduled( self::DEFERRED_HOOK );
		if ( $deferred ) {
			wp_unschedule_event( $deferred, self::DEFERRED_HOOK );
		}
		
		$this->drop_in->remove_object_cache_dropin();
		$this->update_settings_option( array_merge( $this->get_settings(), [ 'object_cache_enabled' => false ] ) );
		
		$this->drop_in->remove_advanced_cache_dropin();
		$this->drop_in->disable_wp_cache_constant();
		$this->update_settings_option( array_merge( $this->get_settings(), [ 'early_cache_enabled' => false ] ) );
		
		$this->purge_page_cache( self::PUBLIC_CACHE_DIR );
		$this->purge_page_cache( self::PRIVATE_CACHE_DIR );
	}

	public function schedule_deferred_full_clear() {
		if ( ! wp_next_scheduled( self::DEFERRED_HOOK ) ) {
			wp_schedule_single_event( time() + 2, self::DEFERRED_HOOK );
		}
	}

	private function purge_divi_cache( array &$report ) {
		if ( function_exists( 'et_core_clear_cache' ) ) {
			et_core_clear_cache();
			$report['divi_core_cache'] = [ 'label' => 'Divi core cache', 'status' => 'triggered' ];
		}
		if ( class_exists( '\ET\Builder\Framework\Utility\CacheUtility' ) ) {
			delete_transient( 'et_core_page_resource_fallback' );
		}
		
		$et_cache_path = trailingslashit( WP_CONTENT_DIR ) . 'et-cache';
		if ( is_dir( $et_cache_path ) ) {
			$this->divi->purge_et_cache_dir();
			$report['divi_et_cache'] = [ 'label' => 'Divi et-cache folder', 'status' => ! is_dir( $et_cache_path ) ? 'ok' : 'failed' ];
		}
	}

	public function maybe_run_overdue_clear() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $next ) {
			return;
		}
		$settings = $this->get_settings();
		$grace    = max( 60, (int) $settings['interval_minutes'] * 60 );
		if ( time() < $next + $grace ) {
			return; 
		}
		if ( get_transient( 'rlcc_overdue_clear_lock' ) ) {
			return;
		}
		set_transient( 'rlcc_overdue_clear_lock', 1, 30 );
		$this->light_clear();
		$this->reschedule_cron();
	}

	public function light_clear() {
		$settings = $this->get_settings();
		$report   = [];

		global $wpdb;
		$deleted = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_%' AND option_value < UNIX_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$report['expired_transients'] = [ 'label' => 'Expired transients', 'status' => false !== $deleted ? 'ok' : 'failed' ];

		$this->purge_divi_cache( $report );

		if ( ! empty( $settings['cache_public'] ) ) {
			$report['page_cache_public'] = [ 'label' => 'Public Cache files', 'status' => $this->purge_page_cache( self::PUBLIC_CACHE_DIR ) ? 'ok' : 'failed' ];
		}
		if ( ! empty( $settings['cache_private'] ) ) {
			$report['page_cache_private'] = [ 'label' => 'Private Cache files', 'status' => $this->purge_page_cache( self::PRIVATE_CACHE_DIR ) ? 'ok' : 'failed' ];
		}
		if ( ! empty( $settings['cache_object'] ) ) {
			$report['object_cache'] = [ 'label' => 'Object cache', 'status' => wp_cache_flush() ? 'ok' : 'failed' ];
		}
		if ( ! empty( $settings['cache_browser'] ) ) {
			update_option( 'rlcc_asset_version', time() );
			$report['browser_cache_bust'] = [ 'label' => 'Browser cache version bump', 'status' => 'ok' ];
		}

		update_option( 'rlcc_last_clear_report', [ 'type' => 'auto', 'items' => $report ] );
		update_option( 'rlcc_last_cleared', time() );
		update_option( 'rlcc_last_clear_type', 'auto' );

		do_action( 'rlcc_after_cache_clear' );
	}

	public function full_clear() {
		$report = [];

		$report['object_cache'] = [ 'label' => 'Object cache', 'status' => wp_cache_flush() ? 'ok' : 'failed' ];

		global $wpdb;
		$deleted1 = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted2 = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$report['transients'] = [ 'label' => 'Transients', 'status' => ( false !== $deleted1 && false !== $deleted2 ) ? 'ok' : 'failed' ];

		if ( function_exists( 'opcache_reset' ) ) {
			$report['opcache'] = [ 'label' => 'OPcache', 'status' => opcache_reset() ? 'ok' : 'failed' ];
		}

		$this->purge_divi_cache( $report );

		$report['page_cache_public']  = [ 'label' => 'Public Cache files', 'status' => $this->purge_page_cache( self::PUBLIC_CACHE_DIR ) ? 'ok' : 'failed' ];
		$report['page_cache_private'] = [ 'label' => 'Private Cache files', 'status' => $this->purge_page_cache( self::PRIVATE_CACHE_DIR ) ? 'ok' : 'failed' ];

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$report['wp_rocket'] = [ 'label' => 'WP Rocket', 'status' => 'triggered' ];
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$report['w3_total_cache'] = [ 'label' => 'W3 Total Cache', 'status' => 'triggered' ];
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$report['wp_super_cache'] = [ 'label' => 'WP Super Cache', 'status' => 'triggered' ];
		}
		if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$report['litespeed_cache'] = [ 'label' => 'LiteSpeed Cache', 'status' => 'triggered' ];
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$report['sg_optimizer'] = [ 'label' => 'SiteGround Optimizer', 'status' => 'triggered' ];
		}
		if ( function_exists( 'autoptimize_flush_pagecache' ) ) {
			autoptimize_flush_pagecache();
			$report['autoptimize'] = [ 'label' => 'Autoptimize', 'status' => 'triggered' ];
		}

		update_option( 'rlcc_asset_version', time() );
		update_option( 'rlcc_last_clear_report', [ 'type' => 'full', 'items' => $report ] );
		update_option( 'rlcc_last_cleared', time() );
		update_option( 'rlcc_last_clear_type', 'full' );

		do_action( 'rlcc_after_cache_clear' );
	}

	public function recursive_clear_directory( $dir ) {
		$items = @scandir( $dir );
		if ( ! $items ) return;
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) continue;
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_clear_directory( $path );
				$this->fs()->rmdir( $path );
			} else {
				$this->fs()->delete( $path, false, 'f' );
			}
		}
	}

	public function cache_base_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'cache/cachenova';
	}

	private function ensure_cache_dir( $scope ) {
		$dir = trailingslashit( $this->cache_base_dir() ) . $scope;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( is_dir( $dir ) && ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\n" );
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( is_dir( $dir ) && ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	private function purge_page_cache( $scope ) {
		$dir = trailingslashit( $this->cache_base_dir() ) . $scope;
		if ( ! is_dir( $dir ) ) {
			return true; 
		}
		$items = @scandir( $dir );
		if ( ! $items ) {
			return true;
		}
		$all_removed = true;
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' || $item === '.htaccess' || $item === 'index.php' ) {
				continue;
			}
			$path = trailingslashit( $dir ) . $item;
			if ( is_file( $path ) && ! $this->fs()->delete( $path, false, 'f' ) ) {
				$all_removed = false;
			}
		}
		return $all_removed;
	}

	public function serve_or_start_page_cache() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! empty( $_GET ) || is_search() || is_404() || is_feed() || is_preview() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		
		if ( ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			return;
		}

		$settings = $this->get_settings();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		if ( is_user_logged_in() ) {
			if ( empty( $settings['cache_private'] ) ) {
				return;
			}
			$scope = self::PRIVATE_CACHE_DIR;
			$key   = md5( 'u' . get_current_user_id() . '|' . $request_uri );
		} else {
			if ( empty( $settings['cache_public'] ) ) {
				return;
			}
			$scope = self::PUBLIC_CACHE_DIR;
			$key   = md5( $request_uri );
		}

		$dir  = $this->ensure_cache_dir( $scope );
		$file = trailingslashit( $dir ) . $key . '.html';

		if ( file_exists( $file ) ) {
			$age = max( 0, time() - (int) @filemtime( $file ) );
			
			header( 'X-Cache: HIT' );
			header( 'Age: ' . $age );
			
			$mtime = (int) @filemtime( $file );
			$etag  = null;
			if ( $mtime ) {
				$etag = '"' . md5( $file . $mtime ) . '"';
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
				header( 'ETag: ' . $etag );
			}

			$if_none_match     = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
			$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';
			$not_modified      = ( $etag && $if_none_match === $etag )
				|| ( $mtime && $if_modified_since && strtotime( $if_modified_since ) >= $mtime );
			if ( $not_modified ) {
				status_header( 304 );
				exit;
			}

			header( 'Content-Type: text/html; charset=UTF-8' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			readfile( $file );
			exit;
		}

		header( 'X-Cache: MISS' );
		$this->rlcc_cache_write_path = $file;
		ob_start( [ $this, 'write_page_cache_buffer' ] );
		$this->rlcc_page_cache_buffer_open = true;
	}

	public function close_page_cache_buffer() {
		if ( ! $this->rlcc_page_cache_buffer_open ) {
			return;
		}
		$this->rlcc_page_cache_buffer_open = false;
		$status = ob_get_status( true );
		if ( empty( $status ) ) {
			return;
		}
		$top = end( $status );
		if ( isset( $top['name'] ) && __CLASS__ . '::write_page_cache_buffer' === $top['name'] ) {
			ob_end_flush();
		}
	}

	public function write_page_cache_buffer( $buffer ) {
		if ( $this->rlcc_cache_write_path && strlen( $buffer ) > 100 && ! is_admin() && $this->looks_like_complete_page( $buffer ) ) {
			@file_put_contents( $this->rlcc_cache_write_path, $buffer, LOCK_EX );
		}
		return $buffer;
	}

	private function looks_like_complete_page( $buffer ) {
		if ( false === stripos( $buffer, '</html>' ) ) {
			return false;
		}
		if ( false !== stripos( $buffer, 'Fatal error' ) || false !== stripos( $buffer, 'Uncaught Error' ) ) {
			return false;
		}
		return true;
	}

	public function maybe_send_browser_cache_headers() {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$settings = $this->get_settings();
		if ( empty( $settings['cache_browser'] ) ) {
			return;
		}
		$max_age = max( 60, (int) $settings['interval_minutes'] * 60 );
		header( 'Cache-Control: public, max-age=' . $max_age );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $max_age ) . ' GMT' );
	}

}

new RLCC_Cache_Cleaner();
