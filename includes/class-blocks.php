<?php
/**
 * All things Gutenberg.
 *
 * @package Scrobbble\AddOn
 */

namespace Scrobbble\AddOn;

/**
 * Where Gutenberg blocks are registered.
 */
class Blocks {
	/**
	 * Hooks and such.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
	}

	/**
	 * Registers our blocks.
	 */
	public static function register_blocks() {
		register_block_type_from_metadata(
			dirname( __DIR__ ) . '/blocks/cover-art',
			array(
				'render_callback' => array( __CLASS__, 'render_cover_art_block' ),
			)
		);
	}

	/**
	 * Renders the `scrobbble/cover-art` block.
	 *
	 * @param  array     $attributes Block attributes.
	 * @param  string    $content    Block default content.
	 * @param  \WP_Block $block      Block instance.
	 * @return string                Output HTML.
	 */
	public static function render_cover_art_block( $attributes, $content, $block ) {
		if ( ! isset( $block->context['postId'] ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();

		$image = get_post_meta( $block->context['postId'], 'scrobbble_cover_art', true );

		if ( empty( $image ) ) {
			// We've only recently started storing cover art in a custom field.
			// But, we can try to recreate the filename from these custom
			// taxonomies.
			$artist = get_the_terms( $block->context['postId'], 'iwcpt_artist' );
			if ( ! empty( $artist[0]->name ) ) {
				$artist = $artist[0]->name;
			} else {
				return '';
			}

			$album = get_the_terms( $block->context['postId'], 'iwcpt_album' );
			if ( ! empty( $album[0]->name ) ) {
				$album = $album[0]->name;
			} else {
				return '';
			}

			$album = preg_replace( "~^$artist - ~", '', $album );
			$hash  = hash( 'sha256', $artist . $album );
			$files = glob( trailingslashit( $upload_dir['basedir'] ) . "scrobbble-art/$hash.*" );

			if ( ! empty( $files[0] ) ) {
				// Recreate URL.
				$image = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $files[0] );
			}
		}

		if ( empty( $image ) ) {
			return '';
		}

		$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $image );

		if ( ! is_file( $file_path ) ) {
			delete_post_meta( $block->context['postId'], 'scrobbble_cover_art' );
			return '';
		}

		return '<div ' . get_block_wrapper_attributes() . '>' .
			'<img src="' . esc_url( $image ) . '" width="80" height="80" alt="">' .
		'</div>';
	}
}
