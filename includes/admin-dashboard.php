<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Admin_Dashboard {

    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_doctor_save']);
        add_action('admin_init', [$this, 'handle_appointment_save']);
        add_action('admin_init', [$this, 'handle_specialty_save']);
        add_action('admin_init', [$this, 'handle_patient_save']);
        add_action('admin_init', [$this, 'handle_delete_actions']);
        add_action('admin_init', [$this, 'handle_schedule_export']);
        add_action('wp_ajax_mdbk_toggle_doctor_active', [$this, 'ajax_toggle_doctor_active']);
        add_action('wp_ajax_mdbk_mark_visited', [$this, 'ajax_mark_visited']);
        add_action('wp_ajax_mdbk_admin_checkin', [$this, 'ajax_admin_checkin']);
        add_action('wp_ajax_mdbk_toggle_skip', [$this, 'ajax_toggle_skip']);
        add_filter('login_redirect', [$this, 'doctor_login_redirect'], 10, 3);
        add_filter('edit_profile_url', [$this, 'redirect_profile_url'], 10, 3);
        add_filter('admin_body_class', [$this, 'admin_body_class']);
    }

    /**
     * Points every "Edit Profile" link WP core generates (the admin
     * toolbar's top-right "Howdy, X" link and its dropdown) at this
     * plugin's own "Profile" page instead of wp-admin/profile.php — but
     * only for a pure doctor account (no manage_options); admin/other
     * roles keep WP's own profile screen untouched.
     */
    public function redirect_profile_url($url, $user_id, $scheme) {
        if (current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) {
            return admin_url('admin.php?page=mdbk-profile');
        }
        return $url;
    }

    /**
     * Sends a doctor straight to their restricted "My Queue" page on
     * login instead of the default wp-admin dashboard. Bails on a failed
     * login (don't redirect an error). WP's own login form always submits
     * a non-empty `redirect_to` (confirmed: it defaults to bare
     * admin_url()) — so "empty" is never actually the normal case, and
     * treating any non-empty value as "explicitly requested" would
     * silently disable this redirect for every ordinary login. Instead,
     * only a value that differs from wp-admin's own generic defaults
     * counts as a real deep link (e.g. a doctor returning to a specific
     * bookmarked admin URL after a session timeout) and is left alone.
     */
    public function doctor_login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (is_wp_error($user) || !($user instanceof \WP_User)) {
            return $redirect_to;
        }
        if (!in_array('mdbk_doctor_role', (array) $user->roles, true)) {
            return $redirect_to;
        }
        $generic_defaults = ['', 'wp-admin/', admin_url(), untrailingslashit(admin_url()) . '/'];
        if (!in_array($requested_redirect_to, $generic_defaults, true)) {
            return $redirect_to;
        }
        return admin_url('admin.php?page=mdbk-my-queue');
    }

    public function ajax_toggle_doctor_active() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        $doctor_id = isset($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        if (!$doctor_id || get_post_type($doctor_id) !== 'mdbk_doctor') wp_send_json_error(['message' => __('Invalid doctor.', 'doctor-appointment')]);
        $active = get_post_meta($doctor_id, '_mdbk_doctor_active', true) === 'no' ? 'yes' : 'no';
        update_post_meta($doctor_id, '_mdbk_doctor_active', $active);
        wp_send_json_success(['active' => $active === 'yes']);
    }

    /**
     * The first identity-checked AJAX handler in this plugin — every
     * existing queue action (mdbk_queue_set_status etc.) is deliberately
     * public/nopriv, nonce-only, with no ownership check anywhere. This
     * one is different on purpose: no `nopriv` variant (requires a real
     * WP login), and beyond the capability check it verifies the target
     * appointment actually belongs to *this* logged-in doctor (or the
     * user is a full admin) before allowing the status change — a doctor
     * must never be able to mark another doctor's patient as visited.
     */
    public function ajax_mark_visited() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Invalid appointment.', 'doctor-appointment')]);
        }

        $appointment_doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        if (!current_user_can('manage_options')) {
            $own_doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
            if (!$own_doctor_id || $own_doctor_id !== $appointment_doctor_id) {
                wp_send_json_error(['message' => __('You can only update your own patients.', 'doctor-appointment')]);
            }
        }

        // Matches the "Mark as Visited" button only appearing for today's
        // queue in render_my_queue_patient_row() — enforced here too, since
        // hiding a button client-side never actually stops a direct AJAX
        // call. A future booking hasn't happened yet; a past still-open one
        // (missed check-in) isn't "today's queue" either.
        $appointment_date = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        if ($appointment_date !== current_time('Y-m-d')) {
            wp_send_json_error(['message' => __('Only today\'s patients can be marked as visited.', 'doctor-appointment')]);
        }

        // Matches the button only appearing for checked-in patients in
        // render_my_queue_patient_row() — a patient who is already
        // mdbk_serving is exempt (they're actively being seen regardless
        // of whether they formally checked in via QR), but a still-waiting
        // one who hasn't arrived yet cannot be closed out from here.
        $is_serving = get_post_status($appointment_id) === 'mdbk_serving';
        $checked_in = get_post_meta($appointment_id, '_mdbk_checked_in', true) === 'yes';
        if (!$is_serving && !$checked_in) {
            wp_send_json_error(['message' => __('This patient has not checked in yet.', 'doctor-appointment')]);
        }

        wp_update_post(['ID' => $appointment_id, 'post_status' => 'mdbk_completed']);

        // Whoever was just marked visited might have been the one actively
        // "Visiting" (or might never have been called at all — a doctor can
        // mark a still-waiting patient visited directly) — either way, if
        // nobody's left "Visiting" for this doctor today, the earliest
        // remaining waiting patient (by ticket number) becomes the new one,
        // so the queue always shows who's next without a separate manual
        // "call next" step.
        if ($appointment_doctor_id) {
            \MDBK\MDBK_Appointment_Manager::promote_next_checked_in_patient($appointment_doctor_id);
        }

        // The whole today's-queue list, not just this one row — whoever
        // just got auto-promoted to "Visiting" needs their row updated
        // too, not only the one that was marked visited.
        wp_send_json_success(['fragment' => $this->render_today_queue_rows($appointment_doctor_id)]);
    }

    /**
     * Toggle a checked-in waiting patient's "Skip" flag on the doctor's
     * own "My Queue" page — for a patient who stepped away (toilet, phone
     * call) after checking in. While skipped,
     * MDBK_Appointment_Manager::promote_next_checked_in_patient() won't
     * auto-advance to them when the doctor marks the current patient
     * visited, but they keep their ticket/place in the list; the doctor
     * can still "Mark as Visited" them directly any time (unaffected by
     * this flag), or toggle it back off ("Recall") to rejoin auto-advance.
     * Same ownership/auth model as ajax_mark_visited() — no nopriv, and a
     * doctor can only toggle this on their own patients.
     */
    public function ajax_toggle_skip() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Invalid appointment.', 'doctor-appointment')]);
        }

        $appointment_doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        if (!current_user_can('manage_options')) {
            $own_doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
            if (!$own_doctor_id || $own_doctor_id !== $appointment_doctor_id) {
                wp_send_json_error(['message' => __('You can only update your own patients.', 'doctor-appointment')]);
            }
        }

        $appointment_date = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        if ($appointment_date !== current_time('Y-m-d')) {
            wp_send_json_error(['message' => __('Only today\'s patients can be skipped.', 'doctor-appointment')]);
        }
        if (get_post_status($appointment_id) !== 'mdbk_waiting' || get_post_meta($appointment_id, '_mdbk_checked_in', true) !== 'yes') {
            wp_send_json_error(['message' => __('Only a checked-in, waiting patient can be skipped.', 'doctor-appointment')]);
        }

        if (get_post_meta($appointment_id, '_mdbk_skipped', true) === 'yes') {
            delete_post_meta($appointment_id, '_mdbk_skipped');
        } else {
            update_post_meta($appointment_id, '_mdbk_skipped', 'yes');
        }

        // Same full-list return as ajax_mark_visited(), for a consistent
        // whole-list swap on the JS side (see admin-script.js).
        wp_send_json_success(['fragment' => $this->render_today_queue_rows($appointment_doctor_id)]);
    }

    /**
     * Staff/admin check-in straight from the Bookings page — bypasses the
     * QR token entirely, since whoever's looking at this list already has
     * the exact record in view. wp_ajax_ only (no nopriv — this is a
     * wp-admin-only action), gated on the same MDBK_CAP_QUEUE capability
     * as the Bookings page itself (not manage_options), so a receptionist
     * who can already see/act on this page can use the button too.
     */
    public function ajax_admin_checkin() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_QUEUE)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Invalid appointment.', 'doctor-appointment')]);
        }

        $result = \MDBK\MDBK_Appointment_Manager::mark_checked_in($appointment_id);
        if ($result !== true) {
            wp_send_json_error(['message' => $result]);
        }

        $show_doctor = isset($_POST['show_doctor']) && $_POST['show_doctor'] === '1';
        wp_send_json_success(['fragment' => $this->render_patient_appointment_row(get_post($appointment_id), $show_doctor)]);
    }

    public function handle_delete_actions() {
        if (!isset($_GET['action'])) return;
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) return;
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_delete_action');
        $redirect = '';
        if ($_GET['action'] === 'mdbk_delete_doctor') { wp_delete_post($id, true); $redirect = admin_url('admin.php?page=mdbk-doctors&deleted=1'); }
        elseif ($_GET['action'] === 'mdbk_delete_appointment') { wp_delete_post($id, true); $redirect = admin_url('admin.php?page=mdbk-schedule&deleted=1'); }
        elseif ($_GET['action'] === 'mdbk_delete_specialty') { wp_delete_term($id, 'mdbk_department'); $redirect = admin_url('admin.php?page=mdbk-specialties&deleted=1'); }
        elseif ($_GET['action'] === 'mdbk_delete_patient') { wp_delete_post($id, true); $redirect = admin_url('admin.php?page=mdbk-patients&deleted=1'); }
        if ($redirect) { wp_redirect($redirect); exit; }
    }

    public function handle_doctor_save() {
        if (!isset($_POST['mdbk_save_doctor'])) return;

        $doctor_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        $is_admin = current_user_can('manage_options');

        // A doctor (no manage_options) reaches this same form/handler via
        // the "My Profile" card on their own My Queue page (see
        // render_my_queue_page(), which reuses render_doctor_card()'s
        // Edit button unchanged) — they may only ever update their OWN
        // already-existing record, never create a new doctor or edit
        // someone else's.
        if (!$is_admin) {
            $own_doctor_id = $doctor_id ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id()) : 0;
            if (!$doctor_id || !$own_doctor_id || $own_doctor_id !== $doctor_id) {
                wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
            }
        }
        check_admin_referer('mdbk_save_doctor');

        // Redirect back to wherever this role actually has access — a
        // doctor editing their own profile has no manage_options and
        // would hit a capability wall on the admin Doctors grid.
        $redirect_page = $is_admin ? 'mdbk-doctors' : 'mdbk-profile';

        // Required (server-side, not just the form's HTML `required`
        // attribute) — a doctor's WP login account needs a real, unique
        // email, and this field previously had no validation at all.
        $email = sanitize_email($_POST['doc_email'] ?? '');
        if (!$email) {
            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&error=' . urlencode(__('Email is required for a doctor account.', 'doctor-appointment'))));
            exit;
        }

        $post_data = ['post_title' => sanitize_text_field($_POST['doc_name']), 'post_type' => 'mdbk_doctor', 'post_status' => 'publish'];
        if ($doctor_id) $post_data['ID'] = $doctor_id;
        $id = $doctor_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_doc_email', $email);
            update_post_meta($id, '_mdbk_doc_phone', sanitize_text_field($_POST['doc_phone']));
            update_post_meta($id, '_mdbk_doc_bio', sanitize_textarea_field($_POST['doc_bio']));
            update_post_meta($id, '_mdbk_show_phone', isset($_POST['show_phone']) ? 'yes' : 'no');
            update_post_meta($id, '_mdbk_show_email', isset($_POST['show_email']) ? 'yes' : 'no');
            if (!empty($_POST['slot_duration'])) update_post_meta($id, '_mdbk_slot_duration', intval($_POST['slot_duration']));
            update_post_meta($id, '_mdbk_slot_enabled', isset($_POST['slot_enabled']) ? 'yes' : 'no');
            if (isset($_POST['schedule'])) update_post_meta($id, '_mdbk_schedule', $_POST['schedule']);
            update_post_meta($id, '_mdbk_extra_dates', self::sanitize_date_list($_POST['extra_dates_json'] ?? ''));
            update_post_meta($id, '_mdbk_off_dates', self::sanitize_date_list($_POST['off_dates_json'] ?? ''));
            if (isset($_POST['specialty'])) wp_set_object_terms($id, [intval($_POST['specialty'])], 'mdbk_department');
            $photo_id = !empty($_POST['photo_id']) ? intval($_POST['photo_id']) : 0;
            if ($photo_id) { set_post_thumbnail($id, $photo_id); } else { delete_post_thumbnail($id); }

            // The doctor post's own fields are already saved above by this
            // point — a problem linking/creating the WP user account below
            // must never lose those edits, so this only ever redirects with
            // an error for the one case that's actually the admin's to fix
            // (an email that belongs to an unrelated, unlinked account);
            // any other failure just logs and the save still succeeds.
            $link_error = self::link_doctor_user($id, $email);
            if ($link_error) {
                wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&error=' . urlencode($link_error)));
                exit;
            }

            wp_redirect(admin_url('admin.php?page=' . $redirect_page . '&success=1'));
            exit;
        }
    }

    /**
     * Ensures $doctor_id has a real WP user account with the Doctor role,
     * creating one if needed. Returns a non-empty error message only when
     * the admin needs to change something (email belongs to an unrelated,
     * not-already-linked account) — any other failure (wp_insert_user()
     * erroring) is logged and swallowed so it never blocks the doctor save.
     */
    private static function link_doctor_user($doctor_id, $email) {
        $existing_user_id = (int) get_post_meta($doctor_id, '_mdbk_doctor_user_id', true);
        if ($existing_user_id && get_user_by('id', $existing_user_id)) {
            return '';
        }

        $user = get_user_by('email', $email);
        if ($user) {
            // Only reuse an account WE already linked to some doctor post —
            // otherwise this could be an unrelated person's account
            // (subscriber, editor, another admin) silently handed
            // doctor-level access to patient data via a typo'd email.
            $linked_elsewhere = get_posts([
                'post_type'   => 'mdbk_doctor',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_query'  => [['key' => '_mdbk_doctor_user_id', 'value' => $user->ID]],
            ]);
            if (!$linked_elsewhere) {
                return __('An account with this email already exists. Please use a different email for this doctor.', 'doctor-appointment');
            }
            if (!in_array('mdbk_doctor_role', $user->roles, true)) {
                $user->add_role('mdbk_doctor_role');
            }
            update_post_meta($doctor_id, '_mdbk_doctor_user_id', $user->ID);
            return '';
        }

        $new_user_id = wp_insert_user([
            'user_login' => self::generate_unique_username($email, 'mdbk_doctor'),
            'user_email' => $email,
            'user_pass'  => wp_generate_password(20, true),
            'role'       => 'mdbk_doctor_role',
        ]);

        if (is_wp_error($new_user_id)) {
            error_log('MDBK: failed to create doctor user account for doctor #' . $doctor_id . ': ' . $new_user_id->get_error_message());
            return '';
        }

        update_post_meta($doctor_id, '_mdbk_doctor_user_id', $new_user_id);
        // WP core's standard "your account" email — a password-*reset*
        // link, not a raw password, matching modern practice for
        // admin-created accounts.
        wp_new_user_notification($new_user_id, null, 'user');
        return '';
    }

    /**
     * A unique WP user_login derived from an email's local-part (falling
     * back to $prefix if sanitizing strips it to nothing), capped well
     * under WP's 60-char limit and de-duplicated with a bounded numeric
     * suffix loop — a run past 50 collisions falls back to a
     * timestamp-suffixed login rather than looping forever.
     */
    private static function generate_unique_username($email, $prefix) {
        $base = sanitize_user(current(explode('@', $email)), true);
        if (!$base) $base = $prefix;
        $base = substr($base, 0, 50);

        $login = $base;
        $suffix = 1;
        while (username_exists($login)) {
            $suffix++;
            $login = $base . $suffix;
            if ($suffix > 50) {
                $login = $base . '_' . substr((string) time(), -8);
                break;
            }
        }
        return $login;
    }

    /**
     * Decode + sanitize the JSON date array the extra/off-dates mini
     * calendars submit (built client-side in admin-script.js), keeping
     * only well-formed 'Y-m-d' strings — a hand-authored JSON hidden field
     * is as untrusted as any other POST value.
     */
    private function sanitize_date_list($json) {
        $dates = json_decode(stripslashes((string) $json), true);
        if (!is_array($dates)) return [];
        $valid = array_filter($dates, function($d) {
            if (!is_string($d)) return false;
            $parsed = \DateTime::createFromFormat('Y-m-d', $d);
            return $parsed && $parsed->format('Y-m-d') === $d;
        });
        return array_values(array_unique($valid));
    }

    public function handle_patient_save() {
        if (!isset($_POST['mdbk_save_patient'])) return;
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_patient');
        $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        $post_data = ['post_title' => sanitize_text_field($_POST['patient_name']), 'post_type' => 'mdbk_patient', 'post_status' => 'publish'];
        if ($patient_id) $post_data['ID'] = $patient_id;
        $id = $patient_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_patient_phone', sanitize_text_field($_POST['patient_phone']));
            update_post_meta($id, '_mdbk_patient_email', sanitize_email($_POST['patient_email']));
            update_post_meta($id, '_mdbk_patient_address', sanitize_textarea_field($_POST['patient_address']));
            update_post_meta($id, '_mdbk_patient_age', isset($_POST['patient_age']) ? sanitize_text_field($_POST['patient_age']) : '');
            update_post_meta($id, '_mdbk_patient_gender', isset($_POST['patient_gender']) ? sanitize_text_field($_POST['patient_gender']) : '');
            wp_redirect(admin_url('admin.php?page=mdbk-patients&success=1'));
            exit;
        }
    }

    public function handle_appointment_save() {
        if (!isset($_POST['mdbk_save_appointment'])) return;
        if (!current_user_can(MDBK_CAP_QUEUE)) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_appointment');

        $app_id = !empty($_POST['app_id']) ? intval($_POST['app_id']) : 0;

        if (!$app_id) {
            // New booking: reuse the same shared submission logic the
            // frontend uses, so patient linking / slot conflict checks /
            // ticket numbering all stay consistent in one place.
            $data = [
                'full_name' => $_POST['patient_name'],
                'mobile'    => $_POST['patient_phone'],
                'email'     => isset($_POST['patient_email']) ? $_POST['patient_email'] : '',
                'age'       => isset($_POST['age']) ? $_POST['age'] : '',
                'gender'    => isset($_POST['gender']) ? $_POST['gender'] : '',
                'doctor'    => $_POST['doctor_id'],
                'date'      => $_POST['app_date'],
                'slot_time' => isset($_POST['slot_time']) ? $_POST['slot_time'] : '',
            ];
            $id = \MDBK\MDBK_Appointment_Manager::handle_submission($data);
            if (is_wp_error($id)) {
                wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode($id->get_error_message())));
                exit;
            }
            // handle_submission() always creates as mdbk_waiting; honor whatever
            // status the receptionist picked in the form.
            $post_status = \MDBK\MDBK_Appointment_Manager::status_slug_to_post_status(sanitize_text_field($_POST['status']));
            if ($post_status !== 'mdbk_waiting') {
                wp_update_post(['ID' => $id, 'post_status' => $post_status]);
            }
            wp_redirect(admin_url('admin.php?page=mdbk-schedule&success=1'));
            exit;
        }

        // Editing an existing appointment.
        $p_name  = sanitize_text_field($_POST['patient_name']);
        $p_phone = sanitize_text_field($_POST['patient_phone']);
        $doctor_id = intval($_POST['doctor_id']);
        $date      = sanitize_text_field($_POST['app_date']);
        $slot_time = isset($_POST['slot_time']) ? sanitize_text_field($_POST['slot_time']) : '';
        $old_date  = get_post_meta($app_id, '_mdbk_appointment_date', true);
        $old_slot_time = get_post_meta($app_id, '_mdbk_slot_time', true);
        $old_doctor_id = intval(get_post_meta($app_id, '_mdbk_doctor_id', true));

        if (\MDBK\MDBK_Appointment_Manager::is_slot_taken($doctor_id, $date, $slot_time, $app_id)) {
            wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode(__('That time slot is already booked.', 'doctor-appointment'))));
            exit;
        }

        $p_email = isset($_POST['patient_email']) ? sanitize_email($_POST['patient_email']) : '';
        $p_age = isset($_POST['age']) ? sanitize_text_field($_POST['age']) : '';
        $p_gender = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : '';
        $patient_id = \MDBK\MDBK_Appointment_Manager::find_or_create_patient($p_name, $p_phone, ['email' => $p_email]);

        $post_status = \MDBK\MDBK_Appointment_Manager::status_slug_to_post_status(sanitize_text_field($_POST['status']));
        $id = wp_update_post(['ID' => $app_id, 'post_title' => "Booking: " . $p_name, 'post_status' => $post_status]);

        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_patient_id', $patient_id);
            update_post_meta($id, '_mdbk_patient_name', $p_name);
            update_post_meta($id, '_mdbk_patient_phone', $p_phone);
            // Was never persisted back onto the appointment on edit before —
            // find_or_create_patient() above updates the linked *patient*
            // record's email, but the appointment's own copy (what the
            // Bookings list actually displays) was silently left stale.
            update_post_meta($id, '_mdbk_patient_email', $p_email);
            update_post_meta($id, '_mdbk_patient_age', $p_age);
            update_post_meta($id, '_mdbk_patient_gender', $p_gender);
            update_post_meta($id, '_mdbk_doctor_id', $doctor_id);
            update_post_meta($id, '_mdbk_appointment_date', $date);
            update_post_meta($id, '_mdbk_slot_time', $slot_time);
            // A patient checked in for the old date/time shouldn't show as
            // checked-in for a rescheduled one. The check-in token itself
            // is a persistent identifier for the appointment and is left
            // alone — only the "has this happened yet" state resets.
            if ($date !== $old_date || $slot_time !== $old_slot_time) {
                delete_post_meta($id, '_mdbk_checked_in');
                delete_post_meta($id, '_mdbk_checkin_time');
            }
            // Reassign the ticket number when the date or doctor changed
            // (rescheduling), or when there was never one (legacy record).
            if (!get_post_meta($id, '_mdbk_ticket_number', true) || $date !== $old_date || $doctor_id !== $old_doctor_id) {
                update_post_meta($id, '_mdbk_ticket_number', \MDBK\MDBK_Appointment_Manager::next_ticket_number($doctor_id, $date, $id));
            }
            wp_redirect(admin_url('admin.php?page=mdbk-schedule&success=1'));
            exit;
        }
    }

    public function handle_specialty_save() {
        if (!isset($_POST['mdbk_save_specialty'])) return;
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_specialty');
        $term_id = !empty($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $name = sanitize_text_field($_POST['spec_name']);
        $term_id ? wp_update_term($term_id, 'mdbk_department', ['name' => $name]) : wp_insert_term($name, 'mdbk_department');
        wp_redirect(admin_url('admin.php?page=mdbk-specialties&success=1'));
        exit;
    }

    public function register_admin_menu() {
        // A single top-level "MedBook" entry, on purpose — no VISIBLE
        // WP-native dropdown/submenu. The plugin's own full-screen sidebar
        // (render_sidebar()) is the real navigation between every page
        // once inside; a native submenu here would just duplicate that
        // and (per feedback) read as clutter.
        //
        // Every other page below is registered as a REAL submenu of
        // 'mdbk-home' (not add_submenu_page(null, ...)) specifically so
        // WordPress's own get_admin_page_parent() lookup — which runs
        // unconditionally in wp-admin/menu-header.php right after the
        // 'parent_file' filter and overwrites whatever that filter
        // returned — naturally resolves back to 'mdbk-home' no matter
        // which of these pages is open, keeping the native "MedBook" item
        // highlighted the whole time you're anywhere in the plugin. The
        // dropdown list this would normally show is then hidden with pure
        // CSS (`#toplevel_page_mdbk-home .wp-submenu`, admin-style.css) —
        // registering with null parent looked like the "hidden page"
        // pattern used elsewhere in this file, but it silently breaks this
        // highlighting (those pages end up bucketed under an empty-string
        // pseudo-parent internally), which is why it doesn't work here.
        //
        // One click on "MedBook" itself routes by role: an admin lands on
        // the Dashboard, a doctor-only user lands on their My Queue — see
        // render_medbook_home(). doctor_login_redirect() and the custom
        // sidebar's own links still target 'mdbk-dashboard'/'mdbk-my-queue'
        // directly, unaffected by any of this.
        add_menu_page('MedBook', 'MedBook', MDBK_CAP_DOCTOR, 'mdbk-home', [$this, 'render_medbook_home'], 'dashicons-plus-alt', 25);
        add_submenu_page('mdbk-home', 'Dashboard', 'Dashboard', 'manage_options', 'mdbk-dashboard', [$this, 'render_dashboard']);
        add_submenu_page('mdbk-home', 'My Queue', 'My Queue', MDBK_CAP_DOCTOR, 'mdbk-my-queue', [$this, 'render_my_queue_page']);
        add_submenu_page('mdbk-home', 'Profile', 'Profile', MDBK_CAP_DOCTOR, 'mdbk-profile', [$this, 'render_profile_page']);

        // Queue/appointments — registered with its own MDBK_CAP_QUEUE
        // capability (not manage_options), so the front-desk role — which
        // has that capability but not manage_options — can still open it.
        add_submenu_page('mdbk-home', 'Booking', 'Booking', MDBK_CAP_QUEUE, 'mdbk-schedule', [$this, 'render_schedule_page']);

        $hidden_pages = ['mdbk-doctors' => 'render_doctors_page', 'mdbk-patients' => 'render_patients_page', 'mdbk-specialties' => 'render_specialties_page'];
        foreach($hidden_pages as $slug => $cb) add_submenu_page('mdbk-home', $slug, $slug, 'manage_options', $slug, [$this, $cb]);

        // "Chamber QR" — a one-time "print this and hang it in the
        // chamber" view per doctor, reached via a link on the Doctors
        // grid. Same MDBK_CAP_QUEUE gate as the Bookings page (not
        // manage_options), since a receptionist managing the queue should
        // be able to print/reprint one too.
        add_submenu_page('mdbk-home', 'mdbk-chamber-qr', 'mdbk-chamber-qr', MDBK_CAP_QUEUE, 'mdbk-chamber-qr', [$this, 'render_chamber_qr_page']);

        // A pure doctor account (MDBK_CAP_DOCTOR, no manage_options) now has
        // its own "Profile" page above — WP core's native profile.php link
        // is redundant with it and confusing to have both, so it's removed
        // from this role's sidebar entirely. index.php (WP's own Dashboard)
        // is removed for the same reason — a doctor never has any use for
        // it, everything they can do lives under "MedBook". Left untouched
        // for admin/other roles. get_edit_profile_url() is also filtered
        // (see redirect_profile_url()) so the admin toolbar's "Howdy" /
        // "Edit Profile" links point at this same page for a doctor too.
        // With both removed, "MedBook" is the ONLY native menu item this
        // role ever has left, which is also why the entire native sidebar
        // is hidden for them via CSS — see admin_body_class() below.
        if (current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) {
            remove_menu_page('profile.php');
            remove_menu_page('index.php');
        }
    }

    /**
     * Appends a body class so admin-style.css can hide the entire native
     * WP left-hand sidebar for a pure doctor account (no manage_options) —
     * the plugin's own full-screen sidebar (render_sidebar()) is already
     * the only navigation this role ever needs (My Queue / Profile), so
     * WP's native menu column would just be near-empty chrome around it.
     */
    public function admin_body_class($classes) {
        if (current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) {
            $classes .= ' mdbk-doctor-chrome';
        }
        return $classes;
    }

    /**
     * The single native "MedBook" top-level menu item's callback — routes
     * by role instead of showing a WP-native submenu: an admin sees the
     * full Dashboard, a doctor-only user (no manage_options) sees their
     * own My Queue. Keeps the native sidebar down to one clean entry per
     * user; all other navigation happens via the plugin's own in-page
     * sidebar (render_sidebar()).
     */
    public function render_medbook_home() {
        if (current_user_can('manage_options')) {
            $this->render_dashboard();
        } else {
            $this->render_my_queue_page();
        }
    }

    public function render_dashboard() {
        $appointment_count = array_sum(array_map(function($status) {
            return (int) wp_count_posts('mdbk_appointment')->$status;
        }, \MDBK\MDBK_CPT::APPOINTMENT_STATUSES));
        $stats = ['doctors' => wp_count_posts('mdbk_doctor')->publish, 'appointments' => $appointment_count, 'patients' => wp_count_posts('mdbk_patient')->publish];
        $today = current_time('Y-m-d');
        $today_apps = get_posts([
            'post_type'   => 'mdbk_appointment',
            'numberposts' => -1,
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'meta_query'  => [['key' => '_mdbk_appointment_date', 'value' => $today, 'compare' => '=']],
        ]);
        usort($today_apps, function($a, $b) {
            return strcmp(get_post_meta($a->ID, '_mdbk_slot_time', true), get_post_meta($b->ID, '_mdbk_slot_time', true));
        });
        $today_groups = $this->group_appointments_by_doctor($today_apps);
        // The grid is built from doctors scheduled to work today, not from
        // who happens to have a booking today — a doctor on shift with an
        // empty queue still gets a (empty) card; a doctor who isn't working
        // today doesn't get a card even if some stray booking landed on
        // their name.
        $working_today_doctors = array_filter(get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']), function($doc) use ($today) {
            return \MDBK\MDBK_Appointment_Manager::is_doctor_working_on($doc->ID, $today);
        });
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('dashboard'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Medical Overview', 'doctor-appointment'); ?></h1><p><?php _e('Track your daily operations.', 'doctor-appointment'); ?></p></div><div class="mdbk-header-right"><input type="text" class="mdbk-search-box" placeholder="<?php esc_attr_e('Quick search...', 'doctor-appointment'); ?>"><a href="#" class="mdbk-btn-add mdbk-add-appointment"><?php _e('+ New Booking', 'doctor-appointment'); ?></a></div></div>
                <div class="mdbk-stats-grid">
                    <div class="mdbk-stat-card mdbk-stat-card-blue">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.5 21a8.5 8.5 0 0 0-17 0"></path><circle cx="12" cy="7.5" r="4.5"></circle></svg></div>
                        <h4><?php _e('Doctors', 'doctor-appointment'); ?></h4>
                        <div class="value"><?php echo esc_html($stats['doctors']); ?></div>
                        <div class="trend"><?php _e('practitioners', 'doctor-appointment'); ?></div>
                    </div>
                    <div class="mdbk-stat-card mdbk-stat-card-violet">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                        <h4><?php _e('Patients', 'doctor-appointment'); ?></h4>
                        <div class="value"><?php echo esc_html($stats['patients']); ?></div>
                        <div class="trend"><?php _e('Total records', 'doctor-appointment'); ?></div>
                    </div>
                    <div class="mdbk-stat-card mdbk-stat-card-green">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
                        <h4><?php _e('Bookings', 'doctor-appointment'); ?></h4>
                        <div class="value"><?php echo esc_html($stats['appointments']); ?></div>
                        <div class="trend"><?php _e('Total processed', 'doctor-appointment'); ?></div>
                    </div>
                    <div class="mdbk-stat-card mdbk-stat-card-amber">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                        <h4><?php _e("Today's Bookings", 'doctor-appointment'); ?></h4>
                        <div class="value"><?php echo esc_html(count($today_apps)); ?></div>
                        <div class="trend"><?php echo esc_html(date_i18n('l, M j', strtotime($today))); ?></div>
                    </div>
                </div>

                <div class="mdbk-dashboard-grid-container">
                    <div class="mdbk-section-header">
                        <h3><?php _e("Patient Bookings", 'doctor-appointment'); ?></h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule')); ?>" class="mdbk-view-all-link"><?php _e('View All', 'doctor-appointment'); ?> &rarr;</a>
                    </div>

                    <?php if (empty($working_today_doctors)): ?>
                        <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding: 40px; opacity:0.6;"><?php _e('No doctors scheduled today.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                    <?php else: ?>
                        <div class="mdbk-dashboard-doctor-groups mdbk-dashboard-doctor-grid">
                        <?php foreach ($working_today_doctors as $doctor): $doc_id = $doctor->ID; $apps = isset($today_groups[$doc_id]) ? $today_groups[$doc_id]['appointments'] : []; $count = count($apps); ?>
                            <div class="mdbk-card mdbk-dash-doctor-card">
                                <div class="mdbk-card-header">
                                    <div>
                                        <h3><?php echo esc_html($doctor->post_title); ?></h3>
                                        <span class="mdbk-dash-card-date"><?php echo esc_html(date_i18n('l, M j', strtotime($today))); ?></span>
                                    </div>
                                    <span class="mdbk-badge mdbk-badge-green"><?php echo esc_html($count); ?></span>
                                </div>
                                <ul class="mdbk-dash-patient-list">
                                <?php if (empty($apps)): ?>
                                    <li class="mdbk-dash-patient-empty"><?php _e('No patients yet.', 'doctor-appointment'); ?></li>
                                <?php else: $queue_no = 0; foreach ($apps as $app): $queue_no++; $p_age = get_post_meta($app->ID, '_mdbk_patient_age', true); ?>
                                    <li class="mdbk-dash-patient-item">
                                        <span class="mdbk-patient-row-ticket mdbk-patient-row-queue">Q<?php echo esc_html(str_pad($queue_no, 2, '0', STR_PAD_LEFT)); ?></span>
                                        <span class="mdbk-dash-patient-name"><?php echo esc_html(get_post_meta($app->ID, '_mdbk_patient_name', true)); ?></span>
                                        <?php if ($p_age): ?><span class="mdbk-dash-patient-age"><?php echo esc_html(sprintf(__('%sy', 'doctor-appointment'), $p_age)); ?></span><?php endif; ?>
                                    </li>
                                <?php endforeach; endif; ?>
                                </ul>
                                <div class="mdbk-card-view-all"><a href="#" data-doctor-modal="mdbk-doctor-modal-<?php echo esc_attr($doc_id); ?>"><?php _e('View All', 'doctor-appointment'); ?> &rarr;</a></div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div></div>
            <?php foreach ($working_today_doctors as $doctor): $doc_id = $doctor->ID; $apps = isset($today_groups[$doc_id]) ? $today_groups[$doc_id]['appointments'] : []; ?>
            <div id="mdbk-doctor-modal-<?php echo esc_attr($doc_id); ?>" class="mdbk-modal mdbk-modal-compact mdbk-doctor-popup">
                <div class="mdbk-modal-content">
                    <div class="mdbk-modal-head">
                        <h2><?php echo esc_html(sprintf(__('%s — All Patients Today', 'doctor-appointment'), $doctor->post_title)); ?></h2>
                        <span class="mdbk-modal-close">&times;</span>
                    </div>
                    <div class="mdbk-modal-body">
                        <?php $this->render_today_queue_table($apps, false); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php $this->render_appointment_modal_html(); ?></div>
        <?php
    }

    public function render_doctors_page() {
        $doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $specialties = get_terms(['taxonomy' => 'mdbk_department', 'hide_empty' => false]);
        $total = count($doctors);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('doctors'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('Staff Management', 'doctor-appointment'); ?></h1></div>

                <div class="mdbk-staff-filters-bar">
                    <a href="#" class="mdbk-btn-add mdbk-add-doctor"><?php _e('+ Add New Doctor', 'doctor-appointment'); ?></a>
                    <div class="mdbk-staff-filters-controls">
                        <span class="mdbk-staff-count-badge" id="mdbk-doctor-count-badge"><?php echo esc_html(sprintf(__('Showing %1$d Doctors of %2$d Total', 'doctor-appointment'), min(9, $total), $total)); ?></span>
                        <div class="mdbk-staff-search-box">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="search" id="mdbk-doctor-search" placeholder="<?php esc_attr_e('Search doctors…', 'doctor-appointment'); ?>">
                        </div>
                        <label for="mdbk-doctor-filter-specialty" class="screen-reader-text"><?php _e('Specialty', 'doctor-appointment'); ?></label>
                        <select id="mdbk-doctor-filter-specialty">
                            <option value=""><?php _e('All Specialties', 'doctor-appointment'); ?></option>
                            <?php foreach ($specialties as $t): ?>
                                <option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mdbk-view-toggle" role="group" aria-label="<?php esc_attr_e('Switch view', 'doctor-appointment'); ?>">
                            <button type="button" class="mdbk-view-btn is-active" data-view="grid" title="<?php esc_attr_e('Grid view', 'doctor-appointment'); ?>">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect></svg>
                            </button>
                            <button type="button" class="mdbk-view-btn" data-view="list" title="<?php esc_attr_e('List view', 'doctor-appointment'); ?>">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mdbk-admin-doctor-grid" id="mdbk-admin-doctor-grid">
                    <?php if (empty($doctors)): ?>
                        <p class="mdbk-admin-doctor-empty"><?php _e('No doctors found.', 'doctor-appointment'); ?></p>
                    <?php else: foreach ($doctors as $d): echo $this->render_doctor_card($d); endforeach; endif; ?>
                </div>
                <p class="mdbk-admin-doctor-empty" id="mdbk-doctor-no-match" style="display:none;"><?php _e('No doctors match your search or filters.', 'doctor-appointment'); ?></p>

                <div class="mdbk-pagination" id="mdbk-doctor-pagination" style="display:none;">
                    <button type="button" class="mdbk-page-btn" id="mdbk-doctor-prev" aria-label="<?php esc_attr_e('Previous page', 'doctor-appointment'); ?>">&lsaquo;</button>
                    <div id="mdbk-doctor-page-numbers" style="display:flex;gap:8px;"></div>
                    <button type="button" class="mdbk-page-btn" id="mdbk-doctor-next" aria-label="<?php esc_attr_e('Next page', 'doctor-appointment'); ?>">&rsaquo;</button>
                </div>
            </div></div><?php $this->render_doctor_modal_html(); $this->render_doctor_view_modal_html(); ?></div>
        <?php
    }

    /**
     * "Chamber QR" — the printable page for one doctor's static walk-in
     * check-in QR code (same code for every patient; see
     * MDBK_Appointment_Manager::get_or_create_chamber_token()). A patient
     * scans it with their own phone and lands on the public check-in
     * landing page (MDBK_Shortcode::render_chamber_checkin_view()).
     */
    public function render_chamber_qr_page() {
        $doctor_id = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;
        $doctor = $doctor_id ? get_post($doctor_id) : null;
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('doctors'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('Chamber Check-In QR', 'doctor-appointment'); ?></h1></div>
                <?php if (!$doctor || $doctor->post_type !== 'mdbk_doctor') : ?>
                    <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('Doctor not found.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                <?php else:
                    $token = \MDBK\MDBK_Appointment_Manager::get_or_create_chamber_token($doctor_id);
                    $url = add_query_arg('mdbk_chamber', $token, home_url('/'));
                    ?>
                    <div class="mdbk-card" style="max-width:420px; text-align:center; padding:40px 30px;">
                        <h2 style="margin-top:0;"><?php echo esc_html($doctor->post_title); ?></h2>
                        <p style="opacity:0.7;"><?php _e('Print this and post it in the chamber — patients scan it with their own phone to check themselves in.', 'doctor-appointment'); ?></p>
                        <div id="mdbk-chamber-qr-img" data-checkin-url="<?php echo esc_attr($url); ?>" style="margin:24px auto; min-height:1px;"></div>
                        <button type="button" class="mdbk-btn-add" onclick="window.print()"><?php _e('Print', 'doctor-appointment'); ?></button>
                    </div>
                    <script>
                    // This inline block is printed as part of the admin page's
                    // own body content, which renders before wp-admin prints
                    // its footer scripts — so the enqueued qrcode.js isn't
                    // guaranteed to have run yet if this ran immediately.
                    // window 'load' always fires after every blocking script
                    // (including footer ones) has executed, regardless of
                    // where in the document this tag sits.
                    window.addEventListener('load', function() {
                        var el = document.getElementById('mdbk-chamber-qr-img');
                        if (el && typeof qrcode === 'function') {
                            var qr = qrcode(0, 'M');
                            qr.addData(el.getAttribute('data-checkin-url'));
                            qr.make();
                            el.innerHTML = qr.createImgTag(6, 4);
                        }
                    });
                    </script>
                <?php endif; ?>
            </div></div></div>
        <?php
    }

    // Reused for the initial page render and could be reused for an AJAX-refreshed
    // fragment later, so the markup only lives in one place.
    private function render_doctor_card($d) {
        $spec = get_the_terms($d->ID, 'mdbk_department');
        $spec_name = ($spec && !is_wp_error($spec)) ? $spec[0]->name : __('General', 'doctor-appointment');
        $spec_id = ($spec && !is_wp_error($spec)) ? $spec[0]->term_id : 0;
        $phone = get_post_meta($d->ID, '_mdbk_doc_phone', true);
        $email = get_post_meta($d->ID, '_mdbk_doc_email', true);
        $bio = get_post_meta($d->ID, '_mdbk_doc_bio', true);
        $show_phone = get_post_meta($d->ID, '_mdbk_show_phone', true);
        $show_email = get_post_meta($d->ID, '_mdbk_show_email', true);
        $schedule = get_post_meta($d->ID, '_mdbk_schedule', true);
        $slot_duration = get_post_meta($d->ID, '_mdbk_slot_duration', true);
        $slot_enabled = get_post_meta($d->ID, '_mdbk_slot_enabled', true);
        $extra_dates = get_post_meta($d->ID, '_mdbk_extra_dates', true);
        $off_dates = get_post_meta($d->ID, '_mdbk_off_dates', true);
        // Doctors default to active — the meta only ever gets written (to 'no')
        // the first time someone flips the card's toggle off.
        $active = get_post_meta($d->ID, '_mdbk_doctor_active', true) !== 'no';
        $thumb = get_the_post_thumbnail_url($d->ID, 'thumbnail');
        $thumb_id = get_post_thumbnail_id($d->ID);
        $colors = self::specialty_colors($spec_id);
        ob_start();
        ?>
        <div class="mdbk-admin-doctor-card<?php echo $active ? '' : ' is-inactive'; ?>" data-id="<?php echo esc_attr($d->ID); ?>" data-name="<?php echo esc_attr($d->post_title); ?>" data-email="<?php echo esc_attr($email); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-bio="<?php echo esc_attr($bio); ?>" data-show-phone="<?php echo esc_attr($show_phone ? $show_phone : 'yes'); ?>" data-show-email="<?php echo esc_attr($show_email ? $show_email : 'yes'); ?>" data-schedule='<?php echo esc_attr(json_encode($schedule)); ?>' data-slot-duration="<?php echo esc_attr($slot_duration ? $slot_duration : 20); ?>" data-slot-enabled="<?php echo esc_attr($slot_enabled === 'no' ? 'no' : 'yes'); ?>" data-extra-dates='<?php echo esc_attr(json_encode(is_array($extra_dates) ? $extra_dates : [])); ?>' data-off-dates='<?php echo esc_attr(json_encode(is_array($off_dates) ? $off_dates : [])); ?>' data-specialty="<?php echo esc_attr($spec_id); ?>" data-thumbnail="<?php echo esc_url($thumb ?: ''); ?>" data-thumbnail-id="<?php echo esc_attr($thumb_id ?: 0); ?>">
            <div class="mdbk-admin-doctor-card-avatar">
                <?php if ($thumb): ?>
                    <img src="<?php echo esc_url($thumb); ?>" alt="">
                <?php else: ?>
                    <?php echo esc_html(self::initials($d->post_title)); ?>
                <?php endif; ?>
            </div>
            <div class="mdbk-admin-doctor-card-body">
                <p class="mdbk-admin-doctor-card-name"><?php echo esc_html($d->post_title); ?></p>
                <span class="mdbk-admin-doctor-card-specialty" style="background:<?php echo esc_attr($colors['bg']); ?>;color:<?php echo esc_attr($colors['fg']); ?>;"><?php echo esc_html(mb_strtoupper($spec_name)); ?></span>
                <div class="mdbk-admin-doctor-card-contact">
                    <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($email ?: '—'); ?></span>
                    <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone ?: '—'); ?></span>
                </div>
            </div>
            <div class="mdbk-admin-doctor-card-footer">
                <?php // This card is also reused as a doctor's own read-only-ish "My
                // Profile" view on the My Queue page (see render_my_queue_page()) —
                // the destructive/administrative controls (active toggle, delete)
                // stay admin-only there; View + Edit remain so a doctor can see and
                // update their own info via the same modal admin uses. ?>
                <?php if (current_user_can('manage_options')) : ?>
                <div class="mdbk-admin-doctor-card-status">
                    <label class="mdbk-toggle mdbk-admin-doctor-active-toggle">
                        <input type="checkbox" <?php checked($active); ?>>
                        <span class="mdbk-toggle-slider"></span>
                        <span class="mdbk-admin-doctor-active-text"><?php echo $active ? esc_html__('Active', 'doctor-appointment') : esc_html__('Inactive', 'doctor-appointment'); ?></span>
                    </label>
                </div>
                <?php endif; ?>
                <div class="mdbk-admin-doctor-card-actions">
                    <a href="#" class="mdbk-icon-btn mdbk-view-doctor" data-id="<?php echo esc_attr($d->ID); ?>" title="<?php esc_attr_e('View', 'doctor-appointment'); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </a>
                    <a href="#" class="mdbk-icon-btn mdbk-edit-doctor" data-id="<?php echo esc_attr($d->ID); ?>" title="<?php esc_attr_e('Edit', 'doctor-appointment'); ?>">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg>
                    </a>
                    <?php if (current_user_can(MDBK_CAP_QUEUE)) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-chamber-qr&doctor_id=' . $d->ID)); ?>" class="mdbk-icon-btn" title="<?php esc_attr_e('Chamber QR', 'doctor-appointment'); ?>" target="_blank" rel="noopener">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect><line x1="14" y1="14" x2="14" y2="21"></line><line x1="21" y1="14" x2="21" y2="21"></line><line x1="14" y1="21" x2="21" y2="21"></line></svg>
                    </a>
                    <?php endif; ?>
                    <?php if (current_user_can('manage_options')) : ?>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-doctors&action=mdbk_delete_doctor&id=' . $d->ID), 'mdbk_delete_action')); ?>" class="mdbk-icon-btn mdbk-icon-btn-danger" title="<?php esc_attr_e('Delete', 'doctor-appointment'); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this doctor?', 'doctor-appointment')); ?>')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // First letter of up to the first two words of a name, e.g. "Dr. Sarah Khan" -> "SK".
    // Strips a leading "Dr."/"Dr" honorific first so the initials reflect the doctor's
    // actual name instead of always starting with "D" (every doctor's title here).
    private static function initials($name) {
        $name = preg_replace('/^dr\.?\s+/i', '', trim($name));
        $parts = preg_split('/\s+/', trim($name));
        $out = '';
        foreach (array_slice($parts, 0, 2) as $p) {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        }
        return $out !== '' ? $out : '?';
    }

    // Deterministic pastel pill color per specialty, so each one reads as a distinct,
    // consistent color across every doctor card with no color field to manage.
    private static function specialty_colors($term_id) {
        if (!$term_id) return ['bg' => '#F1F2F4', 'fg' => '#6B7280'];
        $palette = [
            ['bg' => '#EEF1FF', 'fg' => '#0061d5'],
            ['bg' => '#ECFDF5', 'fg' => '#16A34A'],
            ['bg' => '#FDF2F8', 'fg' => '#DB2777'],
            ['bg' => '#FFF7ED', 'fg' => '#EA580C'],
            ['bg' => '#F5F3FF', 'fg' => '#7C3AED'],
            ['bg' => '#ECFEFF', 'fg' => '#0891B2'],
        ];
        return $palette[crc32((string) $term_id) % count($palette)];
    }

    // Shared by the Dashboard widget and the Bookings page so a single day's
    // appointments always get organized into per-doctor sections the same
    // way in both places. Expects $apps already sorted the way callers want
    // patients ordered within each doctor's group (by slot time, normally).
    private function group_appointments_by_doctor($apps) {
        $groups = [];
        foreach ($apps as $a) {
            $doc_id = (int) get_post_meta($a->ID, '_mdbk_doctor_id', true);
            if (!isset($groups[$doc_id])) {
                $groups[$doc_id] = ['doctor' => $doc_id ? get_post($doc_id) : null, 'appointments' => []];
            }
            $groups[$doc_id]['appointments'][] = $a;
        }
        uasort($groups, function ($a, $b) {
            return strcasecmp($a['doctor']->post_title ?? '', $b['doctor']->post_title ?? '');
        });
        return $groups;
    }

    // Flat "today's queue" table shared by the dashboard's cross-doctor
    // "View All" popup and each per-doctor card's "View All" popup — the
    // only difference between the two is whether the Doctor column is
    // useful (it isn't when the popup is already scoped to one doctor).
    private function render_today_queue_table($apps, $show_doctor_column) {
        if (empty($apps)) {
            echo '<p style="text-align:center; opacity:0.6; padding:30px 0;">' . esc_html__('No bookings found.', 'doctor-appointment') . '</p>';
            return;
        }
        ?>
        <table class="mdbk-table">
            <thead><tr><th><?php _e('Queue', 'doctor-appointment'); ?></th><th><?php _e('Patient', 'doctor-appointment'); ?></th><?php if ($show_doctor_column): ?><th><?php _e('Doctor', 'doctor-appointment'); ?></th><?php endif; ?><th><?php _e('Age', 'doctor-appointment'); ?></th><th><?php _e('Time', 'doctor-appointment'); ?></th><th><?php _e('Status', 'doctor-appointment'); ?></th></tr></thead>
            <tbody>
            <?php $n = 0; foreach ($apps as $app): $n++; $t_doc_id = get_post_meta($app->ID, '_mdbk_doctor_id', true); $t_age = get_post_meta($app->ID, '_mdbk_patient_age', true); $t_slot = get_post_meta($app->ID, '_mdbk_slot_time', true); $t_status = \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($app)); ?>
                <tr>
                    <td><span class="mdbk-patient-row-ticket mdbk-patient-row-queue">Q<?php echo esc_html(str_pad($n, 2, '0', STR_PAD_LEFT)); ?></span></td>
                    <td><strong><?php echo esc_html(get_post_meta($app->ID, '_mdbk_patient_name', true)); ?></strong></td>
                    <?php if ($show_doctor_column): ?><td><?php echo $t_doc_id ? esc_html(get_the_title($t_doc_id)) : esc_html__('N/A', 'doctor-appointment'); ?></td><?php endif; ?>
                    <td><?php echo $t_age ? esc_html($t_age) : '—'; ?></td>
                    <td><?php echo esc_html($t_slot ?: '—'); ?></td>
                    <td><span class="mdbk-badge mdbk-badge-status-<?php echo esc_attr($t_status); ?>"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::status_display_label($t_status)); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // One patient's appointment, full contact/demographic details included —
    // phone, email, age/gender, and symptoms are all captured at booking time
    // (see MDBK_Appointment_Manager::handle_submission()) but the old plain
    // table only ever showed name/time/status. $show_doctor is only true in
    // the ungrouped "All Dates" view, where the doctor isn't already implied
    // by a card header.
    private function render_patient_appointment_row($a, $show_doctor = false) {
        $p_name = get_post_meta($a->ID, '_mdbk_patient_name', true);
        $phone = get_post_meta($a->ID, '_mdbk_patient_phone', true);
        $email = get_post_meta($a->ID, '_mdbk_patient_email', true);
        $age = get_post_meta($a->ID, '_mdbk_patient_age', true);
        $gender = get_post_meta($a->ID, '_mdbk_patient_gender', true);
        $symptoms = get_post_meta($a->ID, '_mdbk_symptoms', true);
        $doc_id = get_post_meta($a->ID, '_mdbk_doctor_id', true);
        $date = get_post_meta($a->ID, '_mdbk_appointment_date', true);
        $slot_time = get_post_meta($a->ID, '_mdbk_slot_time', true);
        $ticket = get_post_meta($a->ID, '_mdbk_ticket_number', true);
        $patient_id = get_post_meta($a->ID, '_mdbk_patient_id', true);
        $status = \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($a));
        $age_gender = trim($gender . ($age && $gender ? ' · ' : '') . $age);
        $gender_key = $gender ? strtolower($gender) : 'unknown';
        $app_spec_id = $doc_id ? (get_the_terms($doc_id, 'mdbk_department') ? get_the_terms($doc_id, 'mdbk_department')[0]->term_id : '') : '';
        // A "Check In" button takes over the status badge's slot (not a new
        // grid column — the row's grid-template-columns is fixed-width and
        // sized only for the icon-only Edit/Delete actions) for today's
        // still-waiting, not-yet-checked-in patients, so staff can check
        // someone in directly from this list without a QR token.
        $checked_in = get_post_meta($a->ID, '_mdbk_checked_in', true) === 'yes';
        $can_checkin = $status === 'waiting' && !$checked_in && $date === current_time('Y-m-d');
        ob_start();
        ?>
        <div class="mdbk-patient-row<?php echo $show_doctor ? ' mdbk-patient-row-has-doctor' : ''; ?> mdbk-status-<?php echo esc_attr($status); ?>" data-id="<?php echo esc_attr($a->ID); ?>" data-patient="<?php echo esc_attr($p_name); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-email="<?php echo esc_attr($email); ?>" data-age="<?php echo esc_attr($age); ?>" data-gender="<?php echo esc_attr($gender); ?>" data-doctor="<?php echo esc_attr($doc_id); ?>" data-specialty="<?php echo esc_attr($app_spec_id); ?>" data-date="<?php echo esc_attr($date); ?>" data-slot-time="<?php echo esc_attr($slot_time); ?>" data-status="<?php echo esc_attr($status); ?>">
            <span class="mdbk-patient-row-ticket-slot"><?php if ($ticket): ?><span class="mdbk-patient-row-ticket mdbk-patient-row-queue" title="<?php esc_attr_e('Queue number', 'doctor-appointment'); ?>">Q<?php echo esc_html(str_pad($ticket, 2, '0', STR_PAD_LEFT)); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-name"><?php echo esc_html($p_name); ?></span>
            <span class="mdbk-patient-row-ticket-slot"><?php if ($patient_id): ?><span class="mdbk-patient-row-ticket mdbk-patient-row-pid" title="<?php esc_attr_e('Patient ID', 'doctor-appointment'); ?>">P<?php echo esc_html($patient_id); ?></span><?php endif; ?></span>
            <?php if ($show_doctor): ?><span class="mdbk-patient-row-chip mdbk-chip-doctor"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.5 21a8.5 8.5 0 0 0-17 0"></path><circle cx="12" cy="7.5" r="4.5"></circle></svg> <?php echo $doc_id ? esc_html(get_the_title($doc_id)) : esc_html__('N/A', 'doctor-appointment'); ?></span><?php endif; ?>
            <span class="mdbk-patient-row-chip-slot"><?php if ($phone): ?><span class="mdbk-patient-row-chip mdbk-chip-phone"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($email): ?><span class="mdbk-patient-row-chip mdbk-chip-email"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($email); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($age_gender): ?><span class="mdbk-patient-row-chip mdbk-meta-pill mdbk-gender-<?php echo esc_attr($gender_key); ?>"><?php echo esc_html($age_gender); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-note-slot"><?php if ($symptoms): ?><span class="mdbk-patient-row-note" title="<?php echo esc_attr($symptoms); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="13" y2="17"></line></svg></span><?php endif; ?></span>
            <span class="mdbk-patient-row-spacer"></span>
            <span class="mdbk-patient-row-time"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><span class="mdbk-patient-row-time-label"><?php esc_html_e('Visit', 'doctor-appointment'); ?></span><span class="mdbk-patient-row-time-value"><?php echo esc_html($slot_time ?: '—'); ?></span></span>
            <?php if ($can_checkin) : ?>
                <button type="button" class="mdbk-btn-add mdbk-btn-sm mdbk-admin-checkin-btn" data-id="<?php echo esc_attr($a->ID); ?>"><?php _e('Check In', 'doctor-appointment'); ?></button>
            <?php else : ?>
                <span class="mdbk-badge mdbk-badge-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::status_display_label($status)); ?></span>
            <?php endif; ?>
            <div class="mdbk-actions">
                <a href="#" class="mdbk-action-btn mdbk-edit-appointment" data-id="<?php echo esc_attr($a->ID); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg></a>
                <?php if (current_user_can('manage_options')) : ?>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-schedule&action=mdbk_delete_appointment&id='.$a->ID), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // GET filters shared by the Bookings page itself and the CSV export
    // handler, so the exported file always matches whatever's on screen.
    private function parse_schedule_filters() {
        $filter_doctor = isset($_GET['filter_doctor']) ? intval($_GET['filter_doctor']) : 0;
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';

        // Date filter defaults to TODAY when the page is opened fresh (no
        // filter_date in the URL at all). An explicit but empty filter_date
        // (the "All Dates" link below) is a deliberate opt-out back to the
        // original unscoped, ungrouped view — distinct from "not set yet".
        $has_date_param = isset($_GET['filter_date']);
        $raw_date = $has_date_param ? sanitize_text_field($_GET['filter_date']) : '';
        $valid_date = function ($str) {
            $d = \DateTime::createFromFormat('Y-m-d', $str);
            return $d && $d->format('Y-m-d') === $str;
        };
        if ($raw_date && $valid_date($raw_date)) {
            $filter_date = $raw_date;
        } elseif (!$has_date_param) {
            $filter_date = current_time('Y-m-d');
        } else {
            $filter_date = '';
        }

        return [$filter_date, $filter_doctor, $filter_status];
    }

    private function get_filtered_appointments($filter_date, $filter_doctor, $filter_status) {
        // Bug: get_posts() with no post_status defaults to 'publish' — none
        // of mdbk_waiting/mdbk_serving/mdbk_completed/mdbk_no_show is ever
        // 'publish', so this silently returned zero rows regardless of how
        // many bookings existed. Always pass the explicit status list.
        $args = [
            'post_type'   => 'mdbk_appointment',
            'numberposts' => -1,
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
        ];
        if (!$filter_date) {
            // "All Dates": most recent bookings first, same as before.
            $args['meta_key'] = '_mdbk_appointment_date';
            $args['orderby']  = 'meta_value';
            $args['order']    = 'DESC';
        }
        $meta_query = [];
        if ($filter_date) $meta_query[] = ['key' => '_mdbk_appointment_date', 'value' => $filter_date];
        if ($filter_doctor) $meta_query[] = ['key' => '_mdbk_doctor_id', 'value' => $filter_doctor];
        if (count($meta_query) > 1) $meta_query = array_merge(['relation' => 'AND'], $meta_query);
        if ($meta_query) $args['meta_query'] = $meta_query;
        if ($filter_status) {
            $mapped_status = \MDBK\MDBK_Appointment_Manager::status_slug_to_post_status($filter_status);
            if (in_array($mapped_status, \MDBK\MDBK_CPT::APPOINTMENT_STATUSES, true)) {
                $args['post_status'] = [$mapped_status];
            }
        }

        $apps = get_posts($args);
        if ($filter_date) {
            // Scoped to one day: sort by time-of-day (that day's real queue
            // order) in PHP after fetching, not via a top-level 'meta_key'
            // WP_Query arg — that combination silently turns into an
            // implicit INNER JOIN requiring the meta row to exist, dropping
            // any appointment with no _mdbk_slot_time value entirely instead
            // of just sorting it last.
            usort($apps, function($a, $b) {
                return strcmp(get_post_meta($a->ID, '_mdbk_slot_time', true), get_post_meta($b->ID, '_mdbk_slot_time', true));
            });
        }
        return $apps;
    }

    /**
     * CSV export of the Bookings page's current filtered list — same
     * filters (date/doctor/status), same rows, just downloadable instead
     * of on-screen. Runs on admin_init (before any page HTML) since it
     * needs to send its own Content-Type/Content-Disposition headers.
     */
    public function handle_schedule_export() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'mdbk-schedule' || !isset($_GET['mdbk_export'])) return;
        if (!current_user_can(MDBK_CAP_QUEUE)) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_export_csv');

        list($filter_date, $filter_doctor, $filter_status) = $this->parse_schedule_filters();
        $apps = $this->get_filtered_appointments($filter_date, $filter_doctor, $filter_status);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bookings-' . ($filter_date ?: 'all-dates') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Queue', 'Patient ID', 'Patient Name', 'Phone', 'Email', 'Age', 'Gender', 'Doctor', 'Date', 'Time', 'Status', 'Symptoms']);
        foreach ($apps as $a) {
            $doc_id = get_post_meta($a->ID, '_mdbk_doctor_id', true);
            $ticket = get_post_meta($a->ID, '_mdbk_ticket_number', true);
            fputcsv($out, [
                $ticket ? 'Q' . str_pad($ticket, 2, '0', STR_PAD_LEFT) : '',
                get_post_meta($a->ID, '_mdbk_patient_id', true),
                get_post_meta($a->ID, '_mdbk_patient_name', true),
                get_post_meta($a->ID, '_mdbk_patient_phone', true),
                get_post_meta($a->ID, '_mdbk_patient_email', true),
                get_post_meta($a->ID, '_mdbk_patient_age', true),
                get_post_meta($a->ID, '_mdbk_patient_gender', true),
                $doc_id ? get_the_title($doc_id) : '',
                get_post_meta($a->ID, '_mdbk_appointment_date', true),
                get_post_meta($a->ID, '_mdbk_slot_time', true),
                \MDBK\MDBK_Appointment_Manager::status_display_label(\MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($a))),
                get_post_meta($a->ID, '_mdbk_symptoms', true),
            ]);
        }
        fclose($out);
        exit;
    }

    public function render_schedule_page() {
        list($filter_date, $filter_doctor, $filter_status) = $this->parse_schedule_filters();
        $apps = $this->get_filtered_appointments($filter_date, $filter_doctor, $filter_status);
        $all_doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1]);

        // Prev/Today/Next + "All Dates" nav links, carrying forward whichever
        // doctor/status filters are already active.
        $nav_args = ['page' => 'mdbk-schedule'];
        if ($filter_doctor) $nav_args['filter_doctor'] = $filter_doctor;
        if ($filter_status) $nav_args['filter_status'] = $filter_status;
        $day_url = function ($date) use ($nav_args) {
            return add_query_arg(array_merge($nav_args, ['filter_date' => $date]), admin_url('admin.php'));
        };
        $all_dates_url = add_query_arg(array_merge($nav_args, ['filter_date' => '']), admin_url('admin.php'));
        $export_url = wp_nonce_url(add_query_arg(array_merge($nav_args, ['filter_date' => $filter_date, 'mdbk_export' => 'csv']), admin_url('admin.php')), 'mdbk_export_csv');
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('schedule'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Patient Bookings', 'doctor-appointment'); ?></h1><p><?php echo $filter_date ? esc_html(date_i18n('l, F j, Y', strtotime($filter_date))) : esc_html__('All dates', 'doctor-appointment'); ?> <span class="mdbk-total-count">&middot; <?php echo esc_html(sprintf(_n('%d patient', '%d patients', count($apps), 'doctor-appointment'), count($apps))); ?></span></p></div>
                <div class="mdbk-header-right">
                    <button type="button" class="mdbk-btn-outline" onclick="window.print()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg><?php _e('Print', 'doctor-appointment'); ?></button>
                    <a href="<?php echo esc_url($export_url); ?>" class="mdbk-btn-outline"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg><?php _e('Export CSV', 'doctor-appointment'); ?></a>
                    <a href="#" class="mdbk-btn-add mdbk-add-appointment"><?php _e('+ New Booking', 'doctor-appointment'); ?></a>
                </div>
                </div>

                <div class="mdbk-filters-bar">
                    <form method="get" class="mdbk-filters-form">
                        <input type="hidden" name="page" value="mdbk-schedule">
                        <?php if ($filter_date): ?>
                        <div class="mdbk-date-nav-group">
                            <a href="<?php echo esc_url($day_url(date('Y-m-d', strtotime($filter_date . ' -1 day')))); ?>" class="mdbk-date-nav-btn" aria-label="<?php esc_attr_e('Previous day', 'doctor-appointment'); ?>">&lsaquo;</a>
                            <a href="<?php echo esc_url($day_url(current_time('Y-m-d'))); ?>" class="mdbk-date-nav-btn mdbk-date-nav-today"><?php _e('Today', 'doctor-appointment'); ?></a>
                            <a href="<?php echo esc_url($day_url(date('Y-m-d', strtotime($filter_date . ' +1 day')))); ?>" class="mdbk-date-nav-btn" aria-label="<?php esc_attr_e('Next day', 'doctor-appointment'); ?>">&rsaquo;</a>
                            <input type="date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>" class="mdbk-input mdbk-date-nav-input" onchange="this.form.submit()">
                        </div>
                        <span class="mdbk-filters-divider"></span>
                        <?php endif; ?>
                        <select name="filter_doctor">
                            <option value=""><?php _e('All Doctors', 'doctor-appointment'); ?></option>
                            <?php foreach ($all_doctors as $d) : ?>
                                <option value="<?php echo esc_attr($d->ID); ?>" <?php selected($filter_doctor, $d->ID); ?>><?php echo esc_html($d->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="filter_status">
                            <option value=""><?php _e('All Statuses', 'doctor-appointment'); ?></option>
                            <option value="waiting" <?php selected($filter_status, 'waiting'); ?>><?php _e('Waiting', 'doctor-appointment'); ?></option>
                            <option value="serving" <?php selected($filter_status, 'serving'); ?>><?php _e('Visiting', 'doctor-appointment'); ?></option>
                            <option value="completed" <?php selected($filter_status, 'completed'); ?>><?php _e('Completed', 'doctor-appointment'); ?></option>
                            <option value="no-show" <?php selected($filter_status, 'no-show'); ?>><?php _e('No Show', 'doctor-appointment'); ?></option>
                        </select>
                        <button type="submit" class="mdbk-btn-add mdbk-btn-sm"><?php _e('Filter', 'doctor-appointment'); ?></button>
                        <div class="mdbk-filters-spacer"></div>
                        <?php if ($filter_doctor || $filter_status) : ?>
                            <a href="<?php echo esc_url(add_query_arg(['page' => 'mdbk-schedule', 'filter_date' => $filter_date], admin_url('admin.php'))); ?>" class="mdbk-date-nav-all"><?php _e('Clear', 'doctor-appointment'); ?></a>
                        <?php endif; ?>
                        <?php if ($filter_date) : ?>
                            <a href="<?php echo esc_url($all_dates_url); ?>" class="mdbk-date-nav-all"><?php _e('All Dates', 'doctor-appointment'); ?></a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($filter_date && empty($apps)): ?>
                    <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php echo esc_html(sprintf(__('No bookings for %s.', 'doctor-appointment'), date_i18n('l, F j, Y', strtotime($filter_date)))); ?></td></tr></tbody></table></div>
                <?php elseif ($filter_date): $schedule_groups = $this->group_appointments_by_doctor($apps); ?>
                    <div class="mdbk-schedule-doctor-groups">
                    <?php foreach ($schedule_groups as $doc_id => $group): $doctor = $group['doctor']; ?>
                    <details class="mdbk-card mdbk-schedule-doctor-card" open>
                        <summary class="mdbk-card-header mdbk-schedule-doctor-summary">
                            <div>
                                <h3><?php echo $doctor ? esc_html($doctor->post_title) : esc_html__('Unassigned', 'doctor-appointment'); ?></h3>
                                <span class="mdbk-dash-card-date"><?php echo esc_html(date_i18n('l, M j', strtotime($filter_date))); ?></span>
                            </div>
                            <div class="mdbk-schedule-doctor-summary-right">
                                <span class="mdbk-badge mdbk-badge-green"><?php echo esc_html(count($group['appointments'])); ?></span>
                                <a href="#" class="mdbk-schedule-doctor-viewall" data-doctor-modal="mdbk-schedule-doctor-modal-<?php echo esc_attr($doc_id); ?>"><?php _e('View All', 'doctor-appointment'); ?></a>
                                <span class="mdbk-schedule-doctor-chevron"></span>
                            </div>
                        </summary>
                        <div class="mdbk-patient-list">
                        <?php foreach ($group['appointments'] as $a): echo $this->render_patient_appointment_row($a, false); ?>
                        <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endforeach; ?>
                    </div>
                    <?php foreach ($schedule_groups as $doc_id => $group): $doctor = $group['doctor']; $doc_export_url = wp_nonce_url(add_query_arg(['page' => 'mdbk-schedule', 'filter_date' => $filter_date, 'filter_doctor' => $doc_id, 'mdbk_export' => 'csv'], admin_url('admin.php')), 'mdbk_export_csv'); ?>
                    <div id="mdbk-schedule-doctor-modal-<?php echo esc_attr($doc_id); ?>" class="mdbk-modal mdbk-modal-compact mdbk-doctor-popup">
                        <div class="mdbk-modal-content">
                            <div class="mdbk-modal-head">
                                <h2><?php echo esc_html(sprintf(__('%1$s — %2$s', 'doctor-appointment'), $doctor ? $doctor->post_title : __('Unassigned', 'doctor-appointment'), date_i18n('l, F j, Y', strtotime($filter_date)))); ?></h2>
                                <div class="mdbk-modal-head-actions">
                                    <button type="button" class="mdbk-icon-btn mdbk-print-modal" title="<?php esc_attr_e('Print', 'doctor-appointment'); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg></button>
                                    <a href="<?php echo esc_url($doc_export_url); ?>" class="mdbk-icon-btn" title="<?php esc_attr_e('Export CSV', 'doctor-appointment'); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg></a>
                                    <span class="mdbk-modal-close">&times;</span>
                                </div>
                            </div>
                            <div class="mdbk-modal-body">
                                <?php $this->render_today_queue_table($group['appointments'], false); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="mdbk-card">
                    <?php if ($apps): ?>
                    <div class="mdbk-patient-list">
                    <?php foreach ($apps as $a): echo $this->render_patient_appointment_row($a, true); ?>
                    <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('No bookings found.', 'doctor-appointment'); ?></td></tr></tbody></table>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div></div><?php $this->render_appointment_modal_html(); ?></div>
        <?php
    }

    /**
     * The doctor-restricted dashboard — the ONLY page a `mdbk_doctor_role`
     * user can reach (see register_admin_menu(), which gates this on
     * MDBK_CAP_DOCTOR alone, and the login_redirect filter which sends
     * them straight here). Always resolves the doctor from the CURRENT
     * user's own link (MDBK_Appointment_Manager::get_doctor_id_for_user())
     * — deliberately never from a $_GET override — so there is no URL
     * parameter a doctor could tamper with to see another doctor's queue
     * or patients. An administrator previewing this page (they also carry
     * MDBK_CAP_DOCTOR) sees a plain "not linked to a doctor" notice instead
     * of a fatal error, since they have no doctor link of their own.
     */
    public function render_my_queue_page() {
        $doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('my-queue'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('My Queue', 'doctor-appointment'); ?></h1></div></div>
                <?php if (!$doctor_id): ?>
                    <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('This account is not linked to a doctor profile.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                <?php else: ?>
                    <?php
                    // The separate Live Queue widget (public "arrival board"
                    // style, MDBK_Shortcode::render_queue_list_instance())
                    // used to sit here too, but it was just a second,
                    // differently-styled view of the exact same waiting/
                    // serving state "Today's Queue" below already shows per
                    // row (see render_my_queue_patient_row()'s status badge)
                    // — removed as redundant rather than kept in sync twice.
                    //
                    // Today's queue is priority-sorted (see
                    // get_today_queue_apps()) rather than plain ticket
                    // order — every other date stays a plain patient-ID
                    // ordered history/record list.
                    $today_apps = $this->get_today_queue_apps($doctor_id);
                    $today = current_time('Y-m-d');
                    $all_apps = $this->get_filtered_appointments(null, $doctor_id, '');
                    $other_apps = array_values(array_filter($all_apps, function($a) use ($today) {
                        return get_post_meta($a->ID, '_mdbk_appointment_date', true) !== $today;
                    }));
                    ?>

                    <div class="mdbk-card" style="margin-bottom:20px;">
                        <div class="mdbk-card-header"><h3><?php _e("Today's Queue", 'doctor-appointment'); ?></h3></div>
                        <?php if ($today_apps): ?>
                        <div class="mdbk-patient-list" id="mdbk-today-queue-list">
                            <?php foreach ($today_apps as $a): echo $this->render_my_queue_patient_row($a); ?>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('No patients in queue today.', 'doctor-appointment'); ?></td></tr></tbody></table>
                        <?php endif; ?>
                    </div>

                    <div class="mdbk-card">
                        <div class="mdbk-card-header"><h3><?php _e('Patient List', 'doctor-appointment'); ?></h3></div>
                        <?php if ($other_apps): ?>
                        <div class="mdbk-patient-list">
                            <?php foreach ($other_apps as $a): echo $this->render_my_queue_patient_row($a); ?>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('No other visits yet.', 'doctor-appointment'); ?></td></tr></tbody></table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div></div></div>
        <?php
    }

    /**
     * The doctor's own "Profile" page — a real page (not a modal, not a
     * card widget), read-only info shown as the default view, same fields
     * as the admin Doctors grid's "View" modal (avatar, specialty,
     * contact, bio, weekly/monthly availability). The "Edit" button opens
     * the SAME mdbk-doctor-modal/form the admin Doctors grid uses — its
     * populate callback (admin-script.js) reads data-* attributes off the
     * nearest .mdbk-admin-doctor-card OR .mdbk-profile-view ancestor, so
     * this page's wrapper carries the identical data-* set a card would,
     * purely to feed that populate function; the visible content below it
     * is plain server-rendered PHP, not re-derived from those attributes.
     * Doctor-only (MDBK_CAP_DOCTOR) — same "not linked" fallback as My
     * Queue for an admin previewing this page with no doctor link of
     * their own.
     */
    public function render_profile_page() {
        $doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
        $doctor = $doctor_id ? get_post($doctor_id) : null;
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('profile'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('My Profile', 'doctor-appointment'); ?></h1></div></div>
                <?php if (!$doctor): ?>
                    <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('This account is not linked to a doctor profile.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                <?php else:
                    $email = get_post_meta($doctor_id, '_mdbk_doc_email', true);
                    $phone = get_post_meta($doctor_id, '_mdbk_doc_phone', true);
                    $bio = get_post_meta($doctor_id, '_mdbk_doc_bio', true);
                    $show_phone = get_post_meta($doctor_id, '_mdbk_show_phone', true);
                    $show_email = get_post_meta($doctor_id, '_mdbk_show_email', true);
                    $schedule = get_post_meta($doctor_id, '_mdbk_schedule', true);
                    if (!is_array($schedule)) $schedule = [];
                    $slot_duration = get_post_meta($doctor_id, '_mdbk_slot_duration', true) ?: 20;
                    $slot_enabled = get_post_meta($doctor_id, '_mdbk_slot_enabled', true);
                    $extra_dates = get_post_meta($doctor_id, '_mdbk_extra_dates', true);
                    if (!is_array($extra_dates)) $extra_dates = [];
                    $off_dates = get_post_meta($doctor_id, '_mdbk_off_dates', true);
                    if (!is_array($off_dates)) $off_dates = [];
                    $thumb = get_the_post_thumbnail_url($doctor_id, 'thumbnail');
                    $thumb_id = get_post_thumbnail_id($doctor_id);
                    $spec = get_the_terms($doctor_id, 'mdbk_department');
                    $spec_name = ($spec && !is_wp_error($spec)) ? $spec[0]->name : __('General', 'doctor-appointment');
                    $spec_id = ($spec && !is_wp_error($spec)) ? $spec[0]->term_id : 0;
                    $colors = self::specialty_colors($spec_id);
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    $format_dates = function($dates) {
                        $dates = $dates;
                        sort($dates);
                        return implode(', ', array_map(function($d) { return date_i18n('M j, Y', strtotime($d)); }, $dates));
                    };
                    ?>
                    <div class="mdbk-card mdbk-profile-view" style="padding:24px;"
                        data-id="<?php echo esc_attr($doctor_id); ?>"
                        data-name="<?php echo esc_attr($doctor->post_title); ?>"
                        data-email="<?php echo esc_attr($email); ?>"
                        data-phone="<?php echo esc_attr($phone); ?>"
                        data-bio="<?php echo esc_attr($bio); ?>"
                        data-show-phone="<?php echo esc_attr($show_phone ? $show_phone : 'yes'); ?>"
                        data-show-email="<?php echo esc_attr($show_email ? $show_email : 'yes'); ?>"
                        data-schedule='<?php echo esc_attr(json_encode($schedule)); ?>'
                        data-slot-duration="<?php echo esc_attr($slot_duration); ?>"
                        data-slot-enabled="<?php echo esc_attr($slot_enabled === 'no' ? 'no' : 'yes'); ?>"
                        data-extra-dates='<?php echo esc_attr(json_encode($extra_dates)); ?>'
                        data-off-dates='<?php echo esc_attr(json_encode($off_dates)); ?>'
                        data-specialty="<?php echo esc_attr($spec_id); ?>"
                        data-thumbnail="<?php echo esc_url($thumb ?: ''); ?>"
                        data-thumbnail-id="<?php echo esc_attr($thumb_id ?: 0); ?>">
                        <div class="mdbk-view-top-row">
                            <div class="mdbk-view-hero">
                                <div class="mdbk-view-avatar">
                                    <?php if ($thumb): ?><img src="<?php echo esc_url($thumb); ?>" alt=""><?php else: ?><?php echo esc_html(self::initials($doctor->post_title)); ?><?php endif; ?>
                                </div>
                                <div class="mdbk-view-hero-info">
                                    <h3><?php echo esc_html($doctor->post_title); ?></h3>
                                    <span class="mdbk-admin-doctor-card-specialty" style="background:<?php echo esc_attr($colors['bg']); ?>;color:<?php echo esc_attr($colors['fg']); ?>;"><?php echo esc_html(mb_strtoupper($spec_name)); ?></span>
                                </div>
                            </div>
                            <div class="mdbk-view-col">
                                <div class="mdbk-view-field"><label><?php _e('Email', 'doctor-appointment'); ?></label><span><?php echo esc_html($email ?: '—'); ?></span></div>
                                <div class="mdbk-view-field"><label><?php _e('Phone', 'doctor-appointment'); ?></label><span><?php echo esc_html($phone ?: '—'); ?></span></div>
                                <div class="mdbk-view-field"><label><?php _e('Slot Duration', 'doctor-appointment'); ?></label><span><?php echo esc_html($slot_duration); ?> <?php _e('min', 'doctor-appointment'); ?></span></div>
                            </div>
                        </div>
                        <div class="mdbk-view-field mdbk-view-field-full"><label><?php _e('Bio', 'doctor-appointment'); ?></label><span><?php echo esc_html($bio ?: '—'); ?></span></div>
                        <details class="mdbk-availability-section" open>
                            <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><h4><?php _e('Weekly Availability', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                            <div class="mdbk-view-schedule-list">
                                <div class="mdbk-view-day-row mdbk-view-day-header"><span><?php _e('Day', 'doctor-appointment'); ?></span><span><?php _e('Hours', 'doctor-appointment'); ?></span></div>
                                <?php foreach ($days as $day): $d = $schedule[$day] ?? null; $working = $d && !empty($d['active']); ?>
                                <div class="mdbk-view-day-row<?php echo $working ? '' : ' is-off'; ?>">
                                    <span class="mdbk-view-day-name"><?php echo esc_html($day); ?></span>
                                    <span class="mdbk-view-day-hours"><?php if ($working): ?><?php echo esc_html(($d['from'] ?: '—') . ' – ' . ($d['to'] ?: '—')); ?><?php else: ?><span class="mdbk-view-day-off"><?php _e('Off', 'doctor-appointment'); ?></span><?php endif; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php if ($extra_dates || $off_dates): ?>
                        <details class="mdbk-availability-section">
                            <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><circle cx="12" cy="15" r="2"></circle></svg><h4><?php _e('Monthly Availability', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                            <div class="mdbk-view-schedule-list">
                                <?php if ($extra_dates): ?><div class="mdbk-view-day-row"><span class="mdbk-view-day-name"><?php _e('Extra Working Dates', 'doctor-appointment'); ?></span><span class="mdbk-view-day-hours"><?php echo esc_html($format_dates($extra_dates)); ?></span></div><?php endif; ?>
                                <?php if ($off_dates): ?><div class="mdbk-view-day-row"><span class="mdbk-view-day-name"><?php _e('Off Dates', 'doctor-appointment'); ?></span><span class="mdbk-view-day-hours"><?php echo esc_html($format_dates($off_dates)); ?></span></div><?php endif; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        <div style="margin-top:20px;">
                            <a href="#" class="mdbk-btn-add mdbk-edit-doctor" data-id="<?php echo esc_attr($doctor_id); ?>"><?php _e('Edit Profile', 'doctor-appointment'); ?></a>
                        </div>
                    </div>
                    <?php $this->render_doctor_modal_html(); ?>
                <?php endif; ?>
            </div></div></div>
        <?php
    }

    /**
     * One row in the doctor's own "My Patients" list — same visual
     * language as render_patient_appointment_row(), but the status badge
     * sits alongside a "Mark as Visited" button (only for appointments
     * still waiting/visiting; already-closed ones just show a plain
     * label, no button) instead of the Edit/Delete admin actions. The
     * Waiting/Visiting badge here is what used to live only in the
     * separate Live Queue widget above this list — that widget was
     * removed as redundant (see render_my_queue_page()) once this row
     * itself could show the same waiting-vs-serving state directly.
     */
    /**
     * All of one doctor's today's-queue rows, rendered together — shared
     * by the initial page render (render_my_queue_page()) and the
     * "Mark as Visited" / Skip AJAX handlers, so a click that closes out
     * or promotes a DIFFERENT row (via
     * MDBK_Appointment_Manager::promote_next_checked_in_patient()) is
     * reflected immediately without a page reload. Returning only the
     * single clicked row's own fragment (the previous behavior) left the
     * newly auto-promoted patient's row showing stale state in the
     * browser until the page was refreshed. Mirrors render_my_queue_page()'s
     * own $today_apps computation exactly, so the AJAX-refreshed list
     * never drifts from what a fresh page load would show.
     */
    private function render_today_queue_rows($doctor_id) {
        $html = '';
        foreach ($this->get_today_queue_apps($doctor_id) as $a) {
            $html .= $this->render_my_queue_patient_row($a);
        }
        return $html;
    }

    /**
     * One doctor's today's-queue appointments, priority-sorted rather
     * than plain ticket order: whoever's currently being seen ("Visiting")
     * leads, then checked-in patients still waiting their turn, then
     * anyone not yet checked in, then no-shows, with already-closed-out
     * visits ("Visited") sinking to the bottom — a doctor scanning this
     * list cares most about who's active right now and least about who's
     * already done. Ticket number breaks ties within each group. Shared
     * by the initial page render and render_today_queue_rows() (the
     * AJAX-refresh fragment for Mark as Visited / Skip), so the order
     * never drifts between the two.
     */
    private function get_today_queue_apps($doctor_id) {
        $today = current_time('Y-m-d');
        $all_apps = $this->get_filtered_appointments(null, $doctor_id, '');
        $today_apps = array_values(array_filter($all_apps, function($a) use ($today) {
            return get_post_meta($a->ID, '_mdbk_appointment_date', true) === $today;
        }));

        $rank = function($app) {
            $status = \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($app));
            if ($status === 'serving') return 0;
            $checked_in = get_post_meta($app->ID, '_mdbk_checked_in', true) === 'yes';
            if ($status === 'waiting' && $checked_in) return 1;
            if ($status === 'waiting') return 2;
            if ($status === 'no-show') return 3;
            return 4; // completed
        };
        usort($today_apps, function($a, $b) use ($rank) {
            $rank_a = $rank($a);
            $rank_b = $rank($b);
            if ($rank_a !== $rank_b) return $rank_a <=> $rank_b;
            $ticket_a = intval(get_post_meta($a->ID, '_mdbk_ticket_number', true));
            $ticket_b = intval(get_post_meta($b->ID, '_mdbk_ticket_number', true));
            return $ticket_a <=> $ticket_b;
        });

        return $today_apps;
    }

    private function render_my_queue_patient_row($a) {
        $p_name = get_post_meta($a->ID, '_mdbk_patient_name', true);
        $phone = get_post_meta($a->ID, '_mdbk_patient_phone', true);
        $email = get_post_meta($a->ID, '_mdbk_patient_email', true);
        $date = get_post_meta($a->ID, '_mdbk_appointment_date', true);
        $slot_time = get_post_meta($a->ID, '_mdbk_slot_time', true);
        $ticket = get_post_meta($a->ID, '_mdbk_ticket_number', true);
        $patient_id = get_post_meta($a->ID, '_mdbk_patient_id', true);
        $status = \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($a));
        // A ticket/queue number is only meaningful within the day it was
        // issued for (next_ticket_number() counts per doctor+date) — this
        // list spans every date, so a past visit's "Q03" would just be
        // that old day's position, confusingly mixed in with today's live
        // queue order. Past/other-day rows show the patient's own ID
        // instead; only today's still-in-progress rows show the queue
        // number.
        $is_today = $date === current_time('Y-m-d');
        $checked_in = get_post_meta($a->ID, '_mdbk_checked_in', true) === 'yes';
        // "Mark as Visited" only makes sense for today's queue — a future
        // booking hasn't happened yet, and a past still-waiting/serving
        // row (missed check-in, never closed out) shouldn't be closeable
        // from here either. Those show a plain "Upcoming" badge instead
        // (matched server-side too, in ajax_mark_visited()). A waiting
        // patient who hasn't checked in also can't be visited yet — they
        // haven't arrived — but an already-mdbk_serving one is exempt
        // (they're actively being seen regardless of QR check-in).
        $can_visit = $is_today && ($status === 'serving' || ($status === 'waiting' && $checked_in));
        // Checked in but temporarily stepped away (toilet, phone call,
        // etc.) — set via the Skip toggle below. Excluded from
        // MDBK_Appointment_Manager::promote_next_checked_in_patient()'s
        // auto-advance while set, so the doctor marking the current
        // patient visited doesn't call someone who isn't in the room; the
        // doctor can still "Mark as Visited" them directly any time
        // (unaffected by this flag), or toggle it off ("Recall") to
        // rejoin auto-advance.
        $skipped = get_post_meta($a->ID, '_mdbk_skipped', true) === 'yes';
        $can_skip = $is_today && $status === 'waiting' && $checked_in;
        // Full-row color/accent — only for today's actionable states
        // (currently being seen, or checked in and waiting); every other
        // row (not checked in, skipped, upcoming, already closed out)
        // stays plain — the badge in the action slot already covers
        // those, a full-row tint on all of them read as too much.
        if ($is_today && $status === 'serving') {
            $row_state = 'serving';
        } elseif ($is_today && $status === 'waiting' && $checked_in && !$skipped) {
            $row_state = 'waiting-checked-in';
        } else {
            $row_state = '';
        }
        $date_display = $date ? date_i18n('M j, Y', strtotime($date)) : '—';
        $time_display = $slot_time ? date_i18n('g:i A', strtotime($slot_time)) : '—';
        ob_start();
        ?>
        <div class="mdbk-patient-row mdbk-my-queue-row<?php echo $row_state ? ' mdbk-row-state-' . esc_attr($row_state) : ''; ?>" data-id="<?php echo esc_attr($a->ID); ?>">
            <span class="mdbk-patient-row-ticket-slot">
                <?php if ($is_today && $ticket) : ?>
                    <span class="mdbk-patient-row-ticket mdbk-patient-row-queue" title="<?php esc_attr_e('Queue number', 'doctor-appointment'); ?>">Q<?php echo esc_html(str_pad($ticket, 2, '0', STR_PAD_LEFT)); ?></span>
                <?php elseif (!$is_today && $patient_id) : ?>
                    <span class="mdbk-patient-row-ticket mdbk-patient-row-pid" title="<?php esc_attr_e('Patient ID', 'doctor-appointment'); ?>">P<?php echo esc_html($patient_id); ?></span>
                <?php endif; ?>
            </span>
            <span class="mdbk-patient-row-name"><?php echo esc_html($p_name); ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($phone): ?><span class="mdbk-patient-row-chip mdbk-chip-phone"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($email): ?><span class="mdbk-patient-row-chip mdbk-chip-email"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($email); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-spacer"></span>
            <span class="mdbk-patient-row-date-col">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <span class="mdbk-patient-row-time-label"><?php esc_html_e('Date', 'doctor-appointment'); ?></span>
                <span class="mdbk-patient-row-time-value"><?php echo esc_html($date_display); ?></span>
            </span>
            <span class="mdbk-patient-row-time-col">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <span class="mdbk-patient-row-time-label"><?php esc_html_e('Time', 'doctor-appointment'); ?></span>
                <span class="mdbk-patient-row-time-value"><?php echo esc_html($time_display); ?></span>
            </span>
            <div class="mdbk-my-queue-actions">
                <?php if ($can_skip): ?>
                    <button type="button" class="mdbk-btn-sm mdbk-btn-skip mdbk-toggle-skip<?php echo $skipped ? ' is-skipped' : ''; ?>" data-id="<?php echo esc_attr($a->ID); ?>" title="<?php echo $skipped ? esc_attr__('Recall to queue', 'doctor-appointment') : esc_attr__('Skip — temporarily away', 'doctor-appointment'); ?>">
                        <?php if ($skipped) : ?>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                            <?php esc_html_e('Recall', 'doctor-appointment'); ?>
                        <?php else : ?>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v4l2.5 2.5"></path></svg>
                            <?php esc_html_e('Skip', 'doctor-appointment'); ?>
                        <?php endif; ?>
                    </button>
                <?php endif; ?>
                <?php if ($can_visit): ?>
                    <span class="mdbk-badge mdbk-badge-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::status_display_label($status)); ?></span>
                    <button type="button" class="mdbk-btn-add mdbk-btn-sm mdbk-mark-visited" data-id="<?php echo esc_attr($a->ID); ?>"><?php _e('Mark as Visited', 'doctor-appointment'); ?></button>
                <?php elseif (!$is_today && in_array($status, ['waiting', 'serving'], true)): ?>
                    <span class="mdbk-badge mdbk-badge-upcoming"><?php _e('Upcoming', 'doctor-appointment'); ?></span>
                <?php elseif ($is_today && $status === 'waiting' && !$checked_in): ?>
                    <span class="mdbk-badge mdbk-badge-not-checked-in"><?php _e('Not Checked In', 'doctor-appointment'); ?></span>
                <?php else: ?>
                    <span class="mdbk-badge mdbk-badge-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::status_display_label($status)); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // One row in the Patient Directory — same visual language as the
    // Bookings page's patient row (ticket-style ID badge, icon chips,
    // slot-based grid for alignment) but for patient-level fields
    // (phone/email/address, no per-visit queue/time/status), plus a
    // derived "total visits" count instead of a stored field.
    private function render_patient_directory_row($p) {
        $phone = get_post_meta($p->ID, '_mdbk_patient_phone', true);
        $email = get_post_meta($p->ID, '_mdbk_patient_email', true);
        $address = get_post_meta($p->ID, '_mdbk_patient_address', true);
        $age = get_post_meta($p->ID, '_mdbk_patient_age', true);
        $gender = get_post_meta($p->ID, '_mdbk_patient_gender', true);
        $age_gender = trim($gender . ($age && $gender ? ' · ' : '') . $age);
        $gender_key = $gender ? strtolower($gender) : 'unknown';
        $visit_count = count(get_posts([
            'post_type'   => 'mdbk_appointment',
            'numberposts' => -1,
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'fields'      => 'ids',
            'meta_query'  => [['key' => '_mdbk_patient_id', 'value' => $p->ID]],
        ]));
        ob_start();
        ?>
        <div class="mdbk-patient-row mdbk-patient-row-directory" data-id="<?php echo esc_attr($p->ID); ?>" data-name="<?php echo esc_attr($p->post_title); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-email="<?php echo esc_attr($email); ?>" data-address="<?php echo esc_attr($address); ?>" data-age="<?php echo esc_attr($age); ?>" data-gender="<?php echo esc_attr($gender); ?>">
            <span class="mdbk-patient-row-ticket-slot"><span class="mdbk-patient-row-ticket mdbk-patient-row-pid" title="<?php esc_attr_e('Patient ID', 'doctor-appointment'); ?>">P<?php echo esc_html($p->ID); ?></span></span>
            <span class="mdbk-patient-row-name"><?php echo esc_html($p->post_title); ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($phone): ?><span class="mdbk-patient-row-chip mdbk-chip-phone"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($email): ?><span class="mdbk-patient-row-chip mdbk-chip-email"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($email); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($address): ?><span class="mdbk-patient-row-chip mdbk-chip-address" title="<?php echo esc_attr($address); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> <?php echo esc_html($address); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($age_gender): ?><span class="mdbk-patient-row-chip mdbk-meta-pill mdbk-gender-<?php echo esc_attr($gender_key); ?>"><?php echo esc_html($age_gender); ?></span><?php endif; ?></span>
            <span class="mdbk-badge mdbk-badge-green" title="<?php esc_attr_e('Total visits', 'doctor-appointment'); ?>"><?php echo esc_html($visit_count); ?></span>
            <div class="mdbk-actions">
                <a href="#" class="mdbk-action-btn mdbk-edit-patient" data-id="<?php echo esc_attr($p->ID); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-patients&action=mdbk_delete_patient&id='.$p->ID), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_patients_page() {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $filter_gender = isset($_GET['filter_gender']) ? sanitize_text_field($_GET['filter_gender']) : '';

        $patients = get_posts(['post_type' => 'mdbk_patient', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);

        // Filtered in PHP rather than via WP_Query — 's' only matches
        // post_title for a CPT like this, and phone/email live in postmeta,
        // so a real name/phone/email search needs to look across all three.
        if ($search || $filter_gender) {
            $patients = array_values(array_filter($patients, function($p) use ($search, $filter_gender) {
                if ($filter_gender && get_post_meta($p->ID, '_mdbk_patient_gender', true) !== $filter_gender) return false;
                if ($search) {
                    $haystack = $p->post_title . ' ' . get_post_meta($p->ID, '_mdbk_patient_phone', true) . ' ' . get_post_meta($p->ID, '_mdbk_patient_email', true);
                    if (stripos($haystack, $search) === false) return false;
                }
                return true;
            }));
        }
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('patients'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Patient Directory', 'doctor-appointment'); ?></h1><p><?php echo esc_html(sprintf(_n('%d patient', '%d patients', count($patients), 'doctor-appointment'), count($patients))); ?></p></div><a href="#" class="mdbk-btn-add mdbk-add-patient"><?php _e('+ Add Patient', 'doctor-appointment'); ?></a></div>

                <div class="mdbk-filters-bar">
                    <form method="get" class="mdbk-filters-form">
                        <input type="hidden" name="page" value="mdbk-patients">
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search name, phone, or email...', 'doctor-appointment'); ?>" class="mdbk-filters-search">
                        <select name="filter_gender">
                            <option value=""><?php _e('All Genders', 'doctor-appointment'); ?></option>
                            <option value="Male" <?php selected($filter_gender, 'Male'); ?>><?php _e('Male', 'doctor-appointment'); ?></option>
                            <option value="Female" <?php selected($filter_gender, 'Female'); ?>><?php _e('Female', 'doctor-appointment'); ?></option>
                            <option value="Other" <?php selected($filter_gender, 'Other'); ?>><?php _e('Other', 'doctor-appointment'); ?></option>
                        </select>
                        <button type="submit" class="mdbk-btn-add mdbk-btn-sm"><?php _e('Filter', 'doctor-appointment'); ?></button>
                        <div class="mdbk-filters-spacer"></div>
                        <?php if ($search || $filter_gender) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-patients')); ?>" class="mdbk-date-nav-all"><?php _e('Clear', 'doctor-appointment'); ?></a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (empty($patients)): ?>
                    <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php echo ($search || $filter_gender) ? esc_html__('No patients match your search.', 'doctor-appointment') : esc_html__('No patients yet.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                <?php else: ?>
                    <div class="mdbk-card mdbk-directory-card">
                        <div class="mdbk-patient-row mdbk-patient-row-directory mdbk-directory-list-header">
                            <span><?php _e('ID', 'doctor-appointment'); ?></span>
                            <span><?php _e('Name', 'doctor-appointment'); ?></span>
                            <span><?php _e('Phone', 'doctor-appointment'); ?></span>
                            <span><?php _e('Email', 'doctor-appointment'); ?></span>
                            <span><?php _e('Address', 'doctor-appointment'); ?></span>
                            <span><?php _e('Age/Gender', 'doctor-appointment'); ?></span>
                            <span><?php _e('Visits', 'doctor-appointment'); ?></span>
                            <span></span>
                        </div>
                        <div class="mdbk-patient-list mdbk-directory-list">
                        <?php foreach ($patients as $p) echo $this->render_patient_directory_row($p); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div></div><?php $this->render_patient_modal_html(); ?></div>
        <?php
    }

    public function render_specialties_page() {
        $terms = get_terms(['taxonomy' => 'mdbk_department', 'hide_empty' => false]);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('specialties'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('Medical Specialties', 'doctor-appointment'); ?></h1><a href="#" class="mdbk-btn-add mdbk-add-specialty"><?php _e('+ Add Specialty', 'doctor-appointment'); ?></a></div>
                <div class="mdbk-card"><table class="mdbk-table"><thead><tr><th><?php _e('Name', 'doctor-appointment'); ?></th><th><?php _e('Count', 'doctor-appointment'); ?></th><th><?php _e('Actions', 'doctor-appointment'); ?></th></tr></thead><tbody>
                <?php foreach($terms as $t): ?><tr data-id="<?php echo esc_attr($t->term_id); ?>" data-name="<?php echo esc_attr($t->name); ?>"><td><strong><?php echo esc_html($t->name); ?></strong></td><td><?php echo esc_html($t->count); ?> <?php _e('doctors', 'doctor-appointment'); ?></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-specialty" data-id="<?php echo esc_attr($t->term_id); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg></a><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-specialties&action=mdbk_delete_specialty&id='.$t->term_id), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg></a></div></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div></div><?php $this->render_specialty_modal_html(); ?></div>
        <?php
    }

    private function render_sidebar($active_page) {
        ?>
        <div class="mdbk-sidebar"><div class="mdbk-sidebar-logo">MedBook</div><ul class="mdbk-sidebar-menu">
            <?php if (current_user_can('manage_options')) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'dashboard' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-dashboard')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg><?php _e('Dashboard', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'doctors' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-doctors')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.5 21a8.5 8.5 0 0 0-17 0"></path><circle cx="12" cy="7.5" r="4.5"></circle></svg><?php _e('Doctors', 'doctor-appointment'); ?></li>
            <?php endif; ?>
            <?php if (current_user_can(MDBK_CAP_QUEUE)) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'schedule' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><?php _e('Booking', 'doctor-appointment'); ?></li>
            <?php endif; ?>
            <?php // Admin technically has MDBK_CAP_DOCTOR too (granted alongside every
            // custom cap, so an admin previewing mdbk-my-queue directly doesn't hit a
            // capability wall) — but this nav item is the doctor's own menu, not
            // admin's, so it's hidden here for anyone who also has manage_options. ?>
            <?php if (current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'my-queue' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-my-queue')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><?php _e('My Queue', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'profile' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-profile')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php _e('Profile', 'doctor-appointment'); ?></li>
            <?php endif; ?>
            <?php if (current_user_can('manage_options')) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'patients' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-patients')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg><?php _e('Patients', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'specialties' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-specialties')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg><?php _e('Specialties', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg><?php _e('Global Settings', 'doctor-appointment'); ?></li>
            <?php endif; ?>
        </ul><div class="mdbk-sidebar-footer"><div class="mdbk-user-avatar"></div><div class="mdbk-user-info"><div style="font-weight: 700; font-size: 13px;"><?php echo esc_html(wp_get_current_user()->display_name); ?></div><div style="font-size: 11px; opacity: 0.6;"><?php _e('Medical Center', 'doctor-appointment'); ?></div></div></div></div>
        <?php
    }

    private function render_doctor_modal_html() { ?>
        <div id="mdbk-doctor-modal" class="mdbk-modal mdbk-modal-compact"><div class="mdbk-modal-content">
            <div class="mdbk-modal-head"><h2 id="mdbk-doctor-modal-title"><?php _e('Add Doctor', 'doctor-appointment'); ?></h2><span class="mdbk-modal-close">&times;</span></div>
            <form id="mdbk-doctor-form" method="POST"><?php wp_nonce_field('mdbk_save_doctor'); ?><input type="hidden" name="doctor_id" id="mdbk-doctor-id"><input type="hidden" name="photo_id" id="mdbk-doc-photo-id" value="0">
            <div class="mdbk-modal-body">
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label"><?php _e('Photo', 'doctor-appointment'); ?></label>
                    <div class="mdbk-photo-picker">
                        <div class="mdbk-photo-preview" id="mdbk-doc-photo-preview"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
                        <div class="mdbk-photo-actions">
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-doc-photo-upload"><?php _e('Upload Photo', 'doctor-appointment'); ?></button>
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-doc-photo-remove" style="display:none;"><?php _e('Remove', 'doctor-appointment'); ?></button>
                        </div>
                    </div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-doc-name"><?php _e('Full Name', 'doctor-appointment'); ?> *</label><input type="text" name="doc_name" id="mdbk-doc-name" required></div>
                    <div>
                        <label class="mdbk-form-label" for="mdbk-doc-spec-trigger"><?php _e('Specialty', 'doctor-appointment'); ?></label>
                        <?php $spec_terms = get_terms(['taxonomy' => 'mdbk_department', 'hide_empty' => false]); ?>
                        <div class="mdbk-custom-select" id="mdbk-doc-spec-select">
                            <button type="button" class="mdbk-custom-select-trigger" id="mdbk-doc-spec-trigger">
                                <span class="mdbk-custom-select-value"><?php echo $spec_terms ? esc_html($spec_terms[0]->name) : ''; ?></span>
                                <span class="mdbk-custom-select-chevron"></span>
                            </button>
                            <div class="mdbk-custom-select-panel" id="mdbk-doc-spec-panel" style="display:none;">
                                <?php foreach ($spec_terms as $i => $t): ?>
                                <div class="mdbk-custom-select-option<?php echo $i === 0 ? ' selected' : ''; ?>" data-value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></div>
                                <?php endforeach; ?>
                            </div>
                            <select name="specialty" id="mdbk-doc-spec" style="display:none;">
                                <?php foreach ($spec_terms as $t): ?><option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div>
                        <div class="mdbk-form-label-row"><label class="mdbk-form-label" for="mdbk-doc-email"><?php _e('Email', 'doctor-appointment'); ?> *</label><label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="show_email" id="mdbk-show-email" value="1" checked><span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Public', 'doctor-appointment'); ?></span></label></div>
                        <input type="email" name="doc_email" id="mdbk-doc-email" required>
                    </div>
                    <div>
                        <div class="mdbk-form-label-row"><label class="mdbk-form-label" for="mdbk-doc-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="show_phone" id="mdbk-show-phone" value="1" checked><span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Public', 'doctor-appointment'); ?></span></label></div>
                        <input type="text" name="doc_phone" id="mdbk-doc-phone">
                    </div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div>
                        <div class="mdbk-form-label-row">
                            <label class="mdbk-form-label"><?php _e('Time Slot Booking', 'doctor-appointment'); ?></label>
                            <label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="slot_enabled" id="mdbk-doc-slot-enabled" value="1" checked><span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Enabled', 'doctor-appointment'); ?></span></label>
                        </div>
                        <p class="mdbk-form-hint"><?php _e('Off: patients are booked serially by queue number — no time picker.', 'doctor-appointment'); ?></p>
                    </div>
                    <div id="mdbk-doc-slot-duration-group"><label class="mdbk-form-label" for="mdbk-doc-slot-duration"><?php _e('Slot Duration (minutes)', 'doctor-appointment'); ?></label><input type="number" name="slot_duration" id="mdbk-doc-slot-duration" min="5" step="5" value="20"></div>
                </div>

                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-doc-bio"><?php _e('Bio / Description', 'doctor-appointment'); ?></label>
                    <textarea name="doc_bio" id="mdbk-doc-bio" rows="3" placeholder="<?php esc_attr_e('Specialty, experience, qualifications...', 'doctor-appointment'); ?>"></textarea>
                </div>

                <details class="mdbk-availability-section" open>
                    <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><h4><?php _e('Weekly Availability', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                    <div class="mdbk-day-grid">
                    <?php foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                    <div class="mdbk-day-row is-off">
                        <span class="mdbk-day-name"><?php echo esc_html($day); ?></span>
                        <label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="schedule[<?php echo esc_attr($day); ?>][active]" value="1" class="mdbk-day-check" onchange="this.closest('.mdbk-day-row').classList.toggle('is-off', !this.checked)"><span class="mdbk-toggle-slider"></span></label>
                        <div class="mdbk-day-times">
                            <input type="time" name="schedule[<?php echo esc_attr($day); ?>][from]">
                            <span>–</span>
                            <input type="time" name="schedule[<?php echo esc_attr($day); ?>][to]">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </details>

                <details class="mdbk-availability-section">
                    <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><circle cx="12" cy="15" r="2"></circle></svg><h4><?php _e('Monthly Availability', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                    <div class="mdbk-monthly-availability">
                        <div class="mdbk-mini-cal-col">
                            <label class="mdbk-form-label"><?php _e('Extra Working Dates', 'doctor-appointment'); ?></label>
                            <p class="mdbk-form-hint"><?php _e('Open for booking on these dates even if the weekday is normally off.', 'doctor-appointment'); ?></p>
                            <div class="mdbk-mini-calendar" id="mdbk-doc-extra-cal"></div>
                            <input type="hidden" name="extra_dates_json" id="mdbk-doc-extra-dates-input" value="[]">
                        </div>
                        <div class="mdbk-mini-cal-col">
                            <label class="mdbk-form-label"><?php _e('Off Dates', 'doctor-appointment'); ?></label>
                            <p class="mdbk-form-hint"><?php _e('Closed on these dates even if the weekday is normally active.', 'doctor-appointment'); ?></p>
                            <div class="mdbk-mini-calendar" id="mdbk-doc-off-cal"></div>
                            <input type="hidden" name="off_dates_json" id="mdbk-doc-off-dates-input" value="[]">
                        </div>
                    </div>
                </details>
            </div>
            <div class="mdbk-modal-foot">
                <button type="button" class="mdbk-btn-outline mdbk-modal-cancel"><?php _e('Cancel', 'doctor-appointment'); ?></button>
                <button type="submit" name="mdbk_save_doctor" class="mdbk-btn-save"><?php _e('Save Profile', 'doctor-appointment'); ?></button>
            </div>
            </form>
        </div></div>
    <?php }

    // Read-only detail popup — filled entirely by JS (openViewDoctor()) from the
    // clicked card's own data-* attributes, so it never drifts out of sync with
    // what's currently on screen (e.g. a just-toggled Active/Inactive state).
    private function render_doctor_view_modal_html() { ?>
        <div id="mdbk-doctor-view-modal" class="mdbk-modal"><div class="mdbk-modal-content" style="max-width:560px;"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Doctor Details', 'doctor-appointment'); ?></h2><div id="mdbk-doctor-view-body"></div></div></div>
    <?php }

    private function render_patient_modal_html() { ?>
        <div id="mdbk-patient-modal" class="mdbk-modal mdbk-modal-compact"><div class="mdbk-modal-content">
            <div class="mdbk-modal-head"><h2 id="mdbk-patient-modal-title"><?php _e('Add Patient', 'doctor-appointment'); ?></h2><span class="mdbk-modal-close">&times;</span></div>
            <form id="mdbk-patient-form" method="POST"><?php wp_nonce_field('mdbk_save_patient'); ?><input type="hidden" name="patient_id" id="mdbk-patient-id">
            <div class="mdbk-modal-body">
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-patient-name"><?php _e('Full Name', 'doctor-appointment'); ?> *</label>
                    <input type="text" name="patient_name" id="mdbk-patient-name" required>
                </div>
                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-patient-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-patient-phone"></div>
                    <div><label class="mdbk-form-label" for="mdbk-patient-email"><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="patient_email" id="mdbk-patient-email"></div>
                </div>
                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-patient-age"><?php _e('Age', 'doctor-appointment'); ?></label><input type="number" name="patient_age" id="mdbk-patient-age" min="0"></div>
                    <div>
                        <label class="mdbk-form-label" for="mdbk-patient-gender-trigger"><?php _e('Gender', 'doctor-appointment'); ?></label>
                        <div class="mdbk-custom-select" id="mdbk-patient-gender-select">
                            <button type="button" class="mdbk-custom-select-trigger" id="mdbk-patient-gender-trigger">
                                <span class="mdbk-custom-select-value"><?php _e('Male', 'doctor-appointment'); ?></span>
                                <span class="mdbk-custom-select-chevron"></span>
                            </button>
                            <div class="mdbk-custom-select-panel" id="mdbk-patient-gender-panel" style="display:none;">
                                <div class="mdbk-custom-select-option selected" data-value="Male"><?php _e('Male', 'doctor-appointment'); ?></div>
                                <div class="mdbk-custom-select-option" data-value="Female"><?php _e('Female', 'doctor-appointment'); ?></div>
                                <div class="mdbk-custom-select-option" data-value="Other"><?php _e('Other', 'doctor-appointment'); ?></div>
                            </div>
                            <select name="patient_gender" id="mdbk-patient-gender" style="display:none;">
                                <option value="Male"><?php _e('Male', 'doctor-appointment'); ?></option>
                                <option value="Female"><?php _e('Female', 'doctor-appointment'); ?></option>
                                <option value="Other"><?php _e('Other', 'doctor-appointment'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-patient-address"><?php _e('Address', 'doctor-appointment'); ?></label>
                    <textarea name="patient_address" id="mdbk-patient-address" rows="3"></textarea>
                </div>
            </div>
            <div class="mdbk-modal-foot">
                <button type="button" class="mdbk-btn-outline mdbk-modal-cancel"><?php _e('Cancel', 'doctor-appointment'); ?></button>
                <button type="submit" name="mdbk_save_patient" class="mdbk-btn-save"><?php _e('Save Record', 'doctor-appointment'); ?></button>
            </div>
            </form>
        </div></div>
    <?php }

    private function render_appointment_modal_html() {
        $all_doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);

        $spec_terms = get_terms(['taxonomy' => 'mdbk_department', 'hide_empty' => false]);
        $doctor_specs = [];
        foreach ($all_doctors as $d) {
            $terms = get_the_terms($d->ID, 'mdbk_department');
            $doctor_specs[$d->ID] = $terms && !is_wp_error($terms) && !empty($terms) ? $terms[0]->term_id : '';
        }
        ?>
        <div id="mdbk-appointment-modal" class="mdbk-modal mdbk-modal-compact"><div class="mdbk-modal-content">
            <div class="mdbk-modal-head"><h2 id="mdbk-appointment-modal-title"><?php _e('Add Booking', 'doctor-appointment'); ?></h2><span class="mdbk-modal-close">&times;</span></div>
            <form id="mdbk-appointment-form" method="POST"><?php wp_nonce_field('mdbk_save_appointment'); ?><input type="hidden" name="app_id" id="mdbk-app-id">
            <div class="mdbk-modal-body">
                <div class="mdbk-card-section-admin">
                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div>
                        <label class="mdbk-form-label" for="mdbk-app-spec-trigger"><?php _e('Specialty', 'doctor-appointment'); ?></label>
                        <div class="mdbk-custom-select" id="mdbk-app-spec-select">
                            <button type="button" class="mdbk-custom-select-trigger" id="mdbk-app-spec-trigger">
                                <span class="mdbk-custom-select-value"><?php _e('All Specialties', 'doctor-appointment'); ?></span>
                                <span class="mdbk-custom-select-chevron"></span>
                            </button>
                            <div class="mdbk-custom-select-panel" id="mdbk-app-spec-panel" style="display:none;">
                                <div class="mdbk-custom-select-option selected" data-value=""><?php _e('All Specialties', 'doctor-appointment'); ?></div>
                                <?php foreach ($spec_terms as $t): ?>
                                <div class="mdbk-custom-select-option" data-value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></div>
                                <?php endforeach; ?>
                            </div>
                            <select name="specialty" id="mdbk-app-spec" style="display:none;">
                                <option value=""><?php _e('All Specialties', 'doctor-appointment'); ?></option>
                                <?php foreach ($spec_terms as $t): ?><option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mdbk-form-label" for="mdbk-app-doctor-trigger"><?php _e('Doctor', 'doctor-appointment'); ?></label>
                        <div class="mdbk-custom-select mdbk-custom-select-highlighted" id="mdbk-app-doctor-select">
                            <button type="button" class="mdbk-custom-select-trigger" id="mdbk-app-doctor-trigger">
                                <span class="mdbk-custom-select-value"><?php echo $all_doctors ? esc_html($all_doctors[0]->post_title) : ''; ?></span>
                                <span class="mdbk-custom-select-chevron"></span>
                            </button>
                            <div class="mdbk-custom-select-panel" id="mdbk-app-doctor-panel" style="display:none;">
                                <?php foreach ($all_doctors as $i => $d): ?>
                                <div class="mdbk-custom-select-option<?php echo $i === 0 ? ' selected' : ''; ?>" data-value="<?php echo esc_attr($d->ID); ?>" data-specialty="<?php echo esc_attr($doctor_specs[$d->ID]); ?>" data-slot-enabled="<?php echo \MDBK\MDBK_Appointment_Manager::is_slot_enabled($d->ID) ? 'yes' : 'no'; ?>"><?php echo esc_html($d->post_title); ?></div>
                                <?php endforeach; ?>
                            </div>
                            <select name="doctor_id" id="mdbk-app-doctor" style="display:none;">
                                <?php foreach ($all_doctors as $d): ?><option value="<?php echo esc_attr($d->ID); ?>"><?php echo esc_html($d->post_title); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div>
                        <label class="mdbk-form-label" for="mdbk-app-status-trigger"><?php _e('Status', 'doctor-appointment'); ?></label>
                        <div class="mdbk-custom-select" id="mdbk-app-status-select">
                            <button type="button" class="mdbk-custom-select-trigger" id="mdbk-app-status-trigger">
                                <span class="mdbk-custom-select-value"><?php _e('Waiting', 'doctor-appointment'); ?></span>
                                <span class="mdbk-custom-select-chevron"></span>
                            </button>
                            <div class="mdbk-custom-select-panel" id="mdbk-app-status-panel" style="display:none;">
                                <div class="mdbk-custom-select-option selected" data-value="waiting"><?php _e('Waiting', 'doctor-appointment'); ?></div>
                                <div class="mdbk-custom-select-option" data-value="serving"><?php _e('Visiting', 'doctor-appointment'); ?></div>
                                <div class="mdbk-custom-select-option" data-value="completed"><?php _e('Completed', 'doctor-appointment'); ?></div>
                                <div class="mdbk-custom-select-option" data-value="no-show"><?php _e('No Show', 'doctor-appointment'); ?></div>
                            </div>
                            <select name="status" id="mdbk-app-status" style="display:none;">
                                <option value="waiting" selected><?php _e('Waiting', 'doctor-appointment'); ?></option>
                                <option value="serving"><?php _e('Visiting', 'doctor-appointment'); ?></option>
                                <option value="completed"><?php _e('Completed', 'doctor-appointment'); ?></option>
                                <option value="no-show"><?php _e('No Show', 'doctor-appointment'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div></div>
                </div>
                </div>

                <div class="mdbk-card-section-admin">
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-app-patient"><?php _e('Patient Name', 'doctor-appointment'); ?> *</label>
                    <input type="text" name="patient_name" id="mdbk-app-patient" required>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-app-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-app-phone"></div>
                    <div><label class="mdbk-form-label" for="mdbk-app-email"><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="patient_email" id="mdbk-app-email"></div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-app-age"><?php _e('Age', 'doctor-appointment'); ?></label><input type="number" name="age" id="mdbk-app-age" min="0"></div>
                    <div><label class="mdbk-form-label" for="mdbk-app-gender"><?php _e('Gender', 'doctor-appointment'); ?></label><select name="gender" id="mdbk-app-gender"><option value="Male"><?php _e('Male', 'doctor-appointment'); ?></option><option value="Female"><?php _e('Female', 'doctor-appointment'); ?></option><option value="Other"><?php _e('Other', 'doctor-appointment'); ?></option></select></div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-app-date"><?php _e('Date', 'doctor-appointment'); ?> *</label><input type="date" name="app_date" id="mdbk-app-date" required></div>
                    <div>
                        <label class="mdbk-form-label" for="mdbk-app-slot-time"><?php _e('Slot Time', 'doctor-appointment'); ?></label>
                        <input type="time" name="slot_time" id="mdbk-app-slot-time" <?php echo ($all_doctors && !\MDBK\MDBK_Appointment_Manager::is_slot_enabled($all_doctors[0]->ID)) ? 'disabled' : ''; ?>>
                        <p class="mdbk-form-hint" id="mdbk-app-slot-hint" style="<?php echo ($all_doctors && !\MDBK\MDBK_Appointment_Manager::is_slot_enabled($all_doctors[0]->ID)) ? '' : 'display:none;'; ?>"><?php _e('Serial booking — queue number is assigned automatically.', 'doctor-appointment'); ?></p>
                    </div>
                </div>
                </div>
            </div>
            <div class="mdbk-modal-foot">
                <button type="button" class="mdbk-btn-outline mdbk-modal-cancel"><?php _e('Cancel', 'doctor-appointment'); ?></button>
                <button type="submit" name="mdbk_save_appointment" class="mdbk-btn-save"><?php _e('Save Booking', 'doctor-appointment'); ?></button>
            </div>
            </form>
        </div></div>
    <?php }

    private function render_specialty_modal_html() { ?>
        <div id="mdbk-specialty-modal" class="mdbk-modal"><div class="mdbk-modal-content" style="max-width:400px;"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Specialty', 'doctor-appointment'); ?></h2><form id="mdbk-specialty-form" method="POST"><?php wp_nonce_field('mdbk_save_specialty'); ?><input type="hidden" name="term_id" id="mdbk-spec-id"><div class="mdbk-input-group"><label><?php _e('Name', 'doctor-appointment'); ?></label><input type="text" name="spec_name" id="mdbk-spec-name" class="mdbk-input" required></div><button type="submit" name="mdbk_save_specialty" class="mdbk-btn-save"><?php _e('Save Specialty', 'doctor-appointment'); ?></button></form></div></div>
    <?php }
}
new MDBK_Admin_Dashboard();
