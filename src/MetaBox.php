<?php declare(strict_types=1);

namespace Gratis\Booking;

defined('ABSPATH') || exit;

/**
 * Meta boxes for services (duration/price) and appointments (booking details).
 */
final class MetaBox {

    private const NONCE_ACTION = 'gratis_booking_save_meta';
    private const NONCE_FIELD  = 'gratis_booking_meta_nonce';

    /** Register both meta boxes. */
    public static function register(): void {
        add_meta_box(
            'gratis-booking-service',
            __('Service Details', 'gratis-booking'),
            [__CLASS__, 'renderService'],
            BookingSystem::SERVICE_TYPE,
            'side',
            'high'
        );
        add_meta_box(
            'gratis-booking-appointment',
            __('Appointment Details', 'gratis-booking'),
            [__CLASS__, 'renderAppointment'],
            BookingSystem::APPOINTMENT_TYPE,
            'normal',
            'high'
        );
    }

    /** Render the service meta box. */
    public static function renderService(\WP_Post $post): void {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $duration = (int) get_post_meta($post->ID, '_duration', true);
        $price    = (string) get_post_meta($post->ID, '_price', true);

        echo '<p><label for="gb_duration"><strong>' . esc_html__('Duration (minutes)', 'gratis-booking') . '</strong></label><br>';
        echo '<input type="number" min="5" id="gb_duration" name="gb_duration" class="widefat" value="' . esc_attr((string) ($duration ?: 60)) . '"></p>';

        echo '<p><label for="gb_price"><strong>' . esc_html__('Price', 'gratis-booking') . '</strong></label><br>';
        echo '<input type="number" min="0" step="0.01" id="gb_price" name="gb_price" class="widefat" value="' . esc_attr($price) . '"></p>';
    }

    /** Render the appointment meta box. */
    public static function renderAppointment(\WP_Post $post): void {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $service = (int) get_post_meta($post->ID, '_service_id', true);
        $date    = (string) get_post_meta($post->ID, '_date', true);
        $time    = (string) get_post_meta($post->ID, '_time', true);
        $name    = (string) get_post_meta($post->ID, '_name', true);
        $email   = (string) get_post_meta($post->ID, '_email', true);
        $status  = (string) get_post_meta($post->ID, '_status', true) ?: 'confirmed';

        $statuses = [
            'pending'   => __('Pending', 'gratis-booking'),
            'confirmed' => __('Confirmed', 'gratis-booking'),
            'cancelled' => __('Cancelled', 'gratis-booking'),
        ];

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gb_service_id">' . esc_html__('Service', 'gratis-booking') . '</label></th><td>';
        echo '<select id="gb_service_id" name="gb_service_id">';
        echo '<option value="0">' . esc_html__('— none —', 'gratis-booking') . '</option>';
        // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded service list for a select.
        foreach (get_posts(['post_type' => BookingSystem::SERVICE_TYPE, 'posts_per_page' => 200]) as $svc) {
            echo '<option value="' . absint($svc->ID) . '"' . selected($service, $svc->ID, false) . '>' . esc_html($svc->post_title) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="gb_date">' . esc_html__('Date', 'gratis-booking') . '</label></th><td>';
        echo '<input type="date" id="gb_date" name="gb_date" value="' . esc_attr($date) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="gb_time">' . esc_html__('Time', 'gratis-booking') . '</label></th><td>';
        echo '<input type="time" id="gb_time" name="gb_time" value="' . esc_attr($time) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="gb_name">' . esc_html__('Customer name', 'gratis-booking') . '</label></th><td>';
        echo '<input type="text" id="gb_name" class="regular-text" name="gb_name" value="' . esc_attr($name) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="gb_email">' . esc_html__('Customer email', 'gratis-booking') . '</label></th><td>';
        echo '<input type="email" id="gb_email" class="regular-text" name="gb_email" value="' . esc_attr($email) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="gb_status">' . esc_html__('Status', 'gratis-booking') . '</label></th><td>';
        echo '<select id="gb_status" name="gb_status">';
        foreach ($statuses as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '</tbody></table>';
    }

    /** Verify nonce + capability for a save. */
    private static function guard(int $post_id): bool {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }
        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return false;
        }
        return current_user_can('edit_post', $post_id);
    }

    /** Persist service meta. */
    public static function saveService(int $post_id): void {
        if (!self::guard($post_id)) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
        $duration = isset($_POST['gb_duration']) ? absint(wp_unslash($_POST['gb_duration'])) : 60;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float cast sanitises the numeric input.
        $price = isset($_POST['gb_price']) ? (float) wp_unslash($_POST['gb_price']) : 0.0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_post_meta($post_id, '_duration', $duration);
        update_post_meta($post_id, '_price', $price);
    }

    /** Persist appointment meta. */
    public static function saveAppointment(int $post_id): void {
        if (!self::guard($post_id)) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
        update_post_meta($post_id, '_service_id', isset($_POST['gb_service_id']) ? absint(wp_unslash($_POST['gb_service_id'])) : 0);
        update_post_meta($post_id, '_date', isset($_POST['gb_date']) ? sanitize_text_field(wp_unslash($_POST['gb_date'])) : '');
        update_post_meta($post_id, '_time', isset($_POST['gb_time']) ? sanitize_text_field(wp_unslash($_POST['gb_time'])) : '');
        update_post_meta($post_id, '_name', isset($_POST['gb_name']) ? sanitize_text_field(wp_unslash($_POST['gb_name'])) : '');
        update_post_meta($post_id, '_email', isset($_POST['gb_email']) ? sanitize_email(wp_unslash($_POST['gb_email'])) : '');

        $status = isset($_POST['gb_status']) ? sanitize_key(wp_unslash($_POST['gb_status'])) : 'confirmed';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $status = in_array($status, ['pending', 'confirmed', 'cancelled'], true) ? $status : 'confirmed';
        update_post_meta($post_id, '_status', $status);
    }
}
