<?php declare(strict_types=1);
namespace Gratis\Booking\CLI;

final class BookingCommand
{
    /** @when after_wp_load */
    public function appointments($args, $assoc_args): void
    {
        $appts = get_posts(['post_type' => 'gratis_appointment', 'numberposts' => 50]);
        if (empty($appts)) { \WP_CLI::log("No appointments."); return; }
        $rows = array_map(fn($a) => [
            'ID' => $a->ID, 'Date' => get_post_meta($a->ID, '_date', true),
            'Time' => get_post_meta($a->ID, '_time', true),
            'Name' => get_post_meta($a->ID, '_name', true),
            'Status' => get_post_meta($a->ID, '_status', true),
        ], $appts);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Date', 'Time', 'Name', 'Status']);
    }

    /** @when after_wp_load */
    public function services($args, $assoc_args): void
    {
        $services = get_posts(['post_type' => 'gratis_service', 'numberposts' => -1]);
        if (empty($services)) { \WP_CLI::log("No services."); return; }
        $rows = array_map(fn($s) => [
            'ID' => $s->ID, 'Title' => $s->post_title,
            'Duration' => get_post_meta($s->ID, '_duration', true) . 'min',
            'Price' => '$' . get_post_meta($s->ID, '_price', true),
        ], $services);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Title', 'Duration', 'Price']);
    }
}
