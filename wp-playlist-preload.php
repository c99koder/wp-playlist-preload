<?php
/*
Plugin Name: Playlist Preload
Plugin URI: https://github.com/c99koder/wp-playlist-preload
GitHub Plugin URI: https://github.com/c99koder/wp-playlist-preload
Description: Enable video preloading on the playlist shortcode
Author: Sam Steele
Version: 1.0
*/

add_filter( 'post_playlist', 'c99_playlist_shortcode', 10, 3 );

# Modified version of wp_playlist_shortcode()
# Original: https://github.com/WordPress/WordPress/blob/master/wp-includes/media.php#L2585
$c99_playlist_shortcode_scripts_inserted = false;
function c99_playlist_shortcode($output, $attr, $instance) {
	global $c99_playlist_shortcode_scripts_inserted;
	$post = get_post();

	$atts = shortcode_atts(
		array(
			'type'         => 'audio',
			'order'        => 'ASC',
			'orderby'      => 'menu_order ID',
			'id'           => $post ? $post->ID : 0,
			'include'      => '',
			'exclude'      => '',
			'style'        => 'light',
			'tracklist'    => true,
			'tracknumbers' => true,
			'images'       => true,
			'artists'      => true,
		),
		$attr,
		'playlist'
	);

	$id = (int) $atts['id'];

	if ( 'audio' !== $atts['type'] ) {
		$atts['type'] = 'video';
	}

	$args = array(
		'post_status'    => 'inherit',
		'post_type'      => 'attachment',
		'post_mime_type' => $atts['type'],
		'order'          => $atts['order'],
		'orderby'        => $atts['orderby'],
	);

	if ( ! empty( $atts['include'] ) ) {
		$args['include'] = $atts['include'];
		$_attachments    = get_posts( $args );

		$attachments = array();
		foreach ( $_attachments as $key => $val ) {
			$attachments[ $val->ID ] = $_attachments[ $key ];
		}
	} elseif ( ! empty( $atts['exclude'] ) ) {
		$args['post_parent'] = $id;
		$args['exclude']     = $atts['exclude'];
		$attachments         = get_children( $args );
	} else {
		$args['post_parent'] = $id;
		$attachments         = get_children( $args );
	}

	if ( empty( $attachments ) ) {
		return '';
	}

	if ( is_feed() ) {
		foreach ( $attachments as $att_id => $attachment ) {
			$output .= wp_get_attachment_link( $att_id ) . "\n";
		}
		return $output;
	}

	$outer = 22; // Default padding and border of wrapper.

	$default_width  = 640;
	$default_height = 360;

	$theme_width  = empty( $content_width ) ? $default_width : ( $content_width - $outer );
	$theme_height = empty( $content_width ) ? $default_height : round( ( $default_height * $theme_width ) / $default_width );

	$data = array(
		'type'         => $atts['type'],
		// Don't pass strings to JSON, will be truthy in JS.
		'tracklist'    => wp_validate_boolean( $atts['tracklist'] ),
		'tracknumbers' => wp_validate_boolean( $atts['tracknumbers'] ),
		'images'       => wp_validate_boolean( $atts['images'] ),
		'artists'      => wp_validate_boolean( $atts['artists'] ),
	);

	$tracks = array();
	foreach ( $attachments as $attachment ) {
		$url   = wp_get_attachment_url( $attachment->ID );
		$ftype = wp_check_filetype( $url, wp_get_mime_types() );
		$track = array(
			'src'         => $url,
			'type'        => $ftype['type'],
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
		);

		$track['meta'] = array();
		$meta          = wp_get_attachment_metadata( $attachment->ID );
		if ( ! empty( $meta ) ) {

			foreach ( wp_get_attachment_id3_keys( $attachment ) as $key => $label ) {
				if ( ! empty( $meta[ $key ] ) ) {
					$track['meta'][ $key ] = $meta[ $key ];
				}
			}

			if ( 'video' === $atts['type'] ) {
				if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
					$width        = $meta['width'];
					$height       = $meta['height'];
					$theme_height = round( ( $height * $theme_width ) / $width );
				} else {
					$width  = $default_width;
					$height = $default_height;
				}

				$track['dimensions'] = array(
					'original' => compact( 'width', 'height' ),
					'resized'  => array(
						'width'  => $theme_width,
						'height' => $theme_height,
					),
				);
			}
		}

		if ( $atts['images'] ) {
			$thumb_id = get_post_thumbnail_id( $attachment->ID );
			if ( ! empty( $thumb_id ) ) {
				list( $src, $width, $height ) = wp_get_attachment_image_src( $thumb_id, 'full' );
				$track['image']               = compact( 'src', 'width', 'height' );
				list( $src, $width, $height ) = wp_get_attachment_image_src( $thumb_id, 'thumbnail' );
				$track['thumb']               = compact( 'src', 'width', 'height' );
			} else {
				$src            = wp_mime_type_icon( $attachment->ID );
				$width          = 48;
				$height         = 64;
				$track['image'] = compact( 'src', 'width', 'height' );
				$track['thumb'] = compact( 'src', 'width', 'height' );
			}
		}

		$tracks[] = $track;
	}
	$data['tracks'] = $tracks;

	$safe_type  = esc_attr( $atts['type'] );
	$safe_style = esc_attr( $atts['style'] );

	ob_start();

	if ( $c99_playlist_shortcode_scripts_inserted == false ) {
		/**
		 * Prints and enqueues playlist scripts, styles, and JavaScript templates.
		 *
		 * @since 3.9.0
		 *
		 * @param string $type  Type of playlist. Possible values are 'audio' or 'video'.
		 * @param string $style The 'theme' for the playlist. Core provides 'light' and 'dark'.
		 */
		do_action( 'wp_playlist_scripts', $atts['type'], $atts['style'] );
		$c99_playlist_shortcode_scripts_inserted = true;
	}
	?>
<div class="wp-playlist wp-<?php echo $safe_type; ?>-playlist wp-playlist-<?php echo $safe_style; ?>">
	<?php if ( 'audio' === $atts['type'] ) : ?>
		<div class="wp-playlist-current-item"></div>
	<?php endif ?>
	<<?php echo $safe_type; ?> controls="controls" preload="metadata" width="<?php echo (int) $theme_width; ?>"
		<?php
		if ( 'video' === $safe_type ) {
			echo ' height="', (int) $theme_height, '"';
		}
		?>
	></<?php echo $safe_type; ?>>
	<div class="wp-playlist-next"></div>
	<div class="wp-playlist-prev"></div>
	<noscript>
	<ol>
		<?php
		foreach ( $attachments as $att_id => $attachment ) {
			printf( '<li>%s</li>', wp_get_attachment_link( $att_id ) );
		}
		?>
	</ol>
	</noscript>
	<script type="application/json" class="wp-playlist-script"><?php echo wp_json_encode( $data ); ?></script>
</div>
	<?php
	return ob_get_clean();
}