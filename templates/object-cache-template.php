<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rlcc_oc_config_file = WP_CONTENT_DIR . '/cache/cachenova-config/object-cache-config.json';
if ( is_readable( $rlcc_oc_config_file ) ) {
	$rlcc_oc_config = json_decode( (string) file_get_contents( $rlcc_oc_config_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( is_array( $rlcc_oc_config ) ) {
		if ( ! defined( 'RLCC_OC_BACKEND' ) ) {
			define( 'RLCC_OC_BACKEND', isset( $rlcc_oc_config['backend'] ) ? (string) $rlcc_oc_config['backend'] : 'none' );
		}
		if ( ! defined( 'RLCC_OC_REDIS_HOST' ) ) {
			define( 'RLCC_OC_REDIS_HOST', isset( $rlcc_oc_config['redis_host'] ) ? (string) $rlcc_oc_config['redis_host'] : '127.0.0.1' );
		}
		if ( ! defined( 'RLCC_OC_REDIS_PORT' ) ) {
			define( 'RLCC_OC_REDIS_PORT', isset( $rlcc_oc_config['redis_port'] ) ? (int) $rlcc_oc_config['redis_port'] : 6379 );
		}
		if ( ! defined( 'RLCC_OC_MEMCACHED_HOST' ) ) {
			define( 'RLCC_OC_MEMCACHED_HOST', isset( $rlcc_oc_config['memcached_host'] ) ? (string) $rlcc_oc_config['memcached_host'] : '127.0.0.1' );
		}
		if ( ! defined( 'RLCC_OC_MEMCACHED_PORT' ) ) {
			define( 'RLCC_OC_MEMCACHED_PORT', isset( $rlcc_oc_config['memcached_port'] ) ? (int) $rlcc_oc_config['memcached_port'] : 11211 );
		}
	}
}

if ( ! defined( 'RLCC_OC_BACKEND' ) ) {
	define( 'RLCC_OC_BACKEND', 'none' );
}

if ( ! class_exists( 'RLCC_OC_Backend_APCu' ) ) {
	class RLCC_OC_Backend_APCu {
		public function available() {
			return function_exists( 'apcu_fetch' ) && function_exists( 'apcu_store' );
		}
		public function get( $key, &$found ) {
			$found = false;
			if ( ! $this->available() ) {
				return null;
			}
			$value = apcu_fetch( $key, $found );
			return $found ? $value : null;
		}
		public function set( $key, $value, $expire ) {
			if ( ! $this->available() ) {
				return false;
			}
			return apcu_store( $key, $value, max( 0, (int) $expire ) );
		}
		public function delete( $key ) {
			if ( ! $this->available() ) {
				return false;
			}
			return apcu_delete( $key );
		}
		public function flush( $prefix ) {
			if ( ! $this->available() ) {
				return false;
			}
			
			return apcu_clear_cache();
		}
		public function incr( $key, $offset ) {
			if ( ! $this->available() || ! apcu_exists( $key ) ) {
				return false;
			}
			return apcu_inc( $key, $offset );
		}
		public function decr( $key, $offset ) {
			if ( ! $this->available() || ! apcu_exists( $key ) ) {
				return false;
			}
			return apcu_dec( $key, $offset );
		}
	}
}

if ( ! function_exists( 'rlcc_oc_circuit_open' ) ) {
	function rlcc_oc_circuit_flag_path( $backend_name ) {
		return WP_CONTENT_DIR . '/cache/rlcc-oc-circuit-' . $backend_name . '.flag';
	}
	function rlcc_oc_circuit_open( $backend_name ) {
		$flag = rlcc_oc_circuit_flag_path( $backend_name );
		$mtime = @filemtime( $flag );
		return $mtime && ( time() - $mtime ) < 30; 
	}
	function rlcc_oc_circuit_trip( $backend_name ) {
		$flag = rlcc_oc_circuit_flag_path( $backend_name );
		$dir  = dirname( $flag );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
			@mkdir( $dir, 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
		@touch( $flag );
	}
	function rlcc_oc_circuit_reset( $backend_name ) {
		$flag = rlcc_oc_circuit_flag_path( $backend_name );
		if ( file_exists( $flag ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
			@unlink( $flag );
		}
	}
}

if ( ! class_exists( 'RLCC_OC_Backend_Redis' ) ) {
	class RLCC_OC_Backend_Redis {
		private $redis = null;
		private $connected = false;

		public function __construct( $host, $port ) {
			if ( ! class_exists( 'Redis' ) ) {
				return;
			}
			if ( rlcc_oc_circuit_open( 'redis' ) ) {
				return;
			}
			try {
				$this->redis = new Redis();
				
				$this->connected = @$this->redis->connect( $host, (int) $port, 1.0 );
				if ( $this->connected ) {
					rlcc_oc_circuit_reset( 'redis' );
				} else {
					rlcc_oc_circuit_trip( 'redis' );
				}
			} catch ( \Throwable $e ) {
				$this->connected = false;
			}
		}
		public function available() {
			return $this->connected;
		}
		public function get( $key, &$found ) {
			$found = false;
			if ( ! $this->connected ) {
				return null;
			}
			try {
				$raw = $this->redis->get( $key );
				if ( false === $raw ) {
					return null;
				}
				$found = true;
				return @unserialize( $raw );
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		public function set( $key, $value, $expire ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				$payload = serialize( $value );
				if ( $expire > 0 ) {
					return (bool) $this->redis->setex( $key, (int) $expire, $payload );
				}
				return (bool) $this->redis->set( $key, $payload );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		public function delete( $key ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				return $this->redis->del( $key ) > 0;
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		public function flush( $prefix ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				$cursor = null;
				do {
					$keys = $this->redis->scan( $cursor, $prefix . '*', 500 );
					if ( ! empty( $keys ) ) {
						$this->redis->del( $keys );
					}
				} while ( $cursor !== null && $cursor != 0 );
				return true;
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		public function incr( $key, $offset ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				return $this->redis->incrBy( $key, $offset );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		public function decr( $key, $offset ) {
			if ( ! $this->connected ) {
				return false;
			}
			try {
				return $this->redis->decrBy( $key, $offset );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
	}
}

if ( ! class_exists( 'RLCC_OC_Backend_Memcached' ) ) {
	class RLCC_OC_Backend_Memcached {
		private $mc = null;
		private $connected = false;

		public function __construct( $host, $port ) {
			if ( ! class_exists( 'Memcached' ) ) {
				return;
			}
			if ( rlcc_oc_circuit_open( 'memcached' ) ) {
				return;
			}
			try {
				$this->mc = new Memcached();
				$this->mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );
				$this->mc->addServer( $host, (int) $port );
				
				$stats           = @$this->mc->getStats();
				$this->connected = ! empty( $stats );
				if ( $this->connected ) {
					rlcc_oc_circuit_reset( 'memcached' );
				} else {
					rlcc_oc_circuit_trip( 'memcached' );
				}
			} catch ( \Throwable $e ) {
				$this->connected = false;
				rlcc_oc_circuit_trip( 'memcached' );
			}
		}
		public function available() {
			return $this->connected;
		}
		public function get( $key, &$found ) {
			$found = false;
			if ( ! $this->connected ) {
				return null;
			}
			$value = $this->mc->get( $key );
			if ( \Memcached::RES_SUCCESS !== $this->mc->getResultCode() ) {
				return null;
			}
			$found = true;
			return $value;
		}
		public function set( $key, $value, $expire ) {
			if ( ! $this->connected ) {
				return false;
			}
			return $this->mc->set( $key, $value, max( 0, (int) $expire ) );
		}
		public function delete( $key ) {
			if ( ! $this->connected ) {
				return false;
			}
			return $this->mc->delete( $key );
		}
		public function flush( $prefix ) {
			if ( ! $this->connected ) {
				return false;
			}
			
			return $this->mc->flush();
		}
		public function incr( $key, $offset ) {
			if ( ! $this->connected ) {
				return false;
			}
			$result = $this->mc->increment( $key, $offset );
			return false === $result ? false : $result;
		}
		public function decr( $key, $offset ) {
			if ( ! $this->connected ) {
				return false;
			}
			$result = $this->mc->decrement( $key, $offset );
			return false === $result ? false : $result;
		}
	}
}

if ( ! class_exists( 'RLCC_OC_Backend_File' ) ) {
	class RLCC_OC_Backend_File {
		private $dir;
		private $store_path;
		private $data        = [];
		private $loaded      = false;
		private $dirty       = false;
		private $max_entries = 3000;

		public function __construct( $dir ) {
			$this->dir        = rtrim( $dir, '/' );
			$this->store_path = $this->dir . '/store.cache';
			
			if ( ! is_dir( $this->dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
				@mkdir( $this->dir, 0755, true );
			}
		}

		public function available() {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return is_dir( $this->dir ) && is_writable( $this->dir );
		}

		private function load() {
			if ( $this->loaded ) {
				return;
			}
			$this->loaded = true;

			if ( is_file( $this->store_path ) ) {
				$raw = @file_get_contents( $this->store_path );
				if ( false !== $raw && '' !== $raw ) {
					$decoded = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					if ( is_array( $decoded ) ) {
						$this->data = $decoded;
					}
				}
			}

			$now = time();
			foreach ( $this->data as $k => $entry ) {
				if ( ! empty( $entry['x'] ) && $now > $entry['x'] ) {
					unset( $this->data[ $k ] );
					$this->dirty = true;
				}
			}
			if ( count( $this->data ) > $this->max_entries ) {
				$excess     = count( $this->data ) - (int) ( $this->max_entries * 0.8 );
				$this->data = array_slice( $this->data, $excess, null, true );
				$this->dirty = true;
			}

			register_shutdown_function( [ $this, 'persist' ] );
		}

		private function contains_incomplete_class( $value ) {
			if ( is_object( $value ) ) {
				return $value instanceof __PHP_Incomplete_Class;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_object( $item ) && $item instanceof __PHP_Incomplete_Class ) {
						return true;
					}
				}
			}
			return false;
		}

		public function persist() {
			if ( ! $this->dirty || ! $this->available() ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
			@file_put_contents( $this->store_path, serialize( $this->data ), LOCK_EX );
			$this->dirty = false;
		}

		public function get( $key, &$found ) {
			$this->load();
			$found = false;
			if ( ! isset( $this->data[ $key ] ) ) {
				return null;
			}
			$entry = $this->data[ $key ];
			if ( ! empty( $entry['x'] ) && time() > $entry['x'] ) {
				unset( $this->data[ $key ] );
				$this->dirty = true;
				return null;
			}
			$found = true;
			if ( $this->contains_incomplete_class( $entry['v'] ) ) {
				unset( $this->data[ $key ] );
				$this->dirty = true;
				$found       = false;
				return null;
			}
			return $entry['v'];
		}

		public function set( $key, $value, $expire ) {
			$this->load();
			$this->data[ $key ] = [
				'v' => $value,
				'x' => $expire > 0 ? ( time() + (int) $expire ) : 0,
			];
			$this->dirty = true;
			return true;
		}

		public function delete( $key ) {
			$this->load();
			if ( isset( $this->data[ $key ] ) ) {
				unset( $this->data[ $key ] );
				$this->dirty = true;
			}
			return true;
		}

		public function flush( $prefix ) {
			$this->load();
			$this->data  = [];
			$this->dirty = true;
			$this->persist(); 
			return true;
		}

		public function incr( $key, $offset ) {
			$found = false;
			$value = $this->get( $key, $found );
			if ( ! $found || ! is_numeric( $value ) ) {
				return false;
			}
			$new = max( 0, (int) $value + (int) $offset );
			$this->set( $key, $new, 0 );
			return $new;
		}
		public function decr( $key, $offset ) {
			return $this->incr( $key, -$offset );
		}
	}
}

if ( ! class_exists( 'WP_Object_Cache' ) ) {
	#[\AllowDynamicProperties]
	class WP_Object_Cache {
		private $cache = [];

		private $backend         = null;
		private $backend_name    = 'none';
		private $key_prefix      = '';
		private $global_groups   = [];
		private $non_persistent_groups = [];
		private $multisite       = false;
		private $blog_id         = 1;

		public $cache_hits   = 0;
		public $cache_misses = 0;

		public function __construct() {
			$this->multisite = function_exists( 'is_multisite' ) ? is_multisite() : false;
			$this->blog_id   = ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );

			$salt             = defined( 'RLCC_OC_KEY_SALT' ) ? RLCC_OC_KEY_SALT : md5( ABSPATH );
			$this->key_prefix = 'rlcc_oc:' . substr( $salt, 0, 12 ) . ':';

			$backend = defined( 'RLCC_OC_BACKEND' ) ? RLCC_OC_BACKEND : 'none';

			switch ( $backend ) {
				case 'apcu':
					$adapter = new RLCC_OC_Backend_APCu();
					if ( $adapter->available() ) {
						$this->backend      = $adapter;
						$this->backend_name = 'apcu';
					}
					break;

				case 'redis':
					$host    = defined( 'RLCC_OC_REDIS_HOST' ) ? RLCC_OC_REDIS_HOST : '127.0.0.1';
					$port    = defined( 'RLCC_OC_REDIS_PORT' ) ? RLCC_OC_REDIS_PORT : 6379;
					$adapter = new RLCC_OC_Backend_Redis( $host, $port );
					if ( $adapter->available() ) {
						$this->backend      = $adapter;
						$this->backend_name = 'redis';
					}
					break;

				case 'memcached':
					$host    = defined( 'RLCC_OC_MEMCACHED_HOST' ) ? RLCC_OC_MEMCACHED_HOST : '127.0.0.1';
					$port    = defined( 'RLCC_OC_MEMCACHED_PORT' ) ? RLCC_OC_MEMCACHED_PORT : 11211;
					$adapter = new RLCC_OC_Backend_Memcached( $host, $port );
					if ( $adapter->available() ) {
						$this->backend      = $adapter;
						$this->backend_name = 'memcached';
					}
					break;

				case 'file':
					$dir     = WP_CONTENT_DIR . '/cache/rlcc-object-cache';
					$adapter = new RLCC_OC_Backend_File( $dir );
					if ( $adapter->available() ) {
						$this->backend      = $adapter;
						$this->backend_name = 'file';
					}
					break;
			}
			
		}

		private function build_key( $key, $group ) {
			$group = $group ?: 'default';
			if ( $this->multisite && ! in_array( $group, $this->global_groups, true ) ) {
				return $this->key_prefix . $this->blog_id . ':' . $group . ':' . $key;
			}
			return $this->key_prefix . $group . ':' . $key;
		}

		private function is_persistent_group( $group ) {
			return null !== $this->backend && ! in_array( $group, $this->non_persistent_groups, true );
		}

		public function add( $key, $value, $group = 'default', $expire = 0 ) {
			$ck = $this->build_key( $key, $group );
			if ( array_key_exists( $ck, $this->cache ) ) {
				return false;
			}
			if ( $this->is_persistent_group( $group ) ) {
				$found = false;
				$this->backend->get( $ck, $found );
				if ( $found ) {
					return false;
				}
			}
			return $this->set( $key, $value, $group, $expire );
		}

		public function replace( $key, $value, $group = 'default', $expire = 0 ) {
			$ck = $this->build_key( $key, $group );
			$exists = array_key_exists( $ck, $this->cache );
			if ( ! $exists && $this->is_persistent_group( $group ) ) {
				$found = false;
				$this->backend->get( $ck, $found );
				$exists = $found;
			}
			if ( ! $exists ) {
				return false;
			}
			return $this->set( $key, $value, $group, $expire );
		}

		public function set( $key, $value, $group = 'default', $expire = 0 ) {
			$ck = $this->build_key( $key, $group );
			if ( is_object( $value ) ) {
				$value = clone $value;
			}
			$this->cache[ $ck ] = $value;
			if ( $this->is_persistent_group( $group ) ) {
				$this->backend->set( $ck, $value, $expire );
			}
			return true;
		}

		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			$ck = $this->build_key( $key, $group );
			if ( ! $force && array_key_exists( $ck, $this->cache ) ) {
				$found = true;
				$this->cache_hits++;
				return is_object( $this->cache[ $ck ] ) ? clone $this->cache[ $ck ] : $this->cache[ $ck ];
			}
			if ( $this->is_persistent_group( $group ) ) {
				$backend_found = false;
				$value         = $this->backend->get( $ck, $backend_found );
				if ( $backend_found ) {
					$this->cache[ $ck ] = $value;
					$found              = true;
					$this->cache_hits++;
					return is_object( $value ) ? clone $value : $value;
				}
			}
			$found = false;
			$this->cache_misses++;
			return false;
		}

		public function delete( $key, $group = 'default' ) {
			$ck = $this->build_key( $key, $group );
			unset( $this->cache[ $ck ] );
			if ( $this->is_persistent_group( $group ) ) {
				$this->backend->delete( $ck );
			}
			return true;
		}

		public function flush() {
			$this->cache = [];
			if ( null !== $this->backend ) {
				$this->backend->flush( $this->key_prefix );
			}
			return true;
		}

		public function flush_runtime() {
			$this->cache = [];
			return true;
		}

		public function incr( $key, $offset = 1, $group = 'default' ) {
			$ck = $this->build_key( $key, $group );
			if ( $this->is_persistent_group( $group ) ) {
				$result = $this->backend->incr( $ck, $offset );
				if ( false !== $result ) {
					$this->cache[ $ck ] = $result;
					return $result;
				}
			}
			if ( ! array_key_exists( $ck, $this->cache ) ) {
				return false;
			}
			$this->cache[ $ck ] = max( 0, (int) $this->cache[ $ck ] + $offset );
			return $this->cache[ $ck ];
		}

		public function decr( $key, $offset = 1, $group = 'default' ) {
			return $this->incr( $key, -$offset, $group );
		}

		public function add_global_groups( $groups ) {
			$this->global_groups = array_unique( array_merge( $this->global_groups, (array) $groups ) );
		}

		public function add_non_persistent_groups( $groups ) {
			$this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, (array) $groups ) );
		}

		public function switch_to_blog( $blog_id ) {
			$this->blog_id = (int) $blog_id;
		}

		public function close() {
			return true;
		}

		public function stats() {
			return [
				'backend'      => $this->backend_name,
				'hits'         => $this->cache_hits,
				'misses'       => $this->cache_misses,
				'runtime_keys' => count( $this->cache ),
			];
		}
	}
}



if ( ! function_exists( 'wp_cache_init' ) ) {
	function wp_cache_init() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	}
}
if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $value, $group = '', $expire = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->add( $key, $value, $group, $expire );
	}
}
if ( ! function_exists( 'wp_cache_replace' ) ) {
	function wp_cache_replace( $key, $value, $group = '', $expire = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->replace( $key, $value, $group, $expire );
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->set( $key, $value, $group, $expire );
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->delete( $key, $group );
	}
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->flush();
	}
}
if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
	function wp_cache_flush_runtime() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->flush_runtime();
	}
}
if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $key, $offset = 1, $group = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
	}
}
if ( ! function_exists( 'wp_cache_decr' ) ) {
	function wp_cache_decr( $key, $offset = 1, $group = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
	}
}
if ( ! function_exists( 'wp_cache_add_global_groups' ) ) {
	function wp_cache_add_global_groups( $groups ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wp_object_cache']->add_global_groups( $groups );
	}
}
if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
	function wp_cache_add_non_persistent_groups( $groups ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
	}
}
if ( ! function_exists( 'wp_cache_switch_to_blog' ) ) {
	function wp_cache_switch_to_blog( $blog_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
	}
}
if ( ! function_exists( 'wp_cache_close' ) ) {
	function wp_cache_close() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['wp_object_cache']->close();
	}
}
if ( ! function_exists( 'wp_cache_supports' ) ) {
	function wp_cache_supports( $feature ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return in_array( $feature, [ 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime' ], true );
	}
}
