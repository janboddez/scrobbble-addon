<?php
/**
 * Plugin Name: Scrobbble Add-On
 * Description: Bundles a number, some experimental, of Scrobbble "improvements."
 * Version:     0.1.0
 * Author:      Jan Boddez
 * Author URI:  https://jan.boddez.net/
 * License:     GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: scrobbble
 *
 * @author  Jan Boddez <jan@boddez.net>
 * @package Scrobbble
 */

namespace Scrobbble\AddOn;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/includes/class-blocks.php';
require __DIR__ . '/includes/class-plugin.php';

Plugin::get_instance()
	->register();
