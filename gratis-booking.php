<?php
/**
 * Plugin Name: Gratis Booking
 * Description: Appointment scheduling + calendar. Replaces Amelia ($59/yr), Bookly ($89).
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * License: GPL-2.0-or-later
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/autoload.php';
add_action('init', [\Gratis\Booking\BookingSystem::class, 'register']);
add_action('rest_api_init', [\Gratis\Booking\BookingSystem::class, 'registerAPI']);
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) \WP_CLI::add_command('gratis-booking', \Gratis\Booking\CLI\BookingCommand::class);
});
