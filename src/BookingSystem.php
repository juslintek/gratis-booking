<?php declare(strict_types=1);

namespace Gratis\Booking;

defined('ABSPATH') || exit;

/**
 * Core booking engine: registers the service/appointment post types, the REST
 * slot/booking endpoints and the front-end booking shortcode.
 */
final class BookingSystem {

    public const SERVICE_TYPE     = 'gratis_service';
    public const APPOINTMENT_TYPE = 'gratis_appointment';

    /** Register post types, meta and the shortcode. */
    public static function register(): void {
        register_post_type(self::SERVICE_TYPE, [
            'labels'      => [
                'name'          => __('Services', 'gratis-booking'),
                'singular_name' => __('Service', 'gratis-booking'),
            ],
            'public'      => true,
            'show_in_rest' => true,
            'menu_icon'   => 'dashicons-calendar-alt',
            'supports'    => ['title', 'editor', 'thumbnail', 'custom-fields'],
        ]);

        register_post_type(self::APPOINTMENT_TYPE, [
            'labels'       => [
                'name'          => __('Appointments', 'gratis-booking'),
                'singular_name' => __('Appointment', 'gratis-booking'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'edit.php?post_type=' . self::SERVICE_TYPE,
            'supports'     => ['title'],
        ]);

        register_post_meta(self::SERVICE_TYPE, '_duration', ['type' => 'integer', 'single' => true, 'show_in_rest' => true, 'auth_callback' => [__CLASS__, 'canEdit']]);
        register_post_meta(self::SERVICE_TYPE, '_price', ['type' => 'number', 'single' => true, 'show_in_rest' => true, 'auth_callback' => [__CLASS__, 'canEdit']]);

        add_shortcode('gratis-booking', [__CLASS__, 'shortcode']);
        add_shortcode('gratis_booking', [__CLASS__, 'shortcode']);
    }

    /** Meta auth callback. */
    public static function canEdit(): bool {
        return current_user_can('edit_posts');
    }

    /** Register the REST slot + booking routes. */
    public static function registerAPI(): void {
        register_rest_route('gratis-booking/v1', '/slots/(?P<service_id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'restSlots'],
        ]);

        register_rest_route('gratis-booking/v1', '/book', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'restBook'],
        ]);
    }

    /** GET available slots for a service on a date. */
    public static function restSlots(\WP_REST_Request $req): \WP_REST_Response {
        $service_id = (int) $req['service_id'];
        $date       = sanitize_text_field((string) ($req->get_param('date') ?: gmdate('Y-m-d')));
        $duration   = (int) get_post_meta($service_id, '_duration', true) ?: Settings::slotInterval();
        $slots      = self::getAvailableSlots($service_id, $date, $duration);

        return new \WP_REST_Response($slots);
    }

    /** POST a new booking. */
    public static function restBook(\WP_REST_Request $req): \WP_REST_Response {
        $rate_key = 'gratis_booking_rl_' . self::visitorHash();
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 5) {
            return new \WP_REST_Response(['error' => __('Too many booking requests. Please try again later.', 'gratis-booking')], 429);
        }

        $data = (array) $req->get_json_params();

        $service_id = absint($data['service_id'] ?? 0);
        $name       = trim(sanitize_text_field((string) ($data['name'] ?? 'Guest')));
        $email      = sanitize_email((string) ($data['email'] ?? ''));
        $date       = sanitize_text_field((string) ($data['date'] ?? ''));
        $time       = sanitize_text_field((string) ($data['time'] ?? ''));

        if ($service_id <= 0 || self::SERVICE_TYPE !== get_post_type($service_id) || 'publish' !== get_post_status($service_id)) {
            return new \WP_REST_Response(['error' => __('A valid service is required.', 'gratis-booking')], 400);
        }
        if ('' === $email || !is_email($email)) {
            return new \WP_REST_Response(['error' => __('A valid email is required.', 'gratis-booking')], 400);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new \WP_REST_Response(['error' => __('A valid booking date (YYYY-MM-DD) is required.', 'gratis-booking')], 400);
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year) || $date < gmdate('Y-m-d')) {
            return new \WP_REST_Response(['error' => __('Booking date must be today or in the future.', 'gratis-booking')], 400);
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            return new \WP_REST_Response(['error' => __('A valid booking time (HH:MM) is required.', 'gratis-booking')], 400);
        }

        $duration  = (int) get_post_meta($service_id, '_duration', true) ?: Settings::slotInterval();
        $available = self::getAvailableSlots($service_id, $date, $duration);
        if (!in_array($time, $available, true)) {
            return new \WP_REST_Response(['error' => __('Selected time slot is no longer available.', 'gratis-booking')], 409);
        }

        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

        $sanitized = [
            'service_id' => $service_id,
            'name'       => '' !== $name ? $name : 'Guest',
            'email'      => $email,
            'date'       => $date,
            'time'       => $time,
        ];

        $id = wp_insert_post([
            'post_type'   => self::APPOINTMENT_TYPE,
            'post_status' => 'publish',
            'post_title'  => $sanitized['name'] . ' - ' . $date . ' ' . $time,
            'meta_input'  => [
                '_service_id' => $service_id,
                '_date'       => $date,
                '_time'       => $time,
                '_name'       => $sanitized['name'],
                '_email'      => $email,
                '_status'     => 'confirmed',
            ],
        ]);

        if (is_wp_error($id)) {
            return new \WP_REST_Response(['error' => $id->get_error_message()], 500);
        }

        self::notify($email, $sanitized['name'], $date, $time);
        do_action('gratis_booking_created', $id, $sanitized);

        return new \WP_REST_Response(['success' => true, 'id' => $id]);
    }

    /** Daily-rotating salted hash of the visitor IP for rate limiting without storing raw IPs. */
    private static function visitorHash(): string {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';
        $ip = (string) filter_var($ip, FILTER_VALIDATE_IP);
        return substr(hash('sha256', wp_salt('nonce') . '|' . gmdate('Y-m-d') . '|' . $ip), 0, 16);
    }

    /** Send confirmation emails to the customer and the site admin. */
    private static function notify(string $email, string $name, string $date, string $time): void {
        $subject = __('Booking Confirmed', 'gratis-booking');
        $body    = sprintf(
            /* translators: 1: date, 2: time. */
            __('Your appointment on %1$s at %2$s is confirmed.', 'gratis-booking'),
            $date,
            $time
        );
        wp_mail($email, $subject, $body);

        $admin = Settings::notificationEmail();
        if ('' !== $admin) {
            wp_mail(
                $admin,
                __('New booking received', 'gratis-booking'),
                sprintf(
                    /* translators: 1: customer name, 2: date, 3: time. */
                    __('%1$s booked an appointment on %2$s at %3$s.', 'gratis-booking'),
                    $name,
                    $date,
                    $time
                )
            );
        }
    }

    /** Front-end booking widget shortcode. */
    public static function shortcode($atts): string {
        $atts       = shortcode_atts(['service' => 0], $atts, 'gratis-booking');
        $service_id = absint($atts['service']);

        // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded service list for selector.
        $services = get_posts([
            'post_type'      => self::SERVICE_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        if (empty($services)) {
            return '<div class="gratis-booking-widget"><p>' . esc_html__('No services are available for booking yet.', 'gratis-booking') . '</p></div>';
        }

        wp_enqueue_script(
            'gratis-booking-widget',
            GRATIS_BOOKING_URL . 'assets/booking.js',
            [],
            GRATIS_BOOKING_VERSION,
            true
        );

        $default_date = gmdate('Y-m-d', strtotime('+1 day'));
        $rest_url     = rest_url('gratis-booking/v1');
        $uid          = wp_unique_id('gb-');

        $options = '';
        foreach ($services as $idx => $svc) {
            $dur   = (int) get_post_meta($svc->ID, '_duration', true) ?: Settings::slotInterval();
            $price = get_post_meta($svc->ID, '_price', true);
            $label = $svc->post_title . ' (' . $dur . ' min' . ('' !== (string) $price ? ' · $' . $price : '') . ')';
            $sel   = ($service_id > 0 ? $svc->ID === $service_id : 0 === $idx) ? ' selected' : '';
            $options .= '<option value="' . absint($svc->ID) . '"' . $sel . '>' . esc_html($label) . '</option>';
        }

        return '<div class="gratis-booking-widget" data-service="' . absint($service_id) . '" data-rest-url="' . esc_url($rest_url) . '">'
            . '<form class="gratis-booking-form">'
            . '<div class="gratis-booking-row">'
            . '<div class="gratis-booking-field">'
            . '<label for="' . esc_attr($uid . '-svc') . '">' . esc_html__('Service', 'gratis-booking') . '</label>'
            . '<select id="' . esc_attr($uid . '-svc') . '" class="gratis-booking-service" name="service_id">' . $options . '</select>'
            . '</div>'
            . '<div class="gratis-booking-field">'
            . '<label for="' . esc_attr($uid . '-date') . '">' . esc_html__('Date', 'gratis-booking') . '</label>'
            . '<input type="date" id="' . esc_attr($uid . '-date') . '" class="gratis-booking-date" name="date" min="' . esc_attr(gmdate('Y-m-d')) . '" value="' . esc_attr($default_date) . '" required>'
            . '</div>'
            . '</div>'
            . '<div class="gratis-booking-field">'
            . '<span class="gratis-booking-label">' . esc_html__('Available Time Slots', 'gratis-booking') . '</span>'
            . '<div class="gratis-booking-slots" role="group" aria-label="' . esc_attr__('Available Time Slots', 'gratis-booking') . '"></div>'
            . '<input type="hidden" class="gratis-booking-time" name="time" value="">'
            . '</div>'
            . '<div class="gratis-booking-row">'
            . '<div class="gratis-booking-field">'
            . '<label for="' . esc_attr($uid . '-name') . '">' . esc_html__('Your Name', 'gratis-booking') . '</label>'
            . '<input type="text" id="' . esc_attr($uid . '-name') . '" name="name" placeholder="' . esc_attr__('Jane Doe', 'gratis-booking') . '" required>'
            . '</div>'
            . '<div class="gratis-booking-field">'
            . '<label for="' . esc_attr($uid . '-email') . '">' . esc_html__('Email Address', 'gratis-booking') . '</label>'
            . '<input type="email" id="' . esc_attr($uid . '-email') . '" name="email" placeholder="' . esc_attr__('jane@example.com', 'gratis-booking') . '" required>'
            . '</div>'
            . '</div>'
            . '<button type="submit" class="gratis-booking-submit">' . esc_html__('Confirm Appointment', 'gratis-booking') . '</button>'
            . '<p class="gratis-booking-status" aria-live="polite"></p>'
            . '</form>'
            . '</div>';
    }

    /**
     * Compute open time slots for a service on a date, honouring business hours
     * configured in settings and already-booked appointments.
     *
     * @return string[]
     */
    private static function getAvailableSlots(int $serviceId, string $date, int $duration): array {
        $duration = max(5, $duration > 0 ? $duration : 60);

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin/booking lookup.
        $booked = get_posts([
            'post_type'      => self::APPOINTMENT_TYPE,
            // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded booking lookup.
            'posts_per_page' => 200,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded slot lookup for one service and date.
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_service_id', 'value' => $serviceId],
                ['key' => '_date', 'value' => $date],
            ],
        ]);

        $bookedTimes = array_map(
            static fn($b) => (string) get_post_meta($b->ID, '_time', true),
            $booked
        );

        [$startHour, $endHour] = Settings::businessHours();
        $startMinutes          = $startHour * 60;
        $endMinutes            = $endHour * 60;

        $slots = [];
        for ($minutes = $startMinutes; $minutes + $duration <= $endMinutes; $minutes += $duration) {
            $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            if (!in_array($time, $bookedTimes, true)) {
                $slots[] = $time;
            }
        }

        return $slots;
    }
}
