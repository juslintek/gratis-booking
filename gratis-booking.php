<?php
/**
 * Plugin Name: Gratis Booking
 * Plugin URI: https://github.com/juslintek/gratis-booking
 * Description: Appointment scheduling + calendar.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-booking
 *
 * @package Gratis\Booking
 */

defined('ABSPATH') || exit;

define('GRATIS_BOOKING_VERSION', '1.0.0');
define('GRATIS_BOOKING_FILE', __FILE__);
define('GRATIS_BOOKING_DIR', plugin_dir_path(__FILE__));
define('GRATIS_BOOKING_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/autoload.php';

add_action('init', [\Gratis\Booking\BookingSystem::class, 'register']);
add_action('rest_api_init', [\Gratis\Booking\BookingSystem::class, 'registerAPI']);

add_action('add_meta_boxes', [\Gratis\Booking\MetaBox::class, 'register']);
add_action('save_post_gratis_service', [\Gratis\Booking\MetaBox::class, 'saveService'], 10, 2);
add_action('save_post_gratis_appointment', [\Gratis\Booking\MetaBox::class, 'saveAppointment'], 10, 2);

add_action('admin_menu', [\Gratis\Booking\Settings::class, 'register_menu']);
add_action('admin_init', [\Gratis\Booking\Settings::class, 'register_settings']);

add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-booking', \Gratis\Booking\CLI\BookingCommand::class);
    }
});
