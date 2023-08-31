<?php
/**
 * Actual plugin logic.
 *
 * @package Scrobbble\AddOn
 */

namespace Scrobbble\AddOn;

/**
 * Main plugin class.
 */
class Plugin {
	const  PLUGIN_VERSION = '0.1.0';

	/**
	 * This class's single instance.
	 *
	 * @var Plugin $instance Plugin instance.
	 */
	private static $instance;

	/**
	 * Returns the single instance of this class.
	 *
	 * @return Plugin This class's single instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers callback functions.
	 */
	public function register() {
		// Runs after a "Scrobble" post is saved.
		add_action( 'scrobbble_save_track', array( $this, 'add_genres' ), 10, 2 );
		add_action( 'scrobbble_save_track', array( $this, 'add_release_meta' ), 10, 2 );

		add_action( 'scrobbble_fetch_cover_art', array( $this, 'fetch_cover_art' ), 10, 3 );

		Blocks::register();
	}

	/**
	 * Adds genres to "listen" posts.
	 *
	 * @param int   $post_id Listen ID.
	 * @param array $track   Track information.
	 */
	public function add_genres( $post_id, $track ) {
		if ( empty( $track['mbid'] ) ) {
			// MusicBrainz ID unknown, because not all scrobblers include one and
			// because certain rarer releases or tracks don't have one.
			error_log( '[Scrobbble Add-On] Missing track MBID. Attempting to search for one.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$title  = urlencode( strtolower( $track['title'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
			$artist = urlencode( strtolower( $track['artist'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
			$album  = urlencode( strtolower( $track['album'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode

			$response = wp_safe_remote_get(
				// So, I *think* this type of query oughta give us somewhat reliable results. Except maybe if MB doesn't know about the track.
				esc_url_raw( "https://musicbrainz.org/ws/2/recording?query=work:{$title}+release:{$album}+artist:{$artist}&limit=1&fmt=json" ),
				array(
					'user-agent' => 'ScrobbbleForWordPress +' . home_url( '/' ),
				)
			);

			if ( ! empty( $response['body'] ) ) {
				error_log( print_r( $response['body'], true ) );
				$data = json_decode( $response['body'], true );
			}

			if (
				! empty( $data['recordings'][0]['id'] ) &&
				! empty( $data['recordings'][0]['title'] ) &&
				preg_replace( '~[^A-Za-z0-9]~', '', $data['recordings'][0]['title'] ) === preg_replace( '~[^A-Za-z0-9]~', '', $track['title'] ) && // Strip away, e.g., curly quotes, etc.
				! empty( $data['recordings'][0]['artist-credit'][0]['name'] ) &&
				preg_replace( '~[^A-Za-z0-9]~', '', $data['recordings'][0]['artist-credit'][0]['name'] ) === preg_replace( '~[^A-Za-z0-9]~', '', $track['artist'] ) // Still overly strict because what if there are multiple artists, etc.?
			) {
				// If we got a result and the artist and track title are a near exact match.
				$track['mbid'] = $data['recordings'][0]['id']; // Use this as the track's MBID, at least temporarily.
			} else {
				error_log( '[Scrobbble Add-On] Could not find a recording MBID.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		if ( empty( $track['mbid'] ) ) {
			// Still empty.
			return;
		}

		error_log( '[Scrobbble Add-On] Getting genre information from MusicBrainz.org.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Get the genres, if any,for this track. Note that these can be quite generic; some (but not all?) tracks have more accurate genre info in their tags.
		$response = wp_safe_remote_get(
			esc_url_raw( "https://musicbrainz.org/ws/2/recording/{$track['mbid']}?fmt=json&inc=genres" ),
			array(
				'user-agent' => 'ScrobbbleForWordPress +' . home_url( '/' ),
			)
		);

		if ( ! empty( $response['body'] ) ) {
			error_log( print_r( $response['body'], true ) );
			$data = json_decode( $response['body'], true );
		} else {
			error_log( '[Scrobbble Add-On] Something went wrong.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			// Quick 'n' dirty troubleshooting.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		// Tag the post with genre information.
		if ( ! empty( $data['genres'] ) && is_array( $data['genres'] ) ) {
			error_log( '[Scrobbble Add-On] Adding ' . count( $data['genres'] ) . ' genre(s).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$genres = array_column( $data['genres'], 'name' );

			if ( ! empty( $genres ) ) {
				$genres = array_map( 'strtolower', $genres );
				$genres = array_map( 'sanitize_text_field', $genres );
				wp_set_object_terms( $post_id, $genres, 'iwcpt_genre' );
			}
		}
	}

	/**
	 * Adds an album MBID to "listen" posts.
	 *
	 * @param string $post_id Listen ID.
	 * @param array  $track   Track information.
	 */
	public function add_release_meta( $post_id, $track ) {
		error_log( '[Scrobbble Add-On] Getting album data.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// @todo: How do we link an image? Term metadata, or do we just use a hash?
		// We could use this as the filename for the cover. Not all albums have a
		// MusicBrainz ID, so this would work even then. And it's easier than
		// generating and keeping track of yet another unique ID.
		$hash = hash( 'sha256', $track['artist'] . $track['album'] );

		/**
		 * So, in a previous version, we would store album (or release) MBIDs in a
		 * separate table. But really, we should probably add them to our "monster
		 * library" above.
		 */

		$artist = urlencode( strtolower( $track['artist'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode
		$album  = urlencode( strtolower( $track['album'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode

		// Search MusicBrainz for the album/single/whatever.
		$response = wp_safe_remote_get(
			esc_url_raw( "https://musicbrainz.org/ws/2/release?query=release:{$album}+artist:{$artist}&limit=1&fmt=json" ),
			array(
				'user-agent' => 'ScrobbbleForWordPress +' . home_url( '/' ),
			)
		);

		if ( ! empty( $response['body'] ) ) {
			$data = json_decode( $response['body'], true );
		} else {
			error_log( '[Scrobbble Add-On] Something went wrong.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		// Store release MBID.
		if ( ! empty( $data['releases'][0]['id'] ) ) {
			error_log( '[Scrobbble Add-On] Got album MBID.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$album_mbid = $data['releases'][0]['id']; // Kinda hoping this is the correct one. If so, with this, we should be able to get cover art, and, e.g., a Discogs URL.
		}

		if ( ! empty( $album_mbid ) ) {
			$album_mbid = sanitize_text_field( $album_mbid );
			// Since each "listen" corresponds with one track, from one "album,"
			// it's "safe" to store this as post meta (and not term meta). An
			// "album" term, after all, could correspond to more than one release.
			// Like, there could be different versions and such.
			update_post_meta( $post_id, 'scrobbble_album_mbid', $album_mbid );

			// Fetch cover art.
			wp_schedule_single_event( time(), 'scrobbble_fetch_cover_art', array( $album_mbid, $hash, $post_id ) );
		}
	}

	/**
	 * Finds album cover art.
	 *
	 * @param string $album_mbid Album (or release) MBID.
	 * @param string $hash       Filename, sans extension.
	 * @param int    $post_id    (Optional) post ID.
	 */
	public function fetch_cover_art( $album_mbid, $hash, $post_id = 0 ) {
		error_log( '[Scrobbble Add-On] Trying to fetch cover art.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( empty( $album_mbid ) ) {
			error_log( '[Scrobbble Add-On] Missing album MBID. Quitting.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Get uploads folder.
		$upload_dir = wp_upload_dir();

		// Looking for the filename (the hash) without an extension.
		$files = glob( $upload_dir['basedir'] . "/scrobbble-art/{$hash}*" );

		if ( count( $files ) > 0 ) {
			// Cover art for this album already exists.
			error_log( '[Scrobbble Add-On] Cover art already exists.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Search for the album/single/whatever.
		$response = wp_safe_remote_get(
			esc_url_raw( "http://coverartarchive.org/release/{$album_mbid}" ), // @todo: Alternative sources? What if we don't have an MBID?
			array(
				'user-agent' => 'ScrobbbleForWordPress +' . home_url( '/' ),
			)
		);

		/*
		* There may not be cover art for a specific release. But the "release
		* group" might have art, or a "front."
		*/

		if ( ! empty( $response['body'] ) ) {
			error_log( print_r( $response['body'], true ) );
			$data = json_decode( $response['body'], true );
		} else {
			error_log( '[Scrobbble Add-On] Something went wrong.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		if ( empty( $data['images'] ) ) {
			error_log( '[Scrobbble Add-On] No cover art found, trying the "release group."' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// Try the "release group" instead.
			$response = wp_safe_remote_get(
				esc_url_raw( "https://musicbrainz.org/ws/2/release/{$album_mbid}?fmt=json&inc=release-groups" ),
				array(
					'user-agent' => 'ScrobbbleForWordPress +' . home_url( '/' ),
				)
			);

			if ( ! empty( $response['body'] ) ) {
				$release_group = json_decode( $response['body'], true );
			} else {
				error_log( '[Scrobbble Add-On] Something went wrong.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
				return;
			}

			if ( ! empty( $release_group['release-group']['id'] ) ) {
				// Query the Cover Art Archive for this release group.
				$response = wp_safe_remote_get(
					esc_url_raw( "http://coverartarchive.org/release-group/{$release_group['release-group']['id']}" ),
					array(
						'user-agent' => 'ScrobbbleForWordPress +' . home_url( '/' ),
					)
				);

				if ( ! empty( $response['body'] ) ) {
					$data = json_decode( $response['body'], true );
				} else {
					// Still not. Return empty-handed.
					error_log( '[Scrobbble Add-On] Something went wrong. Aborting mission.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r
					return;
				}
			}

			if ( empty( $data['images'] ) ) {
				// Still not. Return empty-handed.
				error_log( '[Scrobbble Add-On] Could not find cover art. Stopping here.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return;
			}
		}

		// Now that we have an array of images ...
		foreach ( $data['images'] as $image ) {
			// We're interested in the "fronts" only.
			if ( ! empty( $image['types'] ) && in_array( 'Front', (array) $image['types'], true ) ) {
				$cover_art = $image['thumbnails']['500'] ?? $image['thumbnails']['1200'] ?? $image['image'] ?? '';

				if ( empty( $cover_art ) ) {
					error_log( '[Scrobbble Add-On] Could not find cover art.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					return;
				}

				$file_ext = pathinfo( $cover_art, PATHINFO_EXTENSION );
				$filename = $hash . ( ! empty( $file_ext ) ? '.' . $file_ext : '' );

				error_log( '[Scrobbble Add-On] Attempting to download the file at ' . $cover_art . '.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$local_url = $this->store_image( $cover_art, $filename, 'scrobbble-art' );

				if ( ! empty( $local_url ) && 0 !== $post_id ) {
					update_post_meta( $post_id, 'scrobbble_cover_art', $local_url );
				} else {
					error_log( '[Scrobbble Add-On] Could not download cover art.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}

	/**
	 * Stores a remote image locally.
	 *
	 * @param  string $url      Image URL.
	 * @param  string $filename File name.
	 * @param  string $dir      Target directory, relative to the uploads directory.
	 * @param  string $width    Target width.
	 * @param  string $height   Target height.
	 * @return string|null      Local image URL, or nothing on failure.
	 */
	protected function store_image( $url, $filename, $dir, $width = 150, $height = 150 ) {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . trim( $dir, '/' );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir ); // Recursive directory creation. Permissions are taken from the nearest parent folder.
		}

		$file_path = trailingslashit( $dir ) . sanitize_file_name( $filename );

		if ( file_exists( $file_path ) ) {
			// File exists and is under a month old.
			error_log( '[Scrobbble Add-On] ' . $file_path . ' already exists.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		} else {
			// Attempt to download the image.
			$response = wp_safe_remote_get(
				esc_url_raw( $url ),
				array(
					'headers'    => array( 'Accept' => 'image/*' ),
					'user-agent' => 'ScrobbbleForWordPress +' . home_url( '/' ),
				)
			);

			$body = wp_remote_retrieve_body( $response );

			if ( empty( $body ) ) {
				debug_log( '[Scrobbble Add-On] Could not download the image at ' . esc_url_raw( $url ) . '.' );
				return null;
			}

			// Now store it locally.
			global $wp_filesystem;

			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			// Write image data.
			if ( ! $wp_filesystem->put_contents( $file_path, $body, 0644 ) ) {
				debug_log( '[Scrobbble Add-On] Could not save image file: ' . $file_path . '.' );
				return null;
			}

			if ( ! function_exists( 'wp_crop_image' ) ) {
				// Load WordPress' image functions.
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			if ( ! file_is_valid_image( $file_path ) || ! file_is_displayable_image( $file_path ) ) {
				// Somehow not a valid image. Delete it.
				unlink( $file_path );

				debug_log( '[Scrobbble Add-On] Invalid image file: ' . esc_url_raw( $url ) . '.' );
				return null;
			}

			// Try to scale down and crop it.
			$image = wp_get_image_editor( $file_path );

			if ( ! is_wp_error( $image ) ) {
				$image->resize( $width, $height, true );
				$result = $image->save( $file_path );

				if ( $file_path !== $result['path'] ) {
					// The image editor's `save()` method has altered the file path (like, added an extension that wasn't there previously).
					unlink( $file_path ); // Delete "old" image.
					$file_path = $result['path'];
				}
			} else {
				debug_log( '[Scrobbble Add-On] Could not reisize ' . $file_path . ': ' . $image->get_error_message() . '.' );
			}

			// And return the local URL.
			return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		}
	}
}
