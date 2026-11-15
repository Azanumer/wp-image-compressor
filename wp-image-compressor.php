<?php
/**
 * Plugin Name: Image Compressor
 * Plugin URI: https://github.com/Azanumer/wp-image-compressor
 * Description: Compress JPEG, PNG and WebP uploads automatically — re-encodes at a quality you choose, strips EXIF data, and only keeps the result when it is actually smaller. Includes a bulk compressor for existing media.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-image-compressor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WIC_VERSION', '1.0.0' );
define( 'WIC_OPTION', 'wic_settings' );
define( 'WIC_META_COMPRESSED', '_wic_compressed' );
define( 'WIC_META_SAVED', '_wic_saved_bytes' );

/**
 * Default settings.
 *
 * @return array
 */
function wic_default_settings() {
	return array(
		'enabled'       => 1,
		'jpeg_quality'  => 82,
		'png_quality'   => 82,
		'webp_quality'  => 82,
		'min_file_size' => 0, // KB; 0 = compress every image.
	);
}

/**
 * Get merged settings.
 *
 * @return array
 */
function wic_get_settings() {
	$saved = get_option( WIC_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( wic_default_settings(), $saved );
}

/**
 * Quality to use for a MIME type.
 *
 * @param string $mime MIME type.
 * @return int Quality 1-100.
 */
function wic_quality_for_mime( $mime ) {
	$settings = wic_get_settings();
	switch ( $mime ) {
		case 'image/jpeg':
			$quality = (int) $settings['jpeg_quality'];
			break;
		case 'image/png':
			$quality = (int) $settings['png_quality'];
			break;
		case 'image/webp':
			$quality = (int) $settings['webp_quality'];
			break;
		default:
			$quality = 82;
	}
	$quality = max( 1, min( 100, $quality ) );
	return apply_filters( 'wic_quality', $quality, $mime );
}

/**
 * Compress an image file in place.
 *
 * Re-encodes via WP_Image_Editor (GD or Imagick), which also strips EXIF
 * data. The re-encoded file only replaces the original when it is actually
 * smaller, so compression can never make a file bigger.
 *
 * @param string $file Absolute path to the image.
 * @return int|false Bytes saved, 0 if the file was already optimal, false on failure.
 */
function wic_compress_file( $file ) {
	if ( ! file_exists( $file ) || ! is_writable( $file ) ) {
		return false;
	}

	$type = wp_check_filetype( $file );
	$mime = isset( $type['type'] ) ? $type['type'] : '';
	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return false;
	}

	$settings = wic_get_settings();
	$min_size = (int) $settings['min_file_size'] * 1024;
	if ( $min_size > 0 && filesize( $file ) < $min_size ) {
		return 0;
	}

	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		return false;
	}

	$editor->set_quality( wic_quality_for_mime( $mime ) );

	$tmp = $file . '.wic-tmp';
	$saved = $editor->save( $tmp );
	if ( is_wp_error( $saved ) || ! file_exists( $tmp ) ) {
		if ( file_exists( $tmp ) ) {
			unlink( $tmp );
		}
		return false;
	}

	$original_size = filesize( $file );
	$new_size      = filesize( $tmp );

	if ( $new_size >= $original_size ) {
		// Re-encoding did not help — keep the original untouched.
		unlink( $tmp );
		return 0;
	}

	// Atomic-ish replace: rename over the original.
	if ( ! rename( $tmp, $file ) ) {
		unlink( $tmp );
		return false;
	}

	return $original_size - $new_size;
}

/**
 * Compress every new upload automatically.
 *
 * Runs on wp_handle_upload, before WordPress generates thumbnails, so the
 * thumbnails are built from the already-compressed original.
 *
 * @param array $upload Upload data (file, url, type).
 * @return array
 */
function wic_compress_on_upload( $upload ) {
	$settings = wic_get_settings();
	if ( empty( $settings['enabled'] ) ) {
		return $upload;
	}
	if ( empty( $upload['file'] ) || empty( $upload['type'] ) ) {
		return $upload;
	}
	if ( ! in_array( $upload['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return $upload;
	}
	wic_compress_file( $upload['file'] );
	return $upload;
}
add_filter( 'wp_handle_upload', 'wic_compress_on_upload' );

/**
 * Mark attachments compressed by the bulk tool so they are not re-done.
 *
 * @param int $attachment_id Attachment ID.
 * @param int $saved         Bytes saved.
 */
function wic_mark_compressed( $attachment_id, $saved ) {
	update_post_meta( $attachment_id, WIC_META_COMPRESSED, 1 );
	update_post_meta( $attachment_id, WIC_META_SAVED, (int) $saved );
}

/**
 * Settings page under Settings → Image Compressor.
 */
function wic_register_settings_page() {
	add_options_page(
		__( 'Image Compressor', 'wp-image-compressor' ),
		__( 'Image Compressor', 'wp-image-compressor' ),
		'manage_options',
		'wic-settings',
		'wic_render_settings_page'
	);
}
add_action( 'admin_menu', 'wic_register_settings_page' );

/**
 * Sanitize the settings form.
 *
 * @param array $input Raw input.
 * @return array
 */
function wic_sanitize_settings( $input ) {
	$out = wic_default_settings();
	if ( isset( $input['enabled'] ) ) {
		$out['enabled'] = $input['enabled'] ? 1 : 0;
	}
	foreach ( array( 'jpeg_quality', 'png_quality', 'webp_quality' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$out[ $key ] = max( 1, min( 100, (int) $input[ $key ] ) );
		}
	}
	if ( isset( $input['min_file_size'] ) ) {
		$out['min_file_size'] = max( 0, (int) $input['min_file_size'] );
	}
	return $out;
}

/**
 * Render the settings page.
 */
function wic_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-image-compressor' ) );
	}

	if ( isset( $_POST['wic_save'] ) ) {
		check_admin_referer( 'wic_save_settings' );
		update_option( WIC_OPTION, wic_sanitize_settings( wp_unslash( $_POST ) ) );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-image-compressor' ) . '</p></div>';
	}

	$settings = wic_get_settings();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Image Compressor', 'wp-image-compressor' ); ?></h1>
		<p><?php esc_html_e( 'Uploads are re-encoded at the quality below and EXIF data is stripped. A file is only replaced when the compressed version is actually smaller.', 'wp-image-compressor' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'wic_save_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Compress on upload', 'wp-image-compressor' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> <?php esc_html_e( 'Enabled', 'wp-image-compressor' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="wic-jpeg"><?php esc_html_e( 'JPEG quality', 'wp-image-compressor' ); ?></label></th>
					<td><input type="number" id="wic-jpeg" name="jpeg_quality" value="<?php echo (int) $settings['jpeg_quality']; ?>" min="1" max="100" /> <span class="description"><?php esc_html_e( '1–100. 82 is a good balance of size and quality.', 'wp-image-compressor' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="wic-png"><?php esc_html_e( 'PNG quality', 'wp-image-compressor' ); ?></label></th>
					<td><input type="number" id="wic-png" name="png_quality" value="<?php echo (int) $settings['png_quality']; ?>" min="1" max="100" /> <span class="description"><?php esc_html_e( '1–100. PNG is lossless, so this controls the compression level.', 'wp-image-compressor' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="wic-webp"><?php esc_html_e( 'WebP quality', 'wp-image-compressor' ); ?></label></th>
					<td><input type="number" id="wic-webp" name="webp_quality" value="<?php echo (int) $settings['webp_quality']; ?>" min="1" max="100" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wic-min"><?php esc_html_e( 'Skip files smaller than', 'wp-image-compressor' ); ?></label></th>
					<td><input type="number" id="wic-min" name="min_file_size" value="<?php echo (int) $settings['min_file_size']; ?>" min="0" /> KB <span class="description"><?php esc_html_e( '0 compresses everything.', 'wp-image-compressor' ); ?></span></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'wp-image-compressor' ), 'primary', 'wic_save' ); ?>
		</form>
		<p><a href="<?php echo esc_url( admin_url( 'tools.php?page=wic-bulk' ) ); ?>"><?php esc_html_e( 'Bulk-compress existing media →', 'wp-image-compressor' ); ?></a></p>
	</div>
	<?php
}

/**
 * Bulk-compress page under Tools.
 */
function wic_register_tools_page() {
	add_management_page(
		__( 'Compress Images', 'wp-image-compressor' ),
		__( 'Compress Images', 'wp-image-compressor' ),
		'manage_options',
		'wic-bulk',
		'wic_render_tools_page'
	);
}
add_action( 'admin_menu', 'wic_register_tools_page' );

/**
 * Render the bulk-compress page and run batches.
 */
function wic_render_tools_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-image-compressor' ) );
	}

	$batch_size = 20;
	$running    = isset( $_GET['wic_run'] ) && '1' === $_GET['wic_run'];
	$offset     = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;
	$done       = 0;
	$saved      = 0;

	if ( $running ) {
		check_admin_referer( 'wic_bulk_run' );

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'post_status'    => 'inherit',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'meta_query'     => array(
					array(
						'key'     => WIC_META_COMPRESSED,
						'compare' => 'NOT EXISTS',
					),
				),
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file ) {
				wic_mark_compressed( $id, 0 );
				continue;
			}
			$result = wic_compress_file( $file );
			if ( false === $result ) {
				// Unreadable or unsupported — mark so it is not retried forever.
				wic_mark_compressed( $id, 0 );
				continue;
			}
			wic_mark_compressed( $id, $result );
			$saved += $result;
			$done++;
		}

		$total_saved = (int) get_option( 'wic_total_saved', 0 ) + $saved;
		update_option( 'wic_total_saved', $total_saved );

		if ( count( $ids ) === $batch_size ) {
			// More to go — auto-continue with a meta refresh.
			$next = add_query_arg(
				array(
					'wic_run' => '1',
					'offset'  => $offset + $batch_size,
					'_wpnonce' => wp_create_nonce( 'wic_bulk_run' ),
				),
				admin_url( 'tools.php?page=wic-bulk' )
			);
			echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $next ) . '" />';
			echo '<div class="wrap"><h1>' . esc_html__( 'Compress Images', 'wp-image-compressor' ) . '</h1><p>' . esc_html__( 'Working… the page refreshes automatically until every image is done.', 'wp-image-compressor' ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bulk compression finished.', 'wp-image-compressor' ) . '</p></div>';
	}

	$compressed_count = (int) $GLOBALS['wpdb']->get_var(
		$GLOBALS['wpdb']->prepare(
			"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = %s",
			WIC_META_COMPRESSED
		)
	);
	$total_saved = (int) get_option( 'wic_total_saved', 0 );

	$start_url = wp_nonce_url(
		add_query_arg(
			array(
				'wic_run' => '1',
				'offset'  => 0,
			),
			admin_url( 'tools.php?page=wic-bulk' )
		),
		'wic_bulk_run'
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Compress Images', 'wp-image-compressor' ); ?></h1>
		<p><?php esc_html_e( 'Bulk-compress every JPEG, PNG and WebP in the Media Library. Files are only replaced when the compressed version is smaller, and each attachment is processed once.', 'wp-image-compressor' ); ?></p>
		<table class="widefat striped" style="max-width:480px">
			<tr><th><?php esc_html_e( 'Already compressed', 'wp-image-compressor' ); ?></th><td><?php echo (int) $compressed_count; ?></td></tr>
			<tr><th><?php esc_html_e( 'Total bytes saved (all runs)', 'wp-image-compressor' ); ?></th><td><?php echo esc_html( size_format( $total_saved ) ); ?></td></tr>
		</table>
		<p><a class="button button-primary" href="<?php echo esc_url( $start_url ); ?>"><?php esc_html_e( 'Compress all uncompressed images', 'wp-image-compressor' ); ?></a></p>
	</div>
	<?php
}
