<?php declare(strict_types=1);
namespace Gratis\Booking;

final class BookingSystem
{
    public static function register(): void
    {
        register_post_type('gratis_service', [
            'labels' => ['name' => 'Services'], 'public' => true, 'show_in_rest' => true,
            'menu_icon' => 'dashicons-calendar-alt', 'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        ]);
        register_post_type('gratis_appointment', [
            'labels' => ['name' => 'Appointments'], 'public' => false, 'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=gratis_service',
        ]);
        register_post_meta('gratis_service', '_duration', ['type' => 'integer', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_service', '_price', ['type' => 'number', 'single' => true, 'show_in_rest' => true]);
        register_post_meta('gratis_service', '_availability', ['type' => 'string', 'single' => true, 'show_in_rest' => true]);

        add_shortcode('gratis-booking', [__CLASS__, 'shortcode']);
    }

    public static function registerAPI(): void
    {
        register_rest_route('gratis-booking/v1', '/slots/(?P<service_id>\d+)', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => function ($req) {
                $service_id = (int) $req['service_id'];
                $date = $req->get_param('date') ?: date('Y-m-d');
                $duration = (int) get_post_meta($service_id, '_duration', true) ?: 60;
                $slots = self::getAvailableSlots($service_id, $date, $duration);
                return new \WP_REST_Response($slots);
            },
        ]);

        register_rest_route('gratis-booking/v1', '/book', [
            'methods' => 'POST', 'permission_callback' => '__return_true',
            'callback' => function ($req) {
                $data = $req->get_json_params();
                $id = wp_insert_post([
                    'post_type' => 'gratis_appointment', 'post_status' => 'publish',
                    'post_title' => ($data['name'] ?? 'Guest') . ' - ' . ($data['date'] ?? ''),
                    'meta_input' => [
                        '_service_id' => (int) ($data['service_id'] ?? 0),
                        '_date' => sanitize_text_field($data['date'] ?? ''),
                        '_time' => sanitize_text_field($data['time'] ?? ''),
                        '_name' => sanitize_text_field($data['name'] ?? ''),
                        '_email' => sanitize_email($data['email'] ?? ''),
                        '_status' => 'confirmed',
                    ],
                ]);
                wp_mail($data['email'] ?? '', 'Booking Confirmed', "Your appointment on {$data['date']} at {$data['time']} is confirmed.");
                return new \WP_REST_Response(['success' => true, 'id' => $id]);
            },
        ]);
    }

    public static function shortcode($atts): string
    {
        $atts = shortcode_atts(['service' => 0], $atts);
        return '<div class="gratis-booking-widget" data-service="' . (int) $atts['service'] . '"><p>Loading booking calendar...</p></div>';
    }

    private static function getAvailableSlots(int $serviceId, string $date, int $duration): array
    {
        $booked = get_posts(['post_type' => 'gratis_appointment', 'numberposts' => -1,
            'meta_query' => [['key' => '_service_id', 'value' => $serviceId], ['key' => '_date', 'value' => $date]]]);
        $bookedTimes = array_map(fn($b) => get_post_meta($b->ID, '_time', true), $booked);

        $slots = [];
        for ($h = 9; $h < 17; $h++) {
            for ($m = 0; $m < 60; $m += $duration) {
                $time = sprintf('%02d:%02d', $h, $m);
                if (!in_array($time, $bookedTimes)) $slots[] = $time;
            }
        }
        return $slots;
    }
}
