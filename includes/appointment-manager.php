<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Appointment_Manager {

    /**
     * Map between the plugin's user-facing status vocabulary and the
     * registered post_status slugs (see MDBK_CPT::register_appointment_statuses).
     */
    const STATUS_SLUG_TO_POST_STATUS = [
        'waiting'   => 'mdbk_waiting',
        'serving'   => 'mdbk_serving',
        'completed' => 'mdbk_completed',
        'no-show'   => 'mdbk_no_show',
    ];

    /**
     * Bangladeshi mobile number: 11 digits starting 01[3-9], optionally
     * prefixed with 880 or +880. Enforced on the frontend booking form only
     * (see ajax_handle_submission()) — not on the shared handle_submission()
     * static, since the admin dashboard's new-booking flow also routes
     * through it and shouldn't be constrained to BD numbers.
     */
    const BD_MOBILE_REGEX = '/^(?:\+?880|0)1[3-9]\d{8}$/';

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_boxes']);
        add_filter('manage_mdbk_appointment_posts_columns', [$this, 'add_columns']);
        add_action('manage_mdbk_appointment_posts_custom_column', [$this, 'render_columns'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_mdbk_get_doctors_by_specialty', [$this, 'get_doctors_by_specialty']);
        add_action('wp_ajax_nopriv_mdbk_get_doctors_by_specialty', [$this, 'get_doctors_by_specialty']);
        add_action('wp_ajax_mdbk_get_doctor_info', [$this, 'ajax_get_doctor_info']);
        add_action('wp_ajax_nopriv_mdbk_get_doctor_info', [$this, 'ajax_get_doctor_info']);
        add_action('wp_ajax_mdbk_get_doctor_schedule', [$this, 'get_doctor_schedule']);
        add_action('wp_ajax_nopriv_mdbk_get_doctor_schedule', [$this, 'get_doctor_schedule']);
        add_action('wp_ajax_mdbk_get_doctor_slots', [$this, 'ajax_get_doctor_slots']);
        add_action('wp_ajax_nopriv_mdbk_get_doctor_slots', [$this, 'ajax_get_doctor_slots']);
        add_action('wp_ajax_mdbk_submit_appointment', [$this, 'ajax_handle_submission']);
        add_action('wp_ajax_nopriv_mdbk_submit_appointment', [$this, 'ajax_handle_submission']);

        add_filter('mdbk_email_body', [$this, 'append_checkin_link_to_email'], 10, 4);
    }

    /**
     * Appends the patient's check-in link to the "waiting" confirmation
     * email only. Must NOT fire for the doctor's copy of the same email
     * (recipient_type check) — the link is a bearer token to the patient's
     * own booking.
     */
    public function append_checkin_link_to_email($body, $event, $appointment_id, $recipient_type) {
        if ($event !== 'waiting' || $recipient_type !== 'patient') {
            return $body;
        }

        $token = get_post_meta($appointment_id, '_mdbk_checkin_token', true);
        if (!$token) {
            return $body;
        }

        $ticket    = self::format_ticket_number(get_post_meta($appointment_id, '_mdbk_ticket_number', true));
        $date      = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        $slot_time = get_post_meta($appointment_id, '_mdbk_slot_time', true);
        $checkin_url = add_query_arg('mdbk_token', $token, home_url('/'));

        $body .= "\n\n" . __('Your check-in details:', 'doctor-appointment') . "\n";
        if ($ticket) {
            $body .= sprintf(__('Ticket: %s', 'doctor-appointment'), $ticket) . "\n";
        }
        if ($date) {
            $body .= sprintf(__('Date: %s', 'doctor-appointment'), date_i18n(get_option('date_format'), strtotime($date))) . "\n";
        }
        if ($slot_time) {
            $body .= sprintf(__('Time: %s', 'doctor-appointment'), date_i18n(get_option('time_format'), strtotime($slot_time))) . "\n";
        }
        $body .= "\n" . sprintf(__('View your booking and check in here: %s', 'doctor-appointment'), $checkin_url) . "\n";

        return $body;
    }

    /**
     * Convert a user-facing status slug (waiting/serving/completed/no-show)
     * to its registered post_status (mdbk_waiting/...). Unknown input falls
     * back to 'mdbk_waiting'.
     */
    public static function status_slug_to_post_status($slug) {
        return isset(self::STATUS_SLUG_TO_POST_STATUS[$slug]) ? self::STATUS_SLUG_TO_POST_STATUS[$slug] : 'mdbk_waiting';
    }

    /**
     * Convert a registered post_status back to the user-facing slug.
     */
    public static function post_status_to_slug($post_status) {
        $flipped = array_flip(self::STATUS_SLUG_TO_POST_STATUS);
        return isset($flipped[$post_status]) ? $flipped[$post_status] : 'waiting';
    }

    /**
     * Register Meta Boxes for Appointments
     */
    public function register_meta_boxes() {
        add_meta_box(
            'mdbk_appointment_details',
            __('Appointment Details', 'doctor-appointment'),
            [$this, 'render_appointment_meta_box'],
            'mdbk_appointment',
            'normal',
            'high'
        );
    }

    /**
     * Render Meta Box Content
     */
    public function render_appointment_meta_box($post) {
        wp_nonce_field('mdbk_save_appointment_meta', 'mdbk_appointment_nonce');

        $patient_name = get_post_meta($post->ID, '_mdbk_patient_name', true);
        $patient_age  = get_post_meta($post->ID, '_mdbk_patient_age', true);
        $patient_phone = get_post_meta($post->ID, '_mdbk_patient_phone', true);
        $patient_gender = get_post_meta($post->ID, '_mdbk_patient_gender', true);
        $current_status = get_post_status($post);
        $status         = in_array($current_status, \MDBK\MDBK_CPT::APPOINTMENT_STATUSES, true) ? self::post_status_to_slug($current_status) : 'waiting';
        $app_date      = get_post_meta($post->ID, '_mdbk_appointment_date', true);
        $slot_time     = get_post_meta($post->ID, '_mdbk_slot_time', true);
        $ticket_number = get_post_meta($post->ID, '_mdbk_ticket_number', true);
        $doctor_id     = get_post_meta($post->ID, '_mdbk_doctor_id', true);
        $symptoms      = get_post_meta($post->ID, '_mdbk_symptoms', true);

        ?>
        <div class="mdbk-meta-box-wrapper">
            <style>
                .mdbk-meta-field { margin-bottom: 15px; }
                .mdbk-meta-field label { display: block; font-weight: bold; margin-bottom: 5px; }
                .mdbk-meta-field input, .mdbk-meta-field select, .mdbk-meta-field textarea { width: 100%; }
            </style>
            
            <div class="mdbk-meta-field">
                <label><?php _e('Patient Name', 'doctor-appointment'); ?></label>
                <input type="text" name="mdbk_patient_name" value="<?php echo esc_attr($patient_name); ?>">
            </div>

            <div style="display: flex; gap: 20px;">
                <div class="mdbk-meta-field" style="flex: 1;">
                    <label><?php _e('Age', 'doctor-appointment'); ?></label>
                    <input type="number" name="mdbk_patient_age" value="<?php echo esc_attr($patient_age); ?>">
                </div>
                <div class="mdbk-meta-field" style="flex: 1;">
                    <label><?php _e('Phone Number', 'doctor-appointment'); ?></label>
                    <input type="text" name="mdbk_patient_phone" value="<?php echo esc_attr($patient_phone); ?>">
                </div>
            </div>

            <div class="mdbk-meta-field">
                <label><?php _e('Gender', 'doctor-appointment'); ?></label>
                <select name="mdbk_patient_gender">
                    <option value="Male" <?php selected($patient_gender, 'Male'); ?>>Male</option>
                    <option value="Female" <?php selected($patient_gender, 'Female'); ?>>Female</option>
                    <option value="Other" <?php selected($patient_gender, 'Other'); ?>>Other</option>
                </select>
            </div>

            <div class="mdbk-meta-field">
                <label><?php _e('Status', 'doctor-appointment'); ?></label>
                <select name="mdbk_status">
                    <option value="waiting" <?php selected($status, 'waiting'); ?>>Waiting</option>
                    <option value="serving" <?php selected($status, 'serving'); ?>>Serving</option>
                    <option value="completed" <?php selected($status, 'completed'); ?>>Completed</option>
                    <option value="no-show" <?php selected($status, 'no-show'); ?>>No Show</option>
                </select>
            </div>

            <div class="mdbk-meta-field">
                <label><?php _e('Doctor', 'doctor-appointment'); ?></label>
                <select name="mdbk_doctor_id">
                    <option value=""><?php _e('Select Doctor', 'doctor-appointment'); ?></option>
                    <?php
                    $doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1]);
                    foreach ($doctors as $doctor) {
                        printf('<option value="%d" %s>%s</option>', $doctor->ID, selected($doctor_id, $doctor->ID, false), $doctor->post_title);
                    }
                    ?>
                </select>
            </div>

            <div style="display: flex; gap: 20px;">
                <div class="mdbk-meta-field" style="flex: 1;">
                    <label><?php _e('Appointment Date', 'doctor-appointment'); ?></label>
                    <input type="date" name="mdbk_appointment_date" value="<?php echo esc_attr($app_date); ?>">
                </div>
                <div class="mdbk-meta-field" style="flex: 1;">
                    <label><?php _e('Slot Time', 'doctor-appointment'); ?></label>
                    <input type="time" name="mdbk_slot_time" value="<?php echo esc_attr($slot_time); ?>">
                </div>
            </div>

            <?php if ($ticket_number) : ?>
            <div class="mdbk-meta-field">
                <label><?php _e('Ticket Number', 'doctor-appointment'); ?></label>
                <input type="text" value="<?php echo esc_attr($ticket_number); ?>" disabled>
            </div>
            <?php endif; ?>

            <div class="mdbk-meta-field">
                <label><?php _e('Symptoms', 'doctor-appointment'); ?></label>
                <textarea name="mdbk_symptoms" rows="4"><?php echo esc_textarea($symptoms); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * Save Meta Box Data
     */
    public function save_meta_boxes($post_id) {
        if (!isset($_POST['mdbk_appointment_nonce']) || !wp_verify_nonce($_POST['mdbk_appointment_nonce'], 'mdbk_save_appointment_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (get_post_type($post_id) !== 'mdbk_appointment') return;

        $fields = [
            'mdbk_patient_name'   => '_mdbk_patient_name',
            'mdbk_patient_age'    => '_mdbk_patient_age',
            'mdbk_patient_phone'  => '_mdbk_patient_phone',
            'mdbk_patient_gender' => '_mdbk_patient_gender',
            'mdbk_appointment_date' => '_mdbk_appointment_date',
            'mdbk_slot_time'      => '_mdbk_slot_time',
            'mdbk_doctor_id'      => '_mdbk_doctor_id',
            'mdbk_symptoms'       => '_mdbk_symptoms'
        ];

        foreach ($fields as $key => $meta_key) {
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $meta_key, sanitize_text_field($_POST[$key]));
            }
        }

        if (isset($_POST['mdbk_status'])) {
            $post_status = self::status_slug_to_post_status(sanitize_text_field($_POST['mdbk_status']));
            if ($post_status !== get_post_status($post_id)) {
                // Avoid re-entering save_post (wp_update_post triggers it again).
                remove_action('save_post', [$this, 'save_meta_boxes']);
                wp_update_post(['ID' => $post_id, 'post_status' => $post_status]);
                add_action('save_post', [$this, 'save_meta_boxes']);
            }
        }
    }

    /**
     * Add Columns to Appointments Table
     */
    public function add_columns($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key == 'title') {
                $new_columns['patient_info'] = __('Patient Info', 'doctor-appointment');
                $new_columns['doctor'] = __('Doctor', 'doctor-appointment');
                $new_columns['app_date'] = __('Date', 'doctor-appointment');
                $new_columns['status'] = __('Status', 'doctor-appointment');
            }
        }
        return $new_columns;
    }

    /**
     * Render Columns Content
     */
    public function render_columns($column, $post_id) {
        switch ($column) {
            case 'patient_info':
                $name = get_post_meta($post_id, '_mdbk_patient_name', true);
                $phone = get_post_meta($post_id, '_mdbk_patient_phone', true);
                echo "<strong>$name</strong><br><small>$phone</small>";
                break;
            case 'doctor':
                $doctor_id = get_post_meta($post_id, '_mdbk_doctor_id', true);
                echo $doctor_id ? get_the_title($doctor_id) : '—';
                break;
            case 'app_date':
                $date = get_post_meta($post_id, '_mdbk_appointment_date', true);
                $slot = get_post_meta($post_id, '_mdbk_slot_time', true);
                echo esc_html($date . ($slot ? ' ' . $slot : ''));
                break;
            case 'status':
                $current_status = get_post_status($post_id);
                $status = in_array($current_status, \MDBK\MDBK_CPT::APPOINTMENT_STATUSES, true) ? self::post_status_to_slug($current_status) : 'waiting';
                echo esc_html(ucfirst(str_replace('-', ' ', $status)));
                break;
        }
    }

    /**
     * Find an existing patient by phone, or create one. Central place for
     * frontend booking, admin booking, and migration backfill to link a
     * patient record so the CRM is actually complete (not just admin-created
     * appointments).
     */
    public static function find_or_create_patient($name, $phone, $extra = []) {
        $name  = sanitize_text_field($name);
        $phone = sanitize_text_field($phone);

        $existing = $phone ? get_posts([
            'post_type'   => 'mdbk_patient',
            'meta_query'  => [['key' => '_mdbk_patient_phone', 'value' => $phone]],
            'numberposts' => 1,
        ]) : [];

        if ($existing) {
            $patient_id = $existing[0]->ID;
        } else {
            $patient_id = wp_insert_post([
                'post_title'  => $name,
                'post_type'   => 'mdbk_patient',
                'post_status' => 'publish',
            ]);
            if (is_wp_error($patient_id) || !$patient_id) return 0;
            update_post_meta($patient_id, '_mdbk_patient_phone', $phone);
        }

        if (!empty($extra['email'])) {
            update_post_meta($patient_id, '_mdbk_patient_email', sanitize_email($extra['email']));
        }
        if (!empty($extra['address'])) {
            update_post_meta($patient_id, '_mdbk_patient_address', sanitize_textarea_field($extra['address']));
        }

        return $patient_id;
    }

    /**
     * Generate the doctor's time slots for a given date from their day-level
     * schedule + slot duration, flagging which are already booked.
     */
    public static function get_available_slots($doctor_id, $date) {
        $doctor_id = intval($doctor_id);
        if (!$doctor_id || !$date) return [];

        $schedule = get_post_meta($doctor_id, '_mdbk_schedule', true);
        if (!is_array($schedule)) $schedule = [];

        $timestamp = strtotime($date);
        if (!$timestamp) return [];
        $day_name = date('l', $timestamp);

        // Off dates close the doctor for that date outright, regardless of
        // what the weekday pattern says. Otherwise, an extra date opens a
        // normally-inactive weekday just for that one date — using the
        // first active weekday's hours as a stand-in, since an extra date
        // has no from/to of its own.
        $off_dates = get_post_meta($doctor_id, '_mdbk_off_dates', true);
        if (is_array($off_dates) && in_array($date, $off_dates, true)) return [];

        $is_extra_date = false;
        if (empty($schedule[$day_name]['active'])) {
            $extra_dates = get_post_meta($doctor_id, '_mdbk_extra_dates', true);
            if (!is_array($extra_dates) || !in_array($date, $extra_dates, true)) return [];
            $is_extra_date = true;
        }

        if ($is_extra_date) {
            $from = $to = '';
            foreach ($schedule as $day) {
                if (!empty($day['active']) && !empty($day['from']) && !empty($day['to'])) {
                    $from = $day['from'];
                    $to = $day['to'];
                    break;
                }
            }
            if (!$from || !$to) { $from = '09:00'; $to = '17:00'; }
        } else {
            $from = isset($schedule[$day_name]['from']) ? $schedule[$day_name]['from'] : '';
            $to   = isset($schedule[$day_name]['to']) ? $schedule[$day_name]['to'] : '';
            if (!$from || !$to) return [];
        }

        $duration = intval(get_post_meta($doctor_id, '_mdbk_slot_duration', true));
        if (!$duration) $duration = intval(get_option('mdbk_default_slot_duration', 20));
        if ($duration <= 0) $duration = 20;

        $start = strtotime($date . ' ' . $from);
        $end   = strtotime($date . ' ' . $to);
        if (!$start || !$end || $start >= $end) return [];

        $booked = self::get_booked_slot_times($doctor_id, $date);

        $slots = [];
        for ($t = $start; $t < $end; $t += $duration * 60) {
            $time_str = date('H:i', $t);
            $slots[]  = [
                'time'      => $time_str,
                'available' => !in_array($time_str, $booked, true),
            ];
        }
        return $slots;
    }

    /**
     * Slot times already booked for a doctor+date. no-show frees a slot back
     * up (excluded here), waiting/serving/completed hold it.
     */
    private static function get_booked_slot_times($doctor_id, $date) {
        $ids = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => ['mdbk_waiting', 'mdbk_serving', 'mdbk_completed'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                ['key' => '_mdbk_appointment_date', 'value' => $date],
            ],
        ]);

        $times = [];
        foreach ($ids as $id) {
            $t = get_post_meta($id, '_mdbk_slot_time', true);
            if ($t) $times[] = $t;
        }
        return $times;
    }

    /**
     * Whether a doctor+date+slot is already booked. $exclude_id lets an
     * appointment being edited ignore its own existing booking.
     */
    public static function is_slot_taken($doctor_id, $date, $slot_time, $exclude_id = 0) {
        if (!$slot_time) return false;

        $args = [
            'post_type'   => 'mdbk_appointment',
            'post_status' => ['mdbk_waiting', 'mdbk_serving', 'mdbk_completed'],
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                ['key' => '_mdbk_appointment_date', 'value' => $date],
                ['key' => '_mdbk_slot_time', 'value' => $slot_time],
            ],
        ];
        if ($exclude_id) $args['post__not_in'] = [intval($exclude_id)];

        return !empty(get_posts($args));
    }

    /**
     * Next sequential ticket number for a doctor+date. Counts every status
     * (not just active ones) so a rebooked no-show slot never reuses a
     * number. $exclude_id must be passed as the appointment's own ID when
     * its doctor_id/date meta has already been written before this is
     * called — otherwise it would count itself and be off by one.
     */
    public static function next_ticket_number($doctor_id, $date, $exclude_id = 0) {
        $args = [
            'post_type'   => 'mdbk_appointment',
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                ['key' => '_mdbk_appointment_date', 'value' => $date],
            ],
        ];
        if ($exclude_id) $args['post__not_in'] = [intval($exclude_id)];

        return count(get_posts($args)) + 1;
    }

    /**
     * Whether a doctor takes slot-based bookings at all. Off: the doctor is
     * booked serially by queue number (next_ticket_number()) instead — no
     * time slot picker, no slot-conflict checking. Defaults to enabled
     * (the meta only gets written 'no' the first time someone flips the
     * toggle off), same convention as _mdbk_doctor_active.
     */
    public static function is_slot_enabled($doctor_id) {
        return get_post_meta(intval($doctor_id), '_mdbk_slot_enabled', true) !== 'no';
    }

    /**
     * Whether a doctor is working on a specific date — the weekly schedule
     * pattern, with that date-level overrides layered on top: an off date
     * always closes the doctor for that date; an extra date opens an
     * otherwise-inactive weekday for that one date. Shared by the
     * dashboard's "who's on shift today" filter and get_available_slots().
     */
    public static function is_doctor_working_on($doctor_id, $date) {
        $doctor_id = intval($doctor_id);
        if (!$doctor_id || !$date) return false;

        $off_dates = get_post_meta($doctor_id, '_mdbk_off_dates', true);
        if (is_array($off_dates) && in_array($date, $off_dates, true)) return false;

        $timestamp = strtotime($date);
        if (!$timestamp) return false;
        $day_name = date('l', $timestamp);

        $schedule = get_post_meta($doctor_id, '_mdbk_schedule', true);
        if (!empty($schedule[$day_name]['active'])) return true;

        $extra_dates = get_post_meta($doctor_id, '_mdbk_extra_dates', true);
        return is_array($extra_dates) && in_array($date, $extra_dates, true);
    }

    /**
     * A fresh check-in token — alnum only (no special chars), so it's safe
     * to drop straight into a URL query string and to type/paste from a
     * hardware QR scanner with no escaping concerns. Not checked for
     * collisions: at realistic booking volume against a 62^20 character
     * space, the odds are not worth the extra query.
     */
    public static function generate_checkin_token() {
        return wp_generate_password(20, false);
    }

    /**
     * The one appointment a check-in token belongs to, or null if the
     * token doesn't resolve to anything (deleted appointment, bad/expired
     * link, garbage input from a bogus scan). Shared by the "view my
     * booking" status view and the Queue Management check-in verify
     * handler, both in shortcode.php, so the meta_query lives in one place.
     */
    public static function find_appointment_by_token($token) {
        $token = sanitize_text_field((string) $token);
        if (!$token) return null;

        $found = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'numberposts' => 1,
            'meta_query'  => [['key' => '_mdbk_checkin_token', 'value' => $token]],
        ]);
        return $found ? $found[0] : null;
    }

    /**
     * "Q01"-style display format for a raw ticket number — was inlined
     * separately in the frontend queue display and the backend patient
     * row (str_pad($ticket, 2, '0', STR_PAD_LEFT)); pulled out here so the
     * new booking-success payload doesn't add a third copy.
     */
    public static function format_ticket_number($ticket) {
        return $ticket ? 'Q' . str_pad($ticket, 2, '0', STR_PAD_LEFT) : '';
    }

    /**
     * Best-effort soft lock around the slot-conflict-check + insert critical
     * section. Not a hard atomicity guarantee (no new table for a real
     * mutex) — good enough for realistic front-desk concurrency.
     */
    private static function acquire_slot_lock($doctor_id, $date, $slot_time) {
        if (!$slot_time) return true;

        $key = 'mdbk_slot_lock_' . md5($doctor_id . '|' . $date . '|' . $slot_time);
        for ($i = 0; $i < 5; $i++) {
            if (false === get_transient($key)) {
                set_transient($key, 1, 10);
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    private static function release_slot_lock($doctor_id, $date, $slot_time) {
        if (!$slot_time) return;
        delete_transient('mdbk_slot_lock_' . md5($doctor_id . '|' . $date . '|' . $slot_time));
    }

    /**
     * Handle Frontend Submission
     *
     * Single source of truth for creating an appointment — used by the AJAX
     * booking modal, the legacy plain-POST form, and (for new bookings only)
     * the admin dashboard's appointment save handler. Returns the new
     * appointment ID, or a WP_Error on validation/conflict failure.
     *
     * @return int|\WP_Error
     */
    public static function handle_submission($data) {
        $doctor_id = isset($data['doctor']) ? intval($data['doctor']) : 0;
        $date      = isset($data['date']) ? sanitize_text_field($data['date']) : '';
        $slot_time = isset($data['slot_time']) ? sanitize_text_field($data['slot_time']) : '';
        $name      = isset($data['full_name']) ? sanitize_text_field($data['full_name']) : '';
        $phone     = isset($data['mobile']) ? sanitize_text_field($data['mobile']) : '';
        $email     = isset($data['email']) ? sanitize_email($data['email']) : '';

        if (!self::acquire_slot_lock($doctor_id, $date, $slot_time)) {
            return new \WP_Error('mdbk_slot_locked', __('This slot is being booked by someone else right now. Please try again.', 'doctor-appointment'));
        }

        try {
            if (self::is_slot_taken($doctor_id, $date, $slot_time)) {
                return new \WP_Error('mdbk_slot_taken', __('That time slot is no longer available. Please choose another.', 'doctor-appointment'));
            }

            $patient_id = self::find_or_create_patient($name, $phone, ['email' => $email]);

            // Insert as a plain draft first — inserting directly with
            // post_status 'mdbk_waiting' would fire transition_post_status
            // (and thus the booking-confirmation email) before any of the
            // meta below exists, leaving the notification with nothing to
            // send to/about. Meta first, then transition to mdbk_waiting.
            $appointment_id = wp_insert_post([
                'post_type'   => 'mdbk_appointment',
                'post_title'  => sprintf(__('Booking: %s', 'doctor-appointment'), $name),
                'post_status' => 'draft',
            ]);

            if (is_wp_error($appointment_id)) {
                return $appointment_id;
            }

            update_post_meta($appointment_id, '_mdbk_patient_id', $patient_id);
            update_post_meta($appointment_id, '_mdbk_patient_name', $name);
            update_post_meta($appointment_id, '_mdbk_patient_age', isset($data['age']) ? sanitize_text_field($data['age']) : '');
            update_post_meta($appointment_id, '_mdbk_patient_phone', $phone);
            update_post_meta($appointment_id, '_mdbk_patient_gender', isset($data['gender']) ? sanitize_text_field($data['gender']) : '');
            update_post_meta($appointment_id, '_mdbk_patient_email', $email);
            update_post_meta($appointment_id, '_mdbk_appointment_date', $date);
            update_post_meta($appointment_id, '_mdbk_slot_time', $slot_time);
            update_post_meta($appointment_id, '_mdbk_doctor_id', $doctor_id);
            update_post_meta($appointment_id, '_mdbk_symptoms', isset($data['symptoms']) ? sanitize_textarea_field($data['symptoms']) : '');
            update_post_meta($appointment_id, '_mdbk_ticket_number', self::next_ticket_number($doctor_id, $date, $appointment_id));
            // Token must exist before the status transition below, since that
            // transition synchronously fires the confirmation email, and the
            // email's check-in link needs a token to point at.
            update_post_meta($appointment_id, '_mdbk_checkin_token', self::generate_checkin_token());

            wp_update_post(['ID' => $appointment_id, 'post_status' => 'mdbk_waiting']);

            return $appointment_id;
        } finally {
            self::release_slot_lock($doctor_id, $date, $slot_time);
        }
    }

    /**
     * AJAX: Handle Frontend Form Submission
     */
    public function ajax_handle_submission() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');

        $required = ['full_name', 'mobile', 'doctor', 'date'];
        // Slot time is only required when the selected doctor actually
        // takes slot-based bookings — a slot-disabled doctor books
        // patients serially by queue number, with no time picker on the
        // frontend to even produce a slot_time value.
        if (self::is_slot_enabled(isset($_POST['doctor']) ? intval($_POST['doctor']) : 0)) {
            $required[] = 'slot_time';
        }
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                wp_send_json_error(__('Please fill in all required fields.', 'doctor-appointment'));
                return;
            }
        }

        if (!preg_match(self::BD_MOBILE_REGEX, sanitize_text_field($_POST['mobile']))) {
            wp_send_json_error(__('Please enter a valid Bangladeshi mobile number (e.g. 01XXXXXXXXX).', 'doctor-appointment'));
            return;
        }

        if (!empty($_POST['email']) && !is_email($_POST['email'])) {
            wp_send_json_error(__('Please enter a valid email address.', 'doctor-appointment'));
            return;
        }

        $appointment_id = self::handle_submission($_POST);

        if (is_wp_error($appointment_id)) {
            wp_send_json_error($appointment_id->get_error_message());
        } elseif ($appointment_id) {
            $doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
            $date      = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
            $slot_time = get_post_meta($appointment_id, '_mdbk_slot_time', true);
            $ticket    = get_post_meta($appointment_id, '_mdbk_ticket_number', true);
            $token     = get_post_meta($appointment_id, '_mdbk_checkin_token', true);

            wp_send_json_success([
                'message'      => __('Appointment booked successfully! We will contact you soon.', 'doctor-appointment'),
                'ticket'       => self::format_ticket_number($ticket),
                'patient_name' => get_post_meta($appointment_id, '_mdbk_patient_name', true),
                'doctor_name'  => get_the_title($doctor_id),
                'date'         => $date ? date_i18n(get_option('date_format'), strtotime($date)) : '',
                'slot_time'    => $slot_time ? date_i18n(get_option('time_format'), strtotime($slot_time)) : '',
                'checkin_url'  => $token ? add_query_arg('mdbk_token', $token, home_url('/')) : '',
            ]);
        } else {
            wp_send_json_error(__('Something went wrong. Please try again.', 'doctor-appointment'));
        }
    }

    /**
     * AJAX: Get Doctor's Available Time Slots for a Date
     */
    public function ajax_get_doctor_slots() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');

        $doctor_id = intval($_POST['doctor_id']);
        $date      = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

        wp_send_json_success(self::get_available_slots($doctor_id, $date));
    }

    /**
     * AJAX: Get Doctor Schedule (off days for calendar)
     */
    public function get_doctor_schedule() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');

        $doctor_id = intval($_POST['doctor_id']);
        $schedule = get_post_meta($doctor_id, '_mdbk_schedule', true);

        $day_map = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6
        ];

        $off_days = [];
        if (is_array($schedule)) {
            foreach ($day_map as $day_name => $day_num) {
                if (!isset($schedule[$day_name]) || empty($schedule[$day_name]['active'])) {
                    $off_days[] = $day_num;
                }
            }
        } else {
            $off_days = array_values($day_map);
        }

        // Date-level overrides on top of the weekday pattern above — off
        // dates close a normally-active weekday for that one date; extra
        // dates open a normally-inactive weekday for that one date. The
        // calendar applies these itself (off_days is still weekday-only).
        $extra_dates = get_post_meta($doctor_id, '_mdbk_extra_dates', true);
        $off_dates = get_post_meta($doctor_id, '_mdbk_off_dates', true);

        wp_send_json_success([
            'off_days'    => $off_days,
            'extra_dates' => is_array($extra_dates) ? array_values($extra_dates) : [],
            'off_dates'   => is_array($off_dates) ? array_values($off_dates) : [],
        ]);
    }

    /**
     * AJAX: Get Doctors by Specialty
     */
    public function get_doctors_by_specialty() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');
        
        $spec_id = intval($_POST['specialty_id']);
        
        $args = [
            'post_type' => 'mdbk_doctor',
            'numberposts' => -1,
            // Doctors default to active — the meta only ever gets written (to 'no')
            // once someone flips a card's toggle off in wp-admin.
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_mdbk_doctor_active', 'compare' => 'NOT EXISTS'],
                ['key' => '_mdbk_doctor_active', 'value' => 'no', 'compare' => '!='],
            ],
        ];
        if ($spec_id > 0) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'mdbk_department',
                    'field'    => 'term_id',
                    'terms'    => $spec_id
                ]
            ];
        }
        $doctors = get_posts($args);

        $doctor_data = [];
        if ($doctors) {
            foreach ($doctors as $doctor) {
                $departments = get_the_terms($doctor->ID, 'mdbk_department');
                $dept_names = $departments && !is_wp_error($departments) ? wp_list_pluck($departments, 'name') : [];
                $dept_ids   = $departments && !is_wp_error($departments) ? wp_list_pluck($departments, 'term_id') : [];

                $schedule = get_post_meta($doctor->ID, '_mdbk_schedule', true);
                $available_days = [];
                if (is_array($schedule)) {
                    foreach ($schedule as $day => $time) {
                        if (!empty($time['active'])) {
                            $available_days[] = $day;
                        }
                    }
                }

                $doctor_data[] = [
                    'id'              => $doctor->ID,
                    'name'            => $doctor->post_title,
                    'specialties'     => $dept_names,
                    'department_ids'  => $dept_ids,
                    'available_days'  => $available_days,
                    'thumbnail'       => get_the_post_thumbnail_url($doctor->ID, 'thumbnail') ?: '',
                    'slot_enabled'    => self::is_slot_enabled($doctor->ID),
                ];
            }
        }

        if ($doctor_data) {
            wp_send_json_success($doctor_data);
        } else {
            wp_send_json_error(__('No doctors found for this specialty.', 'doctor-appointment'));
        }
    }

    /**
     * AJAX: Get a Single Doctor's Info
     *
     * Used to preselect a doctor (e.g. from the doctor grid's "Book
     * Appointment" button) without fetching/rendering the full doctor list
     * first — lets the modal jump straight to the details step.
     */
    public function ajax_get_doctor_info() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');

        $doctor_id = intval($_POST['doctor_id']);
        $doctor    = $doctor_id ? get_post($doctor_id) : null;

        if (!$doctor || $doctor->post_type !== 'mdbk_doctor' || $doctor->post_status !== 'publish') {
            wp_send_json_error(__('Doctor not found.', 'doctor-appointment'));
            return;
        }
        if (get_post_meta($doctor->ID, '_mdbk_doctor_active', true) === 'no') {
            wp_send_json_error(__('Doctor not found.', 'doctor-appointment'));
            return;
        }

        $departments = get_the_terms($doctor->ID, 'mdbk_department');
        $dept_names  = $departments && !is_wp_error($departments) ? wp_list_pluck($departments, 'name') : [];
        $dept_ids    = $departments && !is_wp_error($departments) ? wp_list_pluck($departments, 'term_id') : [];

        $schedule = get_post_meta($doctor->ID, '_mdbk_schedule', true);
        $available_days = [];
        if (is_array($schedule)) {
            foreach ($schedule as $day => $time) {
                if (!empty($time['active'])) {
                    $available_days[] = $day;
                }
            }
        }

        wp_send_json_success([
            'id'              => $doctor->ID,
            'name'            => $doctor->post_title,
            'specialties'     => $dept_names,
            'department_ids'  => $dept_ids,
            'available_days'  => $available_days,
            'thumbnail'       => get_the_post_thumbnail_url($doctor->ID, 'thumbnail') ?: '',
            'slot_enabled'    => self::is_slot_enabled($doctor->ID),
        ]);
    }
}

new \MDBK\MDBK_Appointment_Manager();
