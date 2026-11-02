<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'RLCC_Divi_Integration' ) ) :

class RLCC_Divi_Integration {
	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function is_active() {
		return function_exists( 'et_core_clear_cache' ) || defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' );
	}

	public function disable_static_css() {
		$et_divi = get_option( 'et_divi', [] );
		if ( ! is_array( $et_divi ) || empty( $et_divi ) ) {
			return;
		}

		$changed = false;
		foreach ( $et_divi as $key => $value ) {
			if ( stripos( $key, 'static_css' ) === false ) {
				continue;
			}
			$off_value = is_bool( $value ) ? false : 'off';
			if ( $value !== $off_value ) {
				$et_divi[ $key ] = $off_value;
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'et_divi', $et_divi );
			$this->purge_et_cache_dir();
		}
	}

	/** Ask Divi itself to drop its generated resources first (best effort, never fatal). */
	private function ask_divi_to_clear() {
		try {
			if ( function_exists( 'et_core_clear_wp_cache' ) ) {
				et_core_clear_wp_cache();
			}
			if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
				ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
			}
		} catch ( \Throwable $e ) {
			// Divi internals differ between versions; the filesystem purge below is the real work.
		}
	}

	/** Safety check: only ever delete wp-content/et-cache, with Windows-safe path comparison. */
	private function is_safe_et_cache_path( $path ) {
		$root   = realpath( WP_CONTENT_DIR );
		$actual = realpath( $path );
		if ( ! $root || ! $actual ) return false;
		$root   = untrailingslashit( wp_normalize_path( $root ) );
		$actual = untrailingslashit( wp_normalize_path( $actual ) );
		$parent = dirname( $actual );
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$root   = strtolower( $root );
			$parent = strtolower( $parent );
		}
		return $parent === $root && 'et-cache' === strtolower( basename( $actual ) );
	}

	/** Recursively delete a tree; fixes read-only permissions (common on Windows/Laragon/XAMPP) and never follows links. */
	private function remove_tree( $dir ) {
		$items = @scandir( $dir );
		if ( false === $items ) {
			@chmod( $dir, 0755 );
			$items = @scandir( $dir );
			if ( false === $items ) return false;
		}
		$ok = true;
		foreach ( $items as $name ) {
			if ( '.' === $name || '..' === $name ) continue;
			$item = $dir . DIRECTORY_SEPARATOR . $name;
			if ( is_link( $item ) ) {
				if ( ! @unlink( $item ) && ! @rmdir( $item ) ) $ok = false;
			} elseif ( is_dir( $item ) ) {
				if ( ! $this->remove_tree( $item ) ) $ok = false;
			} elseif ( ! @unlink( $item ) ) {
				@chmod( $item, 0666 );
				if ( ! @unlink( $item ) ) $ok = false;
			}
		}
		if ( ! @rmdir( $dir ) ) {
			@chmod( $dir, 0777 );
			if ( ! @rmdir( $dir ) ) $ok = false;
		}
		return $ok;
	}

	/** Remove the entire Divi et-cache directory; never follows symbolic links. */
	public function purge_et_cache_dir() {
		$path = trailingslashit( WP_CONTENT_DIR ) . 'et-cache';
		clearstatcache( true, $path );
		if ( ! file_exists( $path ) && ! is_link( $path ) ) return true;

		$this->ask_divi_to_clear();

		clearstatcache( true, $path );
		if ( ! file_exists( $path ) && ! is_link( $path ) ) return true;

		// A symlinked et-cache: remove only the link itself, never the target.
		if ( is_link( $path ) ) {
			$gone = @unlink( $path ) || @rmdir( $path );
			clearstatcache( true, $path );
			return $gone && ! file_exists( $path );
		}
		if ( ! is_dir( $path ) ) {
			@unlink( $path );
			clearstatcache( true, $path );
			return ! file_exists( $path );
		}
		if ( ! $this->is_safe_et_cache_path( $path ) ) return false;

		$this->remove_tree( $path );
		clearstatcache( true, $path );
		if ( file_exists( $path ) ) {
			// One retry: Divi or the OS may have been holding a file open a moment ago.
			usleep( 150000 );
			$this->remove_tree( $path );
			clearstatcache( true, $path );
		}
		return ! file_exists( $path );
	}

	/** Delete et-cache once more at the very end of the request, after Divi's own shutdown writers have run. */
	public function schedule_shutdown_purge() {
		static $registered = false;
		if ( $registered ) return;
		$registered = true;
		add_action( 'shutdown', [ $this, 'purge_et_cache_dir' ], PHP_INT_MAX );
	}

}

endif;
