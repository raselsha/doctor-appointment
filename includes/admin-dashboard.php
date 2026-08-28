<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Admin_Dashboard {

    public function __construct() {
        add_action('admin_init', [$this, 'enforce_panel_only_access']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_doctor_save']);
        add_action('admin_init', [$this, 'handle_appointment_save']);
        add_action('admin_init', [$this, 'handle_specialty_save']);
        add_action('admin_init', [$this, 'handle_patient_save']);
        add_action('admin_init', [$this, 'handle_staff_save']);
        add_action('admin_init', [$this, 'handle_delete_actions']);
        add_action('admin_init', [$this, 'handle_schedule_export']);
        add_action('admin_init', [$this, 'handle_change_password_save']);
        add_action('admin_init', [$this, 'handle_global_settings_save']);
        add_action('wp_ajax_mdbk_toggle_doctor_active', [$this, 'ajax_toggle_doctor_active']);
        add_action('wp_ajax_mdbk_toggle_doctor_live_queue', [$this, 'ajax_toggle_doctor_live_queue']);
        add_action('wp_ajax_mdbk_toggle_specialty_active', [$this, 'ajax_toggle_specialty_active']);
        add_action('wp_ajax_mdbk_save_doctor_order', [$this, 'ajax_save_doctor_order']);
        add_action('wp_ajax_mdbk_save_specialty_order', [$this, 'ajax_save_specialty_order']);
        add_action('wp_ajax_mdbk_search_patients', [$this, 'ajax_search_patients']);
        add_action('wp_ajax_mdbk_view_patient', [$this, 'ajax_view_patient']);
        add_action('wp_ajax_mdbk_get_invoice', [$this, 'ajax_get_invoice']);
        add_action('wp_ajax_mdbk_save_invoice', [$this, 'ajax_save_invoice']);
        add_action('wp_ajax_mdbk_search_schedule', [$this, 'ajax_search_schedule']);
        add_action('wp_ajax_mdbk_search_patient_phone', [$this, 'ajax_search_patient_phone']);
        add_action('wp_ajax_mdbk_mark_visited', [$this, 'ajax_mark_visited']);
        add_action('wp_ajax_mdbk_admin_checkin', [$this, 'ajax_admin_checkin']);
        add_action('wp_ajax_mdbk_toggle_skip', [$this, 'ajax_toggle_skip']);
        add_action('wp_ajax_mdbk_start_visiting', [$this, 'ajax_start_visiting']);
        add_action('wp_ajax_mdbk_refresh_doctor_group', [$this, 'ajax_refresh_doctor_group']);
        add_action('wp_ajax_mdbk_queue_signature', [$this, 'ajax_queue_signature']);
        add_action('wp_ajax_mdbk_refresh_doctor_card', [$this, 'ajax_refresh_doctor_card']);
        add_filter('login_redirect', [$this, 'doctor_login_redirect'], 10, 3);
        add_filter('edit_profile_url', [$this, 'redirect_profile_url'], 10, 3);
        add_filter('admin_body_class', [$this, 'admin_body_class']);
        add_filter('show_admin_bar', [$this, 'hide_front_end_admin_bar']);
        add_action('admin_bar_menu', [$this, 'remove_wp_logo_from_admin_bar'], 999);
        add_filter('ajax_query_attachments_args', [$this, 'restrict_media_to_own_uploads']);
        add_action('pre_get_posts', [$this, 'restrict_media_library_query']);
        add_action('admin_print_footer_scripts', [$this, 'print_server_clock'], 1);
    }

    /**
     * The site's wall clock, read as late in the response as PHP can
     * still emit markup, for the break countdown in admin-script.js.
     *
     * Each countdown pill carries its own reading too, but that one is
     * taken partway through building the page — and a full Today's
     * Queue spends a good half-second more on the rows below it before
     * anything reaches the browser, which the countdown would otherwise
     * carry as a permanent half-second lag. Read here instead, the only
     * error left between server and browser is network transit.
     *
     * Format matches the pills' own data-server-now-seconds: seconds
     * since midnight in the site's timezone, to the millisecond. See
     * render_break_countdown_el() for why it isn't a Unix timestamp.
     */
    public function print_server_clock() {
        $now = new \DateTimeImmutable('now', wp_timezone());
        $seconds = round(
            intval($now->format('H')) * 3600
            + intval($now->format('i')) * 60
            + intval($now->format('s'))
            + intval($now->format('u')) / 1000000,
            3
        );
        echo '<span id="mdbk-server-clock" style="display:none;" data-now-seconds="' . esc_attr($seconds) . '"></span>';
    }

    /**
     * Confines a doctor account to their OWN uploaded media — granting
     * 'upload_files' (see MDBK_Roles::activate()) is what lets the
     * Profile page's photo picker (wp.media(), admin-script.js) work at
     * all, but without this a doctor could otherwise browse/reuse every
     * other doctor's (or anyone else's) photo through that same picker,
     * since 'upload_files' alone doesn't restrict WHICH attachments show
     * up. Covers the grid/modal view (this filter — what wp.media()
     * itself actually queries through) and the classic list-table view
     * (restrict_media_library_query() below) so neither path leaks other
     * people's media. Admin/staff are untouched.
     */
    public function restrict_media_to_own_uploads($query) {
        if (current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) {
            $query['author'] = get_current_user_id();
        }
        return $query;
    }

    /**
     * Same "own uploads only" restriction as restrict_media_to_own_uploads()
     * above, for the classic Media Library list-table (a plain WP_Query,
     * not the AJAX grid) — belt and suspenders in case that view is ever
     * reached directly (upload.php) rather than through this plugin's own
     * photo picker.
     */
    public function restrict_media_library_query($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'attachment') return;
        if (current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options')) {
            $query->set('author', get_current_user_id());
        }
    }

    /**
     * Removes the WordPress-logo dropdown (About/Docs/Support/Feedback)
     * from the top admin toolbar for a doctor or front-desk-staff account
     * — same "no native WP chrome, only this plugin's own panel" treatment
     * as the hidden native sidebar (see is_restricted_panel_user()); admin
     * keeps the normal toolbar untouched.
     */
    public function remove_wp_logo_from_admin_bar($wp_admin_bar) {
        if ($this->is_restricted_panel_user()) {
            $wp_admin_bar->remove_node('wp-logo');
        }
    }

    /**
     * Points every "Edit Profile" link WP core generates (the admin
     * toolbar's top-right "Howdy, X" link and its dropdown) at this
     * plugin's own "Profile" page instead of wp-admin/profile.php — but
     * only for a doctor or front-desk-staff account (no manage_options);
     * admin/other roles keep WP's own profile screen untouched.
     */
    public function redirect_profile_url($url, $user_id, $scheme) {
        if ($this->is_restricted_panel_user()) {
            return admin_url('admin.php?page=mdbk-profile');
        }
        return $url;
    }

    /**
     * Sends a doctor straight to their own scoped "Booking" queue,
     * front-desk staff straight to the same page's all-doctors view (see
     * render_schedule_page()'s doctor-only scoping), and a Manager
     * straight to the full Dashboard (they have manage_options same as an
     * administrator — see MDBK_Roles::activate() — so the Dashboard is
     * meaningful for them, unlike for staff), on login instead of the
     * default wp-admin dashboard — for a doctor/staff account that
     * dashboard would otherwise render WP's bare/mostly-empty core
     * widgets, since neither has most of the capabilities those widgets
     * check for. Bails on a failed login (don't redirect an error). WP's
     * own login form always submits a non-empty `redirect_to` (confirmed:
     * it defaults to bare admin_url()) — so "empty" is never actually the
     * normal case, and treating any non-empty value as "explicitly
     * requested" would silently disable this redirect for every ordinary
     * login. Instead, only a value that differs from wp-admin's own
     * generic defaults counts as a real deep link (e.g. someone returning
     * to a specific bookmarked admin URL after a session timeout) and is
     * left alone.
     */
    public function doctor_login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (is_wp_error($user) || !($user instanceof \WP_User)) {
            return $redirect_to;
        }
        $roles = (array) $user->roles;
        $is_manager = in_array('mdbk_manager_role', $roles, true);
        $is_doctor_or_staff = in_array('mdbk_doctor_role', $roles, true) || in_array('mdbk_receptionist', $roles, true);
        if (!$is_manager && !$is_doctor_or_staff) {
            return $redirect_to;
        }
        $generic_defaults = ['', 'wp-admin/', admin_url(), untrailingslashit(admin_url()) . '/'];
        if (!in_array($requested_redirect_to, $generic_defaults, true)) {
            return $redirect_to;
        }
        return admin_url($is_manager ? 'admin.php?page=mdbk-dashboard' : 'admin.php?page=mdbk-schedule');
    }

    public function ajax_toggle_doctor_active() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        $doctor_id = isset($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        if (!$doctor_id || get_post_type($doctor_id) !== 'mdbk_doctor') wp_send_json_error(['message' => __('Invalid doctor.', 'doctor-appointment')]);
        $active = get_post_meta($doctor_id, '_mdbk_doctor_active', true) === 'no' ? 'yes' : 'no';
        update_post_meta($doctor_id, '_mdbk_doctor_active', $active);
        wp_send_json_success(['active' => $active === 'yes']);
    }

    /**
     * Per-doctor Live Queue on/off — the toggle next to each doctor's name
     * on the Today's Queue page (grouped view), or standalone in the card
     * header (single-doctor view — see render_schedule_today_view()).
     * Front-desk staff/admin (MDBK_CAP_QUEUE) can flip any doctor's; a pure
     * doctor account (MDBK_CAP_DOCTOR only, no MDBK_CAP_QUEUE) may only
     * flip their OWN — same ownership pattern as ajax_mark_visited().
     */
    public function ajax_toggle_doctor_live_queue() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        $doctor_id = isset($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        if (!$doctor_id || get_post_type($doctor_id) !== 'mdbk_doctor') wp_send_json_error(['message' => __('Invalid doctor.', 'doctor-appointment')]);
        if (!current_user_can('manage_options') && !current_user_can(MDBK_CAP_QUEUE)) {
            $own_doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
            if (!$own_doctor_id || $own_doctor_id !== $doctor_id) {
                wp_send_json_error(['message' => __('You can only control your own Live Queue.', 'doctor-appointment')]);
            }
        }
        $enabled = get_post_meta($doctor_id, '_mdbk_live_queue_enabled', true) === 'no' ? 'yes' : 'no';
        update_post_meta($doctor_id, '_mdbk_live_queue_enabled', $enabled);
        wp_send_json_success(['enabled' => $enabled === 'yes']);
    }

    public function ajax_toggle_specialty_active() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        if (!$term_id || !get_term($term_id, 'mdbk_department')) wp_send_json_error(['message' => __('Invalid specialty.', 'doctor-appointment')]);
        $active = get_term_meta($term_id, '_mdbk_specialty_active', true) === 'no' ? 'yes' : 'no';
        update_term_meta($term_id, '_mdbk_specialty_active', $active);
        wp_send_json_success(['active' => $active === 'yes']);
    }

    /**
     * Persists a drag-and-drop reorder — 'order' is the FULL list of
     * doctor IDs in their new order (the reorder modal always shows every
     * doctor at once, no pagination to fight with), each just gets its
     * array index as WP's own native menu_order. Uses $wpdb directly (like
     * migrate_to_v9()) rather than wp_update_post() in a loop — this is a
     * pure position change, not a content edit, so there's no reason to
     * fire post-save hooks/revisions for every single doctor on every drag.
     */
    public function ajax_save_doctor_order() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        $order = isset($_POST['order']) && is_array($_POST['order']) ? array_map('intval', $_POST['order']) : [];
        if (empty($order)) wp_send_json_error(['message' => __('Invalid request.', 'doctor-appointment')]);
        global $wpdb;
        foreach ($order as $index => $doctor_id) {
            if (get_post_type($doctor_id) !== 'mdbk_doctor') continue;
            $wpdb->update($wpdb->posts, ['menu_order' => $index], ['ID' => $doctor_id], ['%d'], ['%d']);
            clean_post_cache($doctor_id);
        }
        wp_send_json_success();
    }

    /**
     * Same idea as ajax_save_doctor_order(), for Specialties — a taxonomy
     * has no native ordering column, so this writes the array index into
     * each term's own _mdbk_specialty_order meta instead.
     */
    public function ajax_save_specialty_order() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        $order = isset($_POST['order']) && is_array($_POST['order']) ? array_map('intval', $_POST['order']) : [];
        if (empty($order)) wp_send_json_error(['message' => __('Invalid request.', 'doctor-appointment')]);
        foreach ($order as $index => $term_id) {
            if (!get_term($term_id, 'mdbk_department')) continue;
            update_term_meta($term_id, '_mdbk_specialty_order', $index);
        }
        wp_send_json_success();
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
        if (!current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options') && !current_user_can(MDBK_CAP_QUEUE)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Invalid appointment.', 'doctor-appointment')]);
        }

        // Front-desk staff (MDBK_CAP_QUEUE) manages every doctor's queue
        // from the same all-doctors "Patients" view, so the "own doctor
        // only" restriction below is a pure-doctor-account thing — it
        // never applies to staff or a full admin.
        $appointment_doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        if (!current_user_can('manage_options') && !current_user_can(MDBK_CAP_QUEUE)) {
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

        // Matches the button only appearing for a "serving" patient in
        // render_my_queue_patient_row() — a merely checked-in-and-waiting
        // patient must go through "Start Visiting" first (see
        // MDBK_Appointment_Manager::start_visiting()) rather than being
        // closed out directly, so they're never silently marked visited
        // without ever showing as "Visiting" at all.
        if (get_post_status($appointment_id) !== 'mdbk_serving') {
            wp_send_json_error(['message' => __('This patient must be started (Start Visiting) before they can be marked as visited.', 'doctor-appointment')]);
        }

        // Close the stopwatch before the status flips, so the duration is
        // computed from the same moment the row stops showing as "Visiting".
        // Falls back to the check-in time only if the visit was never
        // started through Start Visiting (an older row, or a status edited
        // straight from the booking form) — and stores 0 rather than a
        // fabricated length when there's nothing to measure from.
        $started = intval(get_post_meta($appointment_id, '_mdbk_visit_started_at', true));
        $ended = current_time('timestamp');
        update_post_meta($appointment_id, '_mdbk_visit_ended_at', $ended);
        update_post_meta($appointment_id, '_mdbk_visit_duration', $started ? max(0, $ended - $started) : 0);

        wp_update_post(['ID' => $appointment_id, 'post_status' => 'mdbk_completed']);

        // No auto-advance to whoever's next — the doctor/staff must
        // explicitly "Start Visiting" the next patient themselves (see
        // MDBK_Appointment_Manager::start_visiting()'s comment for why).
        // Still returns the whole today's-queue list (not just this one
        // row) for the same reason every other action on this page does —
        // one consistent refresh path shared with Skip/Start Visiting/
        // Check-In, see render_today_queue_rows().
        list($fragment_doctor_id, $group_by_doctor) = $this->resolve_queue_view_scope($appointment_doctor_id);
        wp_send_json_success(['fragment' => $this->render_today_queue_rows($fragment_doctor_id, $group_by_doctor)]);
    }

    /**
     * Manual per-doctor-group refresh (the Booking page's own Refresh
     * icon, next to Print/Export/Download-Image on each doctor's
     * collapsible header) — replaces the earlier setInterval(runSearch,
     * 12000) whole-page auto-refresh, which reset every <details>' open/
     * closed state and the page's scroll position on every single tick
     * (a full server-rendered #mdbk-schedule-results swap has no way to
     * know which groups a staff member had manually collapsed). This
     * touches only the ONE group that was clicked — the <details> element
     * itself, and every OTHER group, are never replaced, so their
     * open/closed state and the page's scroll position both stay exactly
     * where the user left them. Only present on the grouped (all-doctors)
     * view, gated the same as that view itself (MDBK_CAP_QUEUE), so no
     * separate per-doctor ownership check is needed here the way the
     * single-row actions above (Start Visiting etc.) need one.
     */
    /**
     * A short fingerprint of today's queue, so the panel can ask "has
     * anything changed?" without pulling the queue itself.
     *
     * There is no push channel available here — a check-in or a new
     * booking happens in someone else's browser (the front desk, the
     * kiosk, the public form), and WordPress on shared hosting can't hold
     * a socket open to announce it. Asking the server is unavoidable; what
     * this makes cheap is asking OFTEN. One indexed meta query and a hash
     * come back, instead of re-rendering every row and shipping the HTML
     * on a timer whether or not a single thing moved.
     *
     * The hash covers exactly what the queue view can show: which
     * appointments are on it, each one's status, whether they've checked
     * in, and their slot — so a new booking, a check-in, a status change,
     * a skip or a reschedule all change it, and nothing else does.
     */
    public function ajax_queue_signature() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        $is_queue_staff = current_user_can(MDBK_CAP_QUEUE);
        $own_doctor_id = (!$is_queue_staff && current_user_can(MDBK_CAP_DOCTOR))
            ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id())
            : 0;
        if (!$is_queue_staff && !$own_doctor_id) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        // A doctor can only ever ask about their own queue, whatever the
        // request says — same rule ajax_refresh_doctor_group() applies.
        $doctor_id = $is_queue_staff
            ? (isset($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0)
            : $own_doctor_id;

        $meta_query = [['key' => '_mdbk_appointment_date', 'value' => current_time('Y-m-d')]];
        if ($doctor_id) $meta_query[] = ['key' => '_mdbk_doctor_id', 'value' => $doctor_id];

        $ids = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'numberposts' => -1,
            'fields'      => 'ids',
            'orderby'     => 'ID',
            'order'       => 'ASC',
            'meta_query'  => $meta_query,
        ]);

        $parts = [];
        foreach ($ids as $id) {
            $parts[] = $id
                . ':' . get_post_status($id)
                . ':' . (get_post_meta($id, '_mdbk_checked_in', true) === 'yes' ? '1' : '0')
                . ':' . (get_post_meta($id, '_mdbk_skipped', true) === 'yes' ? '1' : '0')
                . ':' . get_post_meta($id, '_mdbk_slot_time', true);
        }

        wp_send_json_success(['signature' => md5(implode('|', $parts)), 'count' => count($ids)]);
    }

    public function ajax_refresh_doctor_group() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        // Staff/admin refreshing any doctor's group in the grouped view,
        // or a pure doctor account refreshing their own single-doctor
        // Today's Queue header (render_schedule_today_view()'s own
        // Refresh button, added later, reuses this same endpoint) — this
        // used to recognize only the first group, so that button silently
        // no-opped (wp_send_json_error() with no thrown exception for
        // admin-script.js's own fetch to catch) for every doctor login.
        $is_queue_staff = current_user_can(MDBK_CAP_QUEUE);
        $own_doctor_id = (!$is_queue_staff && current_user_can(MDBK_CAP_DOCTOR)) ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id()) : 0;
        if (!$is_queue_staff && !$own_doctor_id) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        // A doctor-only account can only ever refresh their OWN group —
        // never trust the posted doctor_id for them, same rule
        // handle_schedule_export() now enforces on its own filter_doctor.
        $doctor_id = $is_queue_staff
            ? (isset($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0)
            : $own_doctor_id;
        $is_today = isset($_POST['is_today']) && $_POST['is_today'] === '1';
        $search = isset($_POST['s']) ? sanitize_text_field($_POST['s']) : '';
        $filter_status = isset($_POST['filter_status']) ? sanitize_text_field($_POST['filter_status']) : '';

        $apps = $is_today ? $this->get_today_queue_apps($doctor_id) : $this->get_upcoming_queue_apps($doctor_id);
        if ($search !== '' || $filter_status !== '') {
            $apps = array_values(array_filter($apps, function($a) use ($search, $filter_status) {
                if ($filter_status && \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($a)) !== $filter_status) return false;
                if ($search !== '') {
                    $haystack = get_post_meta($a->ID, '_mdbk_patient_name', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_phone', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_email', true);
                    if (stripos($haystack, $search) === false) return false;
                }
                return true;
            }));
        }

        // Computed from the unfiltered today's-queue (see
        // get_serving_doctor_ids()'s comment) — only meaningful for the
        // Today's Queue group, same as render_patient_list_html().
        $is_visiting = false;
        if ($is_today && $doctor_id) {
            $serving_doctor_ids = $this->get_serving_doctor_ids($this->get_today_queue_apps($doctor_id));
            $is_visiting = isset($serving_doctor_ids[$doctor_id]);
        }

        $list_html = $this->render_queue_rows($apps, $is_visiting ? [$doctor_id => true] : []);
        ob_start();
        $this->render_today_queue_table($apps, false);
        $print_table_html = ob_get_clean();

        // The break-countdown pill (render_break_countdown_el()) lives in
        // the HEADER, outside everything list_html/print_table_html above
        // replace — without handing back a fresh copy here too, a break
        // that starts/ends (or gets edited) while this page is sitting
        // open only ever shows up after a full manual reload, even though
        // this same endpoint is already polled every 15s for the patient
        // list. '' when there's nothing to show, same as the page-load
        // render — admin-script.js swaps it in (or removes a stale one)
        // either way. Only relevant for the Today's Queue group.
        $break_html = ($is_today && $doctor_id) ? $this->render_break_countdown_el($doctor_id) : '';

        wp_send_json_success([
            'count'            => count($apps),
            'count_label'      => sprintf(_n('%d patient', '%d patients', count($apps), 'doctor-appointment'), count($apps)),
            'list_html'        => $list_html,
            'print_table_html' => $print_table_html,
            'is_visiting'      => $is_visiting,
            'break_html'       => $break_html,
        ]);
    }

    /**
     * The Doctors panel card's own Refresh button — re-renders one
     * doctor's card server-side and hands the fresh HTML back so
     * admin-script.js can swap it in place, the same "touch only what was
     * clicked" idea as ajax_refresh_doctor_group() above.
     */
    public function ajax_refresh_doctor_card() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $doctor_id = isset($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        $doctor = $doctor_id ? get_post($doctor_id) : null;
        if (!$doctor || $doctor->post_type !== 'mdbk_doctor') {
            wp_send_json_error(['message' => __('Doctor not found.', 'doctor-appointment')]);
        }

        wp_send_json_success(['html' => $this->render_doctor_card($doctor, false)]);
    }

    /**
     * Front-desk staff's Booking view shows every doctor combined
     * (doctor_id 0), while a doctor's own view shows just theirs — so an
     * AJAX action fired from either one needs to know which list to hand
     * back, not just which doctor the acted-on appointment happens to
     * belong to (see the data-view-doctor-id attribute
     * render_schedule_today_view() puts on #mdbk-today-queue-list, echoed
     * back by admin-script.js as view_doctor_id). Only trusted when the
     * current user can actually see the all-doctors view — a pure doctor
     * account passing view_doctor_id=0 must never get another doctor's
     * patient names back in the response, so it silently falls back to
     * their own appointment's doctor instead.
     */
    private function resolve_queue_view_scope($appointment_doctor_id) {
        $can_view_all = current_user_can('manage_options') || current_user_can(MDBK_CAP_QUEUE);
        $requested = isset($_POST['view_doctor_id']) ? intval($_POST['view_doctor_id']) : null;
        if ($requested === 0 && $can_view_all) {
            return [0, true];
        }
        return [$appointment_doctor_id, false];
    }

    /**
     * Toggle a checked-in waiting patient's "Skip" flag on the "Patients"
     * page — for a patient who stepped away (toilet, phone call) after
     * checking in. While skipped, they lose the "Start Visiting" button
     * (see $can_start_visiting in render_my_queue_patient_row()) so a
     * doctor/staff member scanning the queue doesn't start seeing someone
     * who isn't actually in the room, but they keep their ticket/place in
     * the list; toggling it back off ("Recall") brings "Start Visiting"
     * back. Same ownership/auth model as ajax_mark_visited() — no nopriv,
     * and a doctor can only toggle this on their own patients.
     */
    public function ajax_toggle_skip() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options') && !current_user_can(MDBK_CAP_QUEUE)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Invalid appointment.', 'doctor-appointment')]);
        }

        // Same staff/admin bypass as ajax_mark_visited() — see its comment.
        $appointment_doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        if (!current_user_can('manage_options') && !current_user_can(MDBK_CAP_QUEUE)) {
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
        list($fragment_doctor_id, $group_by_doctor) = $this->resolve_queue_view_scope($appointment_doctor_id);
        wp_send_json_success(['fragment' => $this->render_today_queue_rows($fragment_doctor_id, $group_by_doctor)]);
    }

    /**
     * "Start Visiting" — moves a checked-in waiting patient to "serving"
     * by explicit action (see MDBK_Appointment_Manager::start_visiting()'s
     * comment for why this exists alongside the automatic advance). Same
     * ownership/auth model as ajax_mark_visited()/ajax_toggle_skip().
     */
    public function ajax_start_visiting() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_DOCTOR) && !current_user_can('manage_options') && !current_user_can(MDBK_CAP_QUEUE)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Invalid appointment.', 'doctor-appointment')]);
        }

        $appointment_doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        if (!current_user_can('manage_options') && !current_user_can(MDBK_CAP_QUEUE)) {
            $own_doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
            if (!$own_doctor_id || $own_doctor_id !== $appointment_doctor_id) {
                wp_send_json_error(['message' => __('You can only update your own patients.', 'doctor-appointment')]);
            }
        }

        $result = \MDBK\MDBK_Appointment_Manager::start_visiting($appointment_id);
        if ($result !== true) {
            wp_send_json_error(['message' => $result]);
        }

        list($fragment_doctor_id, $group_by_doctor) = $this->resolve_queue_view_scope($appointment_doctor_id);
        wp_send_json_success(['fragment' => $this->render_today_queue_rows($fragment_doctor_id, $group_by_doctor)]);
    }

    /**
     * Staff/admin check-in straight from the Bookings page OR the
     * "Patients" page's Today's Queue — bypasses the QR token entirely,
     * since whoever's looking at either list already has the exact record
     * in view. wp_ajax_ only (no nopriv — this is a wp-admin-only
     * action), gated on the same MDBK_CAP_QUEUE capability both pages
     * require (not manage_options), so a receptionist who can already
     * see/act on either page can use the button too.
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

        // From the "Patients" page (view_doctor_id present): same
        // whole-list fragment ajax_mark_visited()/ajax_toggle_skip()/
        // ajax_start_visiting() already use, for one consistent refresh
        // path across every action on that page. From the Bookings page
        // (no view_doctor_id), keep the original single-row swap.
        if (isset($_POST['view_doctor_id'])) {
            $appointment_doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
            list($fragment_doctor_id, $group_by_doctor) = $this->resolve_queue_view_scope($appointment_doctor_id);
            wp_send_json_success(['mode' => 'list', 'fragment' => $this->render_today_queue_rows($fragment_doctor_id, $group_by_doctor)]);
        }

        $show_doctor = isset($_POST['show_doctor']) && $_POST['show_doctor'] === '1';
        wp_send_json_success(['mode' => 'row', 'fragment' => $this->render_patient_appointment_row(get_post($appointment_id), $show_doctor)]);
    }

    public function handle_delete_actions() {
        if (!isset($_GET['action'])) return;
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) return;
        // Deleting a patient is the one action here a doctor can also take,
        // and only for someone they have actually treated
        // (can_view_patient()). Everything else on this handler —
        // doctors, appointments, specialties, staff — stays admin-only.
        $doctor_deleting_patient = $_GET['action'] === 'mdbk_delete_patient'
            && !current_user_can(MDBK_CAP_ADMIN)
            && $this->can_view_patient($id);
        if (!current_user_can(MDBK_CAP_ADMIN) && !$doctor_deleting_patient) {
            wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        }
        check_admin_referer('mdbk_delete_action');
        $redirect = '';
        if ($_GET['action'] === 'mdbk_delete_doctor') { wp_delete_post($id, true); $redirect = admin_url('admin.php?page=mdbk-doctors&deleted=1'); }
        elseif ($_GET['action'] === 'mdbk_delete_appointment') { wp_delete_post($id, true); $redirect = admin_url('admin.php?page=mdbk-schedule&deleted=1'); }
        elseif ($_GET['action'] === 'mdbk_delete_specialty') { wp_delete_term($id, 'mdbk_department'); $redirect = admin_url('admin.php?page=mdbk-specialties&deleted=1'); }
        elseif ($_GET['action'] === 'mdbk_delete_patient') { wp_delete_post($id, true); $redirect = admin_url('admin.php?page=mdbk-patients&deleted=1'); }
        elseif ($_GET['action'] === 'mdbk_delete_staff') {
            // A WP user, not a post — needs wp-admin's own user-deletion
            // helper (not autoloaded outside wp-admin/user-edit.php's own
            // request) and a reassign target for anything they authored.
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($id, get_current_user_id());
            $redirect = admin_url('admin.php?page=mdbk-staff&deleted=1');
        }
        if ($redirect) { wp_redirect($redirect); exit; }
    }

    public function handle_doctor_save() {
        if (!isset($_POST['mdbk_save_doctor'])) return;

        $doctor_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        $is_admin = current_user_can(MDBK_CAP_ADMIN);

        // A doctor (no manage_options) reaches this same form/handler via
        // their own Profile page's "Edit Profile" link (see
        // render_profile_page(), which opens the SAME doctor-edit modal)
        // — they may only ever update their OWN already-existing record,
        // never create a new doctor or edit someone else's.
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
        if ($doctor_id) {
            $post_data['ID'] = $doctor_id;
        } else {
            // A brand new doctor joins the END of the drag-and-drop order
            // (see migrate_to_v9() in migrations.php for the order field
            // itself) rather than defaulting to menu_order 0, which would
            // otherwise silently jump them to the very front of every
            // doctor list on the site.
            global $wpdb;
            $max_order = (int) $wpdb->get_var("SELECT MAX(menu_order) FROM {$wpdb->posts} WHERE post_type = 'mdbk_doctor'");
            $post_data['menu_order'] = $max_order + 1;
        }
        $id = $doctor_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_doc_email', $email);
            update_post_meta($id, '_mdbk_doc_phone', sanitize_text_field($_POST['doc_phone']));
            update_post_meta($id, '_mdbk_doc_bio', sanitize_textarea_field($_POST['doc_bio']));
            update_post_meta($id, '_mdbk_show_phone', isset($_POST['show_phone']) ? 'yes' : 'no');
            update_post_meta($id, '_mdbk_show_email', isset($_POST['show_email']) ? 'yes' : 'no');
            if (!empty($_POST['slot_duration'])) update_post_meta($id, '_mdbk_slot_duration', intval($_POST['slot_duration']));
            update_post_meta($id, '_mdbk_slot_enabled', isset($_POST['slot_enabled']) ? 'yes' : 'no');
            // Named break windows, doctor-wide — apply inside whichever
            // days above are active, not configured per day (see
            // get_available_slots() in appointment-manager.php, the only
            // place that reads these).
            update_post_meta($id, '_mdbk_breaks', self::sanitize_breaks_list($_POST['breaks_json'] ?? ''));
            update_post_meta($id, '_mdbk_doc_fee', isset($_POST['doc_fee']) && is_numeric($_POST['doc_fee']) ? sanitize_text_field($_POST['doc_fee']) : '');
            if (isset($_POST['schedule'])) update_post_meta($id, '_mdbk_schedule', $_POST['schedule']);
            update_post_meta($id, '_mdbk_extra_dates', self::sanitize_date_list($_POST['extra_dates_json'] ?? ''));
            update_post_meta($id, '_mdbk_off_dates', self::sanitize_date_list($_POST['off_dates_json'] ?? ''));
            if (isset($_POST['specialty'])) wp_set_object_terms($id, [intval($_POST['specialty'])], 'mdbk_department');
            // This doctor's own Queue & Ticketing choice (moved here from
            // global settings). Always written as a concrete 'booking'/'checkin'
            // so the radio's visible state can never silently disagree with
            // what's stored.
            $serial_mode = ($_POST['queue_serial_mode'] ?? '') === 'checkin' ? 'checkin' : 'booking';
            update_post_meta($id, '_mdbk_queue_serial_mode', $serial_mode);
            // Switching THIS doctor back to booking order: their bookings taken
            // while check-in-order was active hold no stored number, which
            // booking-order mode has only to show. Backfill just this doctor's
            // rows, never anyone else's.
            if ($doctor_id && $serial_mode === 'booking') {
                $this->backfill_missing_ticket_numbers($id);
            }
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

    /**
     * Decode + sanitize the JSON break-list the Doctor Edit form's repeater
     * submits (built/kept in sync client-side in admin-script.js, same
     * hidden-JSON-input shape sanitize_date_list() above already uses) —
     * a hand-authored JSON hidden field is as untrusted as any other POST
     * value. Rows missing a name/from/to, or with an inverted range
     * (to <= from), are dropped rather than saved half-configured — see
     * get_available_slots() in appointment-manager.php, the only reader.
     */
    private function sanitize_breaks_list($json) {
        $breaks = json_decode(stripslashes((string) $json), true);
        if (!is_array($breaks)) return [];
        $valid = [];
        foreach ($breaks as $b) {
            if (!is_array($b)) continue;
            $from = isset($b['from']) ? sanitize_text_field($b['from']) : '';
            $to = isset($b['to']) ? sanitize_text_field($b['to']) : '';
            if (!$from || !$to || $from >= $to) continue;
            $name = isset($b['name']) ? sanitize_text_field($b['name']) : '';
            $valid[] = ['name' => $name !== '' ? $name : __('Break', 'doctor-appointment'), 'from' => $from, 'to' => $to];
        }
        return $valid;
    }

    public function handle_patient_save() {
        if (!isset($_POST['mdbk_save_patient'])) return;
        // Three audiences now. Front-desk staff (MDBK_CAP_QUEUE) and admin
        // manage the clinic-wide registry; a doctor may add a patient and
        // edit the ones they have actually treated, but nobody else's —
        // see can_view_patient() for that per-record rule.
        $is_doctor_only = !current_user_can(MDBK_CAP_QUEUE)
            && !current_user_can('manage_options')
            && current_user_can(MDBK_CAP_DOCTOR);
        if (!current_user_can(MDBK_CAP_QUEUE) && !current_user_can('manage_options') && !$is_doctor_only) {
            wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        }
        check_admin_referer('mdbk_save_patient');
        $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        // Editing someone already on file: admin outright, or a doctor for
        // their own patient. Front-desk staff can still only ADD (the
        // long-standing split this mirrors — see handle_appointment_save()).
        if ($patient_id && !current_user_can(MDBK_CAP_ADMIN) && !($is_doctor_only && $this->can_view_patient($patient_id))) {
            wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        }
        $post_data = ['post_title' => sanitize_text_field($_POST['patient_name']), 'post_type' => 'mdbk_patient', 'post_status' => 'publish'];
        if ($patient_id) $post_data['ID'] = $patient_id;
        $id = $patient_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        // A doctor's own list is built from who they've treated, so a
        // patient they just added by hand — with no appointment yet —
        // would vanish the moment the form closed. Recording who created
        // it keeps them in that doctor's list until a real booking exists.
        if (!$patient_id && $is_doctor_only && $id && !is_wp_error($id)) {
            $adding_doctor = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
            if ($adding_doctor) update_post_meta($id, '_mdbk_added_by_doctor', $adding_doctor);
        }
        if ($id && !is_wp_error($id)) {
            $p_phone = sanitize_text_field($_POST['patient_phone']);
            $p_email = sanitize_email($_POST['patient_email']);
            $p_age = isset($_POST['patient_age']) ? sanitize_text_field($_POST['patient_age']) : '';
            $p_gender = isset($_POST['patient_gender']) ? sanitize_text_field($_POST['patient_gender']) : '';
            update_post_meta($id, '_mdbk_patient_phone', $p_phone);
            update_post_meta($id, '_mdbk_patient_email', $p_email);
            // Address arrives as a District + Thana pair (see
            // MDBK_BD_Locations); _mdbk_patient_address stays written as
            // the composed one-liner so every reader of it — Bookings
            // row, print table, CSV, patient modal — is untouched. A pair
            // that isn't a real district/thana combination is ignored
            // rather than saved: the dropdowns can't produce one.
            $p_district = isset($_POST['patient_district']) ? sanitize_text_field($_POST['patient_district']) : '';
            $p_thana    = isset($_POST['patient_thana']) ? sanitize_text_field($_POST['patient_thana']) : '';
            if (\MDBK\MDBK_BD_Locations::is_valid($p_district, $p_thana)
                && \MDBK\MDBK_Appointment_Manager::should_write_location($id, $p_district)) {
                update_post_meta($id, '_mdbk_patient_district', $p_district);
                update_post_meta($id, '_mdbk_patient_thana', $p_thana);
                update_post_meta($id, '_mdbk_patient_address', \MDBK\MDBK_BD_Locations::format_address($p_district, $p_thana));
            }
            update_post_meta($id, '_mdbk_patient_age', $p_age);
            update_post_meta($id, '_mdbk_patient_gender', $p_gender);

            // Editing an EXISTING patient (not a brand-new one, which has
            // no appointments yet) — cascade the corrected name/phone/
            // email/age/gender onto every one of their bookings too. Each
            // appointment keeps its own denormalized copy of these fields
            // (see handle_appointment_save()) so the Bookings list/print/
            // invoice never have to join back to the patient CPT on every
            // render; without this, fixing a typo'd name here left it
            // wrong on every booking already on file for that patient.
            if ($patient_id) {
                $appointment_ids = get_posts([
                    'post_type'   => 'mdbk_appointment',
                    'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
                    'meta_key'    => '_mdbk_patient_id',
                    'meta_value'  => $patient_id,
                    'numberposts' => -1,
                    'fields'      => 'ids',
                ]);
                foreach ($appointment_ids as $app_id) {
                    wp_update_post(['ID' => $app_id, 'post_title' => 'Booking: ' . $post_data['post_title']]);
                    update_post_meta($app_id, '_mdbk_patient_name', $post_data['post_title']);
                    update_post_meta($app_id, '_mdbk_patient_phone', $p_phone);
                    update_post_meta($app_id, '_mdbk_patient_email', $p_email);
                    update_post_meta($app_id, '_mdbk_patient_age', $p_age);
                    update_post_meta($app_id, '_mdbk_patient_gender', $p_gender);
                }
            }

            wp_redirect(admin_url('admin.php?page=mdbk-patients&success=1'));
            exit;
        }
    }

    /**
     * Add/edit a staff account (Front Desk or Manager — see
     * staff_role_choices()) — admin-only, mirrors handle_doctor_save()'s
     * account-creation half (link_doctor_user()) but simpler: staff has
     * no CPT of its own, just a WP user with one of those two roles, so
     * this creates/updates that user directly rather than a linked post.
     */
    public function handle_staff_save() {
        if (!isset($_POST['mdbk_save_staff'])) return;
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_staff');

        $staff_id = !empty($_POST['staff_id']) ? intval($_POST['staff_id']) : 0;
        $name = sanitize_text_field($_POST['staff_name'] ?? '');
        $email = sanitize_email($_POST['staff_email'] ?? '');
        $phone = sanitize_text_field($_POST['staff_phone'] ?? '');
        $role_choices = self::staff_role_choices();
        // Whitelisted against the exact two known role slugs — never trust
        // a posted role string directly as a WP_User::set_role() argument,
        // since that's effectively a privilege-escalation vector otherwise.
        $role = isset($_POST['staff_role'], $role_choices[$_POST['staff_role']]) ? $_POST['staff_role'] : 'mdbk_receptionist';

        if (!$name || !$email) {
            wp_redirect(admin_url('admin.php?page=mdbk-staff&error=' . urlencode(__('Name and email are required.', 'doctor-appointment'))));
            exit;
        }

        if ($staff_id) {
            // Editing an existing staff account — must actually be one of
            // ours (either role), never repurpose this form to rename an
            // unrelated user.
            $user = get_user_by('id', $staff_id);
            if (!$user || !array_intersect(array_keys($role_choices), (array) $user->roles)) {
                wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
            }
            $existing_email_user = email_exists($email);
            if ($existing_email_user && $existing_email_user != $staff_id) {
                wp_redirect(admin_url('admin.php?page=mdbk-staff&error=' . urlencode(__('That email is already used by another account.', 'doctor-appointment'))));
                exit;
            }
            wp_update_user(['ID' => $staff_id, 'display_name' => $name, 'user_email' => $email]);
            // set_role() (not add_cap()) so switching Front Desk <-> Manager
            // actually REPLACES the old role rather than leaving both
            // granted at once.
            $user->set_role($role);
            update_user_meta($staff_id, '_mdbk_staff_phone', $phone);
            wp_redirect(admin_url('admin.php?page=mdbk-staff&success=1'));
            exit;
        }

        // New staff account. Same protective check as link_doctor_user() —
        // reusing an email that already belongs to someone NOT already
        // one of our two staff roles would silently hand an unrelated
        // account (patient, subscriber, another admin) staff/manager
        // access via a typo'd email.
        $existing_user_id = email_exists($email);
        if ($existing_user_id) {
            $existing_user = get_user_by('id', $existing_user_id);
            if (!array_intersect(array_keys($role_choices), (array) $existing_user->roles)) {
                wp_redirect(admin_url('admin.php?page=mdbk-staff&error=' . urlencode(__('An account with this email already exists.', 'doctor-appointment'))));
                exit;
            }
            // Already one of our staff roles under this email somehow
            // (shouldn't normally happen via this form) — just update them
            // instead of erroring.
            wp_update_user(['ID' => $existing_user_id, 'display_name' => $name]);
            $existing_user->set_role($role);
            update_user_meta($existing_user_id, '_mdbk_staff_phone', $phone);
            wp_redirect(admin_url('admin.php?page=mdbk-staff&success=1'));
            exit;
        }

        $new_user_id = wp_insert_user([
            'user_login' => self::generate_unique_username($email, 'mdbk_staff'),
            'user_email' => $email,
            'display_name' => $name,
            'user_pass'  => wp_generate_password(20, true),
            'role'       => $role,
        ]);
        if (is_wp_error($new_user_id)) {
            wp_redirect(admin_url('admin.php?page=mdbk-staff&error=' . urlencode($new_user_id->get_error_message())));
            exit;
        }
        update_user_meta($new_user_id, '_mdbk_staff_phone', $phone);
        // WP core's standard "your account" email — a password-*reset*
        // link, not a raw password, matching how a new doctor account is
        // handed to them (link_doctor_user()).
        wp_new_user_notification($new_user_id, null, 'user');
        wp_redirect(admin_url('admin.php?page=mdbk-staff&success=1'));
        exit;
    }

    public function handle_appointment_save() {
        if (!isset($_POST['mdbk_save_appointment'])) return;
        // Staff/admin, or a pure doctor account creating a NEW booking
        // under their own name (the "+ New Booking" button on their own
        // single-doctor Booking header) — editing an existing one stays
        // MDBK_CAP_ADMIN-only regardless, via the separate check further
        // down this same method.
        $is_queue_staff = current_user_can(MDBK_CAP_QUEUE);
        $own_doctor_id = (!$is_queue_staff && current_user_can(MDBK_CAP_DOCTOR)) ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id()) : 0;
        if (!$is_queue_staff && !$own_doctor_id) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_appointment');

        $app_id = !empty($_POST['app_id']) ? intval($_POST['app_id']) : 0;

        if (!$app_id) {
            // Same completeness rules the public form is held to — a
            // booking taken at the desk is no less of a booking. Only on
            // CREATE: editing an existing one stays savable even when its
            // patient predates these fields, so nobody has to invent a
            // district just to correct a phone number.
            $new_age = isset($_POST['age']) ? trim(sanitize_text_field($_POST['age'])) : '';
            if (!\MDBK\MDBK_Appointment_Manager::is_valid_age($new_age)) {
                wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode(__('Please enter the patient\'s age.', 'doctor-appointment'))));
                exit;
            }
            $new_location_error = \MDBK\MDBK_Appointment_Manager::location_error(
                isset($_POST['patient_district']) ? sanitize_text_field($_POST['patient_district']) : '',
                isset($_POST['patient_thana']) ? sanitize_text_field($_POST['patient_thana']) : ''
            );
            if ($new_location_error) {
                wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode($new_location_error)));
                exit;
            }

            // New booking: reuse the same shared submission logic the
            // frontend uses, so patient linking / slot conflict checks /
            // ticket numbering all stay consistent in one place.
            $data = [
                'full_name' => $_POST['patient_name'],
                'mobile'    => $_POST['patient_phone'],
                'email'     => isset($_POST['patient_email']) ? $_POST['patient_email'] : '',
                'age'       => isset($_POST['age']) ? $_POST['age'] : '',
                'gender'    => isset($_POST['gender']) ? $_POST['gender'] : '',
                // District/Thana pair from the modal's address selects —
                // validated and composed onto the patient record by
                // find_or_create_patient(), same as the public form.
                'district'  => isset($_POST['patient_district']) ? $_POST['patient_district'] : '',
                'thana'     => isset($_POST['patient_thana']) ? $_POST['patient_thana'] : '',
                // A doctor-only submitter always books under their own
                // name — never trust the posted doctor_id for them, same
                // rule handle_schedule_export()/ajax_refresh_doctor_group()
                // already enforce for their own doctor_id/filter_doctor.
                'doctor'    => $own_doctor_id ?: $_POST['doctor_id'],
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

        // Editing an existing appointment is manager-only (matches Delete's
        // existing gate in handle_delete_actions()) — front-desk staff can
        // still create NEW bookings via the QUEUE-level gate above, just
        // not modify one already on the books.
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));

        // Editing an existing appointment.
        $p_name  = sanitize_text_field($_POST['patient_name']);
        $p_phone = sanitize_text_field($_POST['patient_phone']);
        $doctor_id = intval($_POST['doctor_id']);
        $date      = sanitize_text_field($_POST['app_date']);
        $slot_time = isset($_POST['slot_time']) ? sanitize_text_field($_POST['slot_time']) : '';
        $old_date  = get_post_meta($app_id, '_mdbk_appointment_date', true);
        $old_slot_time = get_post_meta($app_id, '_mdbk_slot_time', true);
        $old_doctor_id = intval(get_post_meta($app_id, '_mdbk_doctor_id', true));

        // A blank slot_time here means the selected doctor's picker is
        // hidden from patients (is_slot_enabled() off) — the Add/Edit
        // modal's own JS blanks this field whenever that's the case,
        // including on Edit-open (updateAppSlotTimeAvailability(),
        // admin-script.js), so this branch can't just assume "blank means
        // leave it blank" the way it used to: without resolving a real
        // slot here too, simply re-saving any OTHER field on one of these
        // bookings (fixing a typo'd phone number, say) would silently wipe
        // an already-auto-assigned time back to empty on every save. This
        // is the same resolve-under-lock handle_submission() does for a
        // brand new booking — that function only covers NEW bookings
        // (this is the separate edit path), so it's repeated here rather
        // than reachable from one shared call.
        $lock_key = $slot_time !== ''
            ? 'slot|' . $doctor_id . '|' . $date . '|' . $slot_time
            : 'autoassign|' . $doctor_id . '|' . $date;
        if (!\MDBK\MDBK_Appointment_Manager::acquire_lock($lock_key)) {
            wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode(__('This slot is being booked by someone else right now. Please try again.', 'doctor-appointment'))));
            exit;
        }
        // exit() below bypasses finally entirely (unlike return), so each
        // early-out releases the lock explicitly before exiting — the
        // finally below only covers the normal fall-through case. A
        // second release on that path is harmless (deleting an
        // already-gone transient is a no-op).
        try {
            if ($slot_time === '') {
                $slot_time = \MDBK\MDBK_Appointment_Manager::find_next_available_slot($doctor_id, $date, $app_id);
                if ($slot_time === '') {
                    \MDBK\MDBK_Appointment_Manager::release_lock($lock_key);
                    wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode(__('No available time could be assigned for this doctor on this date.', 'doctor-appointment'))));
                    exit;
                }
            }
            if (\MDBK\MDBK_Appointment_Manager::is_slot_taken($doctor_id, $date, $slot_time, $app_id)) {
                \MDBK\MDBK_Appointment_Manager::release_lock($lock_key);
                wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode(__('That time slot is already booked.', 'doctor-appointment'))));
                exit;
            }
        } finally {
            \MDBK\MDBK_Appointment_Manager::release_lock($lock_key);
        }

        $p_email = isset($_POST['patient_email']) ? sanitize_email($_POST['patient_email']) : '';
        $p_age = isset($_POST['age']) ? sanitize_text_field($_POST['age']) : '';
        $p_gender = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : '';
        // Address lives on the patient record only — the Bookings row reads
        // it back from there rather than keeping a copy per booking, so a
        // patient who moves is corrected once and every one of their
        // bookings shows the new address. Captured as District + Thana
        // (MDBK_BD_Locations), validated and composed by
        // find_or_create_patient().
        $patient_id = \MDBK\MDBK_Appointment_Manager::find_or_create_patient($p_name, $p_phone, [
            'email'    => $p_email,
            'age'      => $p_age,
            'gender'   => $p_gender,
            'district' => isset($_POST['patient_district']) ? sanitize_text_field($_POST['patient_district']) : '',
            'thana'    => isset($_POST['patient_thana']) ? sanitize_text_field($_POST['patient_thana']) : '',
        ]);

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
                // Dropping a check-in shifts everyone who arrived after
                // this patient up one place under check-in-order mode
                // (their numbers are read off the arrival list, see
                // MDBK_Appointment_Manager::checkin_ticket_number()), so the
                // memo of that list can't be trusted for the rest of this
                // request.
                \MDBK\MDBK_Appointment_Manager::flush_checkin_rank_cache();
            }
            // Only booking-order mode keeps a stored number to maintain
            // here. Check-in-order mode derives each patient's number from
            // when they actually arrived, so there's nothing to reassign —
            // and _mdbk_ticket_number is deliberately left untouched rather
            // than cleared, so switching the setting back restores every
            // booking-order number exactly as it was.
            if (\MDBK\MDBK_Appointment_Manager::queue_serial_mode($doctor_id) !== 'checkin') {
                // Reassign when the date or doctor changed (rescheduling),
                // or when there was never one (legacy record) — unchanged
                // from before this setting existed.
                if (!get_post_meta($id, '_mdbk_ticket_number', true) || $date !== $old_date || $doctor_id !== $old_doctor_id) {
                    update_post_meta($id, '_mdbk_ticket_number', \MDBK\MDBK_Appointment_Manager::next_ticket_number($doctor_id, $date, $id));
                }
            }
            wp_redirect(admin_url('admin.php?page=mdbk-schedule&success=1'));
            exit;
        }
    }

    public function handle_specialty_save() {
        if (!isset($_POST['mdbk_save_specialty'])) return;
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_specialty');
        $term_id = !empty($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $name = sanitize_text_field($_POST['spec_name']);
        $result = $term_id ? wp_update_term($term_id, 'mdbk_department', ['name' => $name]) : wp_insert_term($name, 'mdbk_department');
        if (!is_wp_error($result)) {
            $saved_id = $result['term_id'];
            $icon_id = !empty($_POST['icon_id']) ? intval($_POST['icon_id']) : 0;
            if ($icon_id) { update_term_meta($saved_id, '_mdbk_specialty_icon', $icon_id); } else { delete_term_meta($saved_id, '_mdbk_specialty_icon'); }
            update_term_meta($saved_id, '_mdbk_specialty_active', isset($_POST['status']) ? 'yes' : 'no');
            // A brand new specialty joins the END of the drag-and-drop
            // order (same reasoning as new doctors above) — existing ones
            // already have this meta from migrate_to_v9() or a previous
            // save, so this only ever fires for a genuinely new term.
            if (!$term_id && get_term_meta($saved_id, '_mdbk_specialty_order', true) === '') {
                $max_order = -1;
                foreach (get_terms(['taxonomy' => 'mdbk_department', 'hide_empty' => false, 'fields' => 'ids']) as $existing_id) {
                    $order = get_term_meta($existing_id, '_mdbk_specialty_order', true);
                    if ($order !== '') $max_order = max($max_order, (int) $order);
                }
                update_term_meta($saved_id, '_mdbk_specialty_order', $max_order + 1);
            }
        }
        wp_redirect(admin_url('admin.php?page=mdbk-specialties&success=1'));
        exit;
    }

    public function handle_global_settings_save() {
        if (!isset($_POST['mdbk_save_global_settings'])) return;
        if (!current_user_can(MDBK_CAP_ADMIN)) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_global_settings');
        update_option('mdbk_clinic_name', sanitize_text_field($_POST['clinic_name'] ?? ''));
        update_option('mdbk_clinic_contact', sanitize_text_field($_POST['clinic_contact'] ?? ''));
        update_option('mdbk_clinic_address', sanitize_textarea_field($_POST['clinic_address'] ?? ''));
        $logo_id = !empty($_POST['clinic_logo_id']) ? intval($_POST['clinic_logo_id']) : 0;
        if ($logo_id) { update_option('mdbk_clinic_logo', $logo_id); } else { delete_option('mdbk_clinic_logo'); }
        // sanitize_hex_color() returns '' for anything invalid rather than
        // silently keeping a bad value — falls through to update_option's
        // own default handling (get_option()'s fallback) the same as an
        // unset option would.
        $primary_color = sanitize_hex_color($_POST['color_primary'] ?? '');
        update_option('mdbk_color_primary', $primary_color ?: self::DEFAULT_COLOR_PRIMARY);
        $secondary_color = sanitize_hex_color($_POST['color_secondary'] ?? '');
        update_option('mdbk_color_secondary', $secondary_color ?: self::DEFAULT_COLOR_SECONDARY);
        update_option('mdbk_enable_live_queue', isset($_POST['enable_live_queue']) ? 'yes' : 'no');
        // Queue & Ticketing moved OUT of global settings — each doctor picks
        // their own serial mode in the doctor-edit modal now (see
        // handle_doctor_save()). The legacy mdbk_queue_serial_mode option is
        // left in place untouched: queue_serial_mode() still reads it as the
        // site-wide default for any doctor who never chose their own mode.
        wp_redirect(admin_url('admin.php?page=mdbk-global-settings&success=1'));
        exit;
    }

    /**
     * Give a stored queue number to any current booking that never got one.
     *
     * Only bookings taken while check-in-order mode was active are ever in
     * this state: that mode derives each patient's number from when they
     * arrive rather than stamping one at booking time (see
     * MDBK_Appointment_Manager::checkin_ticket_number()), so nothing was
     * written for them. Switching back to booking order would otherwise
     * leave exactly those rows blank, since booking order has only the
     * stored number to show.
     *
     * Scoped to today and later — a past day's queue is finished, and
     * renumbering it would rewrite history nobody is looking at. Ordered
     * by date then slot time so a day filled in here reads chronologically
     * rather than in whatever order the query happened to return.
     *
     * $doctor_id (optional) scopes it to one doctor's bookings — used when
     * that doctor's own profile switches their queue serial mode back to
     * booking order; other doctors' checkin-mode blanks are none of this
     * save's business.
     */
    private function backfill_missing_ticket_numbers($doctor_id = 0) {
        $today = current_time('Y-m-d');
        $meta_query = [
            'relation' => 'AND',
            ['key' => '_mdbk_appointment_date', 'value' => $today, 'compare' => '>='],
            ['key' => '_mdbk_ticket_number', 'compare' => 'NOT EXISTS'],
        ];
        if ($doctor_id) {
            $meta_query[] = ['key' => '_mdbk_doctor_id', 'value' => intval($doctor_id)];
        }
        $pending = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'numberposts' => -1,
            'meta_query'  => $meta_query,
        ]);
        usort($pending, function($a, $b) {
            $key_a = get_post_meta($a->ID, '_mdbk_appointment_date', true) . ' ' . get_post_meta($a->ID, '_mdbk_slot_time', true);
            $key_b = get_post_meta($b->ID, '_mdbk_appointment_date', true) . ' ' . get_post_meta($b->ID, '_mdbk_slot_time', true);
            return strcmp($key_a, $key_b);
        });

        // Deliberately NOT next_ticket_number() per row: that counts a
        // doctor+date's appointments and adds one, which is only the next
        // free number while every appointment already holds a ticket — its
        // normal one-new-booking-at-a-time caller. Here a whole group is
        // missing one at once, so every row in that group would count the
        // same total and all be handed the SAME number. Carrying the
        // highest number already in use per doctor+date and stepping up
        // from it gives each row its own.
        $next_by_group = [];
        foreach ($pending as $app) {
            $doctor_id = intval(get_post_meta($app->ID, '_mdbk_doctor_id', true));
            $date      = get_post_meta($app->ID, '_mdbk_appointment_date', true);
            if (!$doctor_id || !$date) continue;

            $group = $doctor_id . '|' . $date;
            if (!isset($next_by_group[$group])) {
                $highest = 0;
                foreach (get_posts([
                    'post_type'   => 'mdbk_appointment',
                    'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
                    'numberposts' => -1,
                    'fields'      => 'ids',
                    'meta_query'  => [
                        'relation' => 'AND',
                        ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                        ['key' => '_mdbk_appointment_date', 'value' => $date],
                    ],
                ]) as $existing_id) {
                    $highest = max($highest, intval(get_post_meta($existing_id, '_mdbk_ticket_number', true)));
                }
                $next_by_group[$group] = $highest + 1;
            }

            update_post_meta($app->ID, '_mdbk_ticket_number', $next_by_group[$group]);
            $next_by_group[$group]++;
        }
    }

    // Matches the plugin's own already-established frontend brand colors
    // (assets/css/front-end.css's :root fallback) — a fresh install's
    // Global Settings starts on exactly what the CSS already looked like
    // before this setting existed, not some arbitrary new default.
    const DEFAULT_COLOR_PRIMARY = '#0061d5';
    const DEFAULT_COLOR_SECONDARY = '#16a34a';

    public function render_global_settings_page() {
        $clinic_name = get_option('mdbk_clinic_name', '');
        $clinic_contact = get_option('mdbk_clinic_contact', '');
        $clinic_address = get_option('mdbk_clinic_address', '');
        $clinic_logo_id = get_option('mdbk_clinic_logo', 0);
        $clinic_logo_url = $clinic_logo_id ? wp_get_attachment_image_url($clinic_logo_id, 'thumbnail') : '';
        $color_primary = get_option('mdbk_color_primary', self::DEFAULT_COLOR_PRIMARY);
        $color_secondary = get_option('mdbk_color_secondary', self::DEFAULT_COLOR_SECONDARY);
        $enable_live_queue = get_option('mdbk_enable_live_queue', 'yes') !== 'no';
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('global-settings'); ?>
            <div class="mdbk-main-content mdbk-main-content-fixed-header">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Settings', 'doctor-appointment'); ?></h1></div></div>
                <div class="mdbk-global-settings-scroll-wrap">
                <?php if (isset($_GET['success'])) : ?>
                    <p style="color:#16A34A; font-weight:600; margin-top:0;"><?php _e('Settings saved.', 'doctor-appointment'); ?></p>
                <?php endif; ?>
                <form method="POST">
                    <?php wp_nonce_field('mdbk_save_global_settings'); ?>
                    <input type="hidden" name="clinic_logo_id" id="mdbk-clinic-logo-id" value="<?php echo esc_attr($clinic_logo_id ?: 0); ?>">

                    <div class="mdbk-settings-grid">
                    <div class="mdbk-card" style="padding:24px;">
                        <h3 style="margin:0 0 16px; font-size:15px;"><?php _e('Clinic Information', 'doctor-appointment'); ?></h3>
                        <div class="mdbk-form-row">
                            <label class="mdbk-form-label"><?php _e('Logo', 'doctor-appointment'); ?></label>
                            <div class="mdbk-photo-picker">
                                <div class="mdbk-photo-preview" id="mdbk-clinic-logo-preview">
                                    <?php if ($clinic_logo_url) : ?>
                                        <img src="<?php echo esc_url($clinic_logo_url); ?>" alt="">
                                    <?php else : ?>
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
                                    <?php endif; ?>
                                </div>
                                <div class="mdbk-photo-actions">
                                    <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-clinic-logo-upload"><?php _e('Select Image', 'doctor-appointment'); ?></button>
                                    <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-clinic-logo-remove" style="<?php echo $clinic_logo_url ? '' : 'display:none;'; ?>"><?php _e('Remove', 'doctor-appointment'); ?></button>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top:16px;"><label class="mdbk-form-label" for="mdbk-clinic-name"><?php _e('Clinic Name', 'doctor-appointment'); ?></label><input type="text" name="clinic_name" id="mdbk-clinic-name" class="mdbk-input" value="<?php echo esc_attr($clinic_name); ?>" placeholder="<?php esc_attr_e('e.g. Shafiul Amraz Medical Center', 'doctor-appointment'); ?>"></div>
                        <div style="margin-top:16px;"><label class="mdbk-form-label" for="mdbk-clinic-contact"><?php _e('Contact Info', 'doctor-appointment'); ?></label><input type="text" name="clinic_contact" id="mdbk-clinic-contact" class="mdbk-input" value="<?php echo esc_attr($clinic_contact); ?>" placeholder="<?php esc_attr_e('e.g. 01700-000000, info@clinic.com', 'doctor-appointment'); ?>"></div>
                        <div style="margin-top:16px;"><label class="mdbk-form-label" for="mdbk-clinic-address"><?php _e('Address', 'doctor-appointment'); ?></label><textarea name="clinic_address" id="mdbk-clinic-address" class="mdbk-input" rows="3" placeholder="<?php esc_attr_e('e.g. House 12, Road 5, Dhaka', 'doctor-appointment'); ?>"><?php echo esc_textarea($clinic_address); ?></textarea></div>
                    </div>

                    <div class="mdbk-card" style="padding:24px;">
                        <h3 style="margin:0 0 16px; font-size:15px;"><?php _e('Booking Page Appearance', 'doctor-appointment'); ?></h3>
                        <div class="mdbk-form-row mdbk-form-row-duo">
                            <div>
                                <label class="mdbk-form-label" for="mdbk-color-primary"><?php _e('Primary Color', 'doctor-appointment'); ?></label>
                                <input type="color" name="color_primary" id="mdbk-color-primary" value="<?php echo esc_attr($color_primary); ?>" data-default="<?php echo esc_attr(self::DEFAULT_COLOR_PRIMARY); ?>" style="width:100%; height:42px; padding:4px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer;">
                            </div>
                            <div>
                                <label class="mdbk-form-label" for="mdbk-color-secondary"><?php _e('Secondary Color', 'doctor-appointment'); ?></label>
                                <input type="color" name="color_secondary" id="mdbk-color-secondary" value="<?php echo esc_attr($color_secondary); ?>" data-default="<?php echo esc_attr(self::DEFAULT_COLOR_SECONDARY); ?>" style="width:100%; height:42px; padding:4px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer;">
                            </div>
                        </div>
                        <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-colors-reset" style="margin-top:10px;"><?php _e('Reset to Default Colors', 'doctor-appointment'); ?></button>
                        <p class="mdbk-form-hint"><?php _e('Used on the patient-facing booking pages (buttons, highlights, links) — match these to your site theme, or leave as the defaults.', 'doctor-appointment'); ?></p>
                        <div style="margin-top:16px; display:flex; align-items:center; gap:10px;">
                            <label class="mdbk-toggle">
                                <input type="checkbox" name="enable_live_queue" value="1" <?php checked($enable_live_queue); ?>>
                                <span class="mdbk-toggle-slider"></span>
                            </label>
                            <label class="mdbk-form-label" for="mdbk-enable-live-queue" style="margin:0;"><?php _e('Enable Live Queue (the public [mdbk_queue_list] display)', 'doctor-appointment'); ?></label>
                        </div>
                        <p class="mdbk-form-hint"><?php _e('When off, the Live Queue page(s) show a simple "not available" message instead of the queue — useful if you don\'t want walk-in patients\' names visible on a public screen.', 'doctor-appointment'); ?></p>
                    </div>

                    </div>

                    <div class="mdbk-global-settings-save-row">
                        <button type="submit" name="mdbk_save_global_settings" class="mdbk-btn-save"><?php _e('Save Settings', 'doctor-appointment'); ?></button>
                    </div>
                </form>
                </div>
            </div></div></div>
        <?php
    }

    public function render_license_page() {
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            wp_die(__('You do not have permission to access this page.', 'doctor-appointment'));
        }

        $license_key = MDBK_Licensing::get_key();
        $license_status = MDBK_Licensing::get_status();
        $license_expires = MDBK_Licensing::get_expires();
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('license'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('License', 'doctor-appointment'); ?></h1></div>

                <div class="mdbk-card" style="padding:24px; max-width:520px;">
                    <div id="mdbk-license-activated" style="<?php echo $license_key ? '' : 'display:none;'; ?>">
                        <div class="mdbk-form-row">
                            <label class="mdbk-form-label"><?php _e('License Key', 'doctor-appointment'); ?></label>
                            <span class="mdbk-key-chip"><?php echo $license_key ? esc_html(substr($license_key, 0, 9) . '-••••-••••-' . substr($license_key, -8, 4)) : ''; ?></span>
                        </div>
                        <div class="mdbk-form-row" style="display:flex; align-items:center; gap:10px;">
                            <?php
                            $badge_class = 'mdbk-badge-gray';
                            if ('active' === $license_status) {
                                $badge_class = 'mdbk-badge-green';
                            } elseif (in_array($license_status, ['expired', 'revoked', 'invalid', 'limit_reached'], true)) {
                                $badge_class = 'mdbk-badge-red';
                            }
                            ?>
                            <span class="mdbk-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html(ucfirst($license_status)); ?></span>
                            <?php if ($license_expires) : ?>
                                <span class="mdbk-form-hint" style="margin:0;"><?php echo esc_html(sprintf(__('Expires %s', 'doctor-appointment'), $license_expires)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; gap:10px; margin-top:16px;">
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-license-refresh"><?php _e('Refresh Status', 'doctor-appointment'); ?></button>
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-license-deactivate"><?php _e('Deactivate', 'doctor-appointment'); ?></button>
                        </div>
                    </div>

                    <div id="mdbk-license-inactive" style="<?php echo $license_key ? 'display:none;' : ''; ?>">
                        <div class="mdbk-form-row">
                            <label class="mdbk-form-label" for="mdbk-license-key-input"><?php _e('License Key', 'doctor-appointment'); ?></label>
                            <input type="text" id="mdbk-license-key-input" class="mdbk-input" placeholder="<?php esc_attr_e('e.g. A1B2C3D4-E5F6A7B8-C9D0E1F2-A3B4C5D6', 'doctor-appointment'); ?>">
                        </div>
                        <button type="button" class="mdbk-btn-save" id="mdbk-license-activate"><?php _e('Activate', 'doctor-appointment'); ?></button>
                    </div>

                    <p id="mdbk-license-message" style="margin-top:12px; font-size:13px;"></p>
                </div>
            </div></div></div>
        <?php
    }

    /**
     * A doctor-with-stethoscope glyph for the native "MedBook" menu item,
     * in place of the generic dashicons-plus-alt it used before. Filled at
     * #a7aaad — the same grey WordPress's own dashicons render at in their
     * unselected state — so the existing core opacity/hover CSS for
     * #adminmenu still dims/brightens it correctly without any extra CSS.
     */
    private static function menu_icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad">'
            . '<circle cx="12" cy="6.2" r="3.4"/>'
            . '<path d="M12 11c-4.6 0-8.2 2.5-8.2 6v1.8c0 .8.6 1.4 1.4 1.4h13.6c.8 0 1.4-.6 1.4-1.4V17c0-3.5-3.6-6-8.2-6z"/>'
            . '<path d="M8.8 12.2v2.6a3.2 3.2 0 006.4 0v-2.6" fill="none" stroke="#a7aaad" stroke-width="1.3" stroke-linecap="round"/>'
            . '<circle cx="15.5" cy="16.5" r="1" />'
            . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
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
        // the Dashboard, a doctor-only or staff-only user lands on their
        // "Booking" queue — see render_medbook_home(). doctor_login_redirect()
        // and the custom sidebar's own links still target
        // 'mdbk-dashboard'/'mdbk-schedule' directly, unaffected by any of this.
        add_menu_page('MedBook', 'MedBook', MDBK_CAP_DOCTOR, 'mdbk-home', [$this, 'render_medbook_home'], self::menu_icon(), 25);
        add_submenu_page('mdbk-home', 'Dashboard', 'Dashboard', MDBK_CAP_ADMIN, 'mdbk-dashboard', [$this, 'render_dashboard']);
        // 'read' (not MDBK_CAP_DOCTOR alone) — front-desk staff gets its own
        // account Profile + Change Password too now, same as a doctor;
        // render_profile_page()/render_change_password_page() do their own
        // real access check first thing, same OR-capability pattern as
        // Booking/Patients above.
        add_submenu_page('mdbk-home', 'Profile', 'Profile', 'read', 'mdbk-profile', [$this, 'render_profile_page']);
        add_submenu_page('mdbk-home', 'Change Password', 'Change Password', 'read', 'mdbk-change-password', [$this, 'render_change_password_page']);

        // Booking — the ONE main operational page: bookings + today's
        // queue + every status action (Check-In, Start Visiting, Mark as
        // Visited, Skip) all live here now, for both a doctor (scoped to
        // their own patients) and front-desk staff (every doctor,
        // grouped) — see render_schedule_page(). Capability is
        // deliberately the blanket 'read', not MDBK_CAP_QUEUE — those two
        // roles share no single capability in common (see roles.php:
        // mdbk_doctor_role has MDBK_CAP_DOCTOR, mdbk_receptionist has
        // MDBK_CAP_QUEUE, neither has the other) — render_schedule_page()
        // does its own real access check first thing, same OR-capability
        // pattern WP core itself uses for profile.php.
        add_submenu_page('mdbk-home', 'Booking', 'Booking', 'read', 'mdbk-schedule', [$this, 'render_schedule_page']);

        // Patient Directory — registered patients only (view/add/edit),
        // deliberately separate from Booking's day-to-day queue/status
        // work above. MDBK_CAP_QUEUE (not manage_options), so front-desk
        // staff manages the patient registry too, not just admin.
        //
        // A doctor reaches the same page through MDBK_CAP_DOCTOR, but sees
        // only the patients they've actually treated and can't add or
        // delete registry entries — see render_patients_page(), which does
        // that scoping. The capability check here only decides who gets in
        // the door; 'read' is the floor both roles clear.
        add_submenu_page('mdbk-home', 'Patients', 'Patients', 'read', 'mdbk-patients', [$this, 'render_patients_page']);

        $hidden_pages = ['mdbk-doctors' => 'render_doctors_page', 'mdbk-staff' => 'render_staff_page', 'mdbk-specialties' => 'render_specialties_page', 'mdbk-global-settings' => 'render_global_settings_page', 'mdbk-license' => 'render_license_page'];
        foreach($hidden_pages as $slug => $cb) add_submenu_page('mdbk-home', $slug, $slug, MDBK_CAP_ADMIN, $slug, [$this, $cb]);

        // "Chamber QR" — a one-time "print this and hang it in the
        // chamber" view per doctor, reached via a link on the Doctors
        // grid. Same MDBK_CAP_QUEUE gate as the Bookings page (not
        // manage_options), since a receptionist managing the queue should
        // be able to print/reprint one too.
        add_submenu_page('mdbk-home', 'mdbk-chamber-qr', 'mdbk-chamber-qr', MDBK_CAP_QUEUE, 'mdbk-chamber-qr', [$this, 'render_chamber_qr_page']);

        // A doctor or front-desk-staff account (no manage_options) now has
        // its own "Profile" page above — WP core's native profile.php link
        // is redundant with it and confusing to have both, so it's removed
        // from this role's sidebar entirely, same as index.php (WP's own
        // Dashboard) — neither role has any use for either, everything they
        // can do lives under "MedBook". Left untouched for admin/other
        // roles. get_edit_profile_url() is also filtered (see
        // redirect_profile_url()) so the admin toolbar's "Howdy" / "Edit
        // Profile" links point at this same page too. The entire native
        // sidebar is hidden via CSS for both roles — see admin_body_class()
        // below.
        if ($this->is_restricted_panel_user()) {
            remove_menu_page('index.php');
            remove_menu_page('profile.php');
        }
    }

    /**
     * True for an account confined to this plugin's own full-screen panel
     * (a doctor, or front-desk staff) — anyone with MDBK_CAP_DOCTOR or
     * MDBK_CAP_QUEUE but not manage_options. Shared by admin_body_class()
     * and register_admin_menu()'s native-menu trimming so the two stay in
     * sync about who gets the immersive, WP-chrome-free treatment.
     */
    private function is_restricted_panel_user() {
        // A Manager (mdbk_manager_role) has MDBK_CAP_ADMIN — full access
        // to this plugin's own panel — but deliberately NOT real
        // 'manage_options' (see MDBK_Roles::activate()), so this
        // capability-only check already correctly includes them without
        // needing a role-name special-case: a real administrator is the
        // only account excluded here.
        return (current_user_can(MDBK_CAP_DOCTOR) || current_user_can(MDBK_CAP_QUEUE)) && !current_user_can('manage_options');
    }

    /**
     * True on any of this plugin's own admin.php?page=mdbk-* screens.
     * Shared by enforce_panel_only_access() (restricted-user redirect
     * exemption) and admin_body_class() (scoping the chrome-hiding class
     * for a real administrator to just these pages, not every wp-admin
     * screen — see that method's comment).
     */
    private function is_mdbk_page() {
        global $pagenow;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return 'admin.php' === $pagenow && strpos($page, 'mdbk-') === 0;
    }

    /**
     * Bounces a restricted panel user off every wp-admin screen this plugin
     * doesn't own — direct-URL (or default post-login) access to a native
     * screen (Dashboard, Media, Users, Plugins, Themes, Settings, Tools,
     * profile.php, another plugin's own admin.php page, etc.) redirects
     * back into the panel instead of rendering WordPress's native UI.
     *
     * This is the missing half of admin_body_class()'s CSS-based chrome
     * hiding: admin-style.css (where every mdbk-doctor-chrome rule lives)
     * is only ever enqueued when the current admin page's hook contains
     * "mdbk" (see admin_enqueue_scripts() in the main plugin file) — so on
     * any screen that ISN'T one of this plugin's own, the mdbk-doctor-chrome
     * body class still gets added, but there's no stylesheet loaded to act
     * on it, and the full native sidebar/toolbar shows through untouched.
     * Redirecting away from those screens entirely, rather than trying to
     * load this plugin's CSS on every wp-admin screen, is the fix.
     *
     * admin-ajax.php/admin-post.php/async-upload.php stay open since this
     * plugin's own AJAX handlers, doctor-save form, and password-change
     * form all run through them and render no visible chrome anyway.
     */
    public function enforce_panel_only_access() {
        if (!$this->is_restricted_panel_user()) {
            return;
        }

        global $pagenow;

        if (in_array($pagenow, ['admin-ajax.php', 'admin-post.php', 'async-upload.php'], true)) {
            return;
        }

        if ($this->is_mdbk_page()) {
            return;
        }

        // 'mdbk-home' itself is registered on MDBK_CAP_DOCTOR (see
        // register_admin_menu()), which a Manager doesn't have — sending
        // everyone there regardless of role would 403 a Manager account
        // right back out. Same per-role destination doctor_login_redirect()
        // already uses.
        $target = current_user_can(MDBK_CAP_ADMIN) ? 'mdbk-dashboard' : 'mdbk-schedule';
        wp_safe_redirect(admin_url('admin.php?page=' . $target));
        exit;
    }

    /**
     * Appends a body class so admin-style.css can hide the entire native
     * WP left-hand sidebar for a doctor or front-desk-staff account (no
     * manage_options) — the plugin's own full-screen sidebar
     * (render_sidebar()) is already the only navigation either role ever
     * needs, so WP's native menu column would just be near-empty chrome
     * around it.
     */
    public function admin_body_class($classes) {
        if ($this->is_restricted_panel_user()) {
            $classes .= ' mdbk-doctor-chrome';
        } elseif (current_user_can('manage_options') && $this->is_mdbk_page()) {
            // A real administrator isn't funneled into this panel the way a
            // doctor/front-desk/manager login is (enforce_panel_only_access()
            // exempts them entirely) — they still need ordinary wp-admin
            // access to Plugins/Users/Settings/etc, so the native chrome is
            // only hidden here while they're actually ON one of this
            // plugin's own pages, not globally like it is for a restricted
            // login. render_sidebar()'s "Back to WordPress" link (admin-only)
            // is how they get back to the native UI once it's hidden.
            $classes .= ' mdbk-doctor-chrome';
        }
        return $classes;
    }

    /**
     * The wp-admin sidebar/toolbar hiding above (admin_body_class(), CSS)
     * only ever touches wp-admin's own body tag — WordPress shows its
     * front-end admin bar (the same black toolbar, on the public site) to
     * any logged-in user independently of that, and nothing was disabling
     * it here. A doctor/front-desk/manager account has no reason to see
     * WP's own toolbar while browsing the public booking site; only a real
     * administrator (manage_options) still gets it there.
     */
    public function hide_front_end_admin_bar($show) {
        if ($this->is_restricted_panel_user()) {
            return false;
        }
        return $show;
    }

    /**
     * The single native "MedBook" top-level menu item's callback — routes
     * by role instead of showing a WP-native submenu: an admin sees the
     * full Dashboard, a non-admin (doctor or front-desk staff) sees their
     * own Booking queue. Keeps the native sidebar down to one clean entry
     * per user; all other navigation happens via the plugin's own in-page
     * sidebar (render_sidebar()).
     */
    public function render_medbook_home() {
        if (current_user_can(MDBK_CAP_ADMIN)) {
            $this->render_dashboard();
        } else {
            $this->render_schedule_page();
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
        $working_today_doctors = array_filter(get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']), function($doc) use ($today) {
            return \MDBK\MDBK_Appointment_Manager::is_doctor_working_on($doc->ID, $today);
        });
        // Only doctors who actually have a booking today get a card here —
        // an empty "No patients yet" card for every scheduled-but-idle
        // doctor cluttered the dashboard more than it informed.
        $doctors_with_bookings_today = array_filter($working_today_doctors, function($doc) use ($today_groups) {
            return !empty($today_groups[$doc->ID]['appointments']);
        });
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('dashboard'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Medical Overview', 'doctor-appointment'); ?></h1><p><?php _e('Track your daily operations.', 'doctor-appointment'); ?></p></div><div class="mdbk-header-right"><input type="text" class="mdbk-search-box" placeholder="<?php esc_attr_e('Quick search...', 'doctor-appointment'); ?>"><a href="#" class="mdbk-btn-add mdbk-add-appointment"><?php _e('+ New Booking', 'doctor-appointment'); ?></a></div></div>
                <div class="mdbk-stats-grid mdbk-stats-grid-compact">
                    <div class="mdbk-stat-card mdbk-stat-card-blue">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.5 21a8.5 8.5 0 0 0-17 0"></path><circle cx="12" cy="7.5" r="4.5"></circle></svg></div>
                        <div class="mdbk-stat-text">
                            <h4><?php _e('Doctors', 'doctor-appointment'); ?></h4>
                            <div class="value"><?php echo esc_html($stats['doctors']); ?></div>
                        </div>
                    </div>
                    <div class="mdbk-stat-card mdbk-stat-card-violet">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                        <div class="mdbk-stat-text">
                            <h4><?php _e('Patients', 'doctor-appointment'); ?></h4>
                            <div class="value"><?php echo esc_html($stats['patients']); ?></div>
                        </div>
                    </div>
                    <div class="mdbk-stat-card mdbk-stat-card-green">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
                        <div class="mdbk-stat-text">
                            <h4><?php _e('Bookings', 'doctor-appointment'); ?></h4>
                            <div class="value"><?php echo esc_html($stats['appointments']); ?></div>
                        </div>
                    </div>
                    <div class="mdbk-stat-card mdbk-stat-card-amber">
                        <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                        <div class="mdbk-stat-text">
                            <h4><?php _e("Today's Bookings", 'doctor-appointment'); ?></h4>
                            <div class="value"><?php echo esc_html(count($today_apps)); ?></div>
                        </div>
                    </div>
                </div>

                <div class="mdbk-dashboard-grid-container">
                    <div class="mdbk-section-header">
                        <h3><?php _e("Patient Bookings", 'doctor-appointment'); ?></h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule')); ?>" class="mdbk-view-all-link"><?php _e('View All', 'doctor-appointment'); ?> &rarr;</a>
                    </div>

                    <?php if (empty($doctors_with_bookings_today)): ?>
                        <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding: 40px; opacity:0.6;"><?php _e('No bookings today.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                    <?php else: ?>
                        <div class="mdbk-dashboard-doctor-groups mdbk-dashboard-doctor-grid">
                        <?php foreach ($doctors_with_bookings_today as $doctor): $doc_id = $doctor->ID; $apps = $today_groups[$doc_id]['appointments']; $count = count($apps); ?>
                            <div class="mdbk-card mdbk-dash-doctor-card">
                                <div class="mdbk-card-header">
                                    <div>
                                        <h3><?php echo esc_html($doctor->post_title); ?></h3>
                                        <span class="mdbk-dash-card-date"><?php echo esc_html(date_i18n('l, M j', strtotime($today))); ?></span>
                                    </div>
                                    <span class="mdbk-badge mdbk-badge-green"><?php echo esc_html($count); ?></span>
                                </div>
                                <ul class="mdbk-dash-patient-list">
                                <?php // Real label, not this loop's position — see
                                // render_today_queue_table()'s own comment on why a
                                // counter here disagreed with every other view. ?>
                                <?php foreach ($apps as $app): $p_age = get_post_meta($app->ID, '_mdbk_patient_age', true); ?>
                                    <li class="mdbk-dash-patient-item">
                                        <span class="mdbk-patient-row-ticket mdbk-patient-row-queue"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::display_ticket_label($app->ID)); ?></span>
                                        <span class="mdbk-dash-patient-name"><?php echo esc_html(get_post_meta($app->ID, '_mdbk_patient_name', true)); ?></span>
                                        <?php if ($p_age): ?><span class="mdbk-dash-patient-age"><?php echo esc_html(sprintf(__('%sy', 'doctor-appointment'), $p_age)); ?></span><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                                <div class="mdbk-card-view-all"><a href="#" data-doctor-modal="mdbk-doctor-modal-<?php echo esc_attr($doc_id); ?>"><?php _e('View All', 'doctor-appointment'); ?> &rarr;</a></div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div></div>
            <?php foreach ($doctors_with_bookings_today as $doctor): $doc_id = $doctor->ID; $apps = $today_groups[$doc_id]['appointments']; ?>
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
        $doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']);
        $specialties = \MDBK\MDBK_Appointment_Manager::get_specialty_terms(false);
        $total = count($doctors);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('doctors'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Doctor Directory', 'doctor-appointment'); ?></h1><p><?php echo esc_html(sprintf(_n('%d doctor', '%d doctors', $total, 'doctor-appointment'), $total)); ?></p></div></div>

                <div class="mdbk-staff-filters-bar">
                    <a href="#" class="mdbk-btn-add mdbk-add-doctor"><?php _e('+ Add New Doctor', 'doctor-appointment'); ?></a>
                    <?php if (current_user_can(MDBK_CAP_ADMIN)): ?>
                    <span class="mdbk-drag-hint" id="mdbk-doctor-drag-hint"><?php _e('Drag cards to reorder', 'doctor-appointment'); ?></span>
                    <?php endif; ?>
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
                    <?php else: foreach ($doctors as $d): echo $this->render_doctor_card($d, false); endforeach; endif; ?>
                    <?php // Trailing "add new" card — reuses .mdbk-add-doctor
                    // (same class the header button above uses) so
                    // initModal()'s querySelectorAll binding picks it up
                    // for free. Deliberately NOT .mdbk-admin-doctor-card —
                    // that class is what the search/specialty-filter/
                    // pagination JS above queries and toggles .is-hidden on
                    // (allCards()/matchingCards()), and this card has none
                    // of the data-name/data-specialty attributes that logic
                    // expects, so it must stay outside that class entirely
                    // to remain visible on every page/filter/search state
                    // rather than being paginated or filtered away. ?>
                    <a href="#" class="mdbk-admin-doctor-card-add mdbk-add-doctor">
                        <div class="mdbk-admin-doctor-card-add-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></div>
                        <div class="mdbk-admin-doctor-card-add-label"><?php _e('Add New Doctor', 'doctor-appointment'); ?></div>
                    </a>
                </div>
                <p class="mdbk-admin-doctor-empty" id="mdbk-doctor-no-match" style="display:none;"><?php _e('No doctors match your search or filters.', 'doctor-appointment'); ?></p>

                <div class="mdbk-pagination" id="mdbk-doctor-pagination" style="display:none;">
                    <button type="button" class="mdbk-page-btn" id="mdbk-doctor-prev" aria-label="<?php esc_attr_e('Previous page', 'doctor-appointment'); ?>">&lsaquo;</button>
                    <div id="mdbk-doctor-page-numbers" style="display:flex;gap:8px;"></div>
                    <button type="button" class="mdbk-page-btn" id="mdbk-doctor-next" aria-label="<?php esc_attr_e('Next page', 'doctor-appointment'); ?>">&rsaquo;</button>
                </div>
            </div></div><?php
            $this->render_doctor_modal_html();
            $this->render_doctor_view_modal_html();
            ?></div>
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
    /**
     * One admin doctor card. $show_top_actions hides the topbar's action
     * row (Live Queue toggle, Refresh, Print, Export CSV, Download Image)
     * — those are queue-management actions that belong on each doctor's
     * own Booking section (the Bookings page's per-doctor group headers
     * carry exactly this same set), not on a directory/profile card, so
     * the Doctors grid renders cards without them. The break countdown
     * pill stays regardless — it's this doctor's live status, not an
     * action button.
     */
    private function render_doctor_card($d, $show_top_actions = true) {
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
        $fee = get_post_meta($d->ID, '_mdbk_doc_fee', true);
        $breaks = get_post_meta($d->ID, '_mdbk_breaks', true);
        if (!is_array($breaks)) $breaks = [];
        // Doctors default to active — the meta only ever gets written (to 'no')
        // the first time someone flips the card's toggle off.
        $active = get_post_meta($d->ID, '_mdbk_doctor_active', true) !== 'no';
        $thumb = get_the_post_thumbnail_url($d->ID, 'thumbnail');
        $thumb_id = get_post_thumbnail_id($d->ID);
        $colors = self::specialty_colors($spec_id);
        $is_admin_viewer = current_user_can(MDBK_CAP_ADMIN);
        $live_queue_enabled = \MDBK\MDBK_Appointment_Manager::is_doctor_live_queue_enabled($d->ID);
        // Same "all bookings, not just today" export this doctor's own
        // Booking-page header link uses via the "All Dates" opt-out
        // (filter_date='') — a profile card isn't date-scoped the way
        // that page is, so defaulting to today (parse_schedule_filters()'
        // own behavior for a plain, dateless URL) would export nothing
        // most of the time.
        $csv_url = wp_nonce_url(add_query_arg(['page' => 'mdbk-schedule', 'filter_date' => '', 'filter_doctor' => $d->ID, 'mdbk_export' => 'csv'], admin_url('admin.php')), 'mdbk_export_csv');
        ob_start();
        ?>
        <div class="mdbk-admin-doctor-card<?php echo $active ? '' : ' is-inactive'; ?>" data-id="<?php echo esc_attr($d->ID); ?>" data-name="<?php echo esc_attr($d->post_title); ?>" data-email="<?php echo esc_attr($email); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-bio="<?php echo esc_attr($bio); ?>" data-show-phone="<?php echo esc_attr($show_phone ? $show_phone : 'yes'); ?>" data-show-email="<?php echo esc_attr($show_email ? $show_email : 'yes'); ?>" data-schedule='<?php echo esc_attr(json_encode($schedule)); ?>' data-slot-duration="<?php echo esc_attr($slot_duration ? $slot_duration : 20); ?>" data-slot-enabled="<?php echo esc_attr($slot_enabled === 'no' ? 'no' : 'yes'); ?>" data-extra-dates='<?php echo esc_attr(json_encode(is_array($extra_dates) ? $extra_dates : [])); ?>' data-off-dates='<?php echo esc_attr(json_encode(is_array($off_dates) ? $off_dates : [])); ?>' data-specialty="<?php echo esc_attr($spec_id); ?>" data-queue-mode="<?php echo esc_attr(get_post_meta($d->ID, '_mdbk_queue_serial_mode', true) ?: \MDBK\MDBK_Appointment_Manager::queue_serial_mode()); ?>" data-thumbnail="<?php echo esc_url($thumb ?: ''); ?>" data-thumbnail-id="<?php echo esc_attr($thumb_id ?: 0); ?>" data-fee="<?php echo esc_attr($fee ?: ''); ?>" data-breaks='<?php echo esc_attr(json_encode($breaks)); ?>'>
            <?php // Same set of actions the Booking page's per-doctor group
            // header carries (Live Queue toggle, Refresh, Print, Export
            // CSV, Download Image) — reinterpreted for a profile card
            // instead of a queue list: Refresh re-fetches this one card,
            // Print/Image work off this card's own info table below
            // (.mdbk-admin-doctor-card-print-table) instead of a patient
            // table, and Export CSV reuses that exact same per-doctor
            // link, just scoped to every date instead of one filtered day.
            // Management actions stay admin-only, same as the Active
            // toggle/Delete button further down — this card is also a
            // doctor's own read-only "Profile" view (see this function's
            // own docblock), and a doctor has no business re-exporting or
            // refreshing their own listing. The break countdown is the
            // one piece that stays visible to whoever can see the card at
            // all, doctor included — it's their own live status, not a
            // management action. ?>
            <?php // The topbar renders ONLY when it has content: without the
            // action row (Doctors-grid cards) an empty wrapper would still
            // paint its border-bottom + padding as a stray separator line.
            // Pill-only topbars get .mdbk-no-actions so they lose the
            // separator too and sit snug above the avatar. ?>
            <?php $card_break_el = $this->render_break_countdown_el($d->ID);
            $card_has_actions = $is_admin_viewer && $show_top_actions;
            if ($card_has_actions || $card_break_el) : ?>
            <div class="mdbk-admin-doctor-card-topbar<?php echo $card_has_actions ? '' : ' mdbk-no-actions'; ?>">
                <?php // Toggle + action icons share one row (space-between);
                // the break pill gets its own full-width line below rather
                // than sharing this one — this card is far narrower than the
                // Booking header the pill's absolute-centered layout was
                // designed for, and there usually isn't room for a doctor
                // name-length break label alongside a toggle AND four icons
                // on a single line. A dedicated line means it never has to
                // compete with them for space and so never has a reason to
                // hide (see .mdbk-admin-doctor-card-topbar .mdbk-break-countdown
                // in admin-style.css, which drops the shared class's
                // absolute-positioning for a plain static, wrapping one here). ?>
                <?php if ($card_has_actions) : ?>
                <div class="mdbk-admin-doctor-card-topbar-row">
                    <label class="mdbk-toggle mdbk-mini-toggle mdbk-doctor-live-queue-toggle" title="<?php esc_attr_e('Live Queue display for this doctor', 'doctor-appointment'); ?>">
                        <input type="checkbox" class="mdbk-doctor-live-queue-checkbox" data-doctor-id="<?php echo esc_attr($d->ID); ?>" <?php checked($live_queue_enabled); ?>>
                        <span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Live Queue', 'doctor-appointment'); ?></span>
                    </label>
                    <span class="mdbk-admin-doctor-card-topbar-actions">
                        <button type="button" class="mdbk-icon-btn mdbk-icon-btn-xs mdbk-refresh-doctor-card" data-id="<?php echo esc_attr($d->ID); ?>" title="<?php esc_attr_e('Refresh', 'doctor-appointment'); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg></button>
                        <button type="button" class="mdbk-icon-btn mdbk-icon-btn-xs mdbk-print-doctor-card" title="<?php esc_attr_e('Print', 'doctor-appointment'); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg></button>
                        <a href="<?php echo esc_url($csv_url); ?>" class="mdbk-icon-btn mdbk-icon-btn-xs" title="<?php esc_attr_e('Export CSV', 'doctor-appointment'); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg></a>
                        <button type="button" class="mdbk-icon-btn mdbk-icon-btn-xs mdbk-download-doctor-card-image" title="<?php esc_attr_e('Download as Image', 'doctor-appointment'); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg></button>
                    </span>
                </div>
                <?php endif; ?>
                <?php echo $card_break_el; ?>
            </div>
            <?php if ($card_has_actions) : ?>
            <div class="mdbk-admin-doctor-card-print-table" style="display:none;">
                <table>
                    <tr><th><?php _e('Specialty', 'doctor-appointment'); ?></th><td><?php echo esc_html($spec_name); ?></td></tr>
                    <tr><th><?php _e('Email', 'doctor-appointment'); ?></th><td><?php echo esc_html($email ?: '—'); ?></td></tr>
                    <tr><th><?php _e('Phone', 'doctor-appointment'); ?></th><td><?php echo esc_html($phone ?: '—'); ?></td></tr>
                    <tr><th><?php _e('Slot Duration', 'doctor-appointment'); ?></th><td><?php echo esc_html(($slot_duration ?: 20) . ' ' . __('min', 'doctor-appointment')); ?></td></tr>
                    <tr><th><?php _e('Consultation Fee', 'doctor-appointment'); ?></th><td><?php echo $fee !== '' ? esc_html('৳' . $fee) : '—'; ?></td></tr>
                    <tr><th><?php _e('Status', 'doctor-appointment'); ?></th><td><?php echo $active ? esc_html__('Active', 'doctor-appointment') : esc_html__('Inactive', 'doctor-appointment'); ?></td></tr>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (current_user_can(MDBK_CAP_ADMIN)) : ?>
            <span class="mdbk-doctor-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'doctor-appointment'); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="6" r="1.6"></circle><circle cx="16" cy="6" r="1.6"></circle><circle cx="8" cy="12" r="1.6"></circle><circle cx="16" cy="12" r="1.6"></circle><circle cx="8" cy="18" r="1.6"></circle><circle cx="16" cy="18" r="1.6"></circle></svg></span>
            <?php endif; ?>
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
                <?php // This card is also reused as a doctor's own read-only-ish
                // "Profile" view (see render_profile_page()) — the destructive/
                // administrative controls (active toggle, delete) stay admin-only
                // there; View + Edit remain so a doctor can see and update their
                // own info via the same modal admin uses. ?>
                <?php if (current_user_can(MDBK_CAP_ADMIN)) : ?>
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
                    <?php if (current_user_can(MDBK_CAP_ADMIN)) : ?>
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
            <?php // Date sits beside Time, same pairing the on-screen row shows.
            // It used to be missing entirely: the printed/exported sheet
            // carried a time with no day against it, and the clinic header
            // above the table doesn't name one either — fine while this only
            // ever printed today's queue, wrong once the same table started
            // printing the multi-date "Upcoming Dates" section, where every
            // row could be a different day. ?>
            <thead><tr><th><?php _e('Queue', 'doctor-appointment'); ?></th><th><?php _e('Patient', 'doctor-appointment'); ?></th><th><?php _e('Phone', 'doctor-appointment'); ?></th><th><?php _e('Address', 'doctor-appointment'); ?></th><?php if ($show_doctor_column): ?><th><?php _e('Doctor', 'doctor-appointment'); ?></th><?php endif; ?><th><?php _e('Age', 'doctor-appointment'); ?></th><th><?php _e('Gender', 'doctor-appointment'); ?></th><th><?php _e('Date', 'doctor-appointment'); ?></th><th><?php _e('Time', 'doctor-appointment'); ?></th><th><?php _e('Visit Time', 'doctor-appointment'); ?></th><th><?php _e('Status', 'doctor-appointment'); ?></th></tr></thead>
            <tbody>
            <?php // The Queue cell used to print this loop's own 1..N counter,
            // so a printout numbered its rows Q01, Q02, Q03 no matter what
            // the on-screen badges said — wrong against real ticket numbers
            // (which skip and restart per doctor), and wrong entirely under
            // check-in-order mode, where a patient who hasn't arrived has no
            // queue number at all. display_ticket_label() is the same call
            // every visible row makes, so the print/image table and the CSV
            // now say exactly what the screen says. ?>
            <?php foreach ($apps as $app): $t_doc_id = get_post_meta($app->ID, '_mdbk_doctor_id', true); $t_phone = get_post_meta($app->ID, '_mdbk_patient_phone', true); $t_age = get_post_meta($app->ID, '_mdbk_patient_age', true); $t_gender = get_post_meta($app->ID, '_mdbk_patient_gender', true); $t_date = get_post_meta($app->ID, '_mdbk_appointment_date', true); $t_slot = get_post_meta($app->ID, '_mdbk_slot_time', true); $t_status = \MDBK\MDBK_Appointment_Manager::get_display_status_slug($app->ID); $t_badge_class = in_array($t_status, ['upcoming', 'not-checked-in'], true) ? $t_status : 'status-' . $t_status; ?>
                <tr>
                    <td><span class="mdbk-patient-row-ticket mdbk-patient-row-queue"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::display_ticket_label($app->ID)); ?></span></td>
                    <td><strong><?php echo esc_html(get_post_meta($app->ID, '_mdbk_patient_name', true)); ?></strong></td>
                    <?php // Printed and handed round the desk, or saved as an
                    // image — a name with no number on it is of limited use
                    // when someone needs to call ahead or chase a no-show.
                    // The CSV export already carried this; the printout
                    // didn't, so the two disagreed on what a row contained. ?>
                    <td><?php echo $t_phone ? esc_html($t_phone) : '—'; ?></td>
                    <?php // Same live read the on-screen row uses, so a printout
                    // and the list it was printed from can't disagree. ?>
                    <td><?php $t_address = \MDBK\MDBK_Appointment_Manager::patient_address($app->ID); echo $t_address ? esc_html($t_address) : '—'; ?></td>
                    <?php if ($show_doctor_column): ?><td><?php echo $t_doc_id ? esc_html(get_the_title($t_doc_id)) : esc_html__('N/A', 'doctor-appointment'); ?></td><?php endif; ?>
                    <td><?php echo $t_age ? esc_html($t_age) : '—'; ?></td>
                    <td><?php echo $t_gender ? esc_html($t_gender) : '—'; ?></td>
                    <?php // Stored/compared as 24h (_mdbk_slot_time, e.g. is_slot_taken()'s
                    // string comparisons) but shown everywhere else in this
                    // 12h format — render_my_queue_patient_row()'s own
                    // $time_display uses this exact same conversion, so the
                    // printed table matched what's on screen instead of the
                    // raw stored value. ?>
                    <?php // Site's own Settings > General date format, same as the
                    // on-screen row's date chip, so the two read identically. ?>
                    <td><?php echo esc_html($t_date ? date_i18n(get_option('date_format'), strtotime($t_date)) : '—'); ?></td>
                    <td><?php echo esc_html($t_slot ? date_i18n('g:i A', strtotime($t_slot)) : '—'); ?></td>
                    <?php // How long the consultation actually ran, for visits
                    // timed through Start Visiting -> Mark Visited. ?>
                    <td><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::format_duration(\MDBK\MDBK_Appointment_Manager::visit_duration($app->ID)) ?: '—'); ?></td>
                    <td><span class="mdbk-badge mdbk-badge-<?php echo esc_attr($t_badge_class); ?>"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::status_display_label($t_status)); ?></span></td>
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
        $ticket = \MDBK\MDBK_Appointment_Manager::display_ticket_number($a->ID);
        $patient_id = get_post_meta($a->ID, '_mdbk_patient_id', true);
        // Read off the patient record, not this booking — see patient_address().
        $address = \MDBK\MDBK_Appointment_Manager::patient_address($a->ID);
        // The same value's two parts, for the Edit modal's District/Thana
        // dropdowns to re-select from.
        $location = \MDBK\MDBK_Appointment_Manager::patient_location($a->ID);
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
        <div class="mdbk-patient-row<?php echo $show_doctor ? ' mdbk-patient-row-has-doctor' : ''; ?> mdbk-status-<?php echo esc_attr($status); ?>" data-id="<?php echo esc_attr($a->ID); ?>" data-patient="<?php echo esc_attr($p_name); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-email="<?php echo esc_attr($email); ?>" data-address="<?php echo esc_attr($address); ?>" data-district="<?php echo esc_attr($location['district']); ?>" data-thana="<?php echo esc_attr($location['thana']); ?>" data-age="<?php echo esc_attr($age); ?>" data-gender="<?php echo esc_attr($gender); ?>" data-doctor="<?php echo esc_attr($doc_id); ?>" data-specialty="<?php echo esc_attr($app_spec_id); ?>" data-date="<?php echo esc_attr($date); ?>" data-slot-time="<?php echo esc_attr($slot_time); ?>" data-status="<?php echo esc_attr($status); ?>">
            <?php // Same shared label as every other view — see
            // MDBK_Appointment_Manager::display_ticket_label(). ?>
            <?php $ticket_label = \MDBK\MDBK_Appointment_Manager::display_ticket_label($a->ID); ?>
            <?php $is_queue_label = strpos($ticket_label, 'Q') === 0; ?>
            <span class="mdbk-patient-row-ticket-slot"><span class="mdbk-patient-row-ticket <?php echo $is_queue_label ? 'mdbk-patient-row-queue' : 'mdbk-patient-row-bookingid'; ?>" title="<?php echo $is_queue_label ? esc_attr__('Queue number', 'doctor-appointment') : esc_attr__('Booking ID', 'doctor-appointment'); ?>"><?php echo esc_html($ticket_label); ?></span></span>
            <?php if ($patient_id && $this->can_view_patient($patient_id)) : ?>
                <a href="#" class="mdbk-patient-row-name mdbk-view-patient" data-id="<?php echo esc_attr($patient_id); ?>" title="<?php esc_attr_e('View patient', 'doctor-appointment'); ?>"><?php echo esc_html($p_name); ?></a>
            <?php else : ?>
                <span class="mdbk-patient-row-name"><?php echo esc_html($p_name); ?></span>
            <?php endif; ?>
            <span class="mdbk-patient-row-ticket-slot"><?php if ($patient_id): ?><span class="mdbk-patient-row-ticket mdbk-patient-row-pid" title="<?php esc_attr_e('Patient ID', 'doctor-appointment'); ?>">P<?php echo esc_html($patient_id); ?></span><?php endif; ?></span>
            <?php if ($show_doctor): ?><span class="mdbk-patient-row-chip mdbk-chip-doctor"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.5 21a8.5 8.5 0 0 0-17 0"></path><circle cx="12" cy="7.5" r="4.5"></circle></svg> <?php echo $doc_id ? esc_html(get_the_title($doc_id)) : esc_html__('N/A', 'doctor-appointment'); ?></span><?php endif; ?>
            <span class="mdbk-patient-row-chip-slot"><?php if ($phone): ?><span class="mdbk-patient-row-chip mdbk-chip-phone"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($email): ?><span class="mdbk-patient-row-chip mdbk-chip-email"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($email); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($address): ?><span class="mdbk-patient-row-chip mdbk-chip-address" title="<?php echo esc_attr($address); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> <?php echo esc_html($address); ?></span><?php endif; ?></span>
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
                <?php // Only a closed-out visit has an actual consultation to bill —
                // a still-waiting/serving/no-show appointment has nothing to
                // invoice yet, so the action doesn't show at all rather than
                // opening a popup for a fee nobody's confirmed was earned.
                // $date <= today too — a booking dated in the future can't
                // have actually happened yet regardless of what its status
                // field says (a manually-edited/inconsistent record), so
                // Invoice stays limited to today's and past visits only. ?>
                <?php if ($status === 'completed' && $date <= current_time('Y-m-d') && current_user_can(MDBK_CAP_QUEUE)) : ?>
                <a href="#" class="mdbk-action-btn mdbk-open-invoice" data-id="<?php echo esc_attr($a->ID); ?>" title="<?php esc_attr_e('Invoice', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="15" y2="17"></line></svg></a>
                <?php endif; ?>
                <?php if (current_user_can(MDBK_CAP_ADMIN)) : ?>
                <a href="#" class="mdbk-action-btn mdbk-edit-appointment" data-id="<?php echo esc_attr($a->ID); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg></a>
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
        // is a deliberate opt-out back to the original unscoped, ungrouped
        // view — distinct from "not set yet". Still reachable by hand or
        // from a saved URL; the header link that used to offer it was
        // removed, the view behind it was not.
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
        // Same two ways in as render_schedule_page() itself: staff/admin
        // with the plugin-wide MDBK_CAP_QUEUE, or a pure doctor account
        // exporting their own queue (MDBK_CAP_DOCTOR only) — the Export
        // CSV link on that account's own single-doctor Booking header
        // (render_schedule_today_view()) used to 403 here, since this
        // check only ever recognized the first group.
        $is_queue_staff = current_user_can(MDBK_CAP_QUEUE);
        $own_doctor_id = (!$is_queue_staff && current_user_can(MDBK_CAP_DOCTOR)) ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id()) : 0;
        if (!$is_queue_staff && !$own_doctor_id) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_export_csv');

        list($filter_date, $filter_doctor, $filter_status) = $this->parse_schedule_filters();
        // A doctor-only account exports their own queue only, always —
        // same rule render_schedule_page() enforces on the on-screen
        // view, regardless of whatever filter_doctor a hand-edited URL
        // asks for.
        if (!$is_queue_staff) $filter_doctor = $own_doctor_id;
        $apps = $this->get_filtered_appointments($filter_date, $filter_doctor, $filter_status);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bookings-' . ($filter_date ?: 'all-dates') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Queue', 'Patient ID', 'Patient Name', 'Phone', 'Email', 'Address', 'Age', 'Gender', 'Doctor', 'Date', 'Time', 'Visit Time', 'Status', 'Symptoms']);
        foreach ($apps as $a) {
            $doc_id = get_post_meta($a->ID, '_mdbk_doctor_id', true);
            fputcsv($out, [
                // Same label the on-screen row shows — this export spans
                // every date, and a queue number means nothing outside its
                // own day, so non-today rows carry their Booking ID.
                \MDBK\MDBK_Appointment_Manager::display_ticket_label($a->ID),
                get_post_meta($a->ID, '_mdbk_patient_id', true),
                get_post_meta($a->ID, '_mdbk_patient_name', true),
                get_post_meta($a->ID, '_mdbk_patient_phone', true),
                get_post_meta($a->ID, '_mdbk_patient_email', true),
                \MDBK\MDBK_Appointment_Manager::patient_address($a->ID),
                get_post_meta($a->ID, '_mdbk_patient_age', true),
                get_post_meta($a->ID, '_mdbk_patient_gender', true),
                $doc_id ? get_the_title($doc_id) : '',
                get_post_meta($a->ID, '_mdbk_appointment_date', true),
                get_post_meta($a->ID, '_mdbk_slot_time', true),
                \MDBK\MDBK_Appointment_Manager::format_duration(\MDBK\MDBK_Appointment_Manager::visit_duration($a->ID)),
                \MDBK\MDBK_Appointment_Manager::status_display_label(\MDBK\MDBK_Appointment_Manager::get_display_status_slug($a->ID)),
                get_post_meta($a->ID, '_mdbk_symptoms', true),
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * The ONE main operational page: bookings, today's queue, and every
     * status action (Check-In, Start Visiting, Mark as Visited, Skip) —
     * a doctor sees only their own patients (auto-scoped, no doctor
     * filter to pick), front-desk staff/admin sees every doctor
     * (filterable, grouped when viewing all of them combined). The
     * "Patients" page is deliberately separate and narrower — just the
     * registered-patient directory (view/add/edit), not day-to-day queue
     * work — see render_patients_page().
     *
     * Viewing exactly TODAY (the default landing state) shows the richer
     * view: analytics + the priority-sorted Today's Queue with status
     * actions, reusing the exact same row renderer/helpers a doctor's
     * queue always has (render_patient_list_html(), get_today_queue_apps(),
     * etc.). Any OTHER specific date, or "All Dates", falls back to the
     * plain grouped-cards/flat-list booking history view this page always
     * had — those records aren't actionable today, so the richer per-row
     * controls wouldn't apply anyway.
     */
    public function render_schedule_page() {
        $is_doctor_only = current_user_can(MDBK_CAP_DOCTOR) && !current_user_can(MDBK_CAP_QUEUE) && !current_user_can('manage_options');
        if (!$is_doctor_only && !current_user_can(MDBK_CAP_QUEUE) && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        }
        $own_doctor_id = $is_doctor_only ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id()) : 0;
        if ($is_doctor_only && !$own_doctor_id) {
            ?>
            <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('schedule'); ?>
                <div class="mdbk-main-content">
                    <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Booking', 'doctor-appointment'); ?></h1></div></div>
                    <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('This account is not linked to a doctor profile.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                </div></div></div>
            <?php
            return;
        }

        list($filter_date, $filter_doctor, $filter_status) = $this->parse_schedule_filters();
        // A pure doctor account only ever sees their own bookings —
        // always their own doctor_id regardless of what a hand-edited URL
        // might request, and no doctor dropdown is rendered for them below.
        if ($is_doctor_only) {
            $filter_doctor = $own_doctor_id;
        }
        // Text search — narrows which rows DISPLAY on every branch below
        // (today's rich view, the other-date grouped view, and the plain
        // all-dates list), same "doesn't touch computed totals" contract
        // the analytics cards rely on.
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $apps = $this->get_filtered_appointments($filter_date, $filter_doctor, $filter_status);
        if ($search !== '') {
            $apps = array_values(array_filter($apps, function($a) use ($search) {
                $haystack = get_post_meta($a->ID, '_mdbk_patient_name', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_phone', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_email', true);
                return stripos($haystack, $search) !== false;
            }));
        }
        $all_doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']);

        // Prev/Today/Next nav links, carrying forward whichever
        // doctor/status/search filters are already active.
        $nav_args = ['page' => 'mdbk-schedule'];
        if ($filter_doctor && !$is_doctor_only) $nav_args['filter_doctor'] = $filter_doctor;
        if ($filter_status) $nav_args['filter_status'] = $filter_status;
        if ($search !== '') $nav_args['s'] = $search;
        $day_url = function ($date) use ($nav_args) {
            return add_query_arg(array_merge($nav_args, ['filter_date' => $date]), admin_url('admin.php'));
        };
        $today = current_time('Y-m-d');
        $is_today_view = ($filter_date === $today);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('schedule'); ?>
            <div class="mdbk-main-content<?php echo $is_today_view ? ' mdbk-main-content-fixed-header' : ''; ?>">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Booking', 'doctor-appointment'); ?></h1><p><?php echo $filter_date ? esc_html(date_i18n('l, F j, Y', strtotime($filter_date))) : esc_html__('All dates', 'doctor-appointment'); ?> <span id="mdbk-schedule-count" class="mdbk-total-count">&middot; <?php echo esc_html(sprintf(_n('%d patient', '%d patients', count($apps), 'doctor-appointment'), count($apps))); ?></span></p></div>
                <div class="mdbk-header-right">
                    <?php // A doctor account books under their own name via
                    // this same modal (render_appointment_modal_html() below,
                    // told which doctor it's for) — used to be staff/admin
                    // only, with no way for a doctor to add a walk-in patient
                    // to their own queue at all. ?>
                    <a href="#" class="mdbk-btn-add mdbk-add-appointment"><?php _e('+ New Booking', 'doctor-appointment'); ?></a>
                </div>
                </div>

                <?php if ($is_today_view): ?>
                <div class="mdbk-schedule-queue-scroll-wrap">
                    <div id="mdbk-schedule-analytics" style="margin-bottom:20px;"><?php echo $this->render_schedule_analytics_html($filter_doctor); ?></div>
                    <details class="mdbk-filters-bar" id="mdbk-filters-bar" open>
                        <summary class="mdbk-filters-bar-summary"><?php _e('Filters', 'doctor-appointment'); ?><span class="mdbk-filters-bar-chevron"></span></summary>
                        <div class="mdbk-filters-bar-body">
                            <?php $this->render_schedule_filters_bar($filter_date, $filter_doctor, $filter_status, $search, $is_doctor_only, $all_doctors, $day_url); ?>
                        </div>
                    </details>
                    <div id="mdbk-schedule-results"><?php echo $this->render_schedule_results_html($filter_date, $filter_doctor, $filter_status, $search, $apps, $is_today_view); ?></div>
                </div>
                <?php else: ?>
                <details class="mdbk-filters-bar" id="mdbk-filters-bar" open>
                    <summary class="mdbk-filters-bar-summary"><?php _e('Filters', 'doctor-appointment'); ?><span class="mdbk-filters-bar-chevron"></span></summary>
                    <div class="mdbk-filters-bar-body">
                        <?php $this->render_schedule_filters_bar($filter_date, $filter_doctor, $filter_status, $search, $is_doctor_only, $all_doctors, $day_url); ?>
                    </div>
                </details>
                <div id="mdbk-schedule-results"><?php echo $this->render_schedule_results_html($filter_date, $filter_doctor, $filter_status, $search, $apps, $is_today_view); ?></div>
                <?php endif; ?>
            </div></div><?php $this->render_appointment_modal_html($is_doctor_only ? $own_doctor_id : 0); $this->render_patient_view_modal_html(); $this->render_invoice_modal_html(); ?></div>
        <?php
    }

    /**
     * Booking page's filter <form> (date nav, search, doctor/status
     * filters) — shared between both call sites in render_schedule_page(),
     * rendered once and never touched by the live-search AJAX swap, so
     * the search input never loses focus mid-keystroke. Both callers wrap
     * this in a <details id="mdbk-filters-bar"> (admin-script.js persists
     * its open/collapsed state in localStorage) rather than rendering it
     * here directly, since the wrapper itself doesn't depend on any of
     * this method's own arguments.
     */
    private function render_schedule_filters_bar($filter_date, $filter_doctor, $filter_status, $search, $is_doctor_only, $all_doctors, $day_url) {
        ?>
        <form method="get" class="mdbk-filters-form">
            <input type="hidden" name="page" value="mdbk-schedule">
            <?php if ($filter_date): ?>
            <div class="mdbk-date-nav-group">
                <a href="<?php echo esc_url($day_url(date('Y-m-d', strtotime($filter_date . ' -1 day')))); ?>" class="mdbk-date-nav-btn" aria-label="<?php esc_attr_e('Previous day', 'doctor-appointment'); ?>">&lsaquo;</a>
                <a href="<?php echo esc_url($day_url(current_time('Y-m-d'))); ?>" class="mdbk-date-nav-btn mdbk-date-nav-today"><?php _e('Today', 'doctor-appointment'); ?></a>
                <a href="<?php echo esc_url($day_url(date('Y-m-d', strtotime($filter_date . ' +1 day')))); ?>" class="mdbk-date-nav-btn" aria-label="<?php esc_attr_e('Next day', 'doctor-appointment'); ?>">&rsaquo;</a>
                <div class="mdbk-schedule-date-select" id="mdbk-schedule-date-select">
                    <button type="button" class="mdbk-schedule-date-trigger" id="mdbk-schedule-date-trigger">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span id="mdbk-schedule-date-trigger-value"><?php echo esc_html(date_i18n('m/d/Y', strtotime($filter_date))); ?></span>
                    </button>
                    <div class="mdbk-app-date-panel" id="mdbk-schedule-date-panel" style="display:none;"></div>
                </div>
                <input type="hidden" name="filter_date" value="<?php echo esc_attr($filter_date); ?>" class="mdbk-date-nav-input" id="mdbk-schedule-date-hidden">
            </div>
            <span class="mdbk-filters-divider"></span>
            <?php endif; ?>
            <input type="text" id="mdbk-schedule-search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search name, phone, or email...', 'doctor-appointment'); ?>" class="mdbk-filters-search">
            <?php
            // Both filters use the panel's own custom-select (a styled
            // button + panel over a hidden real <select>) rather than the
            // browser's native control, matching every other dropdown in
            // here and letting them be styled consistently. The hidden
            // <select> is still the actual form control, so this form
            // submits exactly as it did before.
            //
            // Status labels come from status_display_label() instead of
            // being written out again here — this list had drifted, offering
            // "Completed" for a status every badge, table and export calls
            // "Visited".
            $status_choices = [];
            foreach (['waiting', 'serving', 'completed', 'no-show'] as $slug) {
                $status_choices[$slug] = \MDBK\MDBK_Appointment_Manager::status_display_label($slug);
            }
            $status_label = $filter_status && isset($status_choices[$filter_status])
                ? $status_choices[$filter_status]
                : __('All Statuses', 'doctor-appointment');
            ?>
            <?php if (!$is_doctor_only): ?>
            <?php
            $doctor_label = __('All Doctors', 'doctor-appointment');
            foreach ($all_doctors as $d) {
                if ((int) $filter_doctor === (int) $d->ID) $doctor_label = $d->post_title;
            }
            ?>
            <div class="mdbk-custom-select mdbk-filters-select" id="mdbk-schedule-doctor-select">
                <button type="button" class="mdbk-custom-select-trigger">
                    <span class="mdbk-custom-select-value"><?php echo esc_html($doctor_label); ?></span>
                    <span class="mdbk-custom-select-chevron"></span>
                </button>
                <div class="mdbk-custom-select-panel" style="display:none;">
                    <div class="mdbk-custom-select-option<?php echo $filter_doctor ? '' : ' selected'; ?>" data-value=""><?php _e('All Doctors', 'doctor-appointment'); ?></div>
                    <?php foreach ($all_doctors as $d) : ?>
                        <div class="mdbk-custom-select-option<?php echo (int) $filter_doctor === (int) $d->ID ? ' selected' : ''; ?>" data-value="<?php echo esc_attr($d->ID); ?>"><?php echo esc_html($d->post_title); ?></div>
                    <?php endforeach; ?>
                </div>
                <select id="mdbk-schedule-filter-doctor" name="filter_doctor" style="display:none;">
                    <option value=""><?php _e('All Doctors', 'doctor-appointment'); ?></option>
                    <?php foreach ($all_doctors as $d) : ?>
                        <option value="<?php echo esc_attr($d->ID); ?>" <?php selected($filter_doctor, $d->ID); ?>><?php echo esc_html($d->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="mdbk-custom-select mdbk-filters-select" id="mdbk-schedule-status-select">
                <button type="button" class="mdbk-custom-select-trigger">
                    <span class="mdbk-custom-select-value"><?php echo esc_html($status_label); ?></span>
                    <span class="mdbk-custom-select-chevron"></span>
                </button>
                <div class="mdbk-custom-select-panel" style="display:none;">
                    <div class="mdbk-custom-select-option<?php echo $filter_status ? '' : ' selected'; ?>" data-value=""><?php _e('All Statuses', 'doctor-appointment'); ?></div>
                    <?php foreach ($status_choices as $slug => $label) : ?>
                        <div class="mdbk-custom-select-option<?php echo $filter_status === $slug ? ' selected' : ''; ?>" data-value="<?php echo esc_attr($slug); ?>"><span class="mdbk-status-dot mdbk-status-dot-<?php echo esc_attr($slug); ?>"></span><?php echo esc_html($label); ?></div>
                    <?php endforeach; ?>
                </div>
                <select id="mdbk-schedule-filter-status" name="filter_status" style="display:none;">
                    <option value=""><?php _e('All Statuses', 'doctor-appointment'); ?></option>
                    <?php foreach ($status_choices as $slug => $label) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($filter_status, $slug); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="mdbk-btn-add mdbk-btn-sm"><?php _e('Filter', 'doctor-appointment'); ?></button>
            <div class="mdbk-filters-spacer"></div>
            <a id="mdbk-schedule-clear-filters" href="<?php echo esc_url(add_query_arg(['page' => 'mdbk-schedule', 'filter_date' => $filter_date], admin_url('admin.php'))); ?>" class="mdbk-date-nav-all" style="<?php echo ($filter_doctor || $filter_status || $search !== '') ? '' : 'display:none;'; ?>"><?php _e('Clear', 'doctor-appointment'); ?></a>
        </form>
        <?php
    }

    /**
     * Today view's analytics cards — split out of render_schedule_today_view()
     * so it can be its own AJAX-swap target (#mdbk-schedule-analytics),
     * independent of #mdbk-schedule-results, now that the analytics cards
     * sit ABOVE the (page-level, never-swapped) filters bar rather than
     * inside the same scrolling fragment as the Queue/Upcoming lists.
     * Scoped by doctor only — search/status text never changes these
     * numbers (see the "doesn't touch computed totals" note in
     * render_schedule_page()), so that's the only input this needs.
     */
    private function render_schedule_analytics_html($doctor_id) {
        $today_apps = $this->get_today_queue_apps($doctor_id);
        $all_apps = $this->get_filtered_appointments(null, $doctor_id, '');
        $today = current_time('Y-m-d');
        $total_patients = count(array_unique(array_filter(array_map(function($a) {
            return get_post_meta($a->ID, '_mdbk_patient_id', true);
        }, $all_apps))));
        $today_visited = count(array_filter($today_apps, function($a) {
            return get_post_status($a) === 'mdbk_completed';
        }));
        $today_no_show = count(array_filter($today_apps, function($a) {
            return get_post_status($a) === 'mdbk_no_show';
        }));
        $upcoming_count = count(array_filter($all_apps, function($a) use ($today) {
            $date = get_post_meta($a->ID, '_mdbk_appointment_date', true);
            return $date > $today && in_array(get_post_status($a), ['mdbk_waiting', 'mdbk_serving'], true);
        }));
        ob_start();
        ?>
        <div class="mdbk-stats-grid mdbk-stats-grid-5 mdbk-stats-grid-compact">
            <div class="mdbk-stat-card mdbk-stat-card-violet">
                <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                <div class="mdbk-stat-text">
                    <h4><?php _e('Total Patients', 'doctor-appointment'); ?></h4>
                    <div class="value"><?php echo esc_html($total_patients); ?></div>
                </div>
            </div>
            <div class="mdbk-stat-card mdbk-stat-card-blue">
                <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></div>
                <div class="mdbk-stat-text">
                    <h4><?php _e("Today's Bookings", 'doctor-appointment'); ?></h4>
                    <div class="value"><?php echo esc_html(count($today_apps)); ?></div>
                </div>
            </div>
            <div class="mdbk-stat-card mdbk-stat-card-green">
                <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></div>
                <div class="mdbk-stat-text">
                    <h4><?php _e('Visited Today', 'doctor-appointment'); ?></h4>
                    <div class="value"><?php echo esc_html($today_visited); ?></div>
                </div>
            </div>
            <div class="mdbk-stat-card mdbk-stat-card-red">
                <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg></div>
                <div class="mdbk-stat-text">
                    <h4><?php _e('No Show Today', 'doctor-appointment'); ?></h4>
                    <div class="value"><?php echo esc_html($today_no_show); ?></div>
                </div>
            </div>
            <div class="mdbk-stat-card mdbk-stat-card-amber">
                <div class="mdbk-stat-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                <div class="mdbk-stat-text">
                    <h4><?php _e('Upcoming', 'doctor-appointment'); ?></h4>
                    <div class="value"><?php echo esc_html($upcoming_count); ?></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The 4-branch results area (Today's rich queue view / "no bookings
     * for this date" / a specific other date's grouped-by-doctor cards /
     * the plain flat All Dates list) — pulled out of render_schedule_page()
     * so ajax_search_schedule() can return the exact same markup for its
     * live search, instead of that AJAX fragment slowly drifting out of
     * sync with whatever the full page render does.
     */
    private function render_schedule_results_html($filter_date, $filter_doctor, $filter_status, $search, $apps, $is_today_view) {
        ob_start();
        if ($is_today_view): $this->render_schedule_today_view($filter_doctor, $search, $filter_status); ?>
        <?php elseif ($filter_date && empty($apps)): ?>
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
        <?php endif;
        return ob_get_clean();
    }

    /**
     * Live search/filter AJAX for the Booking page — mirrors ajax_search_patients(),
     * recomputing exactly what render_schedule_page() itself would compute
     * for the same filters, then handing the fragment off to
     * render_schedule_results_html() so the two can never visually drift.
     */
    public function ajax_search_schedule() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        $is_doctor_only = current_user_can(MDBK_CAP_DOCTOR) && !current_user_can(MDBK_CAP_QUEUE) && !current_user_can('manage_options');
        if (!$is_doctor_only && !current_user_can(MDBK_CAP_QUEUE) && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }
        $own_doctor_id = $is_doctor_only ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id()) : 0;
        if ($is_doctor_only && !$own_doctor_id) {
            wp_send_json_error(['message' => __('This account is not linked to a doctor profile.', 'doctor-appointment')]);
        }

        $filter_date   = isset($_POST['filter_date']) ? sanitize_text_field(wp_unslash($_POST['filter_date'])) : current_time('Y-m-d');
        $filter_doctor = $is_doctor_only ? $own_doctor_id : (isset($_POST['filter_doctor']) ? absint($_POST['filter_doctor']) : 0);
        $filter_status = isset($_POST['filter_status']) ? sanitize_text_field(wp_unslash($_POST['filter_status'])) : '';
        $search        = isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '';

        $apps = $this->get_filtered_appointments($filter_date, $filter_doctor, $filter_status);
        if ($search !== '') {
            $apps = array_values(array_filter($apps, function($a) use ($search) {
                $haystack = get_post_meta($a->ID, '_mdbk_patient_name', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_phone', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_email', true);
                return stripos($haystack, $search) !== false;
            }));
        }
        $today = current_time('Y-m-d');
        $is_today_view = ($filter_date === $today);

        $response = [
            'count_html'   => '&middot; ' . esc_html(sprintf(_n('%d patient', '%d patients', count($apps), 'doctor-appointment'), count($apps))),
            'results_html' => $this->render_schedule_results_html($filter_date, $filter_doctor, $filter_status, $search, $apps, $is_today_view),
        ];
        // Analytics live outside #mdbk-schedule-results now (rendered once
        // in render_schedule_page(), above the never-swapped filters bar),
        // so a doctor-filter change made via this same live search has to
        // refresh them through their own #mdbk-schedule-analytics target
        // instead — otherwise they'd silently go stale showing whichever
        // doctor was selected on the last full page load.
        if ($is_today_view) {
            $response['analytics_html'] = $this->render_schedule_analytics_html($filter_doctor);
        }
        wp_send_json_success($response);
    }

    /**
     * The rich "Today" view for render_schedule_page() — analytics +
     * the priority-sorted queue with Check-In/Start Visiting/Mark as
     * Visited/Skip, reusing the exact same helpers/AJAX fragments a
     * doctor's queue has always used (get_today_queue_apps(),
     * render_patient_list_html(), etc.), so this and the AJAX-refresh
     * path never drift apart. $doctor_id of 0 means "every doctor"
     * throughout (get_filtered_appointments() already treats a falsy
     * filter_doctor that way) — staff/admin viewing all doctors combined
     * get them grouped into collapsible per-doctor sections; a specific
     * doctor (picked from the dropdown, or a pure doctor account's own
     * forced scope) gets a flat list instead, since grouping a single
     * doctor's own rows under their own name would be pointless chrome.
     */
    private function render_schedule_today_view($doctor_id, $search, $filter_status) {
        $group_by_doctor = !$doctor_id;
        $today_apps = $this->get_today_queue_apps($doctor_id);
        $upcoming_apps = $this->get_upcoming_queue_apps($doctor_id);

        $apply_filters = function($apps) use ($search, $filter_status) {
            if ($search === '' && $filter_status === '') return $apps;
            return array_values(array_filter($apps, function($a) use ($search, $filter_status) {
                if ($filter_status && \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($a)) !== $filter_status) return false;
                if ($search !== '') {
                    $haystack = get_post_meta($a->ID, '_mdbk_patient_name', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_phone', true) . ' ' . get_post_meta($a->ID, '_mdbk_patient_email', true);
                    if (stripos($haystack, $search) === false) return false;
                }
                return true;
            }));
        };
        $today_apps_display = $apply_filters($today_apps);
        $upcoming_apps_display = $apply_filters($upcoming_apps);
        $has_active_filters = ($search !== '' || $filter_status !== '');
        // Computed from the unfiltered $today_apps on purpose — see
        // get_serving_doctor_ids()'s comment.
        $serving_doctor_ids = $this->get_serving_doctor_ids($today_apps);

        // "Expand All" / "Collapse All" only makes sense once there's
        // actually per-doctor grouping to toggle.
        $group_toggle_buttons = '<div class="mdbk-group-toggle-btns">'
            . '<button type="button" class="mdbk-icon-btn mdbk-expand-all" title="' . esc_attr__('Expand All', 'doctor-appointment') . '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="7 13 12 18 17 13"></polyline><polyline points="7 6 12 11 17 6"></polyline></svg></button>'
            . '<button type="button" class="mdbk-icon-btn mdbk-collapse-all" title="' . esc_attr__('Collapse All', 'doctor-appointment') . '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 12 6 7 11"></polyline><polyline points="17 18 12 13 7 18"></polyline></svg></button>'
            . '</div>';
        ?>
        <div class="mdbk-card" id="mdbk-today-queue-card" style="margin-bottom:20px;" data-doctor-id="<?php echo esc_attr($doctor_id); ?>" data-doctor-name="<?php echo $doctor_id ? esc_attr(get_the_title($doctor_id)) : ''; ?>">
            <div class="mdbk-card-header">
                <h3><?php _e("Today's Queue", 'doctor-appointment'); ?></h3>
                <?php if ($group_by_doctor) : ?>
                    <?php // No countdown pill here in the grouped view —
                    // each doctor's own group header carries its own (see
                    // render_break_countdown_el()), since two doctors on
                    // break at overlapping times can't share one pill. ?>
                    <?php echo $group_toggle_buttons; ?>
                <?php elseif ($doctor_id) :
                    // Single-doctor view — either a pure doctor account's
                    // own forced scope (no per-doctor group header exists
                    // here to carry this toggle, see the comment above
                    // this function) or staff/admin filtered to one
                    // doctor via the dropdown. Same toggle/classes as the
                    // grouped view's own per-doctor header (see
                    // render_patient_list_html()), reusing its existing
                    // JS handler as-is — just rendered standalone here
                    // instead of inside a <details> summary.
                    $live_queue_enabled = \MDBK\MDBK_Appointment_Manager::is_doctor_live_queue_enabled($doctor_id);
                    // In this view THIS header is the doctor's own
                    // heading (there's no <details> summary to hang it
                    // off), so the countdown pill belongs here.
                    echo $this->render_break_countdown_el($doctor_id);
                    ?>
                    <?php // Same Refresh/Print/Export CSV/Download Image cluster
                    // the grouped view's own per-doctor <summary> carries (see
                    // render_patient_list_html()) — a pure doctor account is
                    // forced into exactly this single-doctor branch with no
                    // grouped header to get them from, so without this they
                    // never had a way to print/export/refresh their OWN queue
                    // at all, only staff viewing every doctor at once did.
                    // New classes rather than the grouped view's
                    // .mdbk-refresh-group/etc. — those look for a
                    // .mdbk-doctor-group wrapper (the <details> element) that
                    // doesn't exist here; ajax_refresh_doctor_group() itself
                    // is still reused as-is, it only ever needed doctor_id +
                    // is_today, both already true here.
                    //
                    // Toggle lives INSIDE this span (first child, same as
                    // the grouped view's own .mdbk-doctor-group-actions)
                    // rather than as a separate sibling — that keeps it
                    // bundled with the icon buttons as one action cluster,
                    // so the mobile layout can force the whole cluster onto
                    // its own row without leaving the icons stranded on a
                    // third row of their own (see admin-style.css). ?>
                    <span class="mdbk-today-card-actions">
                        <label class="mdbk-toggle mdbk-mini-toggle mdbk-doctor-live-queue-toggle" title="<?php esc_attr_e('Live Queue display for this doctor', 'doctor-appointment'); ?>">
                            <input type="checkbox" class="mdbk-doctor-live-queue-checkbox" data-doctor-id="<?php echo esc_attr($doctor_id); ?>" <?php checked($live_queue_enabled); ?>>
                            <span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Live Queue', 'doctor-appointment'); ?></span>
                        </label>
                        <button type="button" class="mdbk-icon-btn mdbk-refresh-today-card" title="<?php esc_attr_e('Refresh', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg></button>
                        <button type="button" class="mdbk-icon-btn mdbk-print-today-card" title="<?php esc_attr_e('Print', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg></button>
                        <?php $today_export_url = wp_nonce_url(add_query_arg(['page' => 'mdbk-schedule', 'filter_date' => current_time('Y-m-d'), 'filter_doctor' => $doctor_id, 'mdbk_export' => 'csv'], admin_url('admin.php')), 'mdbk_export_csv'); ?>
                        <a href="<?php echo esc_url($today_export_url); ?>" class="mdbk-icon-btn" title="<?php esc_attr_e('Export CSV', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg></a>
                        <button type="button" class="mdbk-icon-btn mdbk-download-today-card-image" title="<?php esc_attr_e('Download as Image', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg></button>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($doctor_id) : ?>
            <div class="mdbk-today-card-print-table" style="display:none;">
                <?php $this->render_today_queue_table($today_apps_display, false); ?>
            </div>
            <?php endif; ?>
            <?php if ($today_apps_display): ?>
            <div class="mdbk-patient-list" id="mdbk-today-queue-list" data-view-doctor-id="<?php echo esc_attr($doctor_id); ?>">
                <?php echo $this->render_patient_list_html($today_apps_display, $group_by_doctor, $serving_doctor_ids, true); ?>
            </div>
            <?php else: ?>
            <table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php echo $has_active_filters ? esc_html__('No patients match your search.', 'doctor-appointment') : esc_html__('No patients in queue today.', 'doctor-appointment'); ?></td></tr></tbody></table>
            <?php endif; ?>
        </div>

        <div class="mdbk-card">
            <div class="mdbk-card-header"><h3><?php _e('Upcoming Dates', 'doctor-appointment'); ?></h3><?php if ($group_by_doctor) echo $group_toggle_buttons; ?></div>
            <?php if ($upcoming_apps_display): ?>
            <div class="mdbk-patient-list">
                <?php echo $this->render_patient_list_html($upcoming_apps_display, $group_by_doctor, $serving_doctor_ids, false); ?>
            </div>
            <?php else: ?>
            <table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php echo $has_active_filters ? esc_html__('No patients match your search.', 'doctor-appointment') : esc_html__('No upcoming bookings.', 'doctor-appointment'); ?></td></tr></tbody></table>
            <?php endif; ?>
        </div>
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
     * A doctor account gets the full clinical profile above. Front-desk
     * staff (MDBK_CAP_QUEUE, no linked doctor post) gets a plain account-
     * info card instead — see the $doctor-is-null branch below, which
     * distinguishes "staff — nothing to show but there's nothing wrong"
     * from "admin previewing this page with no doctor link of their own"
     * (the same "not linked" notice as render_schedule_page()).
     */
    public function render_profile_page() {
        if (!current_user_can(MDBK_CAP_DOCTOR) && !current_user_can(MDBK_CAP_QUEUE) && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        }
        $doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
        $doctor = $doctor_id ? get_post($doctor_id) : null;
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('profile'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('My Profile', 'doctor-appointment'); ?></h1></div></div>
                <?php if (!$doctor && current_user_can(MDBK_CAP_QUEUE)):
                    $current_user = wp_get_current_user();
                    $is_manager = in_array('mdbk_manager_role', (array) $current_user->roles, true);
                    $role_label = $is_manager ? __('MANAGER', 'doctor-appointment') : __('FRONT DESK STAFF', 'doctor-appointment');
                ?>
                    <div class="mdbk-card mdbk-profile-view" style="padding:24px;">
                        <div class="mdbk-view-top-row">
                            <div class="mdbk-view-hero">
                                <div class="mdbk-view-avatar"><?php echo esc_html(self::initials($current_user->display_name)); ?></div>
                                <div class="mdbk-view-hero-info">
                                    <h3><?php echo esc_html($current_user->display_name); ?></h3>
                                    <span class="mdbk-admin-doctor-card-specialty" style="background:#ede9fe;color:#6d28d9;"><?php echo esc_html($role_label); ?></span>
                                </div>
                            </div>
                            <div class="mdbk-view-col">
                                <div class="mdbk-view-field"><label><?php _e('Username', 'doctor-appointment'); ?></label><span><?php echo esc_html($current_user->user_login); ?></span></div>
                                <div class="mdbk-view-field"><label><?php _e('Email', 'doctor-appointment'); ?></label><span><?php echo esc_html($current_user->user_email); ?></span></div>
                            </div>
                        </div>
                    </div>
                <?php elseif (!$doctor): ?>
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
                    $fee = get_post_meta($doctor_id, '_mdbk_doc_fee', true);
                    $breaks = get_post_meta($doctor_id, '_mdbk_breaks', true);
                    if (!is_array($breaks)) $breaks = [];
                    $thumb = get_the_post_thumbnail_url($doctor_id, 'thumbnail');
                    $thumb_id = get_post_thumbnail_id($doctor_id);
                    $spec = get_the_terms($doctor_id, 'mdbk_department');
                    $spec_name = ($spec && !is_wp_error($spec)) ? $spec[0]->name : __('General', 'doctor-appointment');
                    $spec_id = ($spec && !is_wp_error($spec)) ? $spec[0]->term_id : 0;
                    $colors = self::specialty_colors($spec_id);
                    $days = \MDBK\MDBK_Appointment_Manager::get_week_day_order();
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
                        <?php // Edit Profile opens the same shared doctor form the
                        // Doctors page uses, and admin-script.js fills its
                        // Queue & Ticketing radios from this element's
                        // data-queue-mode. Without it the form fell back to
                        // "Booking order" every time, so a doctor set to
                        // check-in order saw — and would silently re-save —
                        // the wrong mode. The doctor card on the Doctors page
                        // has carried this attribute all along; this one
                        // didn't. ?>
                        data-queue-mode="<?php echo esc_attr(\MDBK\MDBK_Appointment_Manager::queue_serial_mode($doctor_id)); ?>"
                        data-fee="<?php echo esc_attr($fee ?: ''); ?>"
                        data-extra-dates='<?php echo esc_attr(json_encode($extra_dates)); ?>'
                        data-off-dates='<?php echo esc_attr(json_encode($off_dates)); ?>'
                        data-specialty="<?php echo esc_attr($spec_id); ?>"
                        data-thumbnail="<?php echo esc_url($thumb ?: ''); ?>"
                        data-thumbnail-id="<?php echo esc_attr($thumb_id ?: 0); ?>"
                        data-breaks='<?php echo esc_attr(json_encode($breaks)); ?>'>
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
                            </div>
                            <?php // Same operating-settings pill row the admin's
                            // doctor View modal shows (built in admin-script.js) —
                            // a doctor could see their hours and breaks here but
                            // not how their own queue is numbered, what their fee
                            // is, or whether patients get a time picker at all.
                            // Rendered server-side here because this page has the
                            // values already; the classes are shared so the two
                            // stay visually identical. Duration moved down into
                            // the row for the same reason it did there. ?>
                            <?php
                            $p_checkin = \MDBK\MDBK_Appointment_Manager::queue_serial_mode($doctor_id) === 'checkin';
                            $p_slot_public = $slot_enabled !== 'no';
                            ?>
                            <div class="mdbk-view-pills">
                                <span class="mdbk-view-pill <?php echo $p_checkin ? 'is-amber' : 'is-blue'; ?>">
                                    <span class="mdbk-view-pill-label"><?php _e('Queue', 'doctor-appointment'); ?></span>
                                    <span class="mdbk-view-pill-value"><?php echo esc_html($p_checkin ? __('Check-in order', 'doctor-appointment') : __('Booking order', 'doctor-appointment')); ?></span>
                                </span>
                                <span class="mdbk-view-pill <?php echo $p_slot_public ? 'is-blue' : 'is-muted'; ?>">
                                    <span class="mdbk-view-pill-label"><?php _e('Time Slot', 'doctor-appointment'); ?></span>
                                    <span class="mdbk-view-pill-value"><?php echo esc_html($p_slot_public ? __('Public', 'doctor-appointment') : __('Hidden', 'doctor-appointment')); ?></span>
                                </span>
                                <span class="mdbk-view-pill is-muted">
                                    <span class="mdbk-view-pill-label"><?php _e('Duration', 'doctor-appointment'); ?></span>
                                    <span class="mdbk-view-pill-value"><?php echo esc_html($slot_duration); ?> <?php _e('min', 'doctor-appointment'); ?></span>
                                </span>
                                <span class="mdbk-view-pill is-green">
                                    <span class="mdbk-view-pill-label"><?php _e('Fee', 'doctor-appointment'); ?></span>
                                    <span class="mdbk-view-pill-value"><?php echo $fee !== '' ? esc_html('৳' . $fee) : '—'; ?></span>
                                </span>
                            </div>
                        </div>
                        <?php // A doctor's own live break status — the one piece
                        // of the Booking page header worth surfacing here (see
                        // render_doctor_card()'s own comment on why the rest of
                        // that header's actions don't belong on a profile view).
                        // This page has its own separate markup rather than
                        // reusing render_doctor_card() (a real WP_Post's schedule
                        // needs the day-label/current-week context this branch
                        // already built above it), so it needs its own copy of
                        // this call. ?>
                        <?php echo $this->render_break_countdown_el($doctor_id); ?>
                        <div class="mdbk-view-field mdbk-view-field-full"><label><?php _e('Bio', 'doctor-appointment'); ?></label><span><?php echo esc_html($bio ?: '—'); ?></span></div>
                        <details class="mdbk-availability-section" open>
                            <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><h4><?php _e('Weekly Availability', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                            <div class="mdbk-view-schedule-list">
                                <div class="mdbk-view-day-row mdbk-view-day-header"><span><?php _e('Day', 'doctor-appointment'); ?></span><span><?php _e('Hours', 'doctor-appointment'); ?></span></div>
                                <?php $day_labels = \MDBK\MDBK_Appointment_Manager::get_day_labels(); ?>
                                <?php foreach ($days as $day): $d = $schedule[$day] ?? null; $working = $d && !empty($d['active']); ?>
                                <div class="mdbk-view-day-row<?php echo $working ? '' : ' is-off'; ?>">
                                    <span class="mdbk-view-day-name"><?php echo esc_html($day_labels[$day]); ?></span>
                                    <span class="mdbk-view-day-hours"><?php if ($working): ?><?php echo esc_html(($d['from'] ? date_i18n(get_option('time_format'), strtotime($d['from'])) : '—') . ' – ' . ($d['to'] ? date_i18n(get_option('time_format'), strtotime($d['to'])) : '—')); ?><?php else: ?><span class="mdbk-view-day-off"><?php _e('Off', 'doctor-appointment'); ?></span><?php endif; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php // Same "only if there's anything to show" rule as
                        // Monthly Availability just below — a permanent list of
                        // this doctor's configured breaks, distinct from the
                        // live countdown pill above (which only appears within
                        // 10 minutes of one and says nothing the rest of the
                        // day). Matches the Edit modal's own "Break Times"
                        // section (same icon, same section ordering right
                        // after Weekly Availability) and the admin "View"
                        // popup's copy (admin-script.js) — this page just
                        // needed its own, being server-rendered rather than
                        // built from a data-breaks attribute. ?>
                        <?php if ($breaks): ?>
                        <details class="mdbk-availability-section">
                            <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><h4><?php _e('Break Times', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                            <div class="mdbk-view-schedule-list">
                                <?php foreach ($breaks as $b): if (empty($b['from']) || empty($b['to'])) continue; ?>
                                <div class="mdbk-view-day-row">
                                    <span class="mdbk-view-day-name"><?php echo esc_html($b['name'] ?? ''); ?></span>
                                    <span class="mdbk-view-day-hours"><?php echo esc_html(date_i18n(get_option('time_format'), strtotime($b['from'])) . ' – ' . date_i18n(get_option('time_format'), strtotime($b['to']))); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
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
     * Change-your-own-password page for a doctor or front-desk-staff
     * account — same gate as Profile, since WP's own native profile.php
     * (which has its own password field) is deliberately hidden for both
     * roles (register_admin_menu()) in favor of this plugin's own pages.
     */
    public function render_change_password_page() {
        if (!current_user_can(MDBK_CAP_DOCTOR) && !current_user_can(MDBK_CAP_QUEUE) && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        }
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        $success = isset($_GET['success']);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('change-password'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Change Password', 'doctor-appointment'); ?></h1></div></div>
                <div class="mdbk-card" style="max-width:420px; padding:24px;">
                    <?php if ($success) : ?>
                        <p style="color:#16A34A; font-weight:600; margin-top:0;"><?php _e('Password updated successfully.', 'doctor-appointment'); ?></p>
                    <?php endif; ?>
                    <?php if ($error) : ?>
                        <p style="color:#ef4444; font-weight:600; margin-top:0;"><?php echo esc_html($error); ?></p>
                    <?php endif; ?>
                    <form method="POST" class="mdbk-plain-form">
                        <?php wp_nonce_field('mdbk_change_password'); ?>
                        <input type="hidden" name="mdbk_change_password" value="1">
                        <div style="margin-bottom:8px;">
                            <label class="mdbk-form-label" for="mdbk-new-password"><?php _e('New Password', 'doctor-appointment'); ?></label>
                            <div class="mdbk-pwd-field">
                                <input type="password" name="new_password" id="mdbk-new-password" autocomplete="new-password" required minlength="8" aria-describedby="mdbk-password-length-hint">
                                <button type="button" class="button mdbk-pwd-toggle" data-target="mdbk-new-password" aria-label="<?php esc_attr_e('Show password', 'doctor-appointment'); ?>">
                                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                </button>
                            </div>
                            <p id="mdbk-password-length-hint" style="margin:6px 0 0; font-size:12px; color:#94a3b8;"><?php _e('At least 8 characters.', 'doctor-appointment'); ?></p>
                            <div class="mdbk-pw-strength-bar"><span id="mdbk-pw-strength-fill"></span></div>
                            <p id="mdbk-pw-strength-label" style="margin:4px 0 0; font-size:12px; color:#94a3b8;">&nbsp;</p>
                        </div>
                        <div style="margin-bottom:56px;">
                            <label class="mdbk-form-label" for="mdbk-confirm-password"><?php _e('Confirm New Password', 'doctor-appointment'); ?></label>
                            <div class="mdbk-pwd-field">
                                <input type="password" name="confirm_password" id="mdbk-confirm-password" autocomplete="new-password" required minlength="8">
                                <button type="button" class="button mdbk-pwd-toggle" data-target="mdbk-confirm-password" aria-label="<?php esc_attr_e('Show password', 'doctor-appointment'); ?>">
                                    <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                </button>
                            </div>
                            <p id="mdbk-password-match-hint" style="margin:6px 0 0; font-size:12px; color:#94a3b8;">&nbsp;</p>
                        </div>
                        <button type="submit" class="mdbk-btn-add"><?php _e('Update Password', 'doctor-appointment'); ?></button>
                        <p style="margin-top:14px; font-size:12px; color:#94a3b8;"><?php _e('Tip: pressing Enter after typing also submits the form.', 'doctor-appointment'); ?></p>
                    </form>
                    <script>
                    (function() {
                        var newPass = document.getElementById('mdbk-new-password');
                        var confirmPass = document.getElementById('mdbk-confirm-password');
                        var lengthHint = document.getElementById('mdbk-password-length-hint');
                        var matchHint = document.getElementById('mdbk-password-match-hint');
                        var strengthFill = document.getElementById('mdbk-pw-strength-fill');
                        var strengthLabel = document.getElementById('mdbk-pw-strength-label');
                        if (!newPass || !confirmPass || !lengthHint || !matchHint) return;

                        // Show/hide toggle — plain, parameterized version of WP
                        // core's own .pwd-toggle behavior (wp-admin/js/password-toggle.js),
                        // which is hardcoded to a single id="pwd" and can't target
                        // two independent fields on the same page.
                        document.querySelectorAll('.mdbk-pwd-toggle').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var input = document.getElementById(btn.getAttribute('data-target'));
                                var icon = btn.querySelector('.dashicons');
                                if (!input || !icon) return;
                                var nowShowing = input.type === 'password';
                                input.type = nowShowing ? 'text' : 'password';
                                icon.classList.toggle('dashicons-visibility', !nowShowing);
                                icon.classList.toggle('dashicons-hidden', nowShowing);
                                btn.setAttribute('aria-label', nowShowing
                                    ? <?php echo wp_json_encode(__('Hide password', 'doctor-appointment')); ?>
                                    : <?php echo wp_json_encode(__('Show password', 'doctor-appointment')); ?>);
                            });
                        });

                        // Only ever reports the length REQUIREMENT (>= 8), never
                        // an overall quality verdict — that's what the strength
                        // meter below is for. Showing "Looks good" here once
                        // length passed used to read as a second, contradicting
                        // quality opinion right above a red "Very weak" bar for
                        // something like "12345678" (8 digits, long enough, but
                        // trivially guessable) — so once length is satisfied,
                        // this hint just goes quiet instead of asserting
                        // anything about quality.
                        function updateLengthHint() {
                            var len = newPass.value.length;
                            var ok = len >= 8;
                            lengthHint.innerHTML = ok
                                ? '&nbsp;'
                                : len + '/8 — ' + <?php echo wp_json_encode(__('at least 8 characters.', 'doctor-appointment')); ?>;
                            lengthHint.style.color = '#94a3b8';
                        }
                        function updateMatchHint() {
                            if (!confirmPass.value) { matchHint.innerHTML = '&nbsp;'; return; }
                            var match = confirmPass.value === newPass.value;
                            matchHint.textContent = match
                                ? <?php echo wp_json_encode(__('✓ Passwords match.', 'doctor-appointment')); ?>
                                : <?php echo wp_json_encode(__('Passwords do not match yet.', 'doctor-appointment')); ?>;
                            matchHint.style.color = match ? '#16A34A' : '#ef4444';
                        }

                        // Strength meter — reuses WP core's own
                        // wp.passwordStrength.meter() (zxcvbn), the exact same
                        // scoring wp-admin's native Profile page uses, mapped to
                        // the same score->label convention as core's own
                        // wp-admin/js/user-profile.js check_pass_strength().
                        var STRENGTH_STEPS = [
                            { pct: '20%',  color: '#ef4444', label: <?php echo wp_json_encode(__('Very weak', 'doctor-appointment')); ?> },
                            { pct: '40%',  color: '#ef4444', label: <?php echo wp_json_encode(__('Very weak', 'doctor-appointment')); ?> },
                            { pct: '55%',  color: '#f97316', label: <?php echo wp_json_encode(__('Weak', 'doctor-appointment')); ?> },
                            { pct: '75%',  color: '#eab308', label: <?php echo wp_json_encode(__('Medium', 'doctor-appointment')); ?> },
                            { pct: '100%', color: '#16A34A', label: <?php echo wp_json_encode(__('Strong', 'doctor-appointment')); ?> }
                        ];
                        function updateStrength() {
                            if (!strengthFill || !strengthLabel) return;
                            var val = newPass.value;
                            if (!val) {
                                strengthFill.style.width = '0%';
                                strengthLabel.innerHTML = '&nbsp;';
                                return;
                            }
                            if (typeof wp === 'undefined' || !wp.passwordStrength) {
                                strengthLabel.textContent = '';
                                return;
                            }
                            var disallowed = wp.passwordStrength.userInputDisallowedList
                                ? wp.passwordStrength.userInputDisallowedList()
                                : [];
                            var score = wp.passwordStrength.meter(val, disallowed, val);
                            // -1: zxcvbn hasn't finished loading yet (it's
                            // fetched async) — matches core's own "unknown"
                            // state rather than guessing a bar color for it.
                            if (score === -1) {
                                strengthFill.style.width = '100%';
                                strengthFill.style.backgroundColor = '#cbd5e1';
                                strengthLabel.textContent = <?php echo wp_json_encode(__('Password strength unknown', 'doctor-appointment')); ?>;
                                strengthLabel.style.color = '#94a3b8';
                                return;
                            }
                            var step = STRENGTH_STEPS[Math.max(0, Math.min(4, score))];
                            strengthFill.style.width = step.pct;
                            strengthFill.style.backgroundColor = step.color;
                            strengthLabel.textContent = step.label;
                            strengthLabel.style.color = step.color;
                        }

                        newPass.addEventListener('input', function() { updateLengthHint(); updateMatchHint(); updateStrength(); });
                        confirmPass.addEventListener('input', updateMatchHint);
                        updateLengthHint();
                        updateStrength();
                    })();
                    </script>
                </div>
            </div></div></div>
        <?php
    }

    /**
     * Saves a new password for the CURRENTLY LOGGED-IN user only — there
     * is no target-user parameter anywhere in this handler, so there is
     * no privilege check to get wrong; it can only ever change the
     * requester's own password. wp_set_password() invalidates every
     * session for that user, including the one making this request, so
     * the auth cookie is re-issued immediately after — otherwise a doctor
     * changing their own password would be unexpectedly logged out by it.
     */
    public function handle_change_password_save() {
        if (!isset($_POST['mdbk_change_password'])) return;
        if (!is_user_logged_in()) return;
        check_admin_referer('mdbk_change_password');

        $new_password = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $confirm_password = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

        if (strlen($new_password) < 8) {
            wp_redirect(admin_url('admin.php?page=mdbk-change-password&error=' . urlencode(__('Password must be at least 8 characters.', 'doctor-appointment'))));
            exit;
        }
        if ($new_password !== $confirm_password) {
            wp_redirect(admin_url('admin.php?page=mdbk-change-password&error=' . urlencode(__('Passwords do not match.', 'doctor-appointment'))));
            exit;
        }

        $user_id = get_current_user_id();
        wp_set_password($new_password, $user_id);
        wp_set_auth_cookie($user_id);
        wp_set_current_user($user_id);

        wp_redirect(admin_url('admin.php?page=mdbk-change-password&success=1'));
        exit;
    }

    /**
     * All of one doctor's (or every doctor's, combined) today's-queue
     * rows, rendered together — shared by render_schedule_today_view()'s
     * initial page render and the "Mark as Visited" / Skip / Start
     * Visiting / Check-In AJAX handlers, so every action on the Booking
     * page refreshes through the exact same code path rather than each
     * maintaining its own single-row swap. Mirrors
     * render_schedule_today_view()'s own $today_apps computation exactly,
     * so the AJAX-refreshed list never drifts from what a fresh page load
     * would show.
     */
    private function render_today_queue_rows($doctor_id, $group_by_doctor = false) {
        $apps = $this->get_today_queue_apps($doctor_id);
        return $this->render_patient_list_html($apps, $group_by_doctor, $this->get_serving_doctor_ids($apps), true);
    }

    /**
     * Which doctors (by ID) currently have a patient in "serving" state
     * today, derived from an already-fetched TODAY's-queue list rather
     * than a separate query per row. Deliberately computed from the full,
     * unfiltered today's list (see get_today_queue_apps()) and not from
     * whatever a search/status/doctor filter narrowed the display down
     * to — filtering out the actually-serving row must never make
     * render_my_queue_patient_row() think nobody's being seen and wrongly
     * offer "Start Visiting" to someone else of the same doctor.
     */
    private function get_serving_doctor_ids($apps) {
        $ids = [];
        foreach ($apps as $a) {
            if (get_post_status($a) === 'mdbk_serving') {
                $ids[intval(get_post_meta($a->ID, '_mdbk_doctor_id', true))] = true;
            }
        }
        return $ids;
    }

    /**
     * The "Break name 09:44" pill that sits centred in one doctor's own
     * queue heading, counting down the last 10 minutes before that
     * doctor's next break and then holding as an "on break now" badge
     * until it ends. Deliberately rendered per doctor rather than once
     * for the whole page: two doctors whose breaks overlap would have to
     * share a single pill, which could only ever name one of them.
     *
     * current_time('timestamp') deliberately NOT used for the baseline —
     * it already has the site's GMT offset baked in, so handing it to
     * JS's `new Date(ms)` (which expects a true UTC timestamp and
     * re-applies the *browser's* own timezone on top) double-applies the
     * offset. Passing the site's wall-clock time as plain
     * seconds-since-midnight instead sidesteps timestamp/timezone math
     * entirely — admin-script.js only ever needs "how far into today is
     * it", never a real calendar date.
     *
     * Sent to the millisecond, not the whole second: truncating here
     * used to hand the browser a baseline up to a second in the past,
     * which the countdown then carried as a permanent lag for the rest
     * of the page's life. (The other, larger half of that lag was on
     * the JS side — see its own comment on anchoring to responseStart.)
     */
    private function render_break_countdown_el($doc_id) {
        if (!$doc_id) return '';
        $breaks = get_post_meta($doc_id, '_mdbk_breaks', true);
        if (!is_array($breaks)) return '';
        $list = [];
        foreach ($breaks as $b) {
            if (empty($b['from']) || empty($b['to'])) continue;
            $list[] = ['name' => $b['name'], 'from' => $b['from'], 'to' => $b['to']];
        }
        if (empty($list)) return '';
        $now = new \DateTimeImmutable('now', wp_timezone());
        $seconds_now = round(
            intval($now->format('H')) * 3600
            + intval($now->format('i')) * 60
            + intval($now->format('s'))
            + intval($now->format('u')) / 1000000,
            3
        );
        // Every break goes to JS, not just the next one — the page can
        // sit open for hours, and the pill has to roll on to the next
        // break by itself once the current one ends rather than needing
        // a reload to learn about it.
        return '<span class="mdbk-break-countdown" style="display:none;"'
            . ' data-breaks="' . esc_attr(wp_json_encode($list)) . '"'
            . ' data-server-now-seconds="' . esc_attr($seconds_now) . '"></span>';
    }

    /**
     * One doctor's Today's Queue rows, plain — no inline break marker.
     *
     * An earlier version of this inserted a permanent marker row at the
     * point in the list where a configured break actually fell
     * chronologically. In practice that landed buried among the patient
     * rows wherever a break happened to sit, which was hard to spot —
     * staff wanted the break called out ABOVE the whole list instead,
     * next to the countdown that's already there
     * (render_break_countdown_el()), not scattered through it. That pill
     * already covers the same ground this row did (name, and now a
     * literal countdown to it) without the "where did it go" problem, so
     * the marker row was dropped rather than duplicating the same fact
     * in two places.
     */
    private function render_queue_rows($apps, $serving_doctor_ids) {
        $html = '';
        foreach ($apps as $a) {
            $html .= $this->render_my_queue_patient_row($a, $serving_doctor_ids);
        }
        return $html;
    }

    /**
     * Renders a list of appointments as patient rows — either a flat list
     * (one doctor's own view) or, for front-desk staff's all-doctors
     * view, collapsed under a per-doctor <details> section (see the
     * .mdbk-doctor-group CSS) so staff can scan/collapse one doctor's
     * queue at a time instead of one long mixed list. Shared by the
     * initial page render (Today's Queue + Patient List) and
     * render_today_queue_rows() (the AJAX-refresh fragment for Mark as
     * Visited / Skip / Check-In / Start Visiting), so grouping never
     * drifts between the two. $is_today_group controls whether each
     * doctor's Export CSV link is scoped to just today's date (Today's
     * Queue) or every date (Other Dates) — Print/Download Image need no
     * such distinction, since both work off whatever's already in this
     * doctor's own $doc_apps rather than a fresh server query.
     */
    private function render_patient_list_html($apps, $group_by_doctor, $serving_doctor_ids = [], $is_today_group = true) {
        if (!$group_by_doctor) {
            return $this->render_queue_rows($apps, $serving_doctor_ids);
        }

        $groups = [];
        foreach ($apps as $a) {
            $doc_id = intval(get_post_meta($a->ID, '_mdbk_doctor_id', true));
            $groups[$doc_id][] = $a;
        }
        uksort($groups, function($a, $b) {
            $name_a = $a ? get_the_title($a) : '';
            $name_b = $b ? get_the_title($b) : '';
            return strcasecmp($name_a, $name_b);
        });

        $html = '';
        foreach ($groups as $doc_id => $doc_apps) {
            // get_the_title() comes back empty for a doctor_id pointing at a
            // post that no longer exists (a doctor deleted after their
            // bookings were taken, or demo data re-seeded underneath them),
            // which rendered a nameless group header with no hint of why.
            // Treated the same as never having had a doctor at all.
            $doc_name = $doc_id ? get_the_title($doc_id) : '';
            if ($doc_name === '') $doc_name = __('Unassigned', 'doctor-appointment');
            $count = count($doc_apps);
            $export_args = ['page' => 'mdbk-schedule', 'filter_doctor' => $doc_id, 'mdbk_export' => 'csv'];
            if ($is_today_group) $export_args['filter_date'] = current_time('Y-m-d');
            $doc_export_url = wp_nonce_url(add_query_arg($export_args, admin_url('admin.php')), 'mdbk_export_csv');
            // Per-doctor Live Queue on/off — an independent override on top
            // of Global Settings' own master switch (see render_queue_list()
            // in shortcode.php, which checks both). Only meaningful on the
            // Today's Queue grouping (Live Queue is inherently a "today"
            // display) and only for a real doctor row (not the "Unassigned"
            // bucket, doc_id 0, which has no _mdbk_live_queue_enabled meta
            // to own).
            $show_live_queue_control = $is_today_group && $doc_id;
            $live_queue_enabled = $show_live_queue_control ? \MDBK\MDBK_Appointment_Manager::is_doctor_live_queue_enabled($doc_id) : false;
            // Streaming pulse dot — read-only, automatic: it's lit and
            // pulsing exactly while this doctor currently has someone in
            // "serving" status (i.e. actually visiting a patient right
            // now), and goes back to a plain static dot the moment nobody's
            // being seen (patient finished, or doctor stepped out/on a
            // break) — $serving_doctor_ids is already computed per-render
            // by get_serving_doctor_ids(), and this same header HTML is
            // regenerated on every AJAX action (Start Visiting/Mark
            // Visited/Check-In/Skip — see render_today_queue_rows()), so
            // the dot always reflects current state with no separate JS
            // sync needed. Only meaningful for Today's Queue (a past date's
            // "serving" status isn't a live thing to watch).
            $show_visiting_dot = $is_today_group && $doc_id;
            $doctor_is_visiting = $show_visiting_dot && isset($serving_doctor_ids[$doc_id]);

            $html .= '<details class="mdbk-doctor-group" data-doctor-id="' . esc_attr($doc_id) . '" data-is-today="' . ($is_today_group ? '1' : '0') . '" open>';
            $html .= '<summary class="mdbk-doctor-group-header">';
            if ($show_visiting_dot) {
                $html .= '<span class="mdbk-live-pulse-dot' . ($doctor_is_visiting ? ' mdbk-live-pulse-active' : '') . '" title="' . esc_attr__('Doctor is currently visiting a patient', 'doctor-appointment') . '"></span> ';
            }
            $html .= '<span class="mdbk-doctor-group-name">' . esc_html($doc_name) . '</span><span class="mdbk-doctor-group-count">' . esc_html(sprintf(_n('%d patient', '%d patients', $count, 'doctor-appointment'), $count)) . '</span>';
            // This doctor's own break countdown, centred against their
            // header — only for today (a break is a daily pattern, so it
            // means nothing against a mixed multi-date "Upcoming" list).
            if ($is_today_group) {
                $html .= $this->render_break_countdown_el($doc_id);
            }
            $html .= '<span class="mdbk-doctor-group-actions">';
            if ($show_live_queue_control) {
                $html .= '<label class="mdbk-toggle mdbk-mini-toggle mdbk-doctor-live-queue-toggle" title="' . esc_attr__('Live Queue display for this doctor', 'doctor-appointment') . '" onclick="event.stopPropagation();">';
                $html .= '<input type="checkbox" class="mdbk-doctor-live-queue-checkbox" data-doctor-id="' . esc_attr($doc_id) . '"' . checked($live_queue_enabled, true, false) . '>';
                $html .= '<span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text">' . esc_html__('Live Queue', 'doctor-appointment') . '</span>';
                $html .= '</label>';
            }
            // preventDefault() alone (not stopPropagation() too, like these
            // buttons used to have) is enough to stop the click from
            // also toggling the parent <summary>'s <details> open/closed —
            // stopping propagation went further and kept the click from
            // ever reaching admin-script.js's delegated document-level
            // listeners for these buttons, which are the only thing that
            // actually opens the print window / builds the image.
            $html .= '<button type="button" class="mdbk-icon-btn mdbk-refresh-group" title="' . esc_attr__('Refresh this doctor\'s list', 'doctor-appointment') . '" onclick="event.preventDefault();"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg></button>';
            $html .= '<button type="button" class="mdbk-icon-btn mdbk-print-group" title="' . esc_attr__('Print', 'doctor-appointment') . '" onclick="event.preventDefault();"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg></button>';
            $html .= '<a href="' . esc_url($doc_export_url) . '" class="mdbk-icon-btn" title="' . esc_attr__('Export CSV', 'doctor-appointment') . '" onclick="event.stopPropagation();"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg></a>';
            $html .= '<button type="button" class="mdbk-icon-btn mdbk-download-group-image" title="' . esc_attr__('Download as Image', 'doctor-appointment') . '" onclick="event.preventDefault();"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg></button>';
            // An inline SVG chevron here (not the plain border-corner trick
            // .mdbk-availability-chevron uses elsewhere) — that trick's
            // rotate(45deg)/rotate(-135deg) pairing relies on the box's
            // visual center matching its layout box exactly, which broke
            // (arrow drifted left, wrong angle) once this element grew into
            // a bigger circular button; an SVG rotates cleanly around its
            // own exact center regardless of the button's padding.
            $html .= '<span class="mdbk-availability-chevron"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>';
            $html .= '</span></summary>';
            $html .= '<div class="mdbk-patient-list mdbk-doctor-group-list">';
            $html .= $this->render_queue_rows($doc_apps, $serving_doctor_ids);
            $html .= '</div>';
            $html .= '<div class="mdbk-doctor-group-print-table" style="display:none;">';
            ob_start();
            $this->render_today_queue_table($doc_apps, false);
            $html .= ob_get_clean();
            $html .= '</div>';
            $html .= '</details>';
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

        // Booking-order mode (default): unchanged from before check-in-order
        // queue mode existed — checked-in waiting patients bubble above
        // still-not-checked-in ones (rank 1 vs 2), each tier then sorted by
        // ticket. Check-in mode mirrors that same shape now: a waiting
        // patient who has checked in rises into rank 1 and sorts by their
        // live Q number (display_ticket_number()), while everyone still
        // pending holds rank 2 in slot-time schedule order.
        // Queue serial mode is PER DOCTOR now (each doctor's own profile
        // setting, see queue_serial_mode()), so the mode is resolved per
        // row from that row's own doctor — a mixed-doctor list (the
        // $doctor_id=0 view) then sorts each doctor's rows by that
        // doctor's own rule instead of one site-wide assumption.
        $row_checkin = function($app) {
            return \MDBK\MDBK_Appointment_Manager::queue_serial_mode(
                intval(get_post_meta($app->ID, '_mdbk_doctor_id', true))
            ) === 'checkin';
        };
        $rank = function($app) {
            $status = \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($app));
            if ($status === 'serving') return 0;
            if ($status === 'waiting') {
                $checked_in = get_post_meta($app->ID, '_mdbk_checked_in', true) === 'yes';
                return $checked_in ? 1 : 2;
            }
            if ($status === 'no-show') return 3;
            return 4; // completed
        };
        usort($today_apps, function($a, $b) use ($rank, $row_checkin) {
            $rank_a = $rank($a);
            $rank_b = $rank($b);
            if ($rank_a !== $rank_b) return $rank_a <=> $rank_b;
            $checkin_a = $row_checkin($a);
            $checkin_b = $row_checkin($b);
            if ($checkin_a && $checkin_b && $rank_a === 1) {
                return \MDBK\MDBK_Appointment_Manager::display_ticket_number($a->ID)
                    <=> \MDBK\MDBK_Appointment_Manager::display_ticket_number($b->ID);
            }
            if (!$checkin_a && !$checkin_b) {
                $ticket_a = intval(get_post_meta($a->ID, '_mdbk_ticket_number', true));
                $ticket_b = intval(get_post_meta($b->ID, '_mdbk_ticket_number', true));
                return $ticket_a <=> $ticket_b;
            }
            return strcmp(
                (string) get_post_meta($a->ID, '_mdbk_slot_time', true),
                (string) get_post_meta($b->ID, '_mdbk_slot_time', true)
            );
        });

        return $today_apps;
    }

    /**
     * One doctor's (or every doctor's, $doctor_id=0) strictly-future
     * bookings — "Upcoming Dates" used to also pull in every PAST booking
     * (anything "!== today"), which didn't match what that section
     * actually promises; past bookings stay reachable via a specific date
     * or the All Dates list. Shared by render_schedule_today_view() and
     * ajax_refresh_doctor_group() (the per-doctor-group refresh button),
     * so the two never drift apart on what counts as "upcoming".
     */
    private function get_upcoming_queue_apps($doctor_id) {
        $today = current_time('Y-m-d');
        $all_apps = $this->get_filtered_appointments(null, $doctor_id, '');
        return array_values(array_filter($all_apps, function($a) use ($today) {
            return get_post_meta($a->ID, '_mdbk_appointment_date', true) > $today;
        }));
    }

    private function render_my_queue_patient_row($a, $serving_doctor_ids = []) {
        $p_name = get_post_meta($a->ID, '_mdbk_patient_name', true);
        $phone = get_post_meta($a->ID, '_mdbk_patient_phone', true);
        $email = get_post_meta($a->ID, '_mdbk_patient_email', true);
        $age = get_post_meta($a->ID, '_mdbk_patient_age', true);
        $gender = get_post_meta($a->ID, '_mdbk_patient_gender', true);
        $age_gender = trim($gender . ($age && $gender ? ' · ' : '') . $age);
        $gender_key = $gender ? strtolower($gender) : 'unknown';
        $date = get_post_meta($a->ID, '_mdbk_appointment_date', true);
        $slot_time = get_post_meta($a->ID, '_mdbk_slot_time', true);
        $ticket = \MDBK\MDBK_Appointment_Manager::display_ticket_number($a->ID);
        $patient_id = get_post_meta($a->ID, '_mdbk_patient_id', true);
        // Read off the patient record, not this booking — see patient_address().
        $address = \MDBK\MDBK_Appointment_Manager::patient_address($a->ID);
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
        // "Mark as Visited" only makes sense for someone actually being
        // seen right now ("serving") — closing out a merely
        // waiting-and-checked-in patient directly used to be allowed too,
        // but that silently skipped ever showing them as "Visiting" at
        // all (see start_visiting()'s comment). A past still-waiting/
        // serving row (missed check-in, never closed out) isn't closeable
        // from here either — those show a plain "Upcoming" badge instead
        // (matched server-side too, in ajax_mark_visited()).
        $can_visit = $is_today && $status === 'serving';
        // Checked in but temporarily stepped away (toilet, phone call,
        // etc.) — set via the Skip toggle below. Loses the "Start
        // Visiting" button below while set, so a doctor/staff member
        // doesn't start seeing someone who isn't actually in the room;
        // toggling it off ("Recall") brings that button back.
        $skipped = get_post_meta($a->ID, '_mdbk_skipped', true) === 'yes';
        $can_skip = $is_today && $status === 'waiting' && $checked_in;
        // "Start Visiting" — the ONLY way a checked-in, waiting,
        // not-currently-skipped patient becomes "serving" (see
        // mark_checked_in()'s comment — check-in never auto-promotes,
        // even when this is the only patient checked in today), and only
        // when nobody else of the SAME doctor is already being seen (see
        // get_serving_doctor_ids()/start_visiting()'s one-at-a-time
        // invariant).
        $doctor_id_of_row = intval(get_post_meta($a->ID, '_mdbk_doctor_id', true));
        $someone_else_serving = isset($serving_doctor_ids[$doctor_id_of_row]);
        $can_start_visiting = $is_today && $status === 'waiting' && $checked_in && !$skipped && !$someone_else_serving;
        // Check-In straight from this page too, not just the Bookings page
        // or a QR scan — gated on MDBK_CAP_QUEUE specifically (not just
        // being allowed to view this page at all) since that's the same
        // capability ajax_admin_checkin() itself requires; a pure doctor
        // account (MDBK_CAP_DOCTOR only) won't see this button, same as
        // they've never had a Check-In control on the Bookings page either.
        $can_checkin = $is_today && $status === 'waiting' && !$checked_in && current_user_can(MDBK_CAP_QUEUE);
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
        // Site's own Settings > General > Date Format, not a hardcoded
        // pattern — same convention render_patient_visit_history_table()
        // and the invoice modal's date_display already follow, so this
        // row's own Date chip doesn't show a different format than
        // everywhere else a booking date is printed.
        $date_display = $date ? date_i18n(get_option('date_format'), strtotime($date)) : '—';
        $time_display = $slot_time ? date_i18n('g:i A', strtotime($slot_time)) : '—';
        ob_start();
        ?>
        <?php
        // Timing for the live stopwatch (a running visit) and the recorded
        // length (a finished one). Both come off the server clock, so every
        // panel watching this queue counts the same seconds.
        $visit_started = intval(get_post_meta($a->ID, '_mdbk_visit_started_at', true));
        $visit_duration = \MDBK\MDBK_Appointment_Manager::visit_duration($a->ID);
        $visit_elapsed = ($status === 'serving' && $visit_started) ? max(0, current_time('timestamp') - $visit_started) : 0;
        ?>
        <div class="mdbk-patient-row mdbk-my-queue-row<?php echo $row_state ? ' mdbk-row-state-' . esc_attr($row_state) : ''; ?>" data-id="<?php echo esc_attr($a->ID); ?>" data-patient="<?php echo esc_attr($p_name); ?>"<?php if ($visit_elapsed || ($status === 'serving' && $visit_started)) : ?> data-visit-elapsed="<?php echo esc_attr($visit_elapsed); ?>"<?php endif; ?>>
            <span class="mdbk-patient-row-ticket-slot">
                <?php // Queue number for today's rows that have one, Booking ID
                // otherwise — the single rule in display_ticket_label(), shared
                // with the print/image table and the CSV export so a printout
                // can't disagree with the screen it was printed from. A queue
                // number only means something inside its own day; the Booking
                // ID that stands in elsewhere identifies THIS booking, which is
                // what the row is, and matches the patient's confirmation. ?>
                <?php $ticket_label = \MDBK\MDBK_Appointment_Manager::display_ticket_label($a->ID); ?>
                <?php $is_queue_label = strpos($ticket_label, 'Q') === 0; ?>
                <span class="mdbk-patient-row-ticket <?php echo $is_queue_label ? 'mdbk-patient-row-queue' : 'mdbk-patient-row-bookingid'; ?>" title="<?php echo $is_queue_label ? esc_attr__('Queue number', 'doctor-appointment') : esc_attr__('Booking ID', 'doctor-appointment'); ?>"><?php echo esc_html($ticket_label); ?></span>
            </span>
            <?php if ($patient_id && $this->can_view_patient($patient_id)) : ?>
                <a href="#" class="mdbk-patient-row-name mdbk-view-patient" data-id="<?php echo esc_attr($patient_id); ?>" title="<?php esc_attr_e('View patient', 'doctor-appointment'); ?>"><?php echo esc_html($p_name); ?></a>
            <?php else : ?>
                <span class="mdbk-patient-row-name"><?php echo esc_html($p_name); ?></span>
            <?php endif; ?>
            <span class="mdbk-patient-row-chip-slot"><?php if ($phone): ?><span class="mdbk-patient-row-chip mdbk-chip-phone"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($email): ?><span class="mdbk-patient-row-chip mdbk-chip-email"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($email); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($address): ?><span class="mdbk-patient-row-chip mdbk-chip-address" title="<?php echo esc_attr($address); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> <?php echo esc_html($address); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($age_gender): ?><span class="mdbk-patient-row-chip mdbk-meta-pill mdbk-gender-<?php echo esc_attr($gender_key); ?>"><?php echo esc_html($age_gender); ?></span><?php endif; ?></span>
            <?php // A finished visit carries how long it ran; a running one
            // shows a live counter the stopwatch script ticks. Nothing is
            // shown for a visit that was never timed. ?>
            <?php // Presented like the Date/Time columns beside it rather than
            // as a pill — it is another plain fact about the visit, and a
            // filled chip made it read as a status badge. ?>
            <span class="mdbk-patient-row-visit-col<?php echo ($status === 'serving' && $visit_started) ? ' is-live' : ''; ?>">
                <?php if ($status === 'serving' && $visit_started) : ?>
                    <span data-visit-timer="<?php echo esc_attr($visit_elapsed); ?>" title="<?php esc_attr_e('Visit in progress', 'doctor-appointment'); ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="13" r="8"></circle><path d="M12 9v4l2 2"></path><path d="M9 2h6"></path></svg>
                        <span class="mdbk-patient-row-time-label"><?php esc_html_e('Visit', 'doctor-appointment'); ?></span>
                        <span class="mdbk-patient-row-time-value mdbk-visit-timer-value"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::format_duration($visit_elapsed) ?: '0s'); ?></span>
                    </span>
                <?php elseif ($visit_duration) : ?>
                    <span title="<?php esc_attr_e('How long this visit took', 'doctor-appointment'); ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="13" r="8"></circle><path d="M12 9v4l2 2"></path><path d="M9 2h6"></path></svg>
                        <span class="mdbk-patient-row-time-label"><?php esc_html_e('Visit', 'doctor-appointment'); ?></span>
                        <span class="mdbk-patient-row-time-value"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::format_duration($visit_duration)); ?></span>
                    </span>
                <?php endif; ?>
            </span>
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
                <?php // Invoice — only ever meaningful once a visit has
                // actually happened (same completed-only gate as the
                // other date views' render_patient_appointment_row()),
                // which is exactly when the status block below is already
                // showing "Visited" instead of one of the workflow
                // buttons — this never renders alongside those.
                // $is_today too: this same row template also renders the
                // "Upcoming Dates" section below (see render_patient_list_html()),
                // strictly future dates only — a booking that hasn't
                // happened yet can't have a real invoice regardless of
                // what its status field says.
                //
                // Deliberately BEFORE the status block rather than after:
                // the badge is the last thing in this cluster and the
                // cluster is right-aligned, so every row's badge ends on
                // the same line down the list. With the invoice trailing
                // it instead, only the rows that had one pushed their
                // badge left and the column read as ragged. ?>
                <?php if ($is_today && $status === 'completed' && current_user_can(MDBK_CAP_QUEUE)) : ?>
                    <a href="#" class="mdbk-action-btn mdbk-open-invoice" data-id="<?php echo esc_attr($a->ID); ?>" title="<?php esc_attr_e('Invoice', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="15" y2="17"></line></svg></a>
                <?php endif; ?>
                <?php if ($can_visit): ?>
                    <button type="button" class="mdbk-btn-sm mdbk-status-action-btn mdbk-status-action-visiting mdbk-mark-visited" data-id="<?php echo esc_attr($a->ID); ?>" title="<?php esc_attr_e('Currently Visiting — click to mark as visited', 'doctor-appointment'); ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg><?php _e('Mark Visited', 'doctor-appointment'); ?></button>
                <?php elseif (!$is_today && in_array($status, ['waiting', 'serving'], true)): ?>
                    <span class="mdbk-badge mdbk-badge-upcoming"><?php _e('Upcoming', 'doctor-appointment'); ?></span>
                <?php elseif ($can_start_visiting): ?>
                    <button type="button" class="mdbk-btn-sm mdbk-status-action-btn mdbk-status-action-start mdbk-start-visiting" data-id="<?php echo esc_attr($a->ID); ?>" title="<?php esc_attr_e('Waiting — click to start visiting', 'doctor-appointment'); ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg><?php _e('Start Visit', 'doctor-appointment'); ?></button>
                <?php elseif ($can_checkin): ?>
                    <button type="button" class="mdbk-btn-add mdbk-btn-sm mdbk-admin-checkin-btn" data-id="<?php echo esc_attr($a->ID); ?>"><?php _e('Check In', 'doctor-appointment'); ?></button>
                <?php elseif ($is_today && $status === 'waiting' && !$checked_in): ?>
                    <span class="mdbk-badge mdbk-badge-not-checked-in"><?php _e('Not Checked In', 'doctor-appointment'); ?></span>
                <?php elseif ($is_today && $status === 'waiting' && $checked_in && $someone_else_serving): ?>
                    <span class="mdbk-badge mdbk-badge-status-waiting" title="<?php esc_attr_e('Another patient is currently being seen', 'doctor-appointment'); ?>"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::status_display_label('waiting')); ?></span>
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
    private function render_patient_directory_row($p, $visit_count = 0, $last_doctor_id = 0) {
        $phone = get_post_meta($p->ID, '_mdbk_patient_phone', true);
        $email = get_post_meta($p->ID, '_mdbk_patient_email', true);
        $address = get_post_meta($p->ID, '_mdbk_patient_address', true);
        $age = get_post_meta($p->ID, '_mdbk_patient_age', true);
        $gender = get_post_meta($p->ID, '_mdbk_patient_gender', true);
        $age_gender = trim($gender . ($age && $gender ? ' · ' : '') . $age);
        $gender_key = $gender ? strtolower($gender) : 'unknown';
        ob_start();
        ?>
        <div class="mdbk-patient-row mdbk-patient-row-directory" data-id="<?php echo esc_attr($p->ID); ?>" data-name="<?php echo esc_attr($p->post_title); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-email="<?php echo esc_attr($email); ?>" data-address="<?php echo esc_attr($address); ?>" data-district="<?php echo esc_attr(get_post_meta($p->ID, '_mdbk_patient_district', true)); ?>" data-thana="<?php echo esc_attr(get_post_meta($p->ID, '_mdbk_patient_thana', true)); ?>" data-age="<?php echo esc_attr($age); ?>" data-gender="<?php echo esc_attr($gender); ?>" data-last-doctor-id="<?php echo esc_attr($last_doctor_id ?: ''); ?>">
            <span class="mdbk-patient-row-ticket-slot"><span class="mdbk-patient-row-ticket mdbk-patient-row-pid" title="<?php esc_attr_e('Patient ID', 'doctor-appointment'); ?>">P<?php echo esc_html($p->ID); ?></span></span>
            <a href="#" class="mdbk-patient-row-name mdbk-view-patient" data-id="<?php echo esc_attr($p->ID); ?>" title="<?php esc_attr_e('View patient', 'doctor-appointment'); ?>"><?php echo esc_html($p->post_title); ?></a>
            <span class="mdbk-patient-row-chip-slot"><?php if ($phone): ?><span class="mdbk-patient-row-chip mdbk-chip-phone"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($email): ?><span class="mdbk-patient-row-chip mdbk-chip-email"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($email); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($address): ?><span class="mdbk-patient-row-chip mdbk-chip-address" title="<?php echo esc_attr($address); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> <?php echo esc_html($address); ?></span><?php endif; ?></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($age_gender): ?><span class="mdbk-patient-row-chip mdbk-meta-pill mdbk-gender-<?php echo esc_attr($gender_key); ?>"><?php echo esc_html($age_gender); ?></span><?php endif; ?></span>
            <span class="mdbk-directory-visits-cell">
                <?php // Tooltip says whose visits are being counted — the number
                // means something different on a doctor's own list (their
                // consultations) than on the clinic-wide directory. ?>
                <span class="mdbk-badge mdbk-badge-green" title="<?php echo current_user_can(MDBK_CAP_QUEUE) ? esc_attr__('Total visits', 'doctor-appointment') : esc_attr__('Visits with you', 'doctor-appointment'); ?>"><?php echo esc_html($visit_count); ?></span>
                <?php if (current_user_can(MDBK_CAP_QUEUE) || $this->can_view_patient($p->ID)) : ?>
                <?php // Existing patient, new booking — opens the same
                // Add Booking modal rendered on THIS page in place (see
                // render_patients_page()), prefilled from the row's own
                // data-name/phone/email/age/gender. No page navigation.
                // A doctor gets this for their own patients too; the modal
                // locks the doctor field to them (render_appointment_modal_html()
                // takes their id), so booking from here can only ever land
                // on their own queue. ?>
                <a href="#" class="mdbk-book-btn mdbk-book-appointment" data-id="<?php echo esc_attr($p->ID); ?>" title="<?php esc_attr_e('Book Appointment', 'doctor-appointment'); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><?php _e('Book', 'doctor-appointment'); ?></a>
                <?php endif; ?>
            </span>
            <div class="mdbk-actions">
                <?php if ($this->can_view_patient($p->ID)) : ?>
                <a href="#" class="mdbk-action-btn mdbk-view-patient" data-id="<?php echo esc_attr($p->ID); ?>" title="<?php esc_attr_e('View', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>
                <?php endif; ?>
                <?php // Admin manages any record; a doctor may edit or remove
                // the patients they've treated (or added themselves), but
                // nobody else's — handle_patient_save() and the delete
                // handler enforce the same rule server-side. ?>
                <?php if (current_user_can(MDBK_CAP_ADMIN) || $this->can_view_patient($p->ID)) : ?>
                <a href="#" class="mdbk-action-btn mdbk-edit-patient" data-id="<?php echo esc_attr($p->ID); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-patients&action=mdbk_delete_patient&id='.$p->ID), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shared by the initial page render and ajax_search_patients() — 's'
     * only matches post_title for a CPT like this, and phone/email live in
     * postmeta, so a real name/phone/email search needs PHP-side
     * filtering across all three rather than a WP_Query 's' param.
     */
    /**
     * The patient IDs one doctor has actually seen — everyone with an
     * appointment booked against them, any date, any status.
     *
     * A doctor's own Patients page is scoped through this rather than
     * showing the whole registry: the directory is clinic-wide and holds
     * people who have never been near this doctor. Front-desk staff and
     * admin keep the unscoped view (they book across every doctor);
     * $doctor_id === 0 means "no scoping" for exactly that reason.
     */
    private function get_patient_ids_for_doctor($doctor_id) {
        $doctor_id = intval($doctor_id);
        if (!$doctor_id) return null;

        $ids = [];
        foreach (get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => '_mdbk_doctor_id', 'value' => $doctor_id]],
        ]) as $app_id) {
            $pid = intval(get_post_meta($app_id, '_mdbk_patient_id', true));
            if ($pid) $ids[$pid] = true;
        }
        // Plus anyone this doctor added themselves who hasn't booked yet
        // (handle_patient_save() stamps that) — otherwise a record they
        // just created wouldn't appear in their own list.
        foreach (get_posts([
            'post_type'   => 'mdbk_patient',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => '_mdbk_added_by_doctor', 'value' => $doctor_id]],
        ]) as $pid) {
            $ids[intval($pid)] = true;
        }
        return $ids;
    }

    /**
     * Whether the current user may open one patient's record.
     *
     * Front desk/admin: any patient, as before. A doctor: only someone
     * they have actually treated — the same scope their own Patients list
     * is built from. Without this a doctor could read any record in the
     * clinic by guessing an ID, since the modal is fetched by ID over
     * AJAX. Used both to decide whether to render the trigger at all and,
     * more importantly, to enforce it in ajax_view_patient() — a hidden
     * link is not a permission check.
     *
     * Memoised per request: the Booking list asks this once per row, and
     * the answer for a given doctor can't change mid-request.
     */
    private function can_view_patient($patient_id) {
        if (current_user_can(MDBK_CAP_QUEUE)) return true;
        if (!current_user_can(MDBK_CAP_DOCTOR)) return false;

        static $own_ids = null;
        if ($own_ids === null) {
            $doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
            $own_ids = $doctor_id ? $this->get_patient_ids_for_doctor($doctor_id) : [];
        }
        return isset($own_ids[intval($patient_id)]);
    }

    private function get_filtered_patients($search, $filter_gender, $doctor_id = 0) {
        $patients = get_posts(['post_type' => 'mdbk_patient', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        // Doctor's own view — keep only the people they've actually seen.
        $own = $this->get_patient_ids_for_doctor($doctor_id);
        if ($own !== null) {
            $patients = array_values(array_filter($patients, function($p) use ($own) {
                return isset($own[$p->ID]);
            }));
        }
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
        return $patients;
    }

    /**
     * The directory table (or "no patients"/"no matches" message) —
     * shared by the initial page render and the live-search AJAX fragment
     * ajax_search_patients() swaps in on every keystroke, so the two never
     * drift apart the way render_patient_list_html() explains for the
     * Booking page's own live-refreshed list.
     */
    private function render_patients_results_html($patients, $has_active_filters, $paged = 1, $per_page = 25, $doctor_id = 0) {
        $total = count($patients);
        $total_pages = (int) max(1, ceil($total / $per_page));
        $paged = min(max(1, $paged), $total_pages);
        $page_patients = array_slice($patients, ($paged - 1) * $per_page, $per_page);

        // One aggregated query for the CURRENT PAGE's visit counts, instead
        // of render_patient_directory_row() running its own get_posts() per
        // row — that N+1 pattern was fine at seed-data scale but took the
        // Patient Directory to ~35s with 1,000+ patients (each row
        // re-scanning the entire appointment table). Scoped to just this
        // page's ids (not all $patients) now that there's pagination, so
        // the query stays cheap regardless of how many total patients match.
        $visit_counts = $this->get_visit_counts_for_patients(wp_list_pluck($page_patients, 'ID'), $doctor_id);
        // Same page-scoped-query reasoning as $visit_counts above — lets
        // the Patient Directory's "Book" button (render_patient_directory_row())
        // preselect whichever doctor this SPECIFIC patient saw last time,
        // instead of only the globally last-used doctor (admin-script.js's
        // own localStorage persistence, which stays the fallback when a
        // patient has no appointment history yet).
        $last_doctors = $this->get_last_doctor_for_patients(wp_list_pluck($page_patients, 'ID'));
        ob_start();
        if (empty($patients)) : ?>
            <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php echo $has_active_filters ? esc_html__('No patients match your search.', 'doctor-appointment') : esc_html__('No patients yet.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
        <?php else : ?>
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
                <?php foreach ($page_patients as $p) echo $this->render_patient_directory_row($p, isset($visit_counts[$p->ID]) ? $visit_counts[$p->ID] : 0, isset($last_doctors[$p->ID]) ? $last_doctors[$p->ID] : 0); ?>
                </div>
            </div>
            <?php echo $this->render_pagination_html('mdbk-patients-page-btn', $paged, $total_pages); ?>
        <?php endif;
        return ob_get_clean();
    }

    /**
     * A compact, windowed pager (first/last page always visible, up to 2
     * neighbors either side of the current page, "…" gaps for the rest) —
     * plain server-rendered <a> links so a page reload/direct link/no-JS
     * visitor all still work, with $btn_class letting admin-script.js
     * intercept the same links for the AJAX-swap experience instead.
     * Reusable by any future paginated admin list, not just Patients.
     */
    private function render_pagination_html($btn_class, $current_page, $total_pages) {
        if ($total_pages <= 1) return '';

        $window = 2;
        $pages = [];
        for ($p = 1; $p <= $total_pages; $p++) {
            if ($p === 1 || $p === $total_pages || abs($p - $current_page) <= $window) {
                $pages[] = $p;
            } elseif (end($pages) !== '…') {
                $pages[] = '…';
            }
        }

        ob_start();
        ?>
        <div class="mdbk-pagination">
            <button type="button" class="mdbk-page-btn <?php echo esc_attr($btn_class); ?>" data-page="<?php echo esc_attr($current_page - 1); ?>" aria-label="<?php esc_attr_e('Previous page', 'doctor-appointment'); ?>" <?php disabled($current_page <= 1); ?>>&lsaquo;</button>
            <?php foreach ($pages as $p) : if ($p === '…') : ?>
                <span class="mdbk-page-ellipsis">&hellip;</span>
            <?php else : ?>
                <button type="button" class="mdbk-page-btn <?php echo esc_attr($btn_class); ?><?php echo $p === $current_page ? ' is-active' : ''; ?>" data-page="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></button>
            <?php endif; endforeach; ?>
            <button type="button" class="mdbk-page-btn <?php echo esc_attr($btn_class); ?>" data-page="<?php echo esc_attr($current_page + 1); ?>" aria-label="<?php esc_attr_e('Next page', 'doctor-appointment'); ?>" <?php disabled($current_page >= $total_pages); ?>>&rsaquo;</button>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * patient_id => visit count for every id in $patient_ids, in one
     * grouped query — see render_patients_results_html()'s comment above.
     */
    private function get_visit_counts_for_patients($patient_ids, $doctor_id = 0) {
        global $wpdb;
        $patient_ids = array_filter(array_map('intval', $patient_ids));
        if (empty($patient_ids)) return [];

        $statuses = \MDBK\MDBK_CPT::APPOINTMENT_STATUSES;
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $id_placeholders = implode(',', array_fill(0, count($patient_ids), '%d'));

        // A doctor's own list counts only their own consultations — the
        // unscoped total includes visits to every other doctor in the
        // clinic, which is the same information their patient modal
        // deliberately filters out (see ajax_view_patient()). Front
        // desk/admin pass 0 and keep the clinic-wide total.
        $doctor_id = intval($doctor_id);
        $doctor_join = $doctor_id
            ? "INNER JOIN {$wpdb->postmeta} dm ON dm.post_id = p.ID AND dm.meta_key = '_mdbk_doctor_id' AND dm.meta_value = %d"
            : '';

        $sql = "SELECT pm.meta_value AS patient_id, COUNT(*) AS cnt
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                $doctor_join
                WHERE pm.meta_key = '_mdbk_patient_id'
                  AND pm.meta_value IN ($id_placeholders)
                  AND p.post_type = 'mdbk_appointment'
                  AND p.post_status IN ($status_placeholders)
                GROUP BY pm.meta_value";
        $args = $doctor_id ? array_merge([$doctor_id], $patient_ids, $statuses) : array_merge($patient_ids, $statuses);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));

        $counts = [];
        foreach ($rows as $row) $counts[(int) $row->patient_id] = (int) $row->cnt;
        return $counts;
    }

    /**
     * Which doctor each of these patients most recently had an
     * appointment WITH — one row per appointment (ordered newest first
     * per patient), so the first row PHP sees for a given patient_id is
     * always its latest. Powers the Patient Directory's "Book" button
     * (render_patient_directory_row()): preselecting the doctor a
     * returning patient actually saw last time, not just whichever
     * doctor was last used for ANY booking (admin-script.js's own
     * localStorage-based default, which this simply overrides when
     * a specific patient has appointment history).
     */
    private function get_last_doctor_for_patients($patient_ids) {
        global $wpdb;
        $patient_ids = array_filter(array_map('intval', $patient_ids));
        if (empty($patient_ids)) return [];

        $statuses = \MDBK\MDBK_CPT::APPOINTMENT_STATUSES;
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $id_placeholders = implode(',', array_fill(0, count($patient_ids), '%d'));

        $sql = "SELECT pm_patient.meta_value AS patient_id, pm_doctor.meta_value AS doctor_id
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_patient ON pm_patient.post_id = p.ID AND pm_patient.meta_key = '_mdbk_patient_id'
                INNER JOIN {$wpdb->postmeta} pm_doctor ON pm_doctor.post_id = p.ID AND pm_doctor.meta_key = '_mdbk_doctor_id'
                LEFT JOIN {$wpdb->postmeta} pm_date ON pm_date.post_id = p.ID AND pm_date.meta_key = '_mdbk_appointment_date'
                WHERE pm_patient.meta_value IN ($id_placeholders)
                  AND p.post_type = 'mdbk_appointment'
                  AND p.post_status IN ($status_placeholders)
                ORDER BY pm_patient.meta_value, pm_date.meta_value DESC, p.post_date DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($patient_ids, $statuses)));

        $last_doctor = [];
        foreach ($rows as $row) {
            $pid = (int) $row->patient_id;
            if (!isset($last_doctor[$pid])) $last_doctor[$pid] = (int) $row->doctor_id;
        }
        return $last_doctor;
    }

    /**
     * Live search — debounced on every keystroke in admin-script.js (300ms,
     * matching the same instant-filter feel as the tailor-manager
     * project's own customer search), same nonce/gate as every other AJAX
     * action on this page. Returns the patient-count sentence AND the
     * results fragment together, since both need to update on every
     * search/filter change.
     */
    public function ajax_search_patients() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        // Same two-audience gate as render_patients_page(), and the same
        // scoping applied to the query — a doctor searching must not be
        // able to page past their own patients into the full registry.
        $is_queue_staff = current_user_can(MDBK_CAP_QUEUE);
        $own_doctor_id  = (!$is_queue_staff && current_user_can(MDBK_CAP_DOCTOR))
            ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id())
            : 0;
        if (!$is_queue_staff && !$own_doctor_id) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        $search = isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : '';
        $filter_gender = isset($_POST['filter_gender']) ? sanitize_text_field($_POST['filter_gender']) : '';
        $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $per_page = isset($_POST['per_page']) ? self::sanitize_patients_per_page($_POST['per_page']) : 25;
        $patients = $this->get_filtered_patients($search, $filter_gender, $own_doctor_id);
        wp_send_json_success([
            'count_html'   => $this->render_patients_count_html(count($patients), $paged, $per_page),
            'results_html' => $this->render_patients_results_html($patients, $search !== '' || $filter_gender !== '', $paged, $per_page, $own_doctor_id),
        ]);
    }

    /**
     * Whitelist of "rows per page" choices, shared by the <select> in
     * render_patients_page() and the sanitizer below so the two can never
     * drift — an unrecognized value (tampered request, stale query string)
     * falls back to 25 rather than an arbitrary/unbounded page size.
     */
    private static function patients_per_page_choices() {
        return [10, 25, 50, 100];
    }

    private static function sanitize_patients_per_page($value) {
        $value = intval($value);
        return in_array($value, self::patients_per_page_choices(), true) ? $value : 25;
    }

    /**
     * "Showing X–Y of Z patients" — shared by the initial page render and
     * ajax_search_patients() so the header sentence and the actual sliced
     * rows (render_patients_results_html()) can never disagree about which
     * page is showing.
     */
    private function render_patients_count_html($total, $paged, $per_page) {
        if ($total === 0) {
            return esc_html__('0 patients', 'doctor-appointment');
        }
        $total_pages = (int) max(1, ceil($total / $per_page));
        $paged = min(max(1, $paged), $total_pages);
        $start = ($paged - 1) * $per_page + 1;
        $end = min($total, $paged * $per_page);
        if ($total_pages === 1) {
            return esc_html(sprintf(_n('%d patient', '%d patients', $total, 'doctor-appointment'), $total));
        }
        return esc_html(sprintf(
            /* translators: 1: first row number, 2: last row number, 3: total patients */
            __('Showing %1$d–%2$d of %3$d patients', 'doctor-appointment'),
            $start, $end, $total
        ));
    }

    public function render_patients_page() {
        // Front desk/admin manage the clinic-wide registry; a doctor gets
        // the same page scoped to the patients they've actually treated
        // (get_patient_ids_for_doctor()). Anyone who is neither is out.
        $is_queue_staff = current_user_can(MDBK_CAP_QUEUE);
        $own_doctor_id  = (!$is_queue_staff && current_user_can(MDBK_CAP_DOCTOR))
            ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id())
            : 0;
        if (!$is_queue_staff && !$own_doctor_id) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'doctor-appointment'));
        }

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $filter_gender = isset($_GET['filter_gender']) ? sanitize_text_field($_GET['filter_gender']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? self::sanitize_patients_per_page($_GET['per_page']) : 25;

        // Only offer a gender filter option if at least one patient actually
        // has that gender recorded — computed from the full unfiltered list
        // so the option doesn't disappear once you've filtered down to it.
        $all_patients = $this->get_filtered_patients('', '', $own_doctor_id);
        $genders_present = array_unique(array_filter(array_map(function($p) {
            return get_post_meta($p->ID, '_mdbk_patient_gender', true);
        }, $all_patients)));
        $gender_options = array_intersect(['Male', 'Female'], $genders_present);

        $patients = $this->get_filtered_patients($search, $filter_gender, $own_doctor_id);
        $has_active_filters = ($search !== '' || $filter_gender !== '');
        // "My Patients" for a doctor — the list is theirs, not the clinic's,
        // and calling it the Patient Directory would misrepresent what's in
        // it. Adding a registry entry is a front-desk job, so that button
        // isn't offered here either.
        $page_title = $own_doctor_id ? __('My Patients', 'doctor-appointment') : __('Patient Directory', 'doctor-appointment');
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('patients'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php echo esc_html($page_title); ?></h1><p id="mdbk-patients-count"><?php echo $this->render_patients_count_html(count($patients), $paged, $per_page); ?></p></div><a href="#" class="mdbk-btn-add mdbk-add-patient"><?php _e('+ Add Patient', 'doctor-appointment'); ?></a></div>

                <div class="mdbk-filters-bar">
                    <form method="get" class="mdbk-filters-form" id="mdbk-patients-filters-form">
                        <input type="hidden" name="page" value="mdbk-patients">
                        <input type="text" name="s" id="mdbk-patients-search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search name, phone, or email...', 'doctor-appointment'); ?>" class="mdbk-filters-search" autocomplete="off">
                        <?php $gender_labels = ['Male' => __('Male', 'doctor-appointment'), 'Female' => __('Female', 'doctor-appointment')]; ?>
                        <select name="filter_gender" id="mdbk-patients-filter-gender">
                            <option value=""><?php _e('All Genders', 'doctor-appointment'); ?></option>
                            <?php foreach ($gender_options as $g) : ?>
                                <option value="<?php echo esc_attr($g); ?>" <?php selected($filter_gender, $g); ?>><?php echo esc_html($gender_labels[$g]); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="per_page" id="mdbk-patients-per-page" title="<?php esc_attr_e('Rows per page', 'doctor-appointment'); ?>">
                            <?php foreach (self::patients_per_page_choices() as $choice) : ?>
                                <option value="<?php echo esc_attr($choice); ?>" <?php selected($per_page, $choice); ?>><?php echo esc_html(sprintf(__('%d / page', 'doctor-appointment'), $choice)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php // Always rendered (not just when $has_active_filters, like
                        // this used to be) — admin-script.js's live search needs to
                        // reveal/hide this itself as the user types, and it can only
                        // toggle an element already in the DOM, not conjure one up. ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-patients')); ?>" class="mdbk-icon-btn mdbk-icon-btn-clear" id="mdbk-patients-clear-filters" title="<?php esc_attr_e('Clear filters', 'doctor-appointment'); ?>" style="<?php echo $has_active_filters ? '' : 'display:none;'; ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></a>
                        <?php // Kept as a fallback for a no-JS visitor — the search
                        // input/select above are live (see admin-script.js) for
                        // everyone else, so this button is redundant in the
                        // common case rather than the only way to search. ?>
                        <button type="submit" class="mdbk-btn-add mdbk-btn-sm"><?php _e('Filter', 'doctor-appointment'); ?></button>
                    </form>
                </div>

                <div id="mdbk-patients-results"><?php echo $this->render_patients_results_html($patients, $has_active_filters, $paged, $per_page, $own_doctor_id); ?></div>
            </div></div><?php
            $this->render_patient_modal_html();
            $this->render_patient_view_modal_html();
            // The Book button above needs this modal on the page. Front
            // desk/admin get the full doctor picker; a doctor gets it
            // pinned to themselves, the same argument their own Booking
            // page's "+ New Booking" already passes.
            if ($is_queue_staff || $own_doctor_id) { $this->render_appointment_modal_html($own_doctor_id); }
            ?></div>
        <?php
    }

    // One row in the Staff list — a WP user account (mdbk_receptionist
    // role), not a CPT post, so $s is a WP_User here, and "delete" means
    // wp_delete_user() (see handle_delete_actions()) rather than
    // wp_delete_post().
    // Role select's two allowed values — single source of truth shared by
    // the modal's <option>s, the row's badge/data-role, and
    // handle_staff_save()'s whitelist validation, so a new role option
    // can never be added in one place and missed in another.
    private static function staff_role_choices() {
        return [
            'mdbk_receptionist' => __('Front Desk', 'doctor-appointment'),
            'mdbk_manager_role' => __('Manager', 'doctor-appointment'),
        ];
    }

    private function render_staff_row($s) {
        $phone = get_user_meta($s->ID, '_mdbk_staff_phone', true);
        $role_choices = self::staff_role_choices();
        $role = in_array('mdbk_manager_role', (array) $s->roles, true) ? 'mdbk_manager_role' : 'mdbk_receptionist';
        $role_label = $role_choices[$role];
        ob_start();
        ?>
        <div class="mdbk-patient-row mdbk-staff-row" data-id="<?php echo esc_attr($s->ID); ?>" data-name="<?php echo esc_attr($s->display_name); ?>" data-email="<?php echo esc_attr($s->user_email); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-role="<?php echo esc_attr($role); ?>">
            <span class="mdbk-patient-row-ticket-slot"><span class="mdbk-patient-row-ticket mdbk-patient-row-pid" title="<?php esc_attr_e('User ID', 'doctor-appointment'); ?>">U<?php echo esc_html($s->ID); ?></span></span>
            <span class="mdbk-patient-row-name"><?php echo esc_html($s->display_name); ?></span>
            <span class="mdbk-patient-row-chip-slot"><span class="mdbk-patient-row-chip mdbk-chip-email"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 6l-10 7L2 6"></path><path d="M2 6h20v12H2z"></path></svg> <?php echo esc_html($s->user_email); ?></span></span>
            <span class="mdbk-patient-row-chip-slot"><?php if ($phone): ?><span class="mdbk-patient-row-chip mdbk-chip-phone"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.34 1.79.66 2.64a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.44-1.44a2 2 0 0 1 2.11-.45c.85.32 1.74.54 2.64.66A2 2 0 0 1 22 16.92z"></path></svg> <?php echo esc_html($phone); ?></span><?php endif; ?></span>
            <span class="mdbk-badge <?php echo $role === 'mdbk_manager_role' ? 'mdbk-badge-status-serving' : 'mdbk-badge-status-waiting'; ?>"><?php echo esc_html($role_label); ?></span>
            <div class="mdbk-actions">
                <a href="#" class="mdbk-action-btn mdbk-edit-staff" data-id="<?php echo esc_attr($s->ID); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg></a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-staff&action=mdbk_delete_staff&id=' . $s->ID), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php echo esc_js(__('Remove this staff account?', 'doctor-appointment')); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Staff management — the "Staff" equivalent of the Doctors grid,
     * admin-only. Much simpler than a doctor: no CPT, no
     * photo/specialty/schedule — just a WP user account with either the
     * mdbk_receptionist ("Front Desk") or mdbk_manager_role ("Manager")
     * role (see handle_staff_save()), so this is a plain list rather than
     * the Doctors page's card grid. A Manager gets the same immersive
     * panel as front-desk staff but with full administrator-equivalent
     * capabilities — see MDBK_Roles::activate() and
     * is_restricted_panel_user().
     */
    public function render_staff_page() {
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        $staff = get_users(['role__in' => array_keys(self::staff_role_choices()), 'orderby' => 'display_name', 'order' => 'ASC']);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('staff'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Staff', 'doctor-appointment'); ?></h1><p><?php echo esc_html(sprintf(_n('%d staff account', '%d staff accounts', count($staff), 'doctor-appointment'), count($staff))); ?></p></div><a href="#" class="mdbk-btn-add mdbk-add-staff"><?php _e('+ Add Staff', 'doctor-appointment'); ?></a></div>
                <?php if ($error) : ?>
                    <p style="color:#ef4444; font-weight:600;"><?php echo esc_html($error); ?></p>
                <?php endif; ?>
                <?php if (empty($staff)): ?>
                    <div class="mdbk-card"><table class="mdbk-table"><tbody><tr><td style="text-align:center; padding:40px; opacity:0.6;"><?php _e('No staff accounts yet.', 'doctor-appointment'); ?></td></tr></tbody></table></div>
                <?php else: ?>
                    <div class="mdbk-card">
                        <div class="mdbk-patient-list">
                        <?php foreach ($staff as $s) echo $this->render_staff_row($s); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div></div><?php $this->render_staff_modal_html(); ?></div>
        <?php
    }

    private function render_staff_modal_html() { ?>
        <div id="mdbk-staff-modal" class="mdbk-modal mdbk-modal-compact"><div class="mdbk-modal-content">
            <div class="mdbk-modal-head"><h2 id="mdbk-staff-modal-title"><?php _e('Add Staff', 'doctor-appointment'); ?></h2><span class="mdbk-modal-close">&times;</span></div>
            <form id="mdbk-staff-form" method="POST"><?php wp_nonce_field('mdbk_save_staff'); ?><input type="hidden" name="staff_id" id="mdbk-staff-id">
            <div class="mdbk-modal-body">
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-staff-name"><?php _e('Full Name', 'doctor-appointment'); ?> *</label>
                    <input type="text" name="staff_name" id="mdbk-staff-name" placeholder="<?php esc_attr_e('e.g. Rahim Uddin', 'doctor-appointment'); ?>" required>
                </div>
                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-staff-email"><?php _e('Email', 'doctor-appointment'); ?> *</label><input type="email" name="staff_email" id="mdbk-staff-email" placeholder="<?php esc_attr_e('e.g. staff@clinic.com', 'doctor-appointment'); ?>" required></div>
                    <div><label class="mdbk-form-label" for="mdbk-staff-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="staff_phone" id="mdbk-staff-phone" placeholder="<?php esc_attr_e('e.g. 01700-000000', 'doctor-appointment'); ?>"></div>
                </div>
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-staff-role-trigger"><?php _e('Role', 'doctor-appointment'); ?></label>
                    <?php $role_choices = self::staff_role_choices(); ?>
                    <div class="mdbk-custom-select" id="mdbk-staff-role-select">
                        <button type="button" class="mdbk-custom-select-trigger" id="mdbk-staff-role-trigger">
                            <span class="mdbk-custom-select-value"><?php echo esc_html(reset($role_choices)); ?></span>
                            <span class="mdbk-custom-select-chevron"></span>
                        </button>
                        <div class="mdbk-custom-select-panel" id="mdbk-staff-role-panel" style="display:none;">
                            <?php foreach ($role_choices as $value => $label): ?>
                            <div class="mdbk-custom-select-option<?php echo $value === 'mdbk_receptionist' ? ' selected' : ''; ?>" data-value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></div>
                            <?php endforeach; ?>
                        </div>
                        <select name="staff_role" id="mdbk-staff-role" style="display:none;">
                            <?php foreach ($role_choices as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="mdbk-form-hint"><?php _e('Front Desk: Booking + Patients panel only. Manager: same panel, but full admin-level access to everything (Doctors, Staff, Specialties, Dashboard, Settings).', 'doctor-appointment'); ?></p>
                </div>
                <p class="mdbk-form-hint"><?php _e('A new account gets an email with a link to set their own password.', 'doctor-appointment'); ?></p>
            </div>
            <div class="mdbk-modal-foot">
                <button type="button" class="mdbk-btn-outline mdbk-modal-cancel"><?php _e('Cancel', 'doctor-appointment'); ?></button>
                <button type="submit" name="mdbk_save_staff" class="mdbk-btn-save"><?php _e('Save Staff', 'doctor-appointment'); ?></button>
            </div>
            </form>
        </div></div>
        <?php
    }

    public function render_specialties_page() {
        $terms = \MDBK\MDBK_Appointment_Manager::get_specialty_terms(false);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('specialties'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Medical Specialties', 'doctor-appointment'); ?></h1><p><?php echo esc_html(sprintf(_n('%d specialty', '%d specialties', count($terms), 'doctor-appointment'), count($terms))); ?></p></div><div style="display:flex; gap:10px;"><button type="button" class="mdbk-btn-outline" id="mdbk-open-specialty-reorder"><?php _e('Reorder', 'doctor-appointment'); ?></button><a href="#" class="mdbk-btn-add mdbk-add-specialty"><?php _e('+ Add Specialty', 'doctor-appointment'); ?></a></div></div>
                <div class="mdbk-specialty-grid">
                    <?php foreach ($terms as $t) : $this->render_specialty_card($t); endforeach; ?>
                    <?php // Trailing "add new" card — reuses the exact same
                    // .mdbk-add-specialty class the header button above
                    // already uses, so initModal()'s own querySelectorAll
                    // binding (admin-script.js) picks this up for free, no
                    // new JS needed. ?>
                    <a href="#" class="mdbk-specialty-card mdbk-specialty-card-add mdbk-add-specialty">
                        <div class="mdbk-specialty-card-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></div>
                        <div class="mdbk-specialty-card-name"><?php _e('Add Specialty', 'doctor-appointment'); ?></div>
                    </a>
                </div>
            </div></div><?php
            $this->render_specialty_modal_html();
            $this->render_reorder_modal_html(array_map(function($t) { return ['id' => $t->term_id, 'name' => $t->name]; }, $terms), 'specialty');
            ?></div>
        <?php
    }

    private function render_sidebar($active_page) {
        $clinic_name = get_option('mdbk_clinic_name', '') ?: 'MedBook';
        $clinic_contact = get_option('mdbk_clinic_contact', '');
        ?>
        <button type="button" class="mdbk-mobile-menu-toggle" id="mdbk-mobile-menu-toggle" aria-label="<?php esc_attr_e('Menu', 'doctor-appointment'); ?>">
            <svg class="mdbk-mobile-menu-icon-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            <svg class="mdbk-mobile-menu-icon-close" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        <div class="mdbk-sidebar-backdrop" id="mdbk-sidebar-backdrop"></div>
        <div class="mdbk-sidebar" id="mdbk-sidebar"><div class="mdbk-sidebar-logo"><?php echo esc_html($clinic_name); ?><?php if ($clinic_contact) : ?><div class="mdbk-sidebar-clinic-contact"><?php echo esc_html($clinic_contact); ?></div><?php endif; ?></div><button type="button" class="mdbk-sidebar-toggle" id="mdbk-sidebar-toggle" aria-label="<?php esc_attr_e('Toggle sidebar', 'doctor-appointment'); ?>"><svg class="mdbk-sidebar-toggle-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg></button><ul class="mdbk-sidebar-menu">
            <?php if (current_user_can(MDBK_CAP_ADMIN)) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'dashboard' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Dashboard', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-dashboard')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg><span class="mdbk-menu-label"><?php _e('Dashboard', 'doctor-appointment'); ?></span></li>
            <?php endif; ?>
            <?php // Booking — the main operational page for both a doctor
            // (their own patients only, auto-scoped) and front-desk staff
            // (every doctor, filterable/grouped) — see render_schedule_page(). ?>
            <?php if (current_user_can(MDBK_CAP_QUEUE) || current_user_can(MDBK_CAP_DOCTOR)) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'schedule' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Booking', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><span class="mdbk-menu-label"><?php _e('Booking', 'doctor-appointment'); ?></span></li>
            <?php endif; ?>
            <?php // "Patients" — the registered-patient directory (view/add/edit),
            // deliberately separate from Booking's day-to-day queue work above.
            // MDBK_CAP_QUEUE covers both front-desk staff and admin (admin has
            // this cap granted alongside every custom one — see roles.php).
            //
            // A doctor gets the same entry, but the page behind it is scoped
            // to the patients they have actually treated and titled "My
            // Patients" — see render_patients_page(). Shown only once they
            // resolve to a doctor profile, since without one the page has
            // nothing to scope by and would refuse to load.
            $sidebar_own_doctor = (!current_user_can(MDBK_CAP_QUEUE) && current_user_can(MDBK_CAP_DOCTOR))
                ? \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id())
                : 0;
            ?>
            <?php if (current_user_can(MDBK_CAP_QUEUE) || $sidebar_own_doctor) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'patients' ? 'active' : ''; ?>" data-tooltip="<?php echo $sidebar_own_doctor ? esc_attr__('My Patients', 'doctor-appointment') : esc_attr__('Patients', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-patients')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg><span class="mdbk-menu-label"><?php echo $sidebar_own_doctor ? esc_html__('My Patients', 'doctor-appointment') : esc_html__('Patients', 'doctor-appointment'); ?></span></li>
            <?php endif; ?>
            <?php if (current_user_can(MDBK_CAP_ADMIN)) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'doctors' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Doctors', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-doctors')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.5 21a8.5 8.5 0 0 0-17 0"></path><circle cx="12" cy="7.5" r="4.5"></circle></svg><span class="mdbk-menu-label"><?php _e('Doctors', 'doctor-appointment'); ?></span></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'staff' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Staff', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-staff')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg><span class="mdbk-menu-label"><?php _e('Staff', 'doctor-appointment'); ?></span></li>
            <?php endif; ?>
            <?php // Profile + Change Password — a doctor's own clinical
            // profile, or front-desk staff's plain account info (see
            // render_profile_page()'s staff branch), same as the doctor
            // panel; Change Password itself is fully role-agnostic. ?>
            <?php if ($this->is_restricted_panel_user()) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'profile' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Profile', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-profile')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><span class="mdbk-menu-label"><?php _e('Profile', 'doctor-appointment'); ?></span></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'change-password' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Change Password', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-change-password')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg><span class="mdbk-menu-label"><?php _e('Change Password', 'doctor-appointment'); ?></span></li>
            <?php endif; ?>
            <?php if (current_user_can(MDBK_CAP_ADMIN)) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'specialties' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Specialties', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-specialties')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg><span class="mdbk-menu-label"><?php _e('Specialties', 'doctor-appointment'); ?></span></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'global-settings' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('Settings', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-global-settings')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg><span class="mdbk-menu-label"><?php _e('Settings', 'doctor-appointment'); ?></span></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'license' ? 'active' : ''; ?>" data-tooltip="<?php esc_attr_e('License', 'doctor-appointment'); ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-license')); ?>'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"></circle><path d="M21 2l-9.6 9.6"></path><path d="M15.5 7.5L18 10"></path><path d="M18.5 4.5L21 7"></path></svg><span class="mdbk-menu-label"><?php _e('License', 'doctor-appointment'); ?></span></li>
            <?php endif; ?>
        </ul>
        <?php if (current_user_can('manage_options')) : ?>
        <?php // Only a real administrator sees this — a doctor/front-desk/
        // manager login is hard-blocked from every non-mdbk-* wp-admin
        // screen anyway (enforce_panel_only_access()), so this link would
        // just bounce them straight back here. Native chrome is hidden for
        // admin too now (admin_body_class()), but only while they're ON an
        // mdbk-* page — this is the way back to everything else. ?>
        <a class="mdbk-sidebar-wp-link" href="<?php echo esc_url(admin_url()); ?>" data-tooltip="<?php esc_attr_e('Back to WordPress', 'doctor-appointment'); ?>" title="<?php esc_attr_e('Back to WordPress', 'doctor-appointment'); ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
            <span class="mdbk-menu-label"><?php _e('Back to WordPress', 'doctor-appointment'); ?></span>
        </a>
        <?php endif; ?>
        <?php
        // A doctor account's own name (not their WP display name, which
        // for most of these accounts just defaults to the login username
        // — see e.g. "mdkudrat" — no one had set a friendlier one) —
        // falls back to the WP display name for staff/admin, who have no
        // linked doctor profile to name themselves after. The username
        // moves down to the subtitle line, in place of the old static
        // "Medical Center" text that never reflected anything about who
        // was actually logged in.
        $footer_user = wp_get_current_user();
        $footer_doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user($footer_user->ID);
        $footer_name = $footer_doctor_id ? get_the_title($footer_doctor_id) : $footer_user->display_name;
        ?>
        <?php // "Visit site" — this panel hides wp-admin's own chrome (see
        // admin_body_class()), and with it WordPress's usual admin-bar route
        // out to the public site, so there was no way to open the front end
        // from in here at all. Unlike "Back to WordPress" above this isn't
        // admin-only: a doctor or front-desk account has every reason to
        // check the public booking page or live queue, and the front end is
        // public anyway. Opens in a new tab so whoever clicks it doesn't
        // lose the queue they were working on; rel="noopener" because
        // target="_blank" otherwise hands the new tab a handle back to this
        // one. ?>
        <?php
        // The avatar was an empty green disc for everyone. A doctor already
        // has a profile photo on file more often than not, so show it —
        // falling back to their initials rather than a blank circle.
        $footer_photo = $footer_doctor_id ? get_the_post_thumbnail_url($footer_doctor_id, 'thumbnail') : '';
        ?>
        <div class="mdbk-sidebar-footer">
            <div class="mdbk-user-avatar<?php echo $footer_photo ? ' has-photo' : ''; ?>">
                <?php if ($footer_photo) : ?>
                    <img src="<?php echo esc_url($footer_photo); ?>" alt="">
                <?php else : ?>
                    <span><?php echo esc_html(self::initials($footer_name)); ?></span>
                <?php endif; ?>
            </div>
            <?php // title= carries the full name: it's truncated to one line
            // below, and these names ("Dr. Dewan Md. Kudrat-A-Elahi") are
            // routinely longer than the sidebar is wide. ?>
            <div class="mdbk-user-info">
                <div class="mdbk-user-info-name" title="<?php echo esc_attr($footer_name); ?>"><?php echo esc_html($footer_name); ?></div>
                <div class="mdbk-user-info-login"><?php echo esc_html($footer_user->user_login); ?></div>
            </div>
            <a class="mdbk-sidebar-visit-site" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e('Visit site', 'doctor-appointment'); ?>" aria-label="<?php esc_attr_e('Visit site (opens in a new tab)', 'doctor-appointment'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
            </a>
        </div>
        <?php
        // Sends a logged-out visitor to the MedBook theme's own themed Login
        // page (page-templates/login.php) instead of wp-login.php's default
        // screen — same idea as the tailor-manager plugin's sidebar logout
        // link, just pointed at a dedicated Login page here rather than the
        // site's front page, since this site's front page is Book Appointment,
        // not Login. Falls back to the front page if that page doesn't exist
        // (e.g. the theme's setup hasn't created it) rather than a broken link.
        $login_page = get_page_by_path('login');
        $logout_redirect = $login_page ? get_permalink($login_page) : home_url('/');
        ?>
        <a class="mdbk-sidebar-logout" href="<?php echo esc_url(wp_logout_url($logout_redirect)); ?>" data-tooltip="<?php esc_attr_e('Logout', 'doctor-appointment'); ?>" title="<?php esc_attr_e('Logout', 'doctor-appointment'); ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            <span class="mdbk-menu-label"><?php _e('Logout', 'doctor-appointment'); ?></span>
        </a>
        </div>
        <?php // 782px matches the plugin's one mobile breakpoint everywhere
        // else (admin-style.css's @media rules, WP's own admin_body_class
        // check). Below it .mdbk-sidebar is the off-canvas drawer, not the
        // desktop icon-rail is-collapsed was built for — applying the
        // class there before admin-script.js even runs (this fires before
        // the collapse toggle gets hidden on mobile) squeezed the drawer
        // down to 72px and hid every label, unreadable on a phone, purely
        // because a DESKTOP session had collapsed it earlier in this same
        // browser (localStorage doesn't know about viewport width). ?>
        <script>try{if(window.innerWidth > 782 && localStorage.getItem('mdbk_sidebar_collapsed')==='1')document.getElementById('mdbk-sidebar').classList.add('is-collapsed')}catch(e){}</script>
        <?php
    }

    /**
     * Shared drag-and-drop reorder modal — used for both Doctors (menu_order,
     * WP's own native post ordering field) and Specialties (a taxonomy has
     * no built-in equivalent, so _mdbk_specialty_order term meta instead).
     * $items is a plain [['id' => ..., 'name' => ...], ...] list, already in
     * the CURRENT order (see ajax_save_doctor_order()/ajax_save_specialty_order()
     * for how a drag gets persisted, and the JS drag handler itself in
     * admin-script.js).
     */
    private function render_reorder_modal_html($items, $type) {
        $title = $type === 'doctor' ? __('Reorder Doctors', 'doctor-appointment') : __('Reorder Specialties', 'doctor-appointment');
        ?>
        <div id="mdbk-reorder-modal-<?php echo esc_attr($type); ?>" class="mdbk-modal mdbk-modal-compact mdbk-reorder-modal" data-reorder-type="<?php echo esc_attr($type); ?>">
            <div class="mdbk-modal-content">
                <div class="mdbk-modal-head">
                    <h2><?php echo esc_html($title); ?></h2>
                    <span class="mdbk-modal-close">&times;</span>
                </div>
                <div class="mdbk-modal-body">
                    <div class="mdbk-reorder-toolbar">
                        <p class="mdbk-form-hint" style="margin:0;"><?php _e('Drag to reorder — this is the order patients see on the booking form and doctor list.', 'doctor-appointment'); ?></p>
                        <div class="mdbk-reorder-sort-btns">
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm mdbk-sort-az"><?php _e('A → Z', 'doctor-appointment'); ?></button>
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm mdbk-sort-za"><?php _e('Z → A', 'doctor-appointment'); ?></button>
                        </div>
                    </div>
                    <ul class="mdbk-reorder-list">
                        <?php foreach ($items as $item): ?>
                        <li class="mdbk-reorder-item" data-id="<?php echo esc_attr($item['id']); ?>">
                            <span class="mdbk-reorder-handle" title="<?php esc_attr_e('Drag to reorder', 'doctor-appointment'); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="6" r="1.6"></circle><circle cx="16" cy="6" r="1.6"></circle><circle cx="8" cy="12" r="1.6"></circle><circle cx="16" cy="12" r="1.6"></circle><circle cx="8" cy="18" r="1.6"></circle><circle cx="16" cy="18" r="1.6"></circle></svg></span>
                            <span class="mdbk-reorder-name"><?php echo esc_html($item['name']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="mdbk-modal-foot">
                    <button type="button" class="mdbk-btn-outline mdbk-modal-cancel"><?php _e('Cancel', 'doctor-appointment'); ?></button>
                    <button type="button" class="mdbk-btn-save mdbk-save-reorder"><?php _e('Save Order', 'doctor-appointment'); ?></button>
                </div>
            </div>
        </div>
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
                    <div><label class="mdbk-form-label" for="mdbk-doc-name"><?php _e('Full Name', 'doctor-appointment'); ?> *</label><input type="text" name="doc_name" id="mdbk-doc-name" placeholder="<?php esc_attr_e('e.g. Dr. Karim Ahmed', 'doctor-appointment'); ?>" required></div>
                    <div>
                        <label class="mdbk-form-label" for="mdbk-doc-spec-trigger"><?php _e('Specialty', 'doctor-appointment'); ?></label>
                        <?php $spec_terms = \MDBK\MDBK_Appointment_Manager::get_specialty_terms(false); ?>
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
                        <input type="email" name="doc_email" id="mdbk-doc-email" placeholder="<?php esc_attr_e('e.g. doctor@clinic.com', 'doctor-appointment'); ?>" required>
                    </div>
                    <div>
                        <div class="mdbk-form-label-row"><label class="mdbk-form-label" for="mdbk-doc-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="show_phone" id="mdbk-show-phone" value="1" checked><span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Public', 'doctor-appointment'); ?></span></label></div>
                        <input type="text" name="doc_phone" id="mdbk-doc-phone" placeholder="<?php esc_attr_e('e.g. 01700-000000', 'doctor-appointment'); ?>">
                    </div>
                </div>

                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-doc-bio"><?php _e('Bio / Description', 'doctor-appointment'); ?></label>
                    <textarea name="doc_bio" id="mdbk-doc-bio" rows="3" placeholder="<?php esc_attr_e('Specialty, experience, qualifications...', 'doctor-appointment'); ?>"></textarea>
                </div>

                <?php // Slot duration + consultation fee live in their own
                // collapsible group below Bio — same details/summary shape as
                // Weekly Availability / Break Times above/below, so all three
                // optional-settings groups read identically. Field names/IDs
                // are untouched — only this modal's markup moved. ?>
                <details class="mdbk-availability-section" open>
                    <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 12"></polyline><line x1="12" y1="16" x2="16" y2="18"></line></svg><h4><?php _e('Time Slot & Fee', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                    <div class="mdbk-form-row mdbk-form-row-duo">
                        <div>
                            <div class="mdbk-form-label-row">
                                <label class="mdbk-form-label" for="mdbk-doc-slot-duration"><?php _e('Slot Duration (minutes)', 'doctor-appointment'); ?></label>
                                <label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="slot_enabled" id="mdbk-doc-slot-enabled" value="1" checked><span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Public', 'doctor-appointment'); ?></span></label>
                            </div>
                            <div id="mdbk-doc-slot-duration-group"><input type="number" name="slot_duration" id="mdbk-doc-slot-duration" min="5" step="5" value="20" placeholder="<?php esc_attr_e('e.g. 20', 'doctor-appointment'); ?>"></div>
                            <p class="mdbk-form-hint"><?php _e("When off, patients won't see a time picker — they're queued automatically and still get a real appointment time behind the scenes.", 'doctor-appointment'); ?></p>
                        </div>
                        <div>
                            <label class="mdbk-form-label" for="mdbk-doc-fee"><?php _e('Consultation Fee (৳)', 'doctor-appointment'); ?></label>
                            <div class="mdbk-stepper">
                                <button type="button" class="mdbk-stepper-btn mdbk-stepper-minus" tabindex="-1" aria-label="<?php esc_attr_e('Decrease', 'doctor-appointment'); ?>">&minus;</button>
                                <input type="number" name="doc_fee" id="mdbk-doc-fee" min="0" step="0.01" data-step="50" placeholder="<?php esc_attr_e('e.g. 800', 'doctor-appointment'); ?>">
                                <button type="button" class="mdbk-stepper-btn mdbk-stepper-plus" tabindex="-1" aria-label="<?php esc_attr_e('Increase', 'doctor-appointment'); ?>">&plus;</button>
                            </div>
                        </div>
                    </div>
                </details>

                <?php // Queue & Ticketing — per-doctor since it moved out of
                // global settings: each doctor picks how their own queue
                // serials are issued. Same radio design the old global card
                // used; handle_doctor_save() persists it as this doctor's
                // _mdbk_queue_serial_mode meta. ?>
                <details class="mdbk-availability-section" open>
                    <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16"></path><path d="M4 12h16"></path><path d="M4 18h10"></path><circle cx="19" cy="18" r="3"></circle></svg><h4><?php _e('Queue & Ticketing', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                    <label class="mdbk-form-label" style="display:block; margin-bottom:10px;"><?php _e('Queue Serial Numbering', 'doctor-appointment'); ?></label>
                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; margin-bottom:12px;">
                        <input type="radio" name="queue_serial_mode" value="booking" <?php echo 'booking' === \MDBK\MDBK_Appointment_Manager::queue_serial_mode() ? 'checked' : ''; ?> style="margin-top:3px;">
                        <span>
                            <strong style="display:block; font-size:13px;"><?php _e('Booking order', 'doctor-appointment'); ?></strong>
                            <span class="mdbk-form-hint" style="margin:2px 0 0;"><?php _e("The queue number (\"Q01\") is assigned the moment someone books.", 'doctor-appointment'); ?></span>
                        </span>
                    </label>
                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                        <input type="radio" name="queue_serial_mode" value="checkin" <?php echo 'checkin' === \MDBK\MDBK_Appointment_Manager::queue_serial_mode() ? 'checked' : ''; ?> style="margin-top:3px;">
                        <span>
                            <strong style="display:block; font-size:13px;"><?php _e('Check-in order', 'doctor-appointment'); ?></strong>
                            <span class="mdbk-form-hint" style="margin:2px 0 0;"><?php _e('The number is assigned only when the patient actually checks in, reflecting arrival order instead of booking order. The booking confirmation shows a Booking ID until then.', 'doctor-appointment'); ?></span>
                        </span>
                    </label>
                </details>

                <details class="mdbk-availability-section" open>
                    <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg><h4><?php _e('Weekly Availability', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                    <div class="mdbk-day-grid">
                    <?php $day_labels = \MDBK\MDBK_Appointment_Manager::get_day_labels(); ?>
                    <?php foreach(\MDBK\MDBK_Appointment_Manager::get_week_day_order() as $day): ?>
                    <div class="mdbk-day-row is-off">
                        <span class="mdbk-day-name"><?php echo esc_html($day_labels[$day]); ?></span>
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

                <details class="mdbk-availability-section" id="mdbk-break-section">
                    <summary class="mdbk-availability-header"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg><h4><?php _e('Break Times', 'doctor-appointment'); ?></h4><span class="mdbk-availability-chevron"></span></summary>
                    <p class="mdbk-form-hint"><?php _e('Optional, doctor-wide — applies inside every active day in Weekly Availability above (e.g. lunch, prayer). Booking slots inside a break show its name and can\'t be selected.', 'doctor-appointment'); ?></p>
                    <?php // Same "JS keeps a hidden JSON input in sync" shape as
                    // extra_dates_json/off_dates_json below (createMiniCalendar()
                    // in admin-script.js) — not name="breaks[N][field]" repeater
                    // fields, since a row's 3 inputs have no name attributes of
                    // their own to submit natively. ?>
                    <input type="hidden" name="breaks_json" id="mdbk-breaks-json" value="[]">
                    <div class="mdbk-break-repeater" id="mdbk-break-repeater"></div>
                    <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-add-break-row"><?php _e('+ Add Break', 'doctor-appointment'); ?></button>
                    <?php // Template for JS-added rows (add-break click handler,
                    // admin-script.js) — kept in the same markup shape
                    // renderBreakRow() also uses for existing/edit-populated
                    // rows, so both paths can never drift apart. ?>
                    <template id="mdbk-break-row-template">
                        <div class="mdbk-break-row">
                            <input type="text" class="mdbk-break-name" placeholder="<?php esc_attr_e('e.g. Lunch Break', 'doctor-appointment'); ?>">
                            <input type="time" class="mdbk-break-from">
                            <span class="mdbk-break-row-sep">&ndash;</span>
                            <input type="time" class="mdbk-break-to">
                            <button type="button" class="mdbk-icon-btn mdbk-icon-btn-clear mdbk-icon-btn-xs mdbk-remove-break-row" title="<?php esc_attr_e('Remove', 'doctor-appointment'); ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                        </div>
                    </template>
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

    /**
     * Read-only "View Patient" popup — contact details plus every visit
     * (any doctor, any date) this patient has on record. Content is loaded
     * on demand via ajax_view_patient() (see the .mdbk-view-patient click
     * handler in admin-script.js), same AJAX-into-a-static-modal shape the
     * public booking form's own "Today's Patients" popup already uses —
     * a patient can rack up dozens of visits over time, so rendering every
     * patient's full history inline up front (the way the Doctors page's
     * "All Patients Today" popups render one per doctor, a small fixed
     * set) isn't a fair comparison here.
     */
    private function render_patient_view_modal_html() { ?>
        <div id="mdbk-patient-view-modal" class="mdbk-modal mdbk-modal-compact mdbk-doctor-popup">
            <div class="mdbk-modal-content">
                <div class="mdbk-modal-head">
                    <h2 id="mdbk-patient-view-modal-title"><?php _e('Visit History', 'doctor-appointment'); ?></h2>
                    <span class="mdbk-modal-close">&times;</span>
                </div>
                <div class="mdbk-modal-body" id="mdbk-patient-view-modal-body"></div>
            </div>
        </div>
    <?php }

    /**
     * AJAX body for the modal above: patient contact details, then every
     * visit ordered most-recent-first. MDBK_CAP_QUEUE (not manage_options)
     * matches every other patient-directory action, front-desk staff
     * included.
     */
    public function ajax_view_patient() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');

        $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        if (!$patient_id) {
            wp_send_json_error(['message' => __('Patient not found.', 'doctor-appointment')]);
        }
        // Per-patient, not a flat capability check: a doctor may open the
        // records of people they've treated and no one else, so the ID
        // being requested is part of the decision. See can_view_patient().
        if (!$this->can_view_patient($patient_id)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }
        $patient = get_post($patient_id);
        if ($patient && $patient->post_type !== 'mdbk_patient') $patient = null;

        $apps = get_posts([
            'post_type'   => 'mdbk_appointment',
            'numberposts' => -1,
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'meta_query'  => [['key' => '_mdbk_patient_id', 'value' => $patient_id]],
            'meta_key'    => '_mdbk_appointment_date',
            'orderby'     => 'meta_value',
            'order'       => 'DESC',
        ]);

        // A doctor sees only their own consultations with this patient.
        // The unfiltered history spans every doctor in the clinic, which
        // is front-desk/admin information — what a patient was seen for
        // elsewhere isn't this doctor's to read just because the same
        // person also books with them. "Total Visits" below counts this
        // filtered list too, so the number matches the rows under it.
        if (!current_user_can(MDBK_CAP_QUEUE) && current_user_can(MDBK_CAP_DOCTOR)) {
            $viewer_doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_for_user(get_current_user_id());
            $apps = array_values(array_filter($apps, function($a) use ($viewer_doctor_id) {
                return intval(get_post_meta($a->ID, '_mdbk_doctor_id', true)) === intval($viewer_doctor_id);
            }));
        }

        // A booking outlives the patient record it was linked to — the
        // registry entry can be deleted (or replaced wholesale, as a demo
        // re-seed does) while every appointment keeps pointing at the old
        // ID. Rather than refuse to open at all, fall back to the details
        // each appointment stored for itself at booking time, which is
        // where these fields were copied from in the first place. Only a
        // patient_id that matches nothing anywhere is a real dead end.
        $orphaned = !$patient;
        if ($orphaned && empty($apps)) {
            wp_send_json_error(['message' => __('Patient not found.', 'doctor-appointment')]);
        }

        if ($patient) {
            $title_name = $patient->post_title;
            $phone   = get_post_meta($patient_id, '_mdbk_patient_phone', true);
            $email   = get_post_meta($patient_id, '_mdbk_patient_email', true);
            $address = get_post_meta($patient_id, '_mdbk_patient_address', true);
            $age     = get_post_meta($patient_id, '_mdbk_patient_age', true);
            $gender  = get_post_meta($patient_id, '_mdbk_patient_gender', true);
        } else {
            $latest     = $apps[0];
            $title_name = get_post_meta($latest->ID, '_mdbk_patient_name', true);
            $phone      = get_post_meta($latest->ID, '_mdbk_patient_phone', true);
            $email      = get_post_meta($latest->ID, '_mdbk_patient_email', true);
            $address    = '';
            $age        = get_post_meta($latest->ID, '_mdbk_patient_age', true);
            $gender     = get_post_meta($latest->ID, '_mdbk_patient_gender', true);
        }
        if ($title_name === '') $title_name = __('Unknown patient', 'doctor-appointment');
        $age_gender = trim($gender . ($age && $gender ? ' · ' : '') . $age);

        ob_start();
        ?>
        <?php if ($orphaned) : ?>
            <p class="mdbk-view-orphan-note"><?php _e('This patient is no longer in the registry — showing the details saved with their bookings.', 'doctor-appointment'); ?></p>
        <?php endif; ?>
        <div class="mdbk-patient-view-info">
            <div class="mdbk-view-field"><label><?php _e('Phone', 'doctor-appointment'); ?></label><span><?php echo esc_html($phone ?: '—'); ?></span></div>
            <div class="mdbk-view-field"><label><?php _e('Email', 'doctor-appointment'); ?></label><span><?php echo esc_html($email ?: '—'); ?></span></div>
            <div class="mdbk-view-field"><label><?php _e('Age / Gender', 'doctor-appointment'); ?></label><span><?php echo esc_html($age_gender ?: '—'); ?></span></div>
            <div class="mdbk-view-field"><label><?php _e('Total Visits', 'doctor-appointment'); ?></label><span><?php echo esc_html(count($apps)); ?></span></div>
            <div class="mdbk-view-field mdbk-view-field-full"><label><?php _e('Address', 'doctor-appointment'); ?></label><span><?php echo esc_html($address ?: '—'); ?></span></div>
        </div>
        <h4 style="margin:0 0 10px;"><?php _e('Visit History', 'doctor-appointment'); ?></h4>
        <?php $this->render_patient_visit_history_table($apps); ?>
        <?php
        $body_html = ob_get_clean();

        wp_send_json_success([
            'title'     => sprintf(__('%s — Visit History', 'doctor-appointment'), $title_name),
            'body_html' => $body_html,
        ]);
    }

    /**
     * One patient's own visits across every doctor/date, most recent
     * first — same column shape as render_today_queue_table() (Queue view,
     * one date/many patients) just transposed for "one patient, many
     * dates," so it stays a Date/Doctor/Time/Status log rather than a
     * Queue-number list that wouldn't mean anything outside a single day.
     */
    private function render_patient_visit_history_table($apps) {
        if (empty($apps)) {
            echo '<p style="text-align:center; opacity:0.6; padding:30px 0;">' . esc_html__('No visits yet.', 'doctor-appointment') . '</p>';
            return;
        }
        ?>
        <div class="mdbk-visit-history-scroll">
        <table class="mdbk-table mdbk-visit-history-table">
            <?php // Invoice column sits BEFORE Status, so Status is the last
            // column and every badge lines up against the table's right
            // edge. With the invoice action last instead, only the rows
            // that actually have one pushed their badge left, leaving the
            // badge column looking ragged down the list. ?>
            <thead><tr><th><?php _e('Date', 'doctor-appointment'); ?></th><th><?php _e('Doctor', 'doctor-appointment'); ?></th><th><?php _e('Time', 'doctor-appointment'); ?></th><th></th><th><?php _e('Status', 'doctor-appointment'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($apps as $a): $v_doc_id = get_post_meta($a->ID, '_mdbk_doctor_id', true); $v_date = get_post_meta($a->ID, '_mdbk_appointment_date', true); $v_slot = get_post_meta($a->ID, '_mdbk_slot_time', true); $v_status = \MDBK\MDBK_Appointment_Manager::get_display_status_slug($a->ID); $v_badge_class = in_array($v_status, ['upcoming', 'not-checked-in'], true) ? $v_status : 'status-' . $v_status; ?>
                <tr>
                    <td><?php echo $v_date ? esc_html(date_i18n(get_option('date_format'), strtotime($v_date))) : '—'; ?></td>
                    <td><?php echo $v_doc_id ? esc_html(get_the_title($v_doc_id)) : esc_html__('N/A', 'doctor-appointment'); ?></td>
                    <td><?php echo esc_html($v_slot ? date_i18n('g:i A', strtotime($v_slot)) : '—'); ?></td>
                    <td class="mdbk-visit-history-action">
                        <?php // Same completed-and-not-future gate as every other
                        // Invoice trigger (render_patient_appointment_row(),
                        // render_my_queue_patient_row()) — a past visit shown
                        // here is exactly where staff would look one up again,
                        // so it needs the same access point this modal's own
                        // "View Patient" click already offers from the row list. ?>
                        <?php if ($v_status === 'completed' && $v_date <= current_time('Y-m-d')) : ?>
                            <a href="#" class="mdbk-action-btn mdbk-open-invoice" data-id="<?php echo esc_attr($a->ID); ?>" title="<?php esc_attr_e('Invoice', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="13" x2="15" y2="13"></line><line x1="9" y1="17" x2="15" y2="17"></line></svg></a>
                        <?php endif; ?>
                    </td>
                    <td class="mdbk-visit-history-status"><span class="mdbk-badge mdbk-badge-<?php echo esc_attr($v_badge_class); ?>"><?php echo esc_html(\MDBK\MDBK_Appointment_Manager::status_display_label($v_status)); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /**
     * Invoice popup — opened from the Booking page's per-appointment
     * "Invoice" action (MDBK_CAP_QUEUE only, same as the View Patient
     * trigger). The invoice number is just the appointment's own post ID
     * (INV-000123) rather than a separate running counter — guaranteed
     * unique with no extra state to keep in sync, same "P{id}"/"Q{ticket}"
     * badge convention already used elsewhere on this page. Amount/status
     * are only ever persisted when Save is clicked; Print always uses
     * whatever is currently in the form, so a receptionist can print
     * without saving first if they just want a quick record.
     */
    private function render_invoice_modal_html() { ?>
        <div id="mdbk-invoice-modal" class="mdbk-modal mdbk-modal-compact">
            <div class="mdbk-modal-content">
                <div class="mdbk-modal-head">
                    <h2><?php _e('Invoice', 'doctor-appointment'); ?></h2>
                    <span class="mdbk-modal-close">&times;</span>
                </div>
                <div class="mdbk-modal-body">
                    <div class="mdbk-patient-view-info" style="margin-bottom:16px; padding-bottom:16px;">
                        <div class="mdbk-view-field"><label><?php _e('Invoice No.', 'doctor-appointment'); ?></label><span id="mdbk-invoice-number">—</span></div>
                        <div class="mdbk-view-field"><label><?php _e('Date', 'doctor-appointment'); ?></label><span id="mdbk-invoice-date">—</span></div>
                        <div class="mdbk-view-field"><label><?php _e('Patient', 'doctor-appointment'); ?></label><span id="mdbk-invoice-patient">—</span></div>
                        <div class="mdbk-view-field"><label><?php _e('Doctor', 'doctor-appointment'); ?></label><span id="mdbk-invoice-doctor">—</span></div>
                    </div>
                    <div class="mdbk-form-row mdbk-form-row-duo">
                        <div>
                            <label class="mdbk-form-label" for="mdbk-invoice-amount"><?php _e('Consultation Fee (৳)', 'doctor-appointment'); ?></label>
                            <input type="number" min="0" step="0.01" id="mdbk-invoice-amount" placeholder="<?php esc_attr_e('e.g. 800', 'doctor-appointment'); ?>">
                        </div>
                        <div>
                            <label class="mdbk-form-label"><?php _e('Status', 'doctor-appointment'); ?></label>
                            <div class="mdbk-invoice-status-toggle">
                                <button type="button" class="mdbk-invoice-status-btn" data-status="unpaid"><?php _e('Unpaid', 'doctor-appointment'); ?></button>
                                <button type="button" class="mdbk-invoice-status-btn" data-status="paid"><?php _e('Paid', 'doctor-appointment'); ?></button>
                            </div>
                        </div>
                    </div>
                    <p class="mdbk-invoice-save-msg" id="mdbk-invoice-save-msg" style="display:none;"></p>
                </div>
                <div class="mdbk-modal-foot">
                    <button type="button" class="mdbk-btn-outline" id="mdbk-invoice-print"><?php _e('Print Invoice', 'doctor-appointment'); ?></button>
                    <button type="button" class="mdbk-btn-add" id="mdbk-invoice-save"><?php _e('Save', 'doctor-appointment'); ?></button>
                </div>
            </div>
        </div>
    <?php }

    /**
     * AJAX: current invoice state for one appointment — the saved
     * amount/status if this invoice has been saved before, otherwise the
     * doctor's own default Consultation Fee and 'unpaid'. Never writes
     * anything itself; ajax_save_invoice() is the only place that does.
     */
    public function ajax_get_invoice() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_QUEUE)) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        $appointment = $appointment_id ? get_post($appointment_id) : null;
        if (!$appointment || $appointment->post_type !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Appointment not found.', 'doctor-appointment')]);
        }
        // Same rule the Invoice action's own visibility follows (see
        // render_patient_appointment_row()) — only a closed-out visit has
        // an actual consultation to bill, enforced here too so the AJAX
        // endpoint can't be hit directly for one that's still
        // waiting/serving/no-show. The date check catches a booking whose
        // status was set to completed by mistake (or hand-edited) for a
        // date that hasn't happened yet — that's never billable either,
        // regardless of what the status field says.
        $appointment_date = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        if (\MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($appointment)) !== 'completed' || $appointment_date > current_time('Y-m-d')) {
            wp_send_json_error(['message' => __('This appointment has not been marked Visited yet.', 'doctor-appointment')]);
        }

        $doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        $amount = get_post_meta($appointment_id, '_mdbk_invoice_amount', true);
        if ($amount === '') {
            $amount = $doctor_id ? get_post_meta($doctor_id, '_mdbk_doc_fee', true) : '';
        }
        $status = get_post_meta($appointment_id, '_mdbk_invoice_status', true) ?: 'unpaid';
        $date = get_post_meta($appointment_id, '_mdbk_appointment_date', true);

        wp_send_json_success([
            'invoice_number' => 'INV-' . str_pad($appointment_id, 6, '0', STR_PAD_LEFT),
            'amount'         => $amount,
            'status'         => $status === 'paid' ? 'paid' : 'unpaid',
            'patient_name'   => get_post_meta($appointment_id, '_mdbk_patient_name', true),
            'patient_phone'  => get_post_meta($appointment_id, '_mdbk_patient_phone', true),
            'doctor_name'    => $doctor_id ? get_the_title($doctor_id) : __('N/A', 'doctor-appointment'),
            'date_display'   => $date ? date_i18n(get_option('date_format'), strtotime($date)) : '—',
        ]);
    }

    /**
     * AJAX: persists the amount/status a staff member set in the modal
     * above. Doesn't touch the invoice NUMBER (see render_invoice_modal_html()'s
     * comment — it's derived from the post ID, never stored).
     */
    public function ajax_save_invoice() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_QUEUE)) wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        $appointment = $appointment_id ? get_post($appointment_id) : null;
        if (!$appointment || $appointment->post_type !== 'mdbk_appointment') {
            wp_send_json_error(['message' => __('Appointment not found.', 'doctor-appointment')]);
        }
        $appointment_date = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        if (\MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($appointment)) !== 'completed' || $appointment_date > current_time('Y-m-d')) {
            wp_send_json_error(['message' => __('This appointment has not been marked Visited yet.', 'doctor-appointment')]);
        }

        $amount = isset($_POST['amount']) ? sanitize_text_field($_POST['amount']) : '';
        $amount = is_numeric($amount) ? $amount : '0';
        $status = (isset($_POST['status']) && $_POST['status'] === 'paid') ? 'paid' : 'unpaid';

        update_post_meta($appointment_id, '_mdbk_invoice_amount', $amount);
        update_post_meta($appointment_id, '_mdbk_invoice_status', $status);

        wp_send_json_success(['saved' => true]);
    }

    /**
     * The District + Thana pair, rendered identically wherever an address
     * is captured (New/Edit Booking, Add/Edit Patient). One renderer
     * rather than two copies of the markup: the two modals have to agree
     * on the ids the shared initLocationSelects() JS looks for, and on
     * the field names find_or_create_patient() reads — the moment they
     * are written out twice by hand they start drifting.
     *
     * $prefix distinguishes the two instances ('app' / 'patient'), since
     * both modals exist in the DOM at the same time and ids must stay
     * unique. Thana starts disabled and empty: its options only exist
     * once a district is chosen, and 493 thanas with no district to place
     * them in is not a list worth showing.
     */
    private function render_location_selects($prefix, $required = false) {
        $p = esc_attr($prefix);
        // Booking captures a usable address or it isn't taken; the
        // Patient registry form stays optional, so records created before
        // these fields existed can still be edited without inventing one.
        $star = $required ? ' *' : '';
        ?>
        <div class="mdbk-form-row mdbk-form-row-duo">
            <div>
                <label class="mdbk-form-label" for="mdbk-<?php echo $p; ?>-district-trigger"><?php _e('District', 'doctor-appointment'); ?><?php echo esc_html($star); ?></label>
                <div class="mdbk-custom-select" id="mdbk-<?php echo $p; ?>-district-select" data-clearable>
                    <button type="button" class="mdbk-custom-select-trigger" id="mdbk-<?php echo $p; ?>-district-trigger">
                        <span class="mdbk-custom-select-value mdbk-select-placeholder"><?php esc_html_e('Select district', 'doctor-appointment'); ?></span>
                        <span class="mdbk-custom-select-chevron"></span>
                    </button>
                    <div class="mdbk-custom-select-panel" id="mdbk-<?php echo $p; ?>-district-panel" style="display:none;">
                        <div class="mdbk-custom-select-option" data-value=""><?php esc_html_e('Select district', 'doctor-appointment'); ?></div>
                        <?php foreach (\MDBK\MDBK_BD_Locations::districts() as $bd_district): ?>
                            <div class="mdbk-custom-select-option" data-value="<?php echo esc_attr($bd_district); ?>"><?php echo esc_html($bd_district); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <select name="patient_district" id="mdbk-<?php echo $p; ?>-district" style="display:none;">
                        <option value=""></option>
                        <?php foreach (\MDBK\MDBK_BD_Locations::districts() as $bd_district): ?>
                            <option value="<?php echo esc_attr($bd_district); ?>"><?php echo esc_html($bd_district); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="mdbk-form-label" for="mdbk-<?php echo $p; ?>-thana-trigger"><?php _e('Thana', 'doctor-appointment'); ?><?php echo esc_html($star); ?></label>
                <div class="mdbk-custom-select is-disabled" id="mdbk-<?php echo $p; ?>-thana-select" data-clearable>
                    <button type="button" class="mdbk-custom-select-trigger" id="mdbk-<?php echo $p; ?>-thana-trigger" disabled>
                        <span class="mdbk-custom-select-value mdbk-select-placeholder"><?php esc_html_e('Select district first', 'doctor-appointment'); ?></span>
                        <span class="mdbk-custom-select-chevron"></span>
                    </button>
                    <div class="mdbk-custom-select-panel" id="mdbk-<?php echo $p; ?>-thana-panel" style="display:none;"></div>
                    <select name="patient_thana" id="mdbk-<?php echo $p; ?>-thana" style="display:none;"><option value=""></option></select>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_patient_modal_html() { ?>
        <div id="mdbk-patient-modal" class="mdbk-modal mdbk-modal-compact"><div class="mdbk-modal-content">
            <div class="mdbk-modal-head"><h2 id="mdbk-patient-modal-title"><?php _e('Add Patient', 'doctor-appointment'); ?></h2><span class="mdbk-modal-close">&times;</span></div>
            <form id="mdbk-patient-form" method="POST"><?php wp_nonce_field('mdbk_save_patient'); ?><input type="hidden" name="patient_id" id="mdbk-patient-id">
            <div class="mdbk-modal-body">
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-patient-name"><?php _e('Full Name', 'doctor-appointment'); ?> *</label>
                    <input type="text" name="patient_name" id="mdbk-patient-name" placeholder="<?php esc_attr_e('e.g. Karim Ahmed', 'doctor-appointment'); ?>" required>
                </div>
                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div class="mdbk-patient-suggest-wrap"><label class="mdbk-form-label" for="mdbk-patient-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-patient-phone" placeholder="<?php esc_attr_e('e.g. 01700-000000', 'doctor-appointment'); ?>" autocomplete="off"><div id="mdbk-patient-phone-suggest" class="mdbk-patient-suggest" style="display:none;"></div></div>
                    <div><label class="mdbk-form-label" for="mdbk-patient-email"><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="patient_email" id="mdbk-patient-email" placeholder="<?php esc_attr_e('e.g. patient@example.com', 'doctor-appointment'); ?>"></div>
                </div>
                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-patient-age"><?php _e('Age', 'doctor-appointment'); ?></label><input type="number" name="patient_age" id="mdbk-patient-age" min="0" placeholder="<?php esc_attr_e('e.g. 32', 'doctor-appointment'); ?>"></div>
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
                            </div>
                            <select name="patient_gender" id="mdbk-patient-gender" style="display:none;">
                                <option value="Male"><?php _e('Male', 'doctor-appointment'); ?></option>
                                <option value="Female"><?php _e('Female', 'doctor-appointment'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php $this->render_location_selects('patient'); ?>
            </div>
            <div class="mdbk-modal-foot">
                <button type="button" class="mdbk-btn-outline mdbk-modal-cancel"><?php _e('Cancel', 'doctor-appointment'); ?></button>
                <button type="submit" name="mdbk_save_patient" class="mdbk-btn-save"><?php _e('Save Record', 'doctor-appointment'); ?></button>
            </div>
            </form>
        </div></div>
    <?php }

    /**
     * Live phone-number lookup for the New Booking modal — as staff type a
     * phone number, this surfaces any existing mdbk_patient with a matching
     * (partial) phone so they can pick it instead of re-typing a patient
     * find_or_create_patient() would have matched anyway on submit. Returns
     * pre-escaped HTML (data-* attributes carry the field values) rather
     * than raw JSON, so the client never has to build/escape HTML itself —
     * same reasoning as every other search AJAX handler in this file.
     */
    public function ajax_search_patient_phone() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_QUEUE)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        if (strlen($phone) < 3) {
            wp_send_json_success(['results_html' => '']);
        }
        $patients = get_posts([
            'post_type'   => 'mdbk_patient',
            'numberposts' => 8,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'meta_query'  => [['key' => '_mdbk_patient_phone', 'value' => $phone, 'compare' => 'LIKE']],
        ]);
        ob_start();
        foreach ($patients as $p) {
            $p_phone   = get_post_meta($p->ID, '_mdbk_patient_phone', true);
            $p_email   = get_post_meta($p->ID, '_mdbk_patient_email', true);
            $p_age     = get_post_meta($p->ID, '_mdbk_patient_age', true);
            $p_gender  = get_post_meta($p->ID, '_mdbk_patient_gender', true);
            // id/address: unused by the Booking modal's own suggestion click
            // (New Booking modal admin-script.js) but needed by the "+ Add
            // Patient" form's — picking a suggestion there switches it to
            // editing that existing record (see its own phone-suggest IIFE)
            // instead of letting Save create a same-phone duplicate.
            $p_address = get_post_meta($p->ID, '_mdbk_patient_address', true);
            ?>
            <div class="mdbk-patient-suggest-item" data-id="<?php echo esc_attr($p->ID); ?>" data-name="<?php echo esc_attr($p->post_title); ?>" data-phone="<?php echo esc_attr($p_phone); ?>" data-email="<?php echo esc_attr($p_email); ?>" data-age="<?php echo esc_attr($p_age); ?>" data-gender="<?php echo esc_attr($p_gender); ?>" data-address="<?php echo esc_attr($p_address); ?>" data-district="<?php echo esc_attr(get_post_meta($p->ID, '_mdbk_patient_district', true)); ?>" data-thana="<?php echo esc_attr(get_post_meta($p->ID, '_mdbk_patient_thana', true)); ?>">
                <span class="mdbk-patient-suggest-name"><?php echo esc_html($p->post_title); ?></span>
                <span class="mdbk-patient-suggest-meta"><?php echo esc_html($p_phone); ?><?php echo $p_email ? ' &middot; ' . esc_html($p_email) : ''; ?></span>
            </div>
            <?php
        }
        wp_send_json_success(['results_html' => ob_get_clean()]);
    }

    /**
     * $own_doctor_id: non-zero when this is being rendered for a pure
     * doctor account's own single-doctor Booking header — "which
     * doctor" isn't a real choice for them the way it is for staff, so
     * that whole row is hidden and $all_doctors below is narrowed to
     * just their own post, which — via the exact same markup every
     * other case already uses — leaves the Doctor <select> holding
     * their own ID as its only, pre-selected option. The underlying
     * <select> stays in the DOM either way (never removed), just its
     * row wrapper hidden: the slot-picker's own JS (admin-script.js)
     * reads a doctor id straight off that element on every date pick,
     * and null-ing it out instead of hiding it would silently break
     * that lookup instead of just fixing it to one doctor.
     */
    private function render_appointment_modal_html($own_doctor_id = 0) {
        $all_doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC']);
        if ($own_doctor_id) {
            $own_doctor_post = get_post($own_doctor_id);
            $all_doctors = ($own_doctor_post && $own_doctor_post->post_type === 'mdbk_doctor') ? [$own_doctor_post] : [];
        }

        // hide_empty (WP's own published-post count per term) — a specialty
        // with zero doctors assigned isn't a real choice here either; it
        // would just filter the Doctor dropdown down to nothing. Matches
        // the public booking form's own specialty list (render_booking_widget_fields()
        // in shortcode.php) for the same reason.
        $spec_terms = \MDBK\MDBK_Appointment_Manager::get_specialty_terms(true);
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
                <div class="mdbk-form-row mdbk-form-row-duo"<?php echo $own_doctor_id ? ' style="display:none;"' : ''; ?>>
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
                    <input type="text" name="patient_name" id="mdbk-app-patient" placeholder="<?php esc_attr_e('e.g. Karim Ahmed', 'doctor-appointment'); ?>" required>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div class="mdbk-patient-suggest-wrap"><label class="mdbk-form-label" for="mdbk-app-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-app-phone" placeholder="<?php esc_attr_e('e.g. 01700-000000', 'doctor-appointment'); ?>" autocomplete="off"><div id="mdbk-app-phone-suggest" class="mdbk-patient-suggest" style="display:none;"></div></div>
                    <div><label class="mdbk-form-label" for="mdbk-app-email"><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="patient_email" id="mdbk-app-email" placeholder="<?php esc_attr_e('e.g. patient@example.com', 'doctor-appointment'); ?>"></div>
                </div>

                <?php // Address is a District + Thana pair, not free text —
                // see MDBK_BD_Locations. Two halves of one line, matching
                // the Phone/Email and Age/Gender rows around it. Saved to
                // the patient record only (patient_address() reads it back
                // live for the Bookings list), since an address describes
                // the person rather than the visit. ?>
                <?php $this->render_location_selects('app', true); ?>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-app-age"><?php _e('Age', 'doctor-appointment'); ?> *</label><input type="number" name="age" id="mdbk-app-age" min="0" max="120" placeholder="<?php esc_attr_e('e.g. 32', 'doctor-appointment'); ?>" required></div>
                    <div>
                        <label class="mdbk-form-label" for="mdbk-app-gender-trigger"><?php _e('Gender', 'doctor-appointment'); ?></label>
                        <div class="mdbk-custom-select" id="mdbk-app-gender-select">
                            <button type="button" class="mdbk-custom-select-trigger" id="mdbk-app-gender-trigger">
                                <span class="mdbk-custom-select-value"><?php _e('Male', 'doctor-appointment'); ?></span>
                                <span class="mdbk-custom-select-chevron"></span>
                            </button>
                            <div class="mdbk-custom-select-panel" id="mdbk-app-gender-panel" style="display:none;">
                                <div class="mdbk-custom-select-option selected" data-value="Male"><?php _e('Male', 'doctor-appointment'); ?></div>
                                <div class="mdbk-custom-select-option" data-value="Female"><?php _e('Female', 'doctor-appointment'); ?></div>
                            </div>
                            <select name="gender" id="mdbk-app-gender" style="display:none;">
                                <option value="Male"><?php _e('Male', 'doctor-appointment'); ?></option>
                                <option value="Female"><?php _e('Female', 'doctor-appointment'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div class="mdbk-date-picker-wrap" id="mdbk-app-date-wrap">
                        <label class="mdbk-form-label"><?php _e('Date', 'doctor-appointment'); ?> *</label>
                        <div class="mdbk-app-date-panel" id="mdbk-app-calendar"></div>
                        <input type="hidden" name="app_date" id="mdbk-app-date">
                    </div>
                    <div>
                        <?php
                        // Same clickable time-slot grid the public booking
                        // form uses (#mdbk-modal-slot-picker in
                        // shortcode.php/form-script.js) instead of a bare
                        // native <input type="time"> — a receptionist gets
                        // the exact same picture a patient would: which
                        // slots this doctor actually has open on the
                        // chosen date, with already-booked ones visibly
                        // disabled instead of only failing at Save.
                        $first_slot_enabled = $all_doctors && \MDBK\MDBK_Appointment_Manager::is_slot_enabled($all_doctors[0]->ID);
                        ?>
                        <label class="mdbk-form-label"><?php _e('Slot Time', 'doctor-appointment'); ?></label>
                        <div class="mdbk-slot-picker mdbk-slot-picker-disabled" id="mdbk-app-slot-picker" style="<?php echo $first_slot_enabled ? '' : 'display:none;'; ?>">
                            <p class="mdbk-time-placeholder"><?php _e('Select a date first', 'doctor-appointment'); ?></p>
                        </div>
                        <input type="hidden" name="slot_time" id="mdbk-app-slot-time">
                        <p class="mdbk-form-hint" id="mdbk-app-slot-hint" style="<?php echo $first_slot_enabled ? 'display:none;' : ''; ?>"><?php _e('No time slot shown — a time and queue position are assigned automatically.', 'doctor-appointment'); ?></p>
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

    private function render_specialty_card($term) {
        $icon_id = get_term_meta($term->term_id, '_mdbk_specialty_icon', true);
        $icon_url = $icon_id ? wp_get_attachment_image_url($icon_id, 'thumbnail') : '';
        // Mirrors doctors'/dresses' own pattern — the meta only ever gets
        // written (to 'no') the first time someone flips a card's toggle off.
        $active = get_term_meta($term->term_id, '_mdbk_specialty_active', true) !== 'no';
        ?>
        <div class="mdbk-specialty-card<?php echo $active ? '' : ' is-inactive'; ?>" data-id="<?php echo esc_attr($term->term_id); ?>" data-name="<?php echo esc_attr($term->name); ?>" data-icon-id="<?php echo esc_attr($icon_id ?: 0); ?>" data-icon-url="<?php echo esc_url($icon_url ?: ''); ?>">
            <div class="mdbk-specialty-card-icon">
                <?php if ($icon_url) : ?>
                    <img src="<?php echo esc_url($icon_url); ?>" alt="">
                <?php else : ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
                <?php endif; ?>
            </div>
            <div class="mdbk-specialty-card-name"><?php echo esc_html($term->name); ?></div>
            <div class="mdbk-specialty-card-count"><?php echo esc_html(sprintf(_n('%d doctor', '%d doctors', $term->count, 'doctor-appointment'), $term->count)); ?></div>
            <div class="mdbk-specialty-card-footer">
                <label class="mdbk-toggle mdbk-mini-toggle" title="<?php esc_attr_e('Active/Inactive', 'doctor-appointment'); ?>">
                    <input type="checkbox" class="mdbk-specialty-toggle" <?php checked($active); ?> />
                    <span class="mdbk-toggle-slider"></span>
                </label>
                <div class="mdbk-specialty-card-actions">
                    <span class="mdbk-action-btn mdbk-edit-specialty" data-id="<?php echo esc_attr($term->term_id); ?>" title="<?php esc_attr_e('Edit', 'doctor-appointment'); ?>"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg></span>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-specialties&action=mdbk_delete_specialty&id=' . $term->term_id), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" title="<?php esc_attr_e('Delete', 'doctor-appointment'); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this specialty?', 'doctor-appointment')); ?>')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg></a>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_specialty_modal_html() { ?>
        <div id="mdbk-specialty-modal" class="mdbk-modal mdbk-modal-compact"><div class="mdbk-modal-content">
            <div class="mdbk-modal-head"><h2 id="mdbk-specialty-modal-title"><?php _e('Add Specialty', 'doctor-appointment'); ?></h2><span class="mdbk-modal-close">&times;</span></div>
            <form id="mdbk-specialty-form" method="POST"><?php wp_nonce_field('mdbk_save_specialty'); ?><input type="hidden" name="term_id" id="mdbk-spec-id"><input type="hidden" name="icon_id" id="mdbk-spec-icon-id" value="0">
            <div class="mdbk-modal-body">
                <div class="mdbk-form-row">
                    <label class="mdbk-form-label"><?php _e('Icon / Image (SVG or PNG)', 'doctor-appointment'); ?></label>
                    <div class="mdbk-photo-picker">
                        <div class="mdbk-photo-preview" id="mdbk-spec-icon-preview"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg></div>
                        <div class="mdbk-photo-actions">
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-spec-icon-upload"><?php _e('Select Image', 'doctor-appointment'); ?></button>
                            <button type="button" class="mdbk-btn-outline mdbk-btn-sm" id="mdbk-spec-icon-remove" style="display:none;"><?php _e('Remove', 'doctor-appointment'); ?></button>
                        </div>
                    </div>
                </div>
                <div class="mdbk-input-group"><label><?php _e('Name', 'doctor-appointment'); ?></label><input type="text" name="spec_name" id="mdbk-spec-name" class="mdbk-input" placeholder="<?php esc_attr_e('e.g. Cardiology', 'doctor-appointment'); ?>" required></div>
            </div>
            <div class="mdbk-modal-foot" style="justify-content:space-between;">
                <label class="mdbk-toggle">
                    <input type="checkbox" name="status" value="yes" id="mdbk-spec-status" class="mdbk-status-toggle" checked />
                    <span class="mdbk-toggle-slider"></span>
                    <span class="mdbk-form-label" style="margin:0;"><?php _e('Active', 'doctor-appointment'); ?></span>
                </label>
                <button type="submit" name="mdbk_save_specialty" class="mdbk-btn-save"><?php _e('Save Specialty', 'doctor-appointment'); ?></button>
            </div>
            </form>
        </div></div>
    <?php }
}
new MDBK_Admin_Dashboard();
