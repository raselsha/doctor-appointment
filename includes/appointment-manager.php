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
        'cancelled' => 'mdbk_cancelled',
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
        // No _nopriv variant — this is staff/manager/admin/doctor only
        // (per feedback), not a public feature; see the capability check
        // at the top of the handler too.
        add_action('wp_ajax_mdbk_get_today_patient_summary', [$this, 'ajax_get_today_patient_summary']);

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

        $ticket    = self::format_ticket_number(self::display_ticket_number($appointment_id));
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
     * Human-facing label for a status slug — every place that displays a
     * status as text (badges, CSV export) previously derived one
     * independently via ucfirst(str_replace('-', ' ', $slug)), which meant
     * "serving" always showed as the literal, untranslated word "Serving".
     * One shared mapping, so relabeling (e.g. "Visiting"/"Visited") only
     * has to happen in one place.
     */
    public static function status_display_label($slug) {
        $labels = [
            'waiting'         => __('Waiting', 'doctor-appointment'),
            'serving'         => __('Visiting', 'doctor-appointment'),
            'completed'       => __('Visited', 'doctor-appointment'),
            'no-show'         => __('No Show', 'doctor-appointment'),
            'cancelled'       => __('Cancelled', 'doctor-appointment'),
            'not-checked-in'  => __('Not Checked In', 'doctor-appointment'),
            'upcoming'        => __('Upcoming', 'doctor-appointment'),
        ];
        return isset($labels[$slug]) ? $labels[$slug] : ucfirst(str_replace('-', ' ', $slug));
    }

    /**
     * The status slug a human should actually see for one appointment —
     * "waiting" (the raw post_status) means two different things
     * depending on _mdbk_checked_in: not checked in yet at all, or
     * checked in and genuinely waiting their turn. A same-day 'waiting'/
     * 'serving' row that's actually a DIFFERENT day (rescheduled, or a
     * past no-show that got left waiting) reads as "Upcoming" instead —
     * it isn't part of today's live queue. Shared by the Booking page's
     * own queue rows (render_my_queue_patient_row()), the per-doctor
     * print/image report, and CSV export, so none of them disagree about
     * what a given row's status actually is.
     */
    public static function get_display_status_slug($appointment_id) {
        $status = self::post_status_to_slug(get_post_status($appointment_id));
        $date = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        $is_today = $date === current_time('Y-m-d');

        if (!$is_today && in_array($status, ['waiting', 'serving'], true)) {
            return 'upcoming';
        }
        if ($is_today && $status === 'waiting' && get_post_meta($appointment_id, '_mdbk_checked_in', true) !== 'yes') {
            return 'not-checked-in';
        }
        return $status;
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
        $ticket_number = self::display_ticket_number($post->ID) ?: '';
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
                </select>
            </div>

            <div class="mdbk-meta-field">
                <label><?php _e('Status', 'doctor-appointment'); ?></label>
                <select name="mdbk_status">
                    <option value="waiting" <?php selected($status, 'waiting'); ?>><?php _e('Waiting', 'doctor-appointment'); ?></option>
                    <option value="serving" <?php selected($status, 'serving'); ?>><?php _e('Visiting', 'doctor-appointment'); ?></option>
                    <option value="completed" <?php selected($status, 'completed'); ?>><?php _e('Completed', 'doctor-appointment'); ?></option>
                    <option value="no-show" <?php selected($status, 'no-show'); ?>><?php _e('No Show', 'doctor-appointment'); ?></option>
                </select>
            </div>

            <div class="mdbk-meta-field">
                <label><?php _e('Doctor', 'doctor-appointment'); ?></label>
                <select name="mdbk_doctor_id">
                    <option value=""><?php _e('Select Doctor', 'doctor-appointment'); ?></option>
                    <?php
                    $doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']);
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
     * Find an existing patient by phone + name, or create one. Central
     * place for frontend booking, admin booking, and migration backfill to
     * link a patient record so the CRM is actually complete (not just
     * admin-created appointments).
     *
     * Matching requires BOTH phone and name (case/whitespace-insensitive) —
     * phone alone would silently collapse different family members who
     * share one household phone number into a single patient record, mixing
     * one person's visit history into another's. A phone match with a
     * different name is treated as a different patient (who happens to
     * share that phone), not the same one.
     */
    public static function find_or_create_patient($name, $phone, $extra = []) {
        $name  = sanitize_text_field($name);
        $phone = sanitize_text_field($phone);

        $patient_id = 0;
        if ($phone) {
            $candidates = get_posts([
                'post_type'   => 'mdbk_patient',
                'meta_query'  => [['key' => '_mdbk_patient_phone', 'value' => $phone]],
                'numberposts' => -1,
            ]);
            foreach ($candidates as $candidate) {
                if (mb_strtolower(trim($candidate->post_title)) === mb_strtolower(trim($name))) {
                    $patient_id = $candidate->ID;
                    break;
                }
            }
        }

        if (!$patient_id) {
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
        // Address is captured as District + Thana (two dependent
        // dropdowns), not free text — a typed address was unusable for
        // the thing it was actually wanted for (grouping patients by
        // area), since "Savar", "savar" and "Sabar Thana" are three
        // different strings for one place. Both parts are stored
        // separately AND composed into _mdbk_patient_address, so every
        // existing reader (booking row, print table, CSV, patient modal)
        // keeps working untouched while the structured values stay
        // queryable. An unrecognised pair is dropped rather than saved:
        // the dropdowns can't produce one, so it only ever arrives from a
        // forged POST.
        if (isset($extra['district']) || isset($extra['thana'])) {
            $district = sanitize_text_field(isset($extra['district']) ? $extra['district'] : '');
            $thana    = sanitize_text_field(isset($extra['thana']) ? $extra['thana'] : '');
            if (MDBK_BD_Locations::is_valid($district, $thana) && self::should_write_location($patient_id, $district)) {
                update_post_meta($patient_id, '_mdbk_patient_district', $district);
                update_post_meta($patient_id, '_mdbk_patient_thana', $thana);
                update_post_meta($patient_id, '_mdbk_patient_address', MDBK_BD_Locations::format_address($district, $thana));
            }
        } elseif (!empty($extra['address'])) {
            // Legacy free-text path — still honoured for any caller that
            // hasn't moved to the dropdowns.
            update_post_meta($patient_id, '_mdbk_patient_address', sanitize_textarea_field($extra['address']));
        }
        // Age/gender are captured at every booking (see handle_submission()
        // and admin-dashboard.php's ajax_save_appointment()) but were only
        // ever written onto that ONE appointment's own meta — the Patient
        // Directory reads them off the *patient* post instead, so every
        // patient showed blank there regardless of what was entered at
        // booking time. Refreshed on each booking (like email/address
        // above) since age genuinely changes year to year.
        if (!empty($extra['age'])) {
            update_post_meta($patient_id, '_mdbk_patient_age', sanitize_text_field($extra['age']));
        }
        if (!empty($extra['gender'])) {
            update_post_meta($patient_id, '_mdbk_patient_gender', sanitize_text_field($extra['gender']));
        }

        if ($phone) {
            self::link_patient_user($patient_id, $phone);
        }

        return $patient_id;
    }

    /**
     * Ensures $patient_id has a WP user account with the Patient role,
     * creating one if needed — record-keeping only, no login is ever
     * handed to the patient (no email sent, no credentials surfaced
     * anywhere). Reachable from the PUBLIC, unauthenticated booking form,
     * so this deliberately never looks anyone up by email — only by a
     * user_login we ourselves derive from the phone number, so it can
     * only ever find/reuse an account this exact function created on a
     * previous booking from the same phone, never an unrelated person's
     * pre-existing account.
     */
    private static function link_patient_user($patient_id, $phone) {
        if (get_post_meta($patient_id, '_mdbk_patient_user_id', true)) {
            return;
        }

        $login = 'patient_' . preg_replace('/\D/', '', $phone);
        if ($login === 'patient_') {
            return;
        }

        $user = get_user_by('login', $login);
        if ($user) {
            if (!in_array('mdbk_patient_role', $user->roles, true)) {
                $user->add_role('mdbk_patient_role');
            }
            update_post_meta($patient_id, '_mdbk_patient_user_id', $user->ID);
            return;
        }

        $user_id = wp_insert_user([
            'user_login' => $login,
            'user_pass'  => wp_generate_password(20, true),
            'role'       => 'mdbk_patient_role',
        ]);

        if (is_wp_error($user_id)) {
            error_log('MDBK: failed to create patient user account for patient #' . $patient_id . ': ' . $user_id->get_error_message());
            return;
        }

        update_post_meta($patient_id, '_mdbk_patient_user_id', $user_id);
    }

    /**
     * Generate the doctor's time slots for a given date from their day-level
     * schedule + slot duration, flagging which are already booked.
     */
    public static function get_available_slots($doctor_id, $date, $exclude_id = 0) {
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

        // Built via an explicit DateTimeZone (WordPress's own configured
        // site timezone, Settings > General) rather than bare strtotime()/
        // date(), which both silently fall back to PHP's server-level
        // default timezone (the `date.timezone` ini setting) — that's UTC
        // on this dev box, but is NOT guaranteed on every host, and a
        // mismatch there previously made "now" (current_time()) and the
        // doctor's own start/end times get computed on two different
        // timezone bases, corrupting the past-time filter below on any
        // server where PHP's default timezone isn't UTC.
        $tz = wp_timezone();
        try {
            $start = (new \DateTime($date . ' ' . $from, $tz))->getTimestamp();
            $end   = (new \DateTime($date . ' ' . $to, $tz))->getTimestamp();
        } catch (\Exception $e) {
            return [];
        }
        if ($start >= $end) return [];

        $booked = self::get_booked_slot_times($doctor_id, $date, $exclude_id);

        // Named break windows, doctor-wide (not per-day — see
        // handle_doctor_save() in admin-dashboard.php), applied inside
        // whichever day's working hours are active above. Each one's
        // start/end are built the same explicit-timezone way as
        // $start/$end above, so they compare correctly against $t
        // regardless of server timezone. sanitize_breaks_list() already
        // refuses to save an invalid/inverted range (to <= from) or a
        // missing from/to, but this re-checks in case a value was written
        // before that validation existed.
        $breaks_raw = get_post_meta($doctor_id, '_mdbk_breaks', true);
        if (!is_array($breaks_raw)) $breaks_raw = [];
        $breaks = [];
        foreach ($breaks_raw as $b) {
            $b_from = isset($b['from']) ? $b['from'] : '';
            $b_to   = isset($b['to']) ? $b['to'] : '';
            if (!$b_from || !$b_to) continue;
            try {
                $bs = (new \DateTime($date . ' ' . $b_from, $tz))->getTimestamp();
                $be = (new \DateTime($date . ' ' . $b_to, $tz))->getTimestamp();
            } catch (\Exception $e) {
                continue;
            }
            if ($bs >= $be) continue;
            $breaks[] = ['start' => $bs, 'end' => $be, 'name' => !empty($b['name']) ? $b['name'] : __('Break', 'doctor-appointment')];
        }

        // For today's date, a slot that has already passed the current
        // moment isn't a real option any more — drop it instead of just
        // marking it unavailable, so it's not shown at all. $start/$end/$t
        // are now true, timezone-correct Unix timestamps (via the explicit
        // DateTimeZone above), so a plain time() — the real current
        // moment — is the right thing to compare against.
        $is_today = $date === current_time('Y-m-d');
        $now      = time();

        $slots = [];
        for ($t = $start; $t < $end; $t += $duration * 60) {
            if ($is_today && $t < $now) continue;
            $time_str = wp_date('H:i', $t, $tz);
            // Name of whichever break this slot falls in, or '' if none —
            // the JS slot pickers (admin-script.js, form-script.js) use
            // this string directly as the disabled button's label.
            $break_name = '';
            foreach ($breaks as $b) {
                if ($t >= $b['start'] && $t < $b['end']) { $break_name = $b['name']; break; }
            }
            $slots[]  = [
                'time'      => $time_str,
                'available' => $break_name === '' && !in_array($time_str, $booked, true),
                'break'     => $break_name !== '' ? $break_name : false,
            ];
        }
        return $slots;
    }

    /**
     * The soonest open slot for a doctor+date, or '' if none — either the
     * doctor has no active schedule for this date at all (no Weekly
     * Availability configured, or it's an off day), or every slot that
     * exists is already taken/on break. Both cases are the same signal to
     * the caller: nothing to auto-assign, reject the booking rather than
     * silently skipping the time-slot system altogether. Used to fill in
     * _mdbk_slot_time when a doctor's picker is hidden from patients
     * (is_slot_enabled() off) — see handle_submission() and
     * handle_appointment_save()'s edit branch.
     */
    public static function find_next_available_slot($doctor_id, $date, $exclude_id = 0) {
        foreach (self::get_available_slots($doctor_id, $date, $exclude_id) as $slot) {
            if ($slot['available']) return $slot['time'];
        }
        return '';
    }

    /**
     * Slot times already booked for a doctor+date. no-show frees a slot back
     * up (excluded here), waiting/serving/completed hold it. $exclude_id —
     * same purpose as is_slot_taken()'s own param below — leaves the
     * appointment currently being edited out of its own "taken" count, so
     * re-opening it for edit doesn't show its own already-booked slot as
     * unavailable.
     */
    private static function get_booked_slot_times($doctor_id, $date, $exclude_id = 0) {
        $args = [
            'post_type'   => 'mdbk_appointment',
            'post_status' => ['mdbk_waiting', 'mdbk_serving', 'mdbk_completed'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                ['key' => '_mdbk_appointment_date', 'value' => $date],
            ],
        ];
        if ($exclude_id) $args['post__not_in'] = [intval($exclude_id)];
        $ids = get_posts($args);

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
     * Per-request memo for checkin_ticket_number(), keyed "doctorId|date"
     * — one query per doctor+date instead of one per rendered row.
     */
    private static $checkin_rank_cache = [];

    /**
     * Drop the memo above. Called after anything that changes who is
     * checked in for a doctor+date (mark_checked_in(), and the reschedule
     * path in MDBK_Admin_Dashboard::handle_appointment_save() that clears
     * a check-in), so a rank read later in the SAME request — the kiosk
     * and chamber check-in handlers both read one immediately after
     * checking someone in — sees the new arrival rather than the list as
     * it was a moment ago.
     */
    public static function flush_checkin_rank_cache() {
        self::$checkin_rank_cache = [];
    }

    /**
     * This appointment's queue number under check-in-order mode
     * (queue_serial_mode() === 'checkin'): its 1-based position among
     * everyone checked in for the same doctor+date, ordered by when they
     * actually checked in. First arrival is 1, next is 2, and so on. 0 if
     * this patient hasn't checked in — they have no queue number at all
     * yet under this mode (their booking still identifies them by
     * format_booking_id() until they arrive).
     *
     * Computed live rather than stamped into _mdbk_ticket_number at
     * check-in time, so the sequence is always a true 1..N reading of
     * today's arrivals. A stored number can't promise that: switching
     * this setting on mid-day (or seeded/booking-mode data, which already
     * carries booking-order numbers) would leave the first person to
     * actually arrive holding whatever number the booking-order counter
     * had reached — "Q15" for the first arrival of the day. Deriving it
     * instead means flipping the setting re-reads as 1, 2, 3 immediately,
     * and flipping back restores every booking-order number untouched,
     * since neither mode writes over the other's numbering.
     *
     * Ties on _mdbk_checkin_time (same-second check-ins) fall back to
     * post ID, so the order is at least stable across renders.
     */
    public static function checkin_ticket_number($appointment_id) {
        $appointment_id = intval($appointment_id);
        if (get_post_meta($appointment_id, '_mdbk_checked_in', true) !== 'yes') return 0;

        $doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        $date      = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        $key       = $doctor_id . '|' . $date;

        if (!isset(self::$checkin_rank_cache[$key])) {
            $ids = get_posts([
                'post_type'   => 'mdbk_appointment',
                'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_query'  => [
                    'relation' => 'AND',
                    ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                    ['key' => '_mdbk_appointment_date', 'value' => $date],
                    ['key' => '_mdbk_checked_in', 'value' => 'yes'],
                ],
            ]);
            usort($ids, function($a, $b) {
                $time_a = intval(get_post_meta($a, '_mdbk_checkin_time', true));
                $time_b = intval(get_post_meta($b, '_mdbk_checkin_time', true));
                if ($time_a !== $time_b) return $time_a <=> $time_b;
                return $a <=> $b;
            });
            $ranks = [];
            foreach ($ids as $i => $id) {
                $ranks[$id] = $i + 1;
            }
            self::$checkin_rank_cache[$key] = $ranks;
        }

        return isset(self::$checkin_rank_cache[$key][$appointment_id])
            ? self::$checkin_rank_cache[$key][$appointment_id]
            : 0;
    }

    /**
     * The queue number to SHOW for one appointment, whichever mode is
     * active — the single place every list, badge, email and API response
     * asks, so none of them can disagree about a patient's number.
     * Booking-order mode: the number stamped at booking time
     * (next_ticket_number(), stored in _mdbk_ticket_number). Check-in-order
     * mode: their live arrival position (checkin_ticket_number() above),
     * or 0 while they still haven't checked in. 0 means "no number to
     * show" either way — format_ticket_number() renders it as ''.
     */
    public static function display_ticket_number($appointment_id) {
        $appointment_id = intval($appointment_id);
        if (self::queue_serial_mode(intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true))) === 'checkin') {
            return self::checkin_ticket_number($appointment_id);
        }
        return intval(get_post_meta($appointment_id, '_mdbk_ticket_number', true));
    }

    /**
     * Whether this doctor's time-slot picker is shown to patients on the
     * public booking form. Off: the picker is hidden and the patient just
     * picks a date — a real time slot is still assigned automatically
     * behind the scenes (find_next_available_slot(), called from
     * handle_submission()/handle_appointment_save()'s edit branch), it's
     * just never shown or chosen by the patient. Defaults to enabled (the
     * meta only gets written 'no' the first time someone flips the toggle
     * off), same convention as _mdbk_doctor_active.
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
     * Shared low-level check-in mutation — the one place that sets
     * _mdbk_checked_in/_mdbk_checkin_time, so the kiosk QR-scan check-in
     * (ajax_verify_checkin), the staff Bookings-page button
     * (MDBK_Admin_Dashboard::ajax_admin_checkin), and the doctor's-chamber
     * phone-lookup check-in (ajax_chamber_checkin) can never drift out of
     * sync on what's allowed to be checked in. Returns true on success, or
     * a translated error message string when rejected.
     *
     * Deliberately does NOT auto-promote anyone to "serving" — checking in
     * only ever means "this patient has arrived and is waiting"; becoming
     * "Visiting" is a separate, explicit action a doctor/staff member takes
     * via start_visiting() (or the kiosk's own "Call Next Patient"/"Visit
     * Now"). A patient staying in Waiting right after check-in, even when
     * they're the only one in the queue, is the intended behavior — not a
     * bug — so a single-patient day never skips straight to "Visiting"
     * without someone deciding that.
     */
    public static function mark_checked_in($appointment_id) {
        $appointment_id = intval($appointment_id);
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            return __('Invalid appointment.', 'doctor-appointment');
        }
        if (get_post_status($appointment_id) !== 'mdbk_waiting') {
            return __('This booking is not awaiting check-in (already checked in, served, or cancelled).', 'doctor-appointment');
        }
        if (get_post_meta($appointment_id, '_mdbk_appointment_date', true) !== current_time('Y-m-d')) {
            return __('This booking is not for today.', 'doctor-appointment');
        }

        update_post_meta($appointment_id, '_mdbk_checked_in', 'yes');
        update_post_meta($appointment_id, '_mdbk_checkin_time', current_time('timestamp'));

        // Nothing to stamp for check-in-order mode: the arrival number is
        // derived from _mdbk_checkin_time (just written above) whenever
        // it's displayed — see checkin_ticket_number(), which explains why
        // deriving beats storing here. The memo it keeps has to go, though,
        // or this new arrival would be missing from a rank read later in
        // this same request (both the kiosk and chamber check-in handlers
        // read one right after calling this).
        self::flush_checkin_rank_cache();

        // Check-in has no post status of its own to hang a notification
        // off — every other event in the booking's life is a status
        // transition, this one is a meta flag — so it's announced here
        // instead. All three check-in routes (front desk, kiosk QR,
        // chamber phone lookup) come through this method, so one hook
        // covers them all. See MDBK_SMS::on_checked_in().
        do_action('mdbk_appointment_checked_in', $appointment_id);

        return true;
    }

    /**
     * Staff-initiated cancellation — only allowed while a booking is still
     * 'mdbk_waiting' AND not yet checked in, matching mark_checked_in()'s
     * own guard so the two actions can never race (a booking that's been
     * checked in is committed to the day's queue and must be handled as a
     * no-show/visit instead). Soft-cancels via post_status rather than
     * deleting the post, so the record (and its slot) stays in history and
     * visible on the Bookings page instead of vanishing outright — that's
     * what the existing hard "Delete" action is for.
     */
    public static function cancel_booking($appointment_id) {
        $appointment_id = intval($appointment_id);
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            return __('Invalid appointment.', 'doctor-appointment');
        }
        if (get_post_status($appointment_id) !== 'mdbk_waiting') {
            return __('Only a waiting, not-yet-checked-in booking can be cancelled.', 'doctor-appointment');
        }
        if (get_post_meta($appointment_id, '_mdbk_checked_in', true) === 'yes') {
            return __('This patient has already checked in and can no longer be cancelled.', 'doctor-appointment');
        }

        $updated = wp_update_post(['ID' => $appointment_id, 'post_status' => 'mdbk_cancelled'], true);
        if (is_wp_error($updated)) {
            return $updated->get_error_message();
        }

        return true;
    }

    /**
     * Explicit "Start Visiting" — the ONLY way a checked-in waiting
     * patient becomes "serving" (Visiting) on the admin Patients page;
     * there is no automatic advance anymore (see mark_checked_in()'s
     * comment) — a doctor/staff member must always deliberately choose
     * who's being seen next, even when there's only one patient checked
     * in. Does NOT enforce ticket order — the doctor may have a reason
     * (urgency) to see someone out of turn — but it does enforce "only one
     * serving patient per doctor at a time", returned as a translated
     * error string rather than silently no-op'ing.
     */
    public static function start_visiting($appointment_id) {
        $appointment_id = intval($appointment_id);
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            return __('Invalid appointment.', 'doctor-appointment');
        }
        if (get_post_status($appointment_id) !== 'mdbk_waiting') {
            return __('Only a waiting patient can be started.', 'doctor-appointment');
        }
        if (get_post_meta($appointment_id, '_mdbk_checked_in', true) !== 'yes') {
            return __('This patient has not checked in yet.', 'doctor-appointment');
        }
        if (get_post_meta($appointment_id, '_mdbk_appointment_date', true) !== current_time('Y-m-d')) {
            return __('This booking is not for today.', 'doctor-appointment');
        }

        $doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        $already_serving = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => ['mdbk_serving'],
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_mdbk_appointment_date', 'value' => current_time('Y-m-d')],
                ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
            ],
        ]);
        if ($already_serving) {
            return __('Another patient is already being seen — mark them visited first.', 'doctor-appointment');
        }

        wp_update_post(['ID' => $appointment_id, 'post_status' => 'mdbk_serving']);
        // Stamp when the consultation actually began. The panel counts up
        // from this (so every viewer sees the same elapsed time, not their
        // own browser's idea of when the page happened to load), and
        // ajax_mark_visited() subtracts it to store how long the visit ran.
        // Only set if absent: re-starting someone already timed would
        // otherwise reset a consultation that's been running for a while.
        if (!get_post_meta($appointment_id, '_mdbk_visit_started_at', true)) {
            update_post_meta($appointment_id, '_mdbk_visit_started_at', current_time('timestamp'));
        }
        delete_post_meta($appointment_id, '_mdbk_visit_ended_at');
        delete_post_meta($appointment_id, '_mdbk_visit_duration');
        return true;
    }

    /**
     * How long a finished visit took, in whole seconds — 0 when the visit
     * was never timed (closed out before this existed, or the status was
     * changed straight from the edit form rather than through Start
     * Visiting → Mark Visited).
     */
    public static function visit_duration($appointment_id) {
        return intval(get_post_meta(intval($appointment_id), '_mdbk_visit_duration', true));
    }

    /**
     * "12m 30s" / "45s" / "1h 05m" — a duration a person reads at a
     * glance, not a raw second count. '' for an untimed visit so callers
     * can fall back to a dash rather than printing a misleading "0s".
     */
    public static function format_duration($seconds) {
        $seconds = intval($seconds);
        if ($seconds <= 0) return '';
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h) return sprintf('%dh %02dm', $h, $m);
        if ($m) return sprintf('%dm %02ds', $m, $s);
        return sprintf('%ds', $s);
    }

    /**
     * Persistent per-doctor secret behind the doctor's-chamber walk-in
     * check-in QR (see MDBK_Admin_Dashboard::render_chamber_qr_page()) —
     * one static code printed once and posted in the chamber, scanned by
     * every patient who walks in. Unlike a nonce (~1-2 day lifetime), this
     * has to survive as long as the printed sheet stays on the wall, so
     * it's a stored secret generated lazily on first use, not a nonce.
     */
    public static function get_or_create_chamber_token($doctor_id) {
        $doctor_id = intval($doctor_id);
        $token = get_post_meta($doctor_id, '_mdbk_chamber_token', true);
        if (!$token) {
            $token = wp_generate_password(24, false);
            update_post_meta($doctor_id, '_mdbk_chamber_token', $token);
        }
        return $token;
    }

    /**
     * Per-doctor override for Global Settings' "Enable Live Queue" toggle —
     * lets one specific doctor's public queue display be switched off (e.g.
     * a room not using the screen) without turning every other doctor's
     * off too. Default enabled, same as _mdbk_doctor_active's own
     * absent-meta-means-yes rule.
     */
    public static function is_doctor_live_queue_enabled($doctor_id) {
        return get_post_meta(intval($doctor_id), '_mdbk_live_queue_enabled', true) !== 'no';
    }

    /**
     * The 7 day names in WordPress's own configured week-start order
     * (Settings > General > "Week Starts On" — 0=Sunday..6=Saturday, same
     * option get_calendar()/the date-picker widgets already read). Every
     * place this plugin lists all 7 weekdays (doctor Edit form's Weekly
     * Availability, the Profile page's read-only copy, the frontend
     * doctor card's own schedule list) uses this instead of a hardcoded
     * Monday-first order, so a site whose week starts on Saturday (or any
     * other day) sees that reflected everywhere, not just in WP's own
     * calendar widgets. Not used for _mdbk_schedule's own stored array
     * keys or the day-name-to-weekday-number map in
     * ajax_get_doctor_slots() below — those are keyed lookups/date
     * math, unaffected by display order.
     */
    public static function get_week_day_order() {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $start = ((int) get_option('start_of_week', 1)) % 7;
        if ($start < 0) $start += 7;
        return array_merge(array_slice($days, $start), array_slice($days, 0, $start));
    }

    /**
     * English day name -> translated display label. The day name itself
     * (get_week_day_order()'s own return value) has to stay the literal
     * English string everywhere else — it's the _mdbk_schedule array key
     * AND the `schedule[Monday][active]` form field name the Edit
     * modal/save handler round-trip on every save — so this is only for
     * wherever a day is actually shown to a user as text. Written as a
     * literal __('Monday', ...) call per day (not __($day, ...)) since
     * `wp i18n make-pot` can only extract string literals, not variables.
     */
    public static function get_day_labels() {
        return [
            'Sunday'    => __('Sunday', 'doctor-appointment'),
            'Monday'    => __('Monday', 'doctor-appointment'),
            'Tuesday'   => __('Tuesday', 'doctor-appointment'),
            'Wednesday' => __('Wednesday', 'doctor-appointment'),
            'Thursday'  => __('Thursday', 'doctor-appointment'),
            'Friday'    => __('Friday', 'doctor-appointment'),
            'Saturday'  => __('Saturday', 'doctor-appointment'),
        ];
    }

    /**
     * The doctor a chamber QR token belongs to, or 0 if it doesn't resolve
     * to anything (bad/garbled scan). Mirrors find_appointment_by_token().
     */
    public static function get_doctor_id_by_chamber_token($token) {
        $token = sanitize_text_field((string) $token);
        if (!$token) return 0;

        $found = get_posts([
            'post_type'   => 'mdbk_doctor',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => '_mdbk_chamber_token', 'value' => $token]],
        ]);
        return $found ? intval($found[0]) : 0;
    }

    /**
     * The mdbk_doctor post ID linked to a WP user (via _mdbk_doctor_user_id,
     * set by MDBK_Admin_Dashboard::link_doctor_user()), or 0 if that user
     * isn't linked to any doctor — e.g. an administrator previewing the
     * restricted Booking queue, or a stray login. Shared by the doctor
     * dashboard page and the mdbk_mark_visited AJAX ownership check so
     * both use the exact same reverse-lookup.
     */
    public static function get_doctor_id_for_user($user_id) {
        $user_id = intval($user_id);
        if (!$user_id) return 0;

        $found = get_posts([
            'post_type'   => 'mdbk_doctor',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => '_mdbk_doctor_user_id', 'value' => $user_id]],
        ]);
        return $found ? intval($found[0]) : 0;
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
     * Sort key for check-in-order queue mode (queue_serial_mode() ===
     * 'checkin') — checked-in patients bubble above everyone still
     * pending, serially by Q order: whoever arrived earlier sits higher
     * regardless of their slot time. Returns a [tier, tiebreak] pair so
     * callers can keep using a single <=> comparison:
     *
     *   0 — currently serving (leads the list, same partition every
     *       queue view already applies on its own),
     *   1 — waiting AND checked in, tiebroken by live Q number
     *       (checkin_ticket_number(), i.e. check-in arrival order),
     *   2 — waiting, NOT yet checked in, tiebroken by slot time so the
     *       pending crowd still reads as an appointment-time schedule,
     *   3 — no-show, 4 — completed (bottom of the list).
     *
     * Tiebreak values are zero-padded strings so every tier compares
     * consistently under PHP 8 array comparison.
     *
     * Booking mode has no equivalent here — every one of these lists
     * already had its own, different, pre-existing ticket-order sort for
     * that mode before this setting existed, and each one keeps exactly
     * that, unchanged, in its own booking-mode branch — see
     * render_queue_list_body() in shortcode.php and
     * ajax_get_today_patient_summary() below.
     */
    public static function checkin_order_sort_key($appointment_id) {
        $status = self::post_status_to_slug(get_post_status($appointment_id));
        if ($status === 'serving') return [0, ''];
        if ($status === 'waiting') {
            if (get_post_meta($appointment_id, '_mdbk_checked_in', true) === 'yes') {
                return [1, str_pad((string) self::checkin_ticket_number($appointment_id), 4, '0', STR_PAD_LEFT)];
            }
            return [2, (string) get_post_meta($appointment_id, '_mdbk_slot_time', true)];
        }
        if ($status === 'no-show') return [3, ''];
        return [4, ''];
    }

    /**
     * Every specialty, in the admin's drag-and-drop order (_mdbk_specialty_order
     * term meta). Deliberately does NOT pass 'orderby' => 'meta_value_num' +
     * 'meta_key' => '_mdbk_specialty_order' to get_terms(): a meta_key arg adds
     * an INNER JOIN on termmeta that silently DROPS every term lacking that row,
     * so any specialty created outside the admin save handler (seeder, import)
     * vanished from the doctor modal's specialty dropdown, the Specialties page,
     * and the booking form all at once. Fetched plain and sorted in PHP here
     * instead; terms with no order meta yet fall back to term_id order at the
     * bottom until they're reordered once in wp-admin.
     *
     * $hide_empty keeps WP's own published-post count filter (a specialty with
     * zero doctors isn't a real booking choice on patient-facing lists).
     */
    public static function get_specialty_terms($hide_empty = false) {
        $terms = get_terms(['taxonomy' => 'mdbk_department', 'hide_empty' => $hide_empty]);
        if (is_wp_error($terms)) return [];
        $terms = array_values($terms);
        usort($terms, function($a, $b) {
            $oa = get_term_meta($a->term_id, '_mdbk_specialty_order', true);
            $ob = get_term_meta($b->term_id, '_mdbk_specialty_order', true);
            if ($oa !== $ob) {
                return ($oa !== '' ? intval($oa) : PHP_INT_MAX)
                    <=> ($ob !== '' ? intval($ob) : PHP_INT_MAX);
            }
            return intval($a->term_id) <=> intval($b->term_id);
        });
        return $terms;
    }

    /**
     * "B000123"-style display format for a booking's post ID — shown on
     * the confirmation screen in place of a ticket number when there isn't
     * one yet (check-in-order queue mode, before the patient has checked
     * in — see queue_serial_mode()).
     */
    public static function format_booking_id($appointment_id) {
        return sprintf('B%06d', $appointment_id);
    }

    /**
     * One booking's patient address, read live from the patient record.
     *
     * Deliberately NOT copied onto the appointment the way name/phone/
     * email are. Those are captured per booking because they describe
     * that visit; an address describes the person, changes rarely, and
     * when it does change it should change everywhere at once — a patient
     * who moves is corrected on their record and every booking they have,
     * past and future, reads the new one. '' when the booking has no
     * patient record behind it any more (see ajax_view_patient()'s own
     * note on bookings outliving their registry entry).
     */
    public static function patient_address($appointment_id) {
        $patient_id = intval(get_post_meta(intval($appointment_id), '_mdbk_patient_id', true));
        if (!$patient_id) return '';
        return (string) get_post_meta($patient_id, '_mdbk_patient_address', true);
    }

    /**
     * The same address as patient_address(), but as its two stored parts
     * — what the Edit forms need to re-select the right options in the
     * District and Thana dropdowns. Reading the composed one-liner back
     * and splitting it on the comma would work today and break the first
     * time a district name contains one.
     */
    /**
     * Whether a blank District should be written over what's already on
     * the patient record.
     *
     * Patients created before the dropdowns existed have a typed
     * _mdbk_patient_address and no district/thana of their own. Opening
     * one of those in the Edit form shows an empty District — nobody
     * chose that, it's just what the form has to show for data it can't
     * represent — so saving the form unchanged must not read as "clear
     * this patient's address" and delete a real one.
     *
     * A patient who DOES have a stored district is a different case: an
     * empty District there was genuinely selected, and clearing it is a
     * legitimate edit. Non-empty always writes.
     */
    public static function should_write_location($patient_id, $district) {
        if ($district !== '') return true;
        return get_post_meta($patient_id, '_mdbk_patient_district', true) !== '';
    }

    /**
     * Age as the booking forms accept it: a whole number of years, 0
     * included (a newborn), up to a bound no real patient passes. Kept
     * next to location_error() so the public form, the admin form and
     * their two error paths can't drift apart on what "valid" means.
     */
    public static function is_valid_age($age) {
        $age = trim((string) $age);
        if ($age === '' || !ctype_digit($age)) return false;
        return intval($age) <= 120;
    }

    /**
     * '' when the District/Thana pair is acceptable; otherwise the
     * message to show. $required (Global Settings > Booking Form Fields
     * > Address) decides whether leaving both blank is itself the
     * error — either way, a HALF-filled or mismatched pair is always
     * rejected: a district with no thana (or a thana that isn't really
     * inside the chosen district) is not an address anyone can act on,
     * and the dropdown UI can never produce one on its own — only a
     * hand-crafted request could, so this is the one thing that stays
     * enforced regardless of the required/optional setting.
     */
    public static function location_error($district, $thana, $required = true) {
        $district = trim((string) $district);
        $thana    = trim((string) $thana);
        if ($district === '' && $thana === '') {
            return $required ? __('Please choose a district and thana.', 'doctor-appointment') : '';
        }
        if ($district === '' || $thana === '') {
            return __('Please choose both a district and thana.', 'doctor-appointment');
        }
        if (!MDBK_BD_Locations::is_valid($district, $thana)) {
            return __('That thana does not belong to the chosen district. Please choose again.', 'doctor-appointment');
        }
        return '';
    }

    /**
     * Per-field Show/Required control for the booking forms — configured
     * in Global Settings ("Booking Form Fields") and read by BOTH the
     * public form and the admin Add/Edit Booking modal, so the two can
     * never disagree about what's mandatory. Only the fields where "off"
     * is a coherent choice are covered here: Full Name, Phone, Doctor,
     * Date and Status stay fixed — a booking with no name, no way to
     * reach the patient, or no doctor/date isn't a booking, and nothing
     * downstream (find_or_create_patient()'s matching, the mobile-number
     * check, ticket assignment) works without them. Address covers
     * District+Thana as ONE setting, not two — they're rendered and
     * saved as a single pair (render_location_selects() /
     * find_or_create_patient()), so a site can never end up with Thana
     * required but District hidden, which would leave no control able to
     * satisfy that requirement.
     *
     * Defaults reproduce the plugin's behavior from before this setting
     * existed, so an install that never opens Global Settings sees no
     * change: Age was already required, District/Thana were already
     * required together, Email and Gender were already optional.
     */
    public static function field_settings() {
        $defaults = [
            'email'   => ['visible' => true, 'required' => false],
            'age'     => ['visible' => true, 'required' => true],
            'gender'  => ['visible' => true, 'required' => false],
            'address' => ['visible' => true, 'required' => true],
        ];
        $stored = get_option('mdbk_field_settings', []);
        $settings = [];
        foreach ($defaults as $field => $default) {
            $visible = isset($stored[$field]['visible']) ? $stored[$field]['visible'] === '1' : $default['visible'];
            $required = isset($stored[$field]['required']) ? $stored[$field]['required'] === '1' : $default['required'];
            // A hidden field can never be required — nothing on the form
            // could satisfy that. Enforced here (not only when saving)
            // so it also holds for a value written before this rule
            // existed.
            $settings[$field] = ['visible' => $visible, 'required' => $visible && $required];
        }
        return $settings;
    }

    public static function is_field_visible($field) {
        $settings = self::field_settings();
        return $settings[$field]['visible'] ?? true;
    }

    public static function is_field_required($field) {
        $settings = self::field_settings();
        return $settings[$field]['required'] ?? false;
    }

    public static function patient_location($appointment_id) {
        $patient_id = intval(get_post_meta(intval($appointment_id), '_mdbk_patient_id', true));
        if (!$patient_id) return ['district' => '', 'thana' => ''];
        return [
            'district' => (string) get_post_meta($patient_id, '_mdbk_patient_district', true),
            'thana'    => (string) get_post_meta($patient_id, '_mdbk_patient_thana', true),
        ];
    }

    /**
     * The identifier to print for one booking, wherever it is shown — the
     * badge on a queue row, the Print/Download-image table, the CSV
     * export. One function so those can never disagree: an export that
     * numbers its own rows 1..N produces a "Q01" that matches nothing on
     * screen, which is exactly what this replaced.
     *
     * Today's bookings show their queue number (Q07) once they have one;
     * everything else shows the Booking ID (B000123). A queue number is
     * only meaningful inside the day it was issued for — it restarts each
     * morning per doctor — so printing one against another date would
     * name a position in a queue that has already been and gone. The same
     * fallback covers today's not-yet-arrived patients under
     * check-in-order mode, who have no number until they check in (see
     * checkin_ticket_number()).
     */
    public static function display_ticket_label($appointment_id) {
        $appointment_id = intval($appointment_id);
        if (get_post_meta($appointment_id, '_mdbk_appointment_date', true) === current_time('Y-m-d')) {
            $number = self::display_ticket_number($appointment_id);
            if ($number) return self::format_ticket_number($number);
        }
        return self::format_booking_id($appointment_id);
    }

    /**
     * Whether a patient's queue number is stamped the moment they book
     * (next_ticket_number(), booking order) or derived from when they
     * actually arrive (checkin_ticket_number(), check-in order).
     *
     * Per doctor: each doctor picks their own mode in their profile's
     * "Queue & Ticketing" section (stored as the _mdbk_queue_serial_mode
     * post meta). A doctor who never picked one inherits the site-wide
     * default (the legacy mdbk_queue_serial_mode option, kept working so
     * existing installs don't flip behavior on upgrade). With no doctor
     * given — or a $doctor_id that doesn't resolve to a setting — that
     * same default answers. display_ticket_number() is what every caller
     * actually asks for a number; it resolves the appointment's own
     * doctor internally.
     */
    public static function queue_serial_mode($doctor_id = 0) {
        if ($doctor_id) {
            $own = get_post_meta(intval($doctor_id), '_mdbk_queue_serial_mode', true);
            if ($own === 'checkin' || $own === 'booking') return $own;
        }
        return get_option('mdbk_queue_serial_mode', 'booking') === 'checkin' ? 'checkin' : 'booking';
    }

    /**
     * Best-effort soft lock around a critical section keyed by an arbitrary
     * string. Not a hard atomicity guarantee (no new table for a real
     * mutex) — good enough for realistic front-desk concurrency. Shared by
     * the slot-conflict-check + insert section, the auto-assign section,
     * and check-in-time ticket assignment (see their respective callers).
     * Public — MDBK_Admin_Dashboard::handle_appointment_save()'s edit
     * branch needs the same auto-assign locking handle_submission() uses
     * below, for a doctor whose picker is hidden (see that branch's own
     * comment for why it can't just delegate to handle_submission()).
     */
    public static function acquire_lock($key) {
        $key = 'mdbk_lock_' . md5($key);
        for ($i = 0; $i < 5; $i++) {
            if (false === get_transient($key)) {
                set_transient($key, 1, 10);
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    public static function release_lock($key) {
        delete_transient('mdbk_lock_' . md5($key));
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

        // A blank slot_time means the doctor's picker is hidden from
        // patients (is_slot_enabled() off) — nothing was picked, so a real
        // slot has to be found automatically. That resolution has to
        // happen INSIDE the lock (a doctor+date-wide one here, since there's
        // no specific slot to key on yet) or two concurrent hidden-picker
        // bookings for the same doctor+date could both resolve to the same
        // "next available" slot before either one exists in the DB to make
        // it taken. A picked slot keeps the original, narrower per-slot
        // lock so two different explicit picks never wait on each other.
        $lock_key = $slot_time !== ''
            ? 'slot|' . $doctor_id . '|' . $date . '|' . $slot_time
            : 'autoassign|' . $doctor_id . '|' . $date;

        if (!self::acquire_lock($lock_key)) {
            return new \WP_Error('mdbk_slot_locked', __('This slot is being booked by someone else right now. Please try again.', 'doctor-appointment'));
        }

        try {
            if ($slot_time === '') {
                $slot_time = self::find_next_available_slot($doctor_id, $date);
                if ($slot_time === '') {
                    return new \WP_Error('mdbk_no_slot', __('No available time could be assigned for this doctor on this date. Please choose a different date, or contact the clinic directly.', 'doctor-appointment'));
                }
            }

            // Still checked even for an auto-assigned slot — cheap
            // defense-in-depth against find_next_available_slot() having
            // read a snapshot that's since changed, on top of the lock
            // above.
            if (self::is_slot_taken($doctor_id, $date, $slot_time)) {
                return new \WP_Error('mdbk_slot_taken', __('That time slot is no longer available. Please choose another.', 'doctor-appointment'));
            }

            $patient_id = self::find_or_create_patient($name, $phone, [
                'email'  => $email,
                'age'    => isset($data['age']) ? $data['age'] : '',
                'gender' => isset($data['gender']) ? $data['gender'] : '',
                'address' => isset($data['address']) ? $data['address'] : '',
            ] + (isset($data['district']) ? [
                'district' => $data['district'],
                'thana'    => isset($data['thana']) ? $data['thana'] : '',
            ] : []));

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
            // Booking-order mode: assign the ticket right away, as before.
            // Check-in-order mode: leave it unassigned — mark_checked_in()
            // assigns it once the patient actually arrives, so the number
            // reflects check-in order instead of booking order.
            if (self::queue_serial_mode($doctor_id) !== 'checkin') {
                update_post_meta($appointment_id, '_mdbk_ticket_number', self::next_ticket_number($doctor_id, $date, $appointment_id));
            }
            // Token must exist before the status transition below, since that
            // transition synchronously fires the confirmation email, and the
            // email's check-in link needs a token to point at.
            update_post_meta($appointment_id, '_mdbk_checkin_token', self::generate_checkin_token());

            wp_update_post(['ID' => $appointment_id, 'post_status' => 'mdbk_waiting']);

            return $appointment_id;
        } finally {
            self::release_lock($lock_key);
        }
    }

    /**
     * AJAX: Handle Frontend Form Submission
     */
    public function ajax_handle_submission() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');

        $required = ['full_name', 'mobile', 'doctor', 'date'];
        // Slot time is only required from the patient when this doctor's
        // picker is actually shown to them — a hidden-picker doctor has no
        // time control on the frontend to produce a slot_time value at
        // all, so handle_submission() auto-assigns one server-side instead
        // (find_next_available_slot()).
        if (self::is_slot_enabled(isset($_POST['doctor']) ? intval($_POST['doctor']) : 0)) {
            $required[] = 'slot_time';
        }
        // Email/Gender join the plain presence check above ONLY when
        // Global Settings > Booking Form Fields turns them on — Age and
        // Address (District/Thana) can't use a plain "empty" test (age 0
        // is a real answer for an infant; a district has to be checked
        // against its thana, not just for presence), so those two are
        // validated separately below instead.
        if (self::is_field_required('email')) $required[] = 'email';
        if (self::is_field_required('gender')) $required[] = 'gender';
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

        // Editing an existing booking deliberately does NOT enforce either
        // of these (see handle_appointment_save()) — patients recorded
        // before these fields existed, or before a site turned a field's
        // requirement on, would otherwise be unsavable until someone
        // guessed a value for them.
        $age = isset($_POST['age']) ? trim(sanitize_text_field($_POST['age'])) : '';
        if (self::is_field_required('age')) {
            if (!self::is_valid_age($age)) {
                wp_send_json_error(__('Please enter the patient\'s age.', 'doctor-appointment'));
                return;
            }
        } elseif ($age !== '' && !self::is_valid_age($age)) {
            // Optional doesn't mean "accept garbage" — a value they did
            // type still has to be a real age.
            wp_send_json_error(__('Please enter a valid age.', 'doctor-appointment'));
            return;
        }

        $district = isset($_POST['district']) ? sanitize_text_field($_POST['district']) : '';
        $thana    = isset($_POST['thana']) ? sanitize_text_field($_POST['thana']) : '';
        $location_error = self::location_error($district, $thana, self::is_field_required('address'));
        if ($location_error) {
            wp_send_json_error($location_error);
            return;
        }

        $appointment_id = self::handle_submission($_POST);

        if (is_wp_error($appointment_id)) {
            wp_send_json_error($appointment_id->get_error_message());
        } elseif ($appointment_id) {
            $doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
            $date      = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
            $slot_time = get_post_meta($appointment_id, '_mdbk_slot_time', true);
            $ticket    = self::display_ticket_number($appointment_id);
            $token     = get_post_meta($appointment_id, '_mdbk_checkin_token', true);

            wp_send_json_success([
                'message'      => __('Appointment booked successfully! We will contact you soon.', 'doctor-appointment'),
                'ticket'       => self::format_ticket_number($ticket),
                // Always sent, cheap to compute — the frontend shows this
                // instead of "ticket" only when the latter is empty
                // (check-in-order queue mode, before this patient has
                // checked in).
                'booking_id'   => self::format_booking_id($appointment_id),
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
        // Only ever sent by the admin Add/Edit Booking modal (see
        // get_booked_slot_times()'s comment) — a public booker has no
        // appointment of their own yet to exclude.
        $exclude_id = isset($_POST['exclude_id']) ? intval($_POST['exclude_id']) : 0;

        wp_send_json_success(self::get_available_slots($doctor_id, $date, $exclude_id));
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
            // Matches the admin's own drag-and-drop Doctors order — this is
            // the booking form's Step 2 "Choose a Doctor" list.
            'orderby' => 'menu_order',
            'order' => 'ASC',
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

    /**
     * Today's full patient list for one doctor — shown in a modal from
     * the "Today's Patients" button on the frontend doctor card
     * (shortcode.php's render_doctor_list()/render_today_patients_modal()),
     * only when that doctor is actually working today AND the viewer is
     * logged in as staff/manager/admin/doctor (MDBK_CAP_QUEUE or
     * MDBK_CAP_DOCTOR — not public, per feedback). Names are fine to
     * return here now that this is staff-only — the same information is
     * already visible elsewhere in the admin to these same roles.
     */
    public function ajax_get_today_patient_summary() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');

        if (!current_user_can(MDBK_CAP_QUEUE) && !current_user_can(MDBK_CAP_DOCTOR)) {
            wp_send_json_error(__('You do not have permission to do this.', 'doctor-appointment'));
            return;
        }

        $doctor_id = intval($_POST['doctor_id'] ?? 0);
        if (!$doctor_id || get_post_type($doctor_id) !== 'mdbk_doctor') {
            wp_send_json_error(__('Doctor not found.', 'doctor-appointment'));
            return;
        }

        $today = current_time('Y-m-d');
        // Not sorted via a top-level 'meta_key' => '_mdbk_ticket_number' +
        // orderby arg — that combination turns into an implicit INNER JOIN
        // requiring the meta row to exist, silently DROPPING (not just
        // leaving unordered) every appointment with no ticket yet, which
        // is the normal state for a not-checked-in patient under check-in-
        // order queue mode (see queue_serial_mode()). Sorted in PHP after
        // fetching instead, same fix as render_queue_list_body()'s own
        // copy of this same gotcha in shortcode.php.
        $apps = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'numberposts' => -1,
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                ['key' => '_mdbk_appointment_date', 'value' => $today],
            ],
        ]);
        // Booking mode: ticket order, same as this list's own pre-existing
        // DB-level sort before the top-level meta_key was dropped above
        // (tie/both-blank falls back to slot time). Check-in mode:
        // checkin_order_sort_key() — checked-in patients lead in Q order,
        // everyone still pending follows in slot-time order (most rows
        // have no number at all there until their patient arrives).
        $checkin_mode = self::queue_serial_mode($doctor_id) === 'checkin';
        usort($apps, function($a, $b) use ($checkin_mode) {
            if ($checkin_mode) {
                return self::checkin_order_sort_key($a->ID) <=> self::checkin_order_sort_key($b->ID);
            }
            $ticket_a = intval(get_post_meta($a->ID, '_mdbk_ticket_number', true));
            $ticket_b = intval(get_post_meta($b->ID, '_mdbk_ticket_number', true));
            if ($ticket_a !== $ticket_b) return $ticket_a <=> $ticket_b;
            return strcmp(
                (string) get_post_meta($a->ID, '_mdbk_slot_time', true),
                (string) get_post_meta($b->ID, '_mdbk_slot_time', true)
            );
        });

        $counts = ['waiting' => 0, 'serving' => 0, 'completed' => 0, 'no_show' => 0];
        $patients = [];
        foreach ($apps as $a) {
            $display_slug = self::get_display_status_slug($a->ID);
            $count_key = str_replace('-', '_', self::post_status_to_slug($a->post_status));
            if (isset($counts[$count_key])) $counts[$count_key]++;

            $slot_time = get_post_meta($a->ID, '_mdbk_slot_time', true);
            $patients[] = [
                'ticket'       => self::format_ticket_number(self::display_ticket_number($a->ID)),
                // Shown by the JS in place of an empty ticket — no queue
                // number yet under check-in-order mode, until this patient
                // actually checks in (see checkin_ticket_number()).
                'booking_id'   => self::format_booking_id($a->ID),
                'patient_name' => get_post_meta($a->ID, '_mdbk_patient_name', true),
                'time'         => $slot_time ? date_i18n(get_option('time_format'), strtotime($slot_time)) : '',
                'status_slug'  => $display_slug,
                'status_label' => self::status_display_label($display_slug),
            ];
        }

        wp_send_json_success(array_merge(['total' => count($apps), 'patients' => $patients], $counts));
    }
}

new \MDBK\MDBK_Appointment_Manager();
