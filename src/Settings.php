<?php declare(strict_types=1);

namespace Gratis\Booking;

defined('ABSPATH') || exit;

/**
 * Global settings for Gratis Booking: business hours, default slot interval and
 * the admin notification email.
 */
final class Settings {

    private const GROUP = 'gratis_booking_settings_group';
    private const PAGE  = 'gratis-booking-settings';

    private const OPT_START    = 'gratis_booking_start_hour';
    private const OPT_END      = 'gratis_booking_end_hour';
    private const OPT_INTERVAL = 'gratis_booking_slot_interval';
    private const OPT_EMAIL    = 'gratis_booking_notify_email';

    /** Register the settings submenu under the Services menu. */
    public static function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . BookingSystem::SERVICE_TYPE,
            __('Booking Settings', 'gratis-booking'),
            __('Settings', 'gratis-booking'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    /** Register options with typed sanitisers. */
    public static function register_settings(): void {
        register_setting(self::GROUP, self::OPT_START, [
            'type'              => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_hour'],
            'default'           => 9,
        ]);
        register_setting(self::GROUP, self::OPT_END, [
            'type'              => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_hour'],
            'default'           => 17,
        ]);
        register_setting(self::GROUP, self::OPT_INTERVAL, [
            'type'              => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_interval'],
            'default'           => 60,
        ]);
        register_setting(self::GROUP, self::OPT_EMAIL, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => get_option('admin_email'),
        ]);
    }

    /** Clamp an hour to 0–23. */
    public static function sanitize_hour($value): int {
        return max(0, min(23, absint($value)));
    }

    /** Clamp the slot interval to a sane 5–480 minute range. */
    public static function sanitize_interval($value): int {
        $value = absint($value);
        return max(5, min(480, $value ?: 60));
    }

    /**
     * Configured business hours as [start, end].
     *
     * @return array{0:int,1:int}
     */
    public static function businessHours(): array {
        $start = self::sanitize_hour(get_option(self::OPT_START, 9));
        $end   = self::sanitize_hour(get_option(self::OPT_END, 17));
        if ($end <= $start) {
            $end = min(23, $start + 1);
        }
        return [$start, $end];
    }

    /** Default slot interval in minutes. */
    public static function slotInterval(): int {
        return self::sanitize_interval(get_option(self::OPT_INTERVAL, 60));
    }

    /** Admin notification email (empty disables admin notifications). */
    public static function notificationEmail(): string {
        return (string) get_option(self::OPT_EMAIL, get_option('admin_email'));
    }

    /** Render the settings screen. */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gratis-booking'));
        }

        [$start, $end] = self::businessHours();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Booking Settings', 'gratis-booking') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gb_start">' . esc_html__('Business start hour', 'gratis-booking') . '</label></th><td>';
        echo '<input type="number" min="0" max="23" id="gb_start" class="small-text" name="' . esc_attr(self::OPT_START) . '" value="' . esc_attr((string) $start) . '">';
        echo '<p class="description">' . esc_html__('First bookable hour (24h clock).', 'gratis-booking') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gb_end">' . esc_html__('Business end hour', 'gratis-booking') . '</label></th><td>';
        echo '<input type="number" min="1" max="23" id="gb_end" class="small-text" name="' . esc_attr(self::OPT_END) . '" value="' . esc_attr((string) $end) . '">';
        echo '<p class="description">' . esc_html__('Last bookable hour (24h clock).', 'gratis-booking') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gb_interval">' . esc_html__('Default slot interval (minutes)', 'gratis-booking') . '</label></th><td>';
        echo '<input type="number" min="5" max="480" id="gb_interval" class="small-text" name="' . esc_attr(self::OPT_INTERVAL) . '" value="' . esc_attr((string) self::slotInterval()) . '">';
        echo '<p class="description">' . esc_html__('Used when a service has no explicit duration.', 'gratis-booking') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gb_email">' . esc_html__('Notification email', 'gratis-booking') . '</label></th><td>';
        echo '<input type="email" id="gb_email" class="regular-text" name="' . esc_attr(self::OPT_EMAIL) . '" value="' . esc_attr(self::notificationEmail()) . '">';
        echo '<p class="description">' . esc_html__('Where new-booking notifications are sent. Leave blank to disable.', 'gratis-booking') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save settings', 'gratis-booking'));
        echo '</form>';
        echo '</div>';
    }
}
