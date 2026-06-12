<?php declare(strict_types=1);

namespace Gratis\Booking\CLI;

defined('ABSPATH') || exit;

use Gratis\Booking\BookingSystem;

/**
 * WP-CLI commands for Gratis Booking.
 */
final class BookingCommand {

    /**
     * List recent appointments.
     *
     * @when after_wp_load
     */
    public function appointments($args, $assoc_args): void {
        unset($args, $assoc_args);

        // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded CLI listing.
        $appts = get_posts(['post_type' => BookingSystem::APPOINTMENT_TYPE, 'posts_per_page' => 50]);
        if (empty($appts)) {
            \WP_CLI::log('No appointments.');
            return;
        }
        $rows = array_map(static fn($a) => [
            'ID'     => $a->ID,
            'Date'   => get_post_meta($a->ID, '_date', true),
            'Time'   => get_post_meta($a->ID, '_time', true),
            'Name'   => get_post_meta($a->ID, '_name', true),
            'Status' => get_post_meta($a->ID, '_status', true),
        ], $appts);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Date', 'Time', 'Name', 'Status']);
    }

    /**
     * List bookable services.
     *
     * @when after_wp_load
     */
    public function services($args, $assoc_args): void {
        unset($args, $assoc_args);

        // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded CLI listing.
        $services = get_posts(['post_type' => BookingSystem::SERVICE_TYPE, 'posts_per_page' => 200]);
        if (empty($services)) {
            \WP_CLI::log('No services.');
            return;
        }
        $rows = array_map(static fn($s) => [
            'ID'       => $s->ID,
            'Title'    => $s->post_title,
            'Duration' => get_post_meta($s->ID, '_duration', true) . 'min',
            'Price'    => '$' . get_post_meta($s->ID, '_price', true),
        ], $services);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Title', 'Duration', 'Price']);
    }
}
