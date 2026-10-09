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
		$settings['wp_cache_constant_true']          = $this->plugin->drop_in->is_wp_cache_constant_true();
		$settings['wp_config_writable']              = $this->plugin->drop_in->wp_config_writable();
		$settings['local_ssl_fix_active']            = has_filter( 'https_local_ssl_verify', '__return_false' );
		$settings['divi_active']                     = $this->plugin->divi->is_active();
		$settings['last_db_optimize']                = get_option( 'rlcc_last_db_optimize', null );
		$settings['db_counts']                       = $this->get_database_counts();
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

		$saved = $this->plugin->save_settings( $interval_minutes, $toast_enabled, $cache_public, $cache_private, $cache_object, $cache_browser, $delete_data_on_uninstall );

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
		$this->plugin->reschedule_cron();
		$data = [
			'cleared'                => true,
			'last_cleared_timestamp' => time(),
			'next_run_timestamp'     => wp_next_scheduled( RLCC_Cache_Cleaner::CRON_HOOK ),
		];

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			add_filter( 'rest_pre_serve_request', function ( $served, $result, $req, $server ) use ( $data ) {
				if ( $served ) {
					return $served;
				}
				status_header( 200 );
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
				echo wp_json_encode( $data );
				fastcgi_finish_request();
				$this->plugin->full_clear();
				return true;
			}, 10, 4 );
			return rest_ensure_response( $data );
		}

		$this->plugin->full_clear();
		return rest_ensure_response( $data );
	}

	public function optimize_database( $options ) {
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
			$count = (int) $wpdb->query( "DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b WHERE a.option_name LIKE '\_transient\_timeout\_%' AND b.option_name = CONCAT('_transient_', SUBSTRING(a.option_name, 20)) AND a.option_value < UNIX_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$report['expired_transients'] = max( 0, (int) ( $count / 2 ) ); 
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
		update_option( 'rlcc_last_db_optimize', [ 'timestamp' => time(), 'report' => $report ] );
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

		$css_file = 'assets/css/cn-admin.min.css';
		$js_file  = 'assets/js/cn-admin.min.js';

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
			RLCC_PLUGIN_VERSION,
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
		$wp_admin_bar->add_node( [
			'id'    => 'rlcc-clear-cache',
			'title' => 'Clear Cache Now',
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=rlcc_manual_clear' ), 'rlcc_manual_clear_action', 'rlcc_nonce' ),
		] );
	}

	public function add_view_details_link( $links, $plugin_file ) {
		if ( plugin_basename( RLCC_PLUGIN_FILE ) !== $plugin_file ) {
			return $links;
		}

		$url = admin_url(
			'plugin-install.php?tab=plugin-information&plugin=' . RLCC_Cache_Cleaner::PLUGIN_SLUG
			. '&TB_iframe=true&width=600&height=550'
		);

		$links[] = sprintf(
			'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr__( 'More information about CacheNova', 'cachenova' ),
			esc_html__( 'View details', 'cachenova' )
		);

		return $links;
	}

	public function provide_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || RLCC_Cache_Cleaner::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$info                 = new stdClass();
		$info->name            = 'CacheNova';
		$info->slug             = RLCC_Cache_Cleaner::PLUGIN_SLUG;
		$info->version          = '1.0.0';
		$info->author           = '<a href="https://shaoun18.github.io/">Programmershaoun</a>';
		$info->author_profile   = 'https://shaoun18.github.io/';
		$info->requires         = '7.0';
		$info->tested           = '7.2.0';
		$info->requires_php     = '8.0';
		$info->last_updated     = gmdate( 'Y-m-d H:i:s' );
		$info->homepage         = 'https://shaoun18.github.io/';
		$info->short_description = 'Silently auto-clears every cache layer on any WordPress site — transients, object cache, OPcache, page cache, and popular cache plugins — on a schedule and on every save. Automatic bonus support for Divi\'s et-cache folder when Divi is detected.';

		$info->sections = [
			'description' => '<p><strong>CacheNova</strong> works on any WordPress site, with any theme and any plugin — it is not tied to Divi or any other page builder. It silently clears every cache layer that matters (transients, object cache, OPcache, this plugin\'s own optional page cache, and popular third-party cache plugins) on a schedule, on every content save, and whenever any plugin is activated, deactivated, or deleted.</p>'
				. '<p>If Divi happens to be active on the site, it automatically detects it and adds continuous purging of Divi\'s et-cache directory on top — a bonus for Divi users, not a requirement for anyone else.</p>'
				. '<p><strong>Key features:</strong></p>'
				. '<ul>'
				. '<li>Universal: instant full clear on any post/page save (Gutenberg, Divi, Elementor, classic editor — anything that fires WordPress\'s standard save hooks), theme switch, or plugin activate/deactivate/delete — with a per-layer clear report so you can verify what actually succeeded.</li>'
				. '<li>Lightweight scheduled background clear (1–60 minutes, your choice) that never noticeably touches site performance.</li>'
				. '<li>Optional built-in Public/Private page caching, with a true early-bypass mode that serves cached pages before WordPress even loads.</li>'
				. '<li>Optional persistent object cache — APCu, Redis, Memcached, or a zero-dependency File-based fallback — auto-selected on install.</li>'
				. '<li>Detects and flushes WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, and Autoptimize rather than working against them.</li>'
				. '<li>If Divi is active: Static CSS File Generation forced off (Divi 4) plus continuous et-cache purging (Divi 5, which removed that setting entirely).</li>'
				. '<li>Modern React-based settings dashboard with live toasts and a real-time countdown to the next clear.</li>'
				. '</ul>',
			'installation' => '<ol>'
				. '<li>Upload the plugin folder to <code>/wp-content/plugins/</code>, or install the zip via Plugins → Add New → Upload Plugin.</li>'
				. '<li>Activate.</li>'
				. '<li>Go to Settings → CacheNova to configure your schedule and cache types.</li>'
				. '</ol>',
			'faq' => '<p><strong>Will this conflict with my other caching plugin?</strong><br>'
				. 'It detects and flushes WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, and Autoptimize rather than working against them.</p>'
				. '<p><strong>Does the background schedule slow my site down?</strong><br>'
				. 'No — scheduled ticks run a lightweight clear only. Full clears run only when content actually changes or you click Clear Cache Now.</p>',
			'changelog' => '<p><strong>1.0.0</strong> — Initial release. Auto-clears transients, object cache, OPcache, and page cache instantly on every save (Gutenberg, Divi, Elementor, classic editor, or any other editor), on a lightweight background schedule, and automatically whenever any plugin on the site is activated, deactivated, deleted, or updated. Detects and flushes WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, and Autoptimize automatically. Includes an optional built-in Public/Private page cache with a true early-bypass mode that serves cached pages before WordPress even loads, an optional persistent object cache with automatic backend detection (Redis, Memcached, APCu, or a zero-dependency file-based fallback), an opt-in Database Optimization tool for revisions/trash/spam/expired transients, and a modern React-based settings dashboard with live status, toast notifications, and a real-time countdown to the next scheduled clear. Automatically refreshes its own cache files whenever the plugin itself is updated, so fixes and improvements always take effect. If Divi is active, also keeps its et-cache folder from ever accumulating stale files (Divi 4 and Divi 5) — no effect on any other theme.</p>',
		];

		return $info;
	}

	public function maybe_enqueue_thickbox( $hook ) {
		if ( 'plugins.php' === $hook ) {
			wp_enqueue_script( 'plugin-install' );
			wp_enqueue_style( 'thickbox' );
			wp_enqueue_script( 'thickbox' );
		}
	}

}

endif;
