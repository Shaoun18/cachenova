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

	public function purge_et_cache_dir() {
		$path = trailingslashit( WP_CONTENT_DIR ) . 'et-cache';
		if ( is_dir( $path ) ) {
			$this->plugin->recursive_clear_directory( $path );
			$this->plugin->fs()->rmdir( $path );
		}
	}
}

endif;
