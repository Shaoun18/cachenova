<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'RLCC_Cache_Drop_In_Manager' ) ) :

class RLCC_Cache_Drop_In_Manager {
	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function detect_available_object_cache_backends() {
		return [
			'apcu'      => function_exists( 'apcu_fetch' ) && function_exists( 'apcu_store' ),
			'redis'     => class_exists( 'Redis' ),
			'memcached' => class_exists( 'Memcached' ),
		];
	}

	private function object_cache_dropin_path() {
		return trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
	}

	private function object_cache_config_path() {
		return $this->config_dir() . '/' . RLCC_Cache_Cleaner::OBJECT_CACHE_CONFIG_FILENAME;
	}

	private function config_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'cache/cachenova-config';
	}

	private function ensure_wp_content_cache_dir() {
		$dir = $this->config_dir();
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
			@mkdir( $dir, 0755, true );
		}
		$htaccess = $dir . '/.htaccess';
		if ( is_dir( $dir ) && ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = $dir . '/index.php';
		if ( is_dir( $dir ) && ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	public function is_our_object_cache_dropin_active() {
		$path = $this->object_cache_dropin_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$head = @file_get_contents( $path, false, null, 0, 4096 );
		return $head && false !== strpos( $head, RLCC_Cache_Cleaner::OBJECT_CACHE_DROPIN_SIGNATURE );
	}

	public function is_foreign_object_cache_dropin_present() {
		return file_exists( $this->object_cache_dropin_path() ) && ! $this->is_our_object_cache_dropin_active();
	}

	public function test_object_cache_connection( $backend, $redis_host, $redis_port, $mc_host, $mc_port ) {
		$available = $this->detect_available_object_cache_backends();

		if ( 'apcu' === $backend ) {
			if ( empty( $available['apcu'] ) ) {
				return [ 'success' => false, 'message' => 'The APCu PHP extension is not available on this server.' ];
			}
			$probe_key = 'rlcc_oc_probe_' . wp_generate_password( 8, false );
			apcu_store( $probe_key, 1, 5 );
			$ok = apcu_fetch( $probe_key ) === 1;
			apcu_delete( $probe_key );
			return $ok
				? [ 'success' => true, 'message' => 'APCu is available and working.' ]
				: [ 'success' => false, 'message' => 'APCu is installed but a test write/read failed. Check apc.enabled / apc.enable_cli in php.ini.' ];
		}

		if ( 'redis' === $backend ) {
			if ( empty( $available['redis'] ) ) {
				return [ 'success' => false, 'message' => 'The phpredis PHP extension (class Redis) is not available on this server.' ];
			}
			try {
				$redis = new Redis();
				$connected = @$redis->connect( $redis_host, (int) $redis_port, 1.0 );
				if ( ! $connected ) {
					return [ 'success' => false, 'message' => "Could not connect to Redis at {$redis_host}:{$redis_port}." ];
				}
				$pong = $redis->ping();
				$redis->close();
				return [ 'success' => true, 'message' => "Connected to Redis at {$redis_host}:{$redis_port}." ];
			} catch ( \Throwable $e ) {
				return [ 'success' => false, 'message' => 'Redis connection failed: ' . $e->getMessage() ];
			}
		}

		if ( 'memcached' === $backend ) {
			if ( empty( $available['memcached'] ) ) {
				return [ 'success' => false, 'message' => 'The Memcached PHP extension is not available on this server.' ];
			}
			try {
				$mc = new Memcached();
				$mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );
				$mc->addServer( $mc_host, (int) $mc_port );
				$stats = @$mc->getStats();
				if ( empty( $stats ) ) {
					return [ 'success' => false, 'message' => "Could not reach Memcached at {$mc_host}:{$mc_port}." ];
				}
				return [ 'success' => true, 'message' => "Connected to Memcached at {$mc_host}:{$mc_port}." ];
			} catch ( \Throwable $e ) {
				return [ 'success' => false, 'message' => 'Memcached connection failed: ' . $e->getMessage() ];
			}
		}

		if ( 'file' === $backend ) {
			$dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/rlcc-object-cache';
			if ( ! is_dir( $dir ) ) {
				@wp_mkdir_p( $dir );
			}
			$probe_file = $dir . '/.rlcc_probe_' . wp_generate_password( 8, false );
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
			$written    = is_dir( $dir ) && @file_put_contents( $probe_file, 'ok' );
			if ( $written ) {
				$this->plugin->fs()->delete( $probe_file, false, 'f' );
				return [ 'success' => true, 'message' => 'File-based cache directory is writable — works without any PHP extension, on local and live alike.' ];
			}
			return [ 'success' => false, 'message' => "Could not write to {$dir} — check that wp-content/cache/ is writable by the web server." ];
		}

		return [ 'success' => false, 'message' => 'Unknown backend "' . $backend . '".' ];
	}

	public function install_object_cache_dropin( $backend, $redis_host, $redis_port, $mc_host, $mc_port ) {
		if ( $this->is_foreign_object_cache_dropin_present() ) {
			return [ 'success' => false, 'message' => 'A different object-cache.php is already present in wp-content/ — remove it first if you want CacheNova to manage the persistent object cache.' ];
		}

		$config = [
			'backend'        => (string) $backend,
			'redis_host'     => (string) $redis_host,
			'redis_port'     => (int) $redis_port,
			'memcached_host' => (string) $mc_host,
			'memcached_port' => (int) $mc_port,
		];
		$this->ensure_wp_content_cache_dir();
		$written = @file_put_contents( $this->object_cache_config_path(), wp_json_encode( $config ), LOCK_EX );
		if ( false === $written ) {
			return [ 'success' => false, 'message' => 'wp-content/ is not writable — could not write the object cache config file.' ];
		}

		$template = trailingslashit( dirname( RLCC_PLUGIN_FILE ) ) . 'templates/object-cache-template.php';
		if ( ! @copy( $template, $this->object_cache_dropin_path() ) ) {
			return [ 'success' => false, 'message' => 'wp-content/ is not writable — could not install object-cache.php.' ];
		}

		return [ 'success' => true, 'message' => 'Persistent object cache installed and active.' ];
	}

	public function remove_object_cache_dropin() {
		if ( $this->is_our_object_cache_dropin_active() ) {
			$this->plugin->fs()->delete( $this->object_cache_dropin_path(), false, 'f' );
		}
		if ( file_exists( $this->object_cache_config_path() ) ) {
			$this->plugin->fs()->delete( $this->object_cache_config_path(), false, 'f' );
		}
	}

	public function apply_object_cache_settings( $enabled, $backend, $redis_host, $redis_port, $mc_host, $mc_port ) {
		$current          = $this->plugin->get_settings();
		$explicit_backend = $backend && 'auto' !== $backend;
		$backend          = $explicit_backend ? $backend : $current['object_cache_backend'];
		$redis_host = null === $redis_host ? $current['object_cache_redis_host'] : $redis_host;
		$redis_port = null === $redis_port ? $current['object_cache_redis_port'] : $redis_port;
		$mc_host    = null === $mc_host ? $current['object_cache_memcached_host'] : $mc_host;
		$mc_port    = null === $mc_port ? $current['object_cache_memcached_port'] : $mc_port;

		if ( false === $enabled ) {
			$new_settings = array_merge( $current, [
				'object_cache_backend'        => $backend,
				'object_cache_redis_host'     => $redis_host,
				'object_cache_redis_port'     => (int) $redis_port,
				'object_cache_memcached_host' => $mc_host,
				'object_cache_memcached_port' => (int) $mc_port,
			] );
			$this->remove_object_cache_dropin();
			$new_settings['object_cache_enabled'] = false;
			$this->plugin->update_settings_option( $new_settings );
			$this->plugin->full_clear();
			return [ 'success' => true, 'message' => 'Persistent object cache turned off.' ];
		}

		if ( true === $enabled ) {
			if ( ! $explicit_backend ) {
				$backend = $this->plugin->pick_best_object_cache_backend();
			}
			$new_settings = array_merge( $current, [
				'object_cache_backend'        => $backend,
				'object_cache_redis_host'     => $redis_host,
				'object_cache_redis_port'     => (int) $redis_port,
				'object_cache_memcached_host' => $mc_host,
				'object_cache_memcached_port' => (int) $mc_port,
			] );
			$test = $this->test_object_cache_connection( $backend, $redis_host, $redis_port, $mc_host, $mc_port );
			if ( empty( $test['success'] ) ) {
				$this->plugin->update_settings_option( array_merge( $new_settings, [ 'object_cache_enabled' => false ] ) );
				return $test;
			}
			$install = $this->install_object_cache_dropin( $backend, $redis_host, $redis_port, $mc_host, $mc_port );
			if ( empty( $install['success'] ) ) {
				$this->plugin->update_settings_option( array_merge( $new_settings, [ 'object_cache_enabled' => false ] ) );
				return $install;
			}
			$new_settings['object_cache_enabled'] = true;
			$this->plugin->update_settings_option( $new_settings );
			$this->plugin->full_clear();
			return $install;
		}

		$new_settings = array_merge( $current, [
			'object_cache_backend'        => $backend,
			'object_cache_redis_host'     => $redis_host,
			'object_cache_redis_port'     => (int) $redis_port,
			'object_cache_memcached_host' => $mc_host,
			'object_cache_memcached_port' => (int) $mc_port,
		] );

		if ( ! empty( $current['object_cache_enabled'] ) ) {
			return $this->apply_object_cache_settings( true, $backend, $redis_host, $redis_port, $mc_host, $mc_port );
		}
		$this->plugin->update_settings_option( $new_settings );
		return [ 'success' => true, 'message' => 'Settings saved.' ];
	}

	private function advanced_cache_dropin_path() {
		return trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
	}

	private function early_cache_config_path() {
		return $this->config_dir() . '/' . RLCC_Cache_Cleaner::EARLY_CACHE_CONFIG_FILENAME;
	}

	public function is_our_advanced_cache_dropin_active() {
		$path = $this->advanced_cache_dropin_path();
		if ( ! file_exists( $path ) ) {
			return false;
		}
		$head = @file_get_contents( $path, false, null, 0, 4096 );
		return $head && false !== strpos( $head, RLCC_Cache_Cleaner::ADVANCED_CACHE_DROPIN_SIGNATURE );
	}

	public function is_foreign_advanced_cache_dropin_present() {
		return file_exists( $this->advanced_cache_dropin_path() ) && ! $this->is_our_advanced_cache_dropin_active();
	}

	private function find_wp_config_path() {
		$candidate = ABSPATH . 'wp-config.php';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
		$parent = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $parent ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $parent;
		}
		return null;
	}

	public function is_wp_cache_constant_true() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	public function wp_config_writable() {
		$path = $this->find_wp_config_path();
		return $path && $this->plugin->fs()->is_writable( $path );
	}

	private function wp_config_has_our_marker() {
		$path = $this->find_wp_config_path();
		if ( ! $path ) {
			return false;
		}
		$contents = @file_get_contents( $path );
		return $contents && false !== strpos( $contents, RLCC_Cache_Cleaner::WP_CONFIG_MARKER );
	}

	public function enable_wp_cache_constant() {
		if ( $this->is_wp_cache_constant_true() ) {
			return [ 'success' => true, 'message' => 'WP_CACHE is already enabled.' ];
		}
		$path = $this->find_wp_config_path();
		if ( ! $path || ! $this->plugin->fs()->is_writable( $path ) ) {
			return [
				'success' => false,
				'message' => "wp-config.php could not be found or isn't writable. Add this line yourself, near the top, right after the opening <?php tag: define( 'WP_CACHE', true );",
			];
		}
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			return [ 'success' => false, 'message' => 'Could not read wp-config.php.' ];
		}
		if ( false !== strpos( $contents, RLCC_Cache_Cleaner::WP_CONFIG_MARKER ) ) {
			return [ 'success' => true, 'message' => 'WP_CACHE already enabled by this plugin.' ];
		}
		$insertion    = "<?php\ndefine( 'WP_CACHE', true ); // " . RLCC_Cache_Cleaner::WP_CONFIG_MARKER;
		$new_contents = preg_replace( '/^<\?php/', $insertion, $contents, 1, $count );
		if ( ! $count ) {
			return [ 'success' => false, 'message' => 'Could not locate the opening <?php tag in wp-config.php — please add define(\'WP_CACHE\', true); manually.' ];
		}
		if ( false === @file_put_contents( $path, $new_contents, LOCK_EX ) ) { // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
			return [ 'success' => false, 'message' => 'wp-config.php is not writable.' ];
		}
		return [ 'success' => true, 'message' => 'WP_CACHE enabled in wp-config.php.' ];
	}

	public function disable_wp_cache_constant() {
		if ( ! $this->wp_config_has_our_marker() ) {
			return;
		}
		$path = $this->find_wp_config_path();
		if ( ! $path || ! $this->plugin->fs()->is_writable( $path ) ) {
			return;
		}
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			return;
		}
		$lines    = explode( "\n", $contents );
		$filtered = array_filter( $lines, function ( $line ) {
			return false === strpos( $line, RLCC_Cache_Cleaner::WP_CONFIG_MARKER );
		} );
		@file_put_contents( $path, implode( "\n", $filtered ), LOCK_EX );
	}

	public function install_advanced_cache_dropin() {
		if ( $this->is_foreign_advanced_cache_dropin_present() ) {
			return [ 'success' => false, 'message' => 'A different advanced-cache.php already exists in wp-content/ — remove it first if you want CacheNova to manage early-bypass caching.' ];
		}

		$public_dir   = trailingslashit( $this->plugin->cache_base_dir() ) . RLCC_Cache_Cleaner::PUBLIC_CACHE_DIR;
		$config = [
			'enabled' => true,
			'dir'     => (string) $public_dir,
		];
		$this->ensure_wp_content_cache_dir();
		$written = @file_put_contents( $this->early_cache_config_path(), wp_json_encode( $config ), LOCK_EX );
		if ( false === $written ) {
			return [ 'success' => false, 'message' => 'wp-content/ is not writable — could not write the early-cache config file.' ];
		}

		$template = trailingslashit( dirname( RLCC_PLUGIN_FILE ) ) . 'templates/advanced-cache-template.php';
		if ( ! @copy( $template, $this->advanced_cache_dropin_path() ) ) {
			return [ 'success' => false, 'message' => 'wp-content/ is not writable — could not install advanced-cache.php.' ];
		}

		return [ 'success' => true, 'message' => 'Early-bypass page cache drop-in installed.' ];
	}

	public function remove_advanced_cache_dropin() {
		if ( $this->is_our_advanced_cache_dropin_active() ) {
			$this->plugin->fs()->delete( $this->advanced_cache_dropin_path(), false, 'f' );
		}
		if ( file_exists( $this->early_cache_config_path() ) ) {
			$this->plugin->fs()->delete( $this->early_cache_config_path(), false, 'f' );
		}
	}

	public function apply_early_cache_settings( $enabled ) {
		$current = $this->plugin->get_settings();

		if ( null === $enabled ) {
			return [ 'success' => true, 'message' => 'No change.' ];
		}

		if ( false === $enabled ) {
			$this->remove_advanced_cache_dropin();
			$this->disable_wp_cache_constant();
			$this->plugin->update_settings_option( array_merge( $current, [ 'early_cache_enabled' => false ] ) );
			$this->plugin->full_clear();
			return [ 'success' => true, 'message' => 'Early-bypass page cache turned off.' ];
		}

		if ( empty( $current['cache_public'] ) ) {
			return [ 'success' => false, 'message' => 'Turn on Public Cache first — early-bypass caching serves the exact same cached files, just before WordPress loads.' ];
		}

		$install = $this->install_advanced_cache_dropin();
		if ( empty( $install['success'] ) ) {
			$this->plugin->update_settings_option( array_merge( $current, [ 'early_cache_enabled' => false ] ) );
			return $install;
		}

		$this->plugin->update_settings_option( array_merge( $current, [ 'early_cache_enabled' => true ] ) );
		$wp_cache_result = $this->enable_wp_cache_constant();
		$this->plugin->full_clear();

		if ( empty( $wp_cache_result['success'] ) ) {
			return [
				'success' => true,
				'message' => 'Drop-in installed, but ' . lcfirst( $wp_cache_result['message'] ),
			];
		}

		return [ 'success' => true, 'message' => 'Early-bypass page cache is active — cached pages now serve before WordPress even loads.' ];
	}

}

endif;
