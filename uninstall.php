<?php
/**
 * Uninstall handler for Image Compressor.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wic_settings' );
delete_option( 'wic_total_saved' );
delete_post_meta_by_key( '_wic_compressed' );
delete_post_meta_by_key( '_wic_saved_bytes' );
