<?php
/**
 * Helper functions.
 *
 * @package Scrobbble\AddOn
 */

namespace Scrobbble\AddOn;

/**
 * Emulate PHP's `glob` function.
 *
 * @see http://www.delorie.com/djgpp/doc/libc/libc_426.html
 * @see https://github.com/aws/aws-sdk-php/issues/556#issuecomment-96336522
 *
 * @author Ahmad Priatama <ahmad.priatam@gmail.com>
 *
 * @param  string $pattern Pattern to match against.
 * @return array           Array of filenames.
 */
function glob( $pattern ) {
	$return = array();

	$pattern_found = preg_match( '(\*|\?|\[.+\])', $pattern, $parent_pattern, PREG_OFFSET_CAPTURE );

	if ( $pattern_found ) {
		$parent        = dirname( substr( $pattern, 0, $parent_pattern[0][1] + 1 ) );
		$parent_length = strlen( $parent );
		$leftover      = substr( $pattern, $parent_pattern[0][1] );

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found,Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
		if ( false !== ( $index = strpos( $leftover, '/' ) ) ) {
			$search_pattern = substr( $pattern, $parent_length + 1, $parent_pattern[0][1] - $parent_length + $index - 1 );
		} else {
			$search_pattern = substr( $pattern, $parent_length + 1 );
		}

		$replacement = array(
			'/\*/' => '.*',
			'/\?/' => '.',
		);

		$search_pattern = preg_replace( array_keys( $replacement ), array_values( $replacement ), $search_pattern );

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found,Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
		if ( is_dir( $parent . '/' ) && ( $dh = opendir( $parent . '/' ) ) ) {
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			while ( $dir = readdir( $dh ) ) {
				if ( in_array( $dir, array( '.', '..' ), true ) ) {
					continue;
				}

				if ( preg_match( "/^$search_pattern$/", $dir ) ) {
					if ( false === $index || strlen( $leftover ) === $index + 1 ) {
						$return[] = "$parent/$dir";
					} elseif ( strlen( $leftover ) > $index + 1 ) {
						$return = array_merge( $return, glob( "$parent/$dir" . substr( $leftover, $index ) ) );
					}
				}
			}
		}
	} elseif ( file_exists( $pattern ) ) {
		$return[] = $pattern;
	}

	return $return;
}
