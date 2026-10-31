<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'RLCC_Admin' ) ) :

class RLCC_Admin {
	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function register_routes() {
		register_rest_route( RLCC_Cache_Cleaner::REST_NAMESPACE, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_settings' ],
				'permission_callback' => [ $this, 'rest_permissions' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_save_settings' ],
				'permission_callback' => [ $this, 'rest_permissions' ],
				'args'                => [
					'interval_minutes' => [ 'required' => true ],
					'toast_enabled'    => [ 'required' => true ],
					'cache_public'     => [ 'required' => false ],
					'cache_private'    => [ 'required' => false ],
					'cache_object'     => [ 'required' => false ],
					'cache_browser'    => [ 'required' => false ],
					'delete_data_on_uninstall' => [ 'required' => false ],
					'db_schedule'      => [ 'required' => false ],
					'object_cache_enabled'        => [ 'required' => false ],
					'object_cache_backend'        => [ 'required' => false ],
					'object_cache_redis_host'     => [ 'required' => false ],
					'object_cache_redis_port'     => [ 'required' => false ],
					'object_cache_memcached_host' => [ 'required' => false ],
					'object_cache_memcached_port' => [ 'required' => false ],
					'early_cache_enabled'         => [ 'required' => false ],
				],
			],
		] );

		register_rest_route( RLCC_Cache_Cleaner::REST_NAMESPACE, '/divi-per-request', [
			'methods' => 'POST',
			'callback' => [ $this, 'rest_divi_per_request' ],
			'permission_callback' => [ $this, 'rest_permissions' ],
		] );

		register_rest_route( RLCC_Cache_Cleaner::REST_NAMESPACE, '/object-cache/test', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_test_object_cache' ],
			'permission_callback' => [ $this, 'rest_permissions' ],
		] );

		register_rest_route( RLCC_Cache_Cleaner::REST_NAMESPACE, '/clear', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_clear_cache' ],
			'permission_callback' => [ $this, 'rest_permissions' ],
		] );

		register_rest_route( RLCC_Cache_Cleaner::REST_NAMESPACE, '/database/optimize', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_optimize_database' ],
			'permission_callback' => [ $this, 'rest_permissions' ],
		] );
	}

	public function rest_divi_per_request( $request ) {
		$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		$settings = $this->plugin->get_settings();
		$settings['divi_per_request_purge'] = $enabled;
		$this->plugin->update_settings_option( $settings );
		$deleted = null;
		if ( $enabled ) {
			$deleted = $this->plugin->divi->purge_et_cache_dir();
		}
		return rest_ensure_response( [ 'enabled' => $enabled, 'folder_deleted' => $deleted ] );
	}

	public function rest_permissions() {
		return current_user_can( 'manage_options' );
	}

	public function rest_get_settings( $request ) {
		$settings                           = $this->plugin->get_settings();
		$settings['next_run_timestamp']     = wp_next_scheduled( RLCC_Cache_Cleaner::CRON_HOOK );
		$settings['last_cleared_timestamp'] = get_option( 'rlcc_last_cleared', 0 );
		$settings['last_clear_type']        = get_option( 'rlcc_last_clear_type', '' );
		$settings['last_clear_report']      = get_option( 'rlcc_last_clear_report', null );
		$settings['allowed_intervals']      = RLCC_Cache_Cleaner::ALLOWED_INTERVALS;
		$settings['object_cache_available_backends'] = $this->plugin->drop_in->detect_available_object_cache_backends();
		$settings['object_cache_dropin_active']      = $this->plugin->drop_in->is_our_object_cache_dropin_active();
		$settings['object_cache_foreign_dropin']     = $this->plugin->drop_in->is_foreign_object_cache_dropin_present();
		$settings['advanced_cache_dropin_active']    = $this->plugin->drop_in->is_our_advanced_cache_dropin_active();
		$settings['advanced_cache_foreign_dropin']   = $this->plugin->drop_in->is_foreign_advanced_cache_dropin_present();
		$settings['object_cache_dropin_details'] = $this->plugin->drop_in->describe_foreign_dropin( 'object-cache.php' );
		$settings['advanced_cache_dropin_details'] = $this->plugin->drop_in->describe_foreign_dropin( 'advanced-cache.php' );
		$settings['wp_cache_constant_true']          = $this->plugin->drop_in->is_wp_cache_constant_true();
		$settings['wp_config_writable']              = $this->plugin->drop_in->wp_config_writable();
		$settings['local_ssl_fix_active']            = has_filter( 'https_local_ssl_verify', '__return_false' );
		$settings['divi_active']                     = $this->plugin->divi->is_active();
        $settings['divi_per_request_purge'] = ! empty( $settings['divi_per_request_purge'] );
		$settings['last_db_optimize']                = get_option( 'rlcc_last_db_optimize', null );
		$settings['db_counts']                       = $this->get_database_counts();
		$settings['db_next_run_timestamp']           = wp_next_scheduled( RLCC_Cache_Cleaner::DB_CRON_HOOK );
		$settings['db_schedule']                     = $this->plugin->sanitize_db_schedule( $settings['db_schedule'] );
		return rest_ensure_response( $settings );
	}

	public function get_database_counts() {
		$cached = wp_cache_get( 'rlcc_db_counts', 'cachenova' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$counts = [
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'expired_transients' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_%' AND option_value < UNIX_TIMESTAMP()" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		];
		wp_cache_set( 'rlcc_db_counts', $counts, 'cachenova', 60 );
		return $counts;
	}

	public function rest_save_settings( $request ) {
		$interval_minutes = (int) $request->get_param( 'interval_minutes' );
		$toast_enabled     = (bool) $request->get_param( 'toast_enabled' );
		$cache_public      = null !== $request->get_param( 'cache_public' ) ? (bool) $request->get_param( 'cache_public' ) : null;
		$cache_private     = null !== $request->get_param( 'cache_private' ) ? (bool) $request->get_param( 'cache_private' ) : null;
		$cache_object      = null !== $request->get_param( 'cache_object' ) ? (bool) $request->get_param( 'cache_object' ) : null;
		$cache_browser     = null !== $request->get_param( 'cache_browser' ) ? (bool) $request->get_param( 'cache_browser' ) : null;
		$delete_data_on_uninstall = null !== $request->get_param( 'delete_data_on_uninstall' ) ? (bool) $request->get_param( 'delete_data_on_uninstall' ) : null;

		$db_schedule = null !== $request->get_param( 'db_schedule' ) ? sanitize_key( (string) $request->get_param( 'db_schedule' ) ) : null;

		$saved = $this->plugin->save_settings( $interval_minutes, $toast_enabled, $cache_public, $cache_private, $cache_object, $cache_browser, $delete_data_on_uninstall, $db_schedule );
		$saved['db_next_run_timestamp'] = wp_next_scheduled( RLCC_Cache_Cleaner::DB_CRON_HOOK );

		$oc_enabled = $request->get_param( 'object_cache_enabled' );
		$oc_touched = null !== $oc_enabled
			|| null !== $request->get_param( 'object_cache_backend' )
			|| null !== $request->get_param( 'object_cache_redis_host' )
			|| null !== $request->get_param( 'object_cache_redis_port' )
			|| null !== $request->get_param( 'object_cache_memcached_host' )
			|| null !== $request->get_param( 'object_cache_memcached_port' );

		if ( $oc_touched ) {
			$oc_result = $this->plugin->drop_in->apply_object_cache_settings(
				null !== $oc_enabled ? (bool) $oc_enabled : null,
				$request->get_param( 'object_cache_backend' ),
				$request->get_param( 'object_cache_redis_host' ),
				$request->get_param( 'object_cache_redis_port' ),
				$request->get_param( 'object_cache_memcached_host' ),
				$request->get_param( 'object_cache_memcached_port' )
			);
			$saved                       = array_merge( $saved, $this->plugin->get_settings() );
			$saved['object_cache_result'] = $oc_result;
		}

		$saved['object_cache_dropin_active']  = $this->plugin->drop_in->is_our_object_cache_dropin_active();
		$saved['next_run_timestamp'] = wp_next_scheduled( RLCC_Cache_Cleaner::CRON_HOOK );

		$early_cache_enabled = $request->get_param( 'early_cache_enabled' );
		if ( null !== $early_cache_enabled ) {
			$ec_result = $this->plugin->drop_in->apply_early_cache_settings( (bool) $early_cache_enabled );
			$saved     = array_merge( $saved, $this->plugin->get_settings() );
			$saved['early_cache_result']          = $ec_result;
			$saved['advanced_cache_dropin_active'] = $this->plugin->drop_in->is_our_advanced_cache_dropin_active();
			$saved['wp_cache_constant_true']       = $this->plugin->drop_in->is_wp_cache_constant_true();
		}

		return rest_ensure_response( $saved );
	}

	public function rest_test_object_cache( $request ) {
		$backend    = $request->get_param( 'backend' );
		$redis_host = $request->get_param( 'redis_host' );
		$redis_port = $request->get_param( 'redis_port' );
		$mc_host    = $request->get_param( 'memcached_host' );
		$mc_port    = $request->get_param( 'memcached_port' );
		$current    = $this->plugin->get_settings();

		$result = $this->plugin->drop_in->test_object_cache_connection(
			$backend ?: $current['object_cache_backend'],
			$redis_host ?: $current['object_cache_redis_host'],
			$redis_port ?: $current['object_cache_redis_port'],
			$mc_host ?: $current['object_cache_memcached_host'],
			$mc_port ?: $current['object_cache_memcached_port']
		);
		return rest_ensure_response( $result );
	}

	public function rest_clear_cache( $request ) {
		// Complete the purge before responding, so the dashboard reports the actual result.
		$this->plugin->reschedule_cron();
		$this->plugin->full_clear();
		$report = get_option( 'rlcc_last_clear_report', [] );
		$divi_result = isset( $report['items']['divi_et_cache']['status'] ) ? $report['items']['divi_et_cache']['status'] : 'not_present';
		return rest_ensure_response( [
			'cleared'                => true,
			'last_cleared_timestamp' => (int) get_option( 'rlcc_last_cleared', time() ),
			'next_run_timestamp'     => wp_next_scheduled( RLCC_Cache_Cleaner::CRON_HOOK ),
			'last_clear_report'      => $report,
			'divi_folder_deleted'   => ! is_dir( trailingslashit( WP_CONTENT_DIR ) . 'et-cache' ),
			'divi_folder_status'    => $divi_result,
			'divi_folder_exists'    => is_dir( trailingslashit( WP_CONTENT_DIR ) . 'et-cache' ),
			'divi_folder_message'   => is_dir( trailingslashit( WP_CONTENT_DIR ) . 'et-cache' ) ? 'Divi et-cache exists after the purge. It may have been recreated, or the web server lacks deletion permissions.' : 'Divi et-cache directory is absent after clearing.',
		] );
	}

	public function optimize_database( $options, $type = 'manual' ) {
		global $wpdb;
		$report = [];

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		if ( ! empty( $options['revisions'] ) ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $ids as $id ) {
				wp_delete_post_revision( (int) $id );
			}
			$report['revisions'] = count( $ids );
		}

		if ( ! empty( $options['trashed_posts'] ) ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
			$report['trashed_posts'] = count( $ids );
		}

		if ( ! empty( $options['spam_comments'] ) ) {
			$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $ids as $id ) {
				wp_delete_comment( (int) $id, true );
			}
			$report['spam_comments'] = count( $ids );
		}

		if ( ! empty( $options['trashed_comments'] ) ) {
			$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $ids as $id ) {
				wp_delete_comment( (int) $id, true );
			}
			$report['trashed_comments'] = count( $ids );
		}

		if ( ! empty( $options['expired_transients'] ) ) {
			// Use WordPress APIs so both database and external object-cache transients are handled safely, in batches.
			$deleted = 0;
			for ( $batch = 0; $batch < 20; $batch++ ) {
				$expired = $wpdb->get_col( $wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 500",
					$wpdb->esc_like( '_transient_timeout_' ) . '%', time()
				) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( empty( $expired ) ) {
					break;
				}
				$batch_deleted = 0;
				foreach ( $expired as $name ) {
					$key = substr( $name, strlen( '_transient_timeout_' ) );
					if ( delete_transient( $key ) ) {
						$batch_deleted++;
					} else {
						// Orphaned timeout row: remove it directly so the loop always makes progress.
						delete_option( $name );
						$batch_deleted++;
					}
				}
				$deleted += $batch_deleted;
				if ( count( $expired ) < 500 ) {
					break;
				}
			}
			$report['expired_transients'] = $deleted;
		}

		if ( ! empty( $options['optimize_tables'] ) ) {
			$tables    = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$optimized = 0;
			foreach ( $tables as $table ) {
				$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
				if ( '' === $table ) {
					continue;
				}
				if ( $wpdb->query( "OPTIMIZE TABLE `{$table}`" ) !== false ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$optimized++;
				}
			}
			$report['optimize_tables'] = $optimized;
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		wp_cache_delete( 'rlcc_db_counts', 'cachenova' );
		update_option( 'rlcc_last_db_optimize', [ 'timestamp' => time(), 'report' => $report, 'type' => 'auto' === $type ? 'auto' : 'manual' ] );
		return $report;
	}

	public function rest_optimize_database( $request ) {
		$options = [
			'revisions'           => (bool) $request->get_param( 'revisions' ),
			'trashed_posts'       => (bool) $request->get_param( 'trashed_posts' ),
			'spam_comments'       => (bool) $request->get_param( 'spam_comments' ),
			'trashed_comments'    => (bool) $request->get_param( 'trashed_comments' ),
			'expired_transients'  => (bool) $request->get_param( 'expired_transients' ),
			'optimize_tables'     => (bool) $request->get_param( 'optimize_tables' ),
		];
		if ( ! array_filter( $options ) ) {
			return new WP_Error( 'rlcc_no_options', 'Select at least one item to optimize.', [ 'status' => 400 ] );
		}
		$report = $this->optimize_database( $options );
		return rest_ensure_response( [
			'success'   => true,
			'report'    => $report,
			'timestamp' => time(),
		] );
	}

	public function add_settings_page() {
		add_options_page(
			'CacheNova',
			'CacheNova',
			'manage_options',
			'rlcc-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( $hook !== 'settings_page_rlcc-settings' ) {
			return;
		}

		$css_file = 'assets/css/cn-admin.css';
		$js_file  = 'assets/js/cn-admin.js';

		wp_enqueue_style(
			'cn-admin-style',
			plugins_url( $css_file, RLCC_PLUGIN_FILE ),
			[],
			RLCC_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'cn-admin-script',
			plugins_url( $js_file, RLCC_PLUGIN_FILE ),
			[ 'wp-element', 'wp-api-fetch', 'wp-i18n' ],
			filemtime( plugin_dir_path( RLCC_PLUGIN_FILE ) . $js_file ),
			true
		);

		wp_localize_script( 'cn-admin-script', 'RLCC_DATA', [
			'restUrl'          => esc_url_raw( rest_url() ),
			'namespace'         => RLCC_Cache_Cleaner::REST_NAMESPACE,
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'allowedIntervals'  => RLCC_Cache_Cleaner::ALLOWED_INTERVALS,
		] );
	}

	public function render_settings_page() {
		echo '<div class="wrap rlcc-wrap"><div id="rlcc-settings-root"></div></div>';
	}

	public function manual_clear_fallback() {
		$nonce = isset( $_REQUEST['rlcc_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['rlcc_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rlcc_manual_clear_action' ) ) {
			wp_die( 'Security check failed' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed' );
		}
		$this->plugin->reschedule_cron();
		$this->plugin->full_clear();
		wp_safe_redirect( add_query_arg( 'cleared', '1', wp_get_referer() ) );
		exit;
	}

	public function add_admin_bar_button( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) return;
		// Inline SVG (CacheNova sparkle mark) so the button is recognisable on every admin and front-end page, with no extra CSS file.
		$icon = '<span class="ab-icon" aria-hidden="true" style="display:inline-block;width:22px;height:22px;margin:-4px 7px 0 0;padding:0;vertical-align:middle;float:none;background:#7c6cf5;border-radius:6px;line-height:0;">'
			. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 22" width="22" height="22" style="display:block;">'
			. '<path fill="#ffffff" d="M11 3.8l1.7 4.7 4.7 1.7-4.7 1.7L11 16.6l-1.7-4.7-4.7-1.7 4.7-1.7z"/>'
			. '<circle cx="16.6" cy="5.4" r="1.2" fill="#ffd86b"/>'
			. '</svg></span>';
		$wp_admin_bar->add_node( [
			'id'    => 'rlcc-clear-cache',
			'title' => $icon . '<span class="ab-label">' . esc_html__( 'Clear Cache Now', 'cachenova' ) . '</span>',
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=rlcc_manual_clear' ), 'rlcc_manual_clear_action', 'rlcc_nonce' ),
			'meta'  => [ 'title' => esc_attr__( 'CacheNova — clear all caches', 'cachenova' ) ],
		] );
	}

}

endif;
