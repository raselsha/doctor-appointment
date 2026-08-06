<?php
/**
 * @author Shahadat Hossain <raselsha@gmail.com>
 */
namespace MDBK;

defined('ABSPATH') || exit;

/**
 * Talks to the lieusoft-licensing REST API (on lieusoft.com) to activate,
 * check, and deactivate this install's license key.
 *
 * These clinics run mostly-offline (local server, unreliable internet), so
 * enforcement is deliberately soft: an unreachable/expired/revoked license
 * never disables a feature — it only shows an admin notice. A stale
 * connection (GRACE_PERIOD_DAYS) is treated differently from a store-
 * confirmed invalid/expired status, since "can't verify" must never be
 * read as "confirmed invalid" on a site that may go weeks without a
 * working internet connection.
 */
class MDBK_Licensing {

    const STORE_URL         = 'https://lieusoft.com';
    const CRON_HOOK          = 'mdbk_license_weekly_check';
    const GRACE_PERIOD_DAYS  = 14;

    public function __construct() {
        add_filter('cron_schedules', [$this, 'register_weekly_schedule']);
        add_action('init', [$this, 'maybe_schedule_check']);
        add_action(self::CRON_HOOK, [$this, 'check']);
        add_action('wp_ajax_mdbk_license_activate', [$this, 'ajax_activate']);
        add_action('wp_ajax_mdbk_license_deactivate', [$this, 'ajax_deactivate']);
        add_action('wp_ajax_mdbk_license_refresh', [$this, 'ajax_refresh']);
        add_action('admin_notices', [$this, 'render_notice']);
    }

    public function register_weekly_schedule($schedules) {
        $schedules['mdbk_weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => __('Once Weekly', 'doctor-appointment'),
        ];
        return $schedules;
    }

    public function maybe_schedule_check() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'mdbk_weekly', self::CRON_HOOK);
        }
    }

    public static function clear_scheduled_check() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function get_key() {
        return get_option('mdbk_license_key', '');
    }

    public static function get_status() {
        return get_option('mdbk_license_status', 'unchecked');
    }

    public static function get_expires() {
        return get_option('mdbk_license_expires', '');
    }

    public static function get_last_check() {
        return (int) get_option('mdbk_license_last_check', 0);
    }

    private function request($endpoint, $key) {
        $response = wp_remote_post(self::STORE_URL . '/wp-json/lsl/v1/' . $endpoint, [
            'timeout' => 15,
            'body'    => [
                'license'  => $key,
                'site_url' => home_url('/'),
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code || !is_array($body)) {
            return new \WP_Error('mdbk_license_bad_response', __('Unexpected response from the license server.', 'doctor-appointment'));
        }

        return $body;
    }

    private function status_message($status) {
        $messages = [
            'invalid'       => __('This license key is not valid.', 'doctor-appointment'),
            'revoked'       => __('This license key has been revoked.', 'doctor-appointment'),
            'limit_reached' => __('This license key is already active on the maximum number of sites.', 'doctor-appointment'),
            'expired'       => __('This license key has expired.', 'doctor-appointment'),
        ];
        return isset($messages[$status]) ? $messages[$status] : __('Could not activate this license key.', 'doctor-appointment');
    }

    public function activate($key) {
        $key = sanitize_text_field($key);
        if ('' === $key) {
            return new \WP_Error('mdbk_license_empty', __('Please enter a license key.', 'doctor-appointment'));
        }

        $result = $this->request('activate', $key);
        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['success'])) {
            $status = isset($result['status']) ? $result['status'] : 'invalid';
            return new \WP_Error('mdbk_license_activate_failed', $this->status_message($status));
        }

        update_option('mdbk_license_key', $key);
        update_option('mdbk_license_status', $result['status']);
        update_option('mdbk_license_expires', isset($result['expires']) ? $result['expires'] : '');
        update_option('mdbk_license_last_check', time());

        return true;
    }

    public function deactivate() {
        $key = self::get_key();
        if ('' !== $key) {
            // Best-effort — local state is cleared either way, so a site
            // going through this while offline still ends up "not
            // licensed" locally instead of stuck.
            $this->request('deactivate', $key);
        }
        delete_option('mdbk_license_key');
        delete_option('mdbk_license_status');
        delete_option('mdbk_license_expires');
        delete_option('mdbk_license_last_check');
        return true;
    }

    /**
     * Re-verifies the stored key. Called weekly by cron and by the admin's
     * manual "Refresh" button. A failed/unreachable request deliberately
     * leaves the last-known status untouched — only mdbk_license_last_check
     * stays stale, which is what the grace-period notice keys off.
     */
    public function check() {
        $key = self::get_key();
        if ('' === $key) {
            return;
        }

        $result = $this->request('check', $key);
        if (is_wp_error($result) || empty($result['success'])) {
            return;
        }

        update_option('mdbk_license_status', $result['status']);
        update_option('mdbk_license_expires', isset($result['expires']) ? $result['expires'] : '');
        update_option('mdbk_license_last_check', time());
    }

    public function render_notice() {
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            return;
        }

        $key = self::get_key();
        if ('' === $key) {
            return;
        }

        $status     = self::get_status();
        $last_check = self::get_last_check();
        $stale_days = $last_check ? floor((time() - $last_check) / DAY_IN_SECONDS) : null;

        $message = '';
        if (in_array($status, ['expired', 'revoked', 'invalid', 'limit_reached'], true)) {
            $message = sprintf(
                /* translators: %s: license status message */
                __('Doctor Appointment Booking license: %s Please renew to keep receiving updates and support.', 'doctor-appointment'),
                $this->status_message($status)
            );
        } elseif (null !== $stale_days && $stale_days > self::GRACE_PERIOD_DAYS) {
            $message = __("Doctor Appointment Booking: couldn't verify your license — this site hasn't reached the license server in a while. Please connect it to the internet briefly so it can check in.", 'doctor-appointment');
        }

        if ('' === $message) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    public function ajax_activate() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'doctor-appointment')]);
        }

        $key    = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = $this->activate($key);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'status'  => self::get_status(),
            'expires' => self::get_expires(),
        ]);
    }

    public function ajax_deactivate() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'doctor-appointment')]);
        }

        $this->deactivate();
        wp_send_json_success();
    }

    public function ajax_refresh() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'doctor-appointment')]);
        }

        $this->check();
        wp_send_json_success([
            'status'  => self::get_status(),
            'expires' => self::get_expires(),
        ]);
    }
}

new MDBK_Licensing();
