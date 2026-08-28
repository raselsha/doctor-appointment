<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Shortcode {

    public function __construct() {
        add_shortcode('mdbk_appointment_form', [$this, 'render_form']);
        add_shortcode('mdbk_queue_management', [$this, 'render_queue']);
        add_shortcode('mdbk_doctor_list', [$this, 'render_doctor_list']);
        add_shortcode('mdbk_queue_list', [$this, 'render_queue_list']);
        add_action('wp_footer', [$this, 'render_modal']);
        // Priority 30: must run after wp_print_footer_scripts (priority 20)
        // has actually printed the enqueued qrcode.js <script> tag, or the
        // inline QR-render script below finds `qrcode` undefined.
        add_action('wp_footer', [$this, 'render_status_view'], 30);
        // Doctor's-chamber walk-in check-in landing page (?mdbk_chamber=) —
        // no QR-render dependency of its own (this page shows a phone-entry
        // form, not a QR image), so no priority-30 ordering need here.
        add_action('wp_footer', [$this, 'render_chamber_checkin_view']);
        // One shared modal (not one per doctor card) for the "Today's
        // Patients" button on render_doctor_list()'s cards — populated via
        // AJAX per click, same pattern as the booking modal above.
        add_action('wp_footer', [$this, 'render_today_patients_modal']);

        // Queue AJAX endpoints — nopriv because the queue is a public/kiosk
        // display, same trust model the plain-POST version already had
        // (nonce-gated, no login required).
        add_action('wp_ajax_mdbk_get_queue_state', [$this, 'ajax_get_queue_state']);
        add_action('wp_ajax_nopriv_mdbk_get_queue_state', [$this, 'ajax_get_queue_state']);
        add_action('wp_ajax_mdbk_queue_call_next', [$this, 'ajax_queue_call_next']);
        add_action('wp_ajax_nopriv_mdbk_queue_call_next', [$this, 'ajax_queue_call_next']);
        add_action('wp_ajax_mdbk_queue_set_status', [$this, 'ajax_queue_set_status']);
        add_action('wp_ajax_nopriv_mdbk_queue_set_status', [$this, 'ajax_queue_set_status']);
        add_action('wp_ajax_mdbk_verify_checkin', [$this, 'ajax_verify_checkin']);
        add_action('wp_ajax_nopriv_mdbk_verify_checkin', [$this, 'ajax_verify_checkin']);
        add_action('wp_ajax_mdbk_queue_toggle_skip', [$this, 'ajax_queue_toggle_skip']);
        add_action('wp_ajax_nopriv_mdbk_queue_toggle_skip', [$this, 'ajax_queue_toggle_skip']);
        add_action('wp_ajax_mdbk_chamber_checkin', [$this, 'ajax_chamber_checkin']);
        add_action('wp_ajax_nopriv_mdbk_chamber_checkin', [$this, 'ajax_chamber_checkin']);
    }

    /**
     * Render Doctor List
     */
    public function render_doctor_list($atts = []) {

        $atts = shortcode_atts([
            'department'  => '',
            'doctor'      => '',
            'limit'       => -1,
            // Matches the admin's own drag-and-drop Doctors order (see
            // ajax_save_doctor_order() in admin-dashboard.php) by default —
            // still overridable per-embed, e.g. [mdbk_doctor_list orderby="title"].
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'booking_url' => '',
        ], $atts, 'mdbk_doctor_list');

        $args = [
            'post_type'      => 'mdbk_doctor',
            'post_status'    => 'publish',
            'posts_per_page' => intval($atts['limit']),
            'orderby'        => sanitize_key($atts['orderby']),
            'order'          => strtoupper($atts['order']) === 'DESC' ? 'DESC' : 'ASC',
            // Doctors default to active — the meta only ever gets written (to 'no')
            // once someone flips a card's toggle off in wp-admin. Kept even for
            // a single-doctor request ('doctor' attribute below) — an inactive
            // doctor shouldn't become bookable again just by linking straight
            // to their own page.
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_mdbk_doctor_active', 'compare' => 'NOT EXISTS'],
                ['key' => '_mdbk_doctor_active', 'value' => 'no', 'compare' => '!='],
            ],
        ];

        // [mdbk_doctor_list doctor="6"] (or a slug) — a single doctor's own
        // page, reusing this exact same card markup (bio, contact,
        // availability, Today's Patients/Book Appointment) instead of a
        // separate template, so it never drifts from the grid version.
        if (!empty($atts['doctor'])) {
            $args['p'] = is_numeric($atts['doctor']) ? intval($atts['doctor']) : 0;
            if (!$args['p']) {
                $args['name'] = sanitize_title($atts['doctor']);
            }
            $args['posts_per_page'] = 1;
        } elseif (!empty($atts['department'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'mdbk_department',
                    'field'    => is_numeric($atts['department']) ? 'term_id' : 'slug',
                    'terms'    => is_numeric($atts['department']) ? intval($atts['department']) : sanitize_title($atts['department']),
                ],
            ];
        }

        $doctors = new \WP_Query($args);

        ob_start();
    ?>

        <div class="mdbk-doctor-list">

            <?php if ($doctors->have_posts()) : ?>

                <div class="mdbk-doctor-grid">

                    <?php while ($doctors->have_posts()) : $doctors->the_post();

                        $doctor_id   = get_the_ID();
                        $email       = get_post_meta($doctor_id, '_mdbk_doc_email', true);
                        $phone       = get_post_meta($doctor_id, '_mdbk_doc_phone', true);
                        $bio         = get_post_meta($doctor_id, '_mdbk_doc_bio', true);
                        $show_phone  = get_post_meta($doctor_id, '_mdbk_show_phone', true);
                        $show_email  = get_post_meta($doctor_id, '_mdbk_show_email', true);
                        $schedule    = get_post_meta($doctor_id, '_mdbk_schedule', true);
                        $departments = get_the_terms($doctor_id, 'mdbk_department');

                        $today          = current_time('Y-m-d');
                        $today_day_name = current_time('l');
                        $extra_dates    = get_post_meta($doctor_id, '_mdbk_extra_dates', true);
                        if (!is_array($extra_dates)) $extra_dates = [];
                        $off_dates = get_post_meta($doctor_id, '_mdbk_off_dates', true);
                        if (!is_array($off_dates)) $off_dates = [];
                        // This month's upcoming extra (one-off) working
                        // dates — past ones already happened, so aren't
                        // useful information for a prospective patient here.
                        $month_extra_dates = array_values(array_filter($extra_dates, function($d) use ($today) {
                            return $d >= $today && substr($d, 0, 7) === substr($today, 0, 7);
                        }));
                        sort($month_extra_dates);
                        // An extra date borrows the first active weekday's
                        // hours as its own stand-in — same rule
                        // get_available_slots() itself uses, so this display
                        // never promises hours the booking flow wouldn't
                        // actually honor.
                        $extra_date_from = $extra_date_to = '';
                        if (is_array($schedule)) {
                            foreach ($schedule as $day_hours) {
                                if (!empty($day_hours['active']) && !empty($day_hours['from']) && !empty($day_hours['to'])) {
                                    $extra_date_from = $day_hours['from'];
                                    $extra_date_to   = $day_hours['to'];
                                    break;
                                }
                            }
                        }
                        // Same precedence as get_available_slots(): an off
                        // date closes the doctor outright regardless of the
                        // weekly pattern or any extra date.
                        if (in_array($today, $off_dates, true)) {
                            $is_working_today = false;
                        } elseif (is_array($schedule) && !empty($schedule[$today_day_name]['active'])) {
                            $is_working_today = true;
                        } else {
                            $is_working_today = in_array($today, $extra_dates, true);
                        }
                        // "Today's Patients" is staff/manager/admin/doctor
                        // only (per feedback — not a public feature after
                        // all) — MDBK_CAP_QUEUE covers front-desk/manager/
                        // admin, MDBK_CAP_DOCTOR covers a doctor viewing
                        // their own card, same capability pair used
                        // throughout the admin side of this plugin.
                        $can_see_today_patients = $is_working_today && (current_user_can(MDBK_CAP_QUEUE) || current_user_can(MDBK_CAP_DOCTOR));
                        ?>

                        <article class="mdbk-doctor-card">

                            <div class="mdbk-doctor-top">

                                <?php if (has_post_thumbnail()) : ?>
                                    <div class="mdbk-doctor-photo">
                                        <?php the_post_thumbnail('medium_large'); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="mdbk-doctor-content">

                                    <?php if (!empty($departments) && !is_wp_error($departments)) : ?>
                                        <div class="mdbk-doctor-departments">
                                            <?php foreach ($departments as $department) : ?>
                                                <span><?php echo esc_html($department->name); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <h3 class="mdbk-doctor-name">
                                        <?php the_title(); ?>
                                    </h3>

                                    <?php if (!empty($bio)) : ?>
                                        <p class="mdbk-doctor-bio">
                                            <?php echo esc_html($bio); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (has_excerpt()) : ?>
                                        <p class="mdbk-doctor-excerpt">
                                            <?php echo esc_html(get_the_excerpt()); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (($phone && $show_phone !== 'no') || ($email && $show_email !== 'no')) : ?>
                                    <ul class="mdbk-doctor-contact">

                                        <?php if ($phone && $show_phone !== 'no') : ?>
                                            <li>
                                                <span class="label"><?php _e('Phone', 'doctor-appointment'); ?></span>
                                                <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $phone)); ?>">
                                                    <?php echo esc_html($phone); ?>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php if ($email && $show_email !== 'no') : ?>
                                            <li>
                                                <span class="label"><?php _e('Email', 'doctor-appointment'); ?></span>
                                                <a href="mailto:<?php echo esc_attr($email); ?>">
                                                    <?php echo esc_html($email); ?>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                    </ul>
                                    <?php endif; ?>

                                </div>
                            </div>

                            <?php if (is_array($schedule) && !empty($schedule)) : ?>

                                <div class="mdbk-doctor-schedule">

                                    <h4><?php _e('Availability', 'doctor-appointment'); ?></h4>

                                    <ul>

                                        <?php $day_labels = \MDBK\MDBK_Appointment_Manager::get_day_labels(); ?>
                                        <?php foreach (\MDBK\MDBK_Appointment_Manager::get_week_day_order() as $day) : $time = $schedule[$day] ?? null; ?>

                                            <?php if (!empty($time['active'])) : ?>

                                                <li<?php echo ($day === $today_day_name && $is_working_today) ? ' class="is-today"' : ''; ?>>
                                                    <span class="day">
                                                        <?php echo esc_html($day_labels[$day]); ?>
                                                        <?php if ($day === $today_day_name && $is_working_today) : ?><span class="mdbk-today-tag"><?php _e('Today', 'doctor-appointment'); ?></span><?php endif; ?>
                                                    </span>

                                                    <span class="time">
                                                        <?php echo esc_html(!empty($time['from']) ? date_i18n(get_option('time_format'), strtotime($time['from'])) : ''); ?>
                                                        -
                                                        <?php echo esc_html(!empty($time['to']) ? date_i18n(get_option('time_format'), strtotime($time['to'])) : ''); ?>
                                                    </span>
                                                </li>

                                            <?php endif; ?>

                                        <?php endforeach; ?>

                                        <?php foreach ($month_extra_dates as $extra_date) : $is_extra_today = ($extra_date === $today); ?>
                                            <li class="mdbk-extra-date-row<?php echo $is_extra_today ? ' is-today' : ''; ?>">
                                                <span class="day">
                                                    <?php echo esc_html(date_i18n('M j', strtotime($extra_date))); ?>
                                                    <span class="mdbk-special-tag"><?php _e('Special', 'doctor-appointment'); ?></span>
                                                    <?php if ($is_extra_today) : ?><span class="mdbk-today-tag"><?php _e('Today', 'doctor-appointment'); ?></span><?php endif; ?>
                                                </span>
                                                <?php if ($extra_date_from && $extra_date_to) : ?>
                                                <span class="time">
                                                    <?php echo esc_html(date_i18n(get_option('time_format'), strtotime($extra_date_from))); ?>
                                                    -
                                                    <?php echo esc_html(date_i18n(get_option('time_format'), strtotime($extra_date_to))); ?>
                                                </span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>

                                    </ul>

                                </div>

                            <?php endif; ?>

                            <div class="mdbk-doctor-footer">

                                <div class="mdbk-doctor-footer-buttons">
                                    <?php if ($can_see_today_patients) : ?>
                                    <button type="button" class="mdbk-doctor-today-btn mdbk-today-patients-trigger"
                                    data-mdbk-doctor-id="<?php echo esc_attr($doctor_id); ?>"
                                    data-mdbk-doctor-name="<?php echo esc_attr(get_the_title($doctor_id)); ?>">
                                        <?php _e("Today's Patients", 'doctor-appointment'); ?>
                                    </button>
                                    <?php endif; ?>

                                    <button type="button" class="mdbk-doctor-book-btn mdbk-book-trigger"
                                    data-mdbk-doctor-id="<?php echo esc_attr($doctor_id); ?>">

                                        <?php _e('Book Appointment', 'doctor-appointment'); ?>

                                    </button>
                                </div>

                            </div>

                        </article>

                    <?php endwhile; ?>

                </div>

                <?php wp_reset_postdata(); ?>

            <?php else : ?>

                <p class="mdbk-no-doctors">
                    <?php _e('No doctors found.', 'doctor-appointment'); ?>
                </p>

            <?php endif; ?>

        </div>

    <?php

    return ob_get_clean();
}

    /**
     * Whether the booking widget (specialty/doctor/booking/details form)
     * has already been rendered on this page load — by render_form(), the
     * [mdbk_appointment_form] shortcode. All the widget's JS is written
     * against fixed element IDs (one instance per page), so render_modal()
     * checks this and skips its own output entirely when true, rather than
     * emitting a second, ID-colliding copy in the footer.
     */
    private static $widget_rendered = false;

    /**
     * Render the Appointment Booking form
     *
     * Renders the same specialty/doctor/booking/details widget used inside
     * the shared popup modal (see render_modal()) — but inline, as normal
     * page content, not inside an overlay. This is for a dedicated booking
     * page; the popup modal remains available everywhere else via
     * class="mdbk-book-trigger" (e.g. the doctor grid's per-doctor
     * buttons), which stays completely separate from this shortcode.
     */
    public function render_form($atts = []) {
        $atts = shortcode_atts([
            'doctor' => '',
            // For a caller that already provides its own outer spacing/
            // centering (e.g. a theme template wrapping this shortcode in
            // its own positioned container) — set flush="1" to drop this
            // widget's own default margin, instead of the caller having to
            // override .mdbk-booking-inline's CSS from outside this plugin.
            'flush'  => '',
        ], $atts, 'mdbk_appointment_form');

        $doctor_id = $atts['doctor'] !== '' ? absint($atts['doctor']) : (isset($_GET['doctor']) ? absint(wp_unslash($_GET['doctor'])) : 0);
        $flush = in_array($atts['flush'], ['1', 'true', 'yes'], true);

        self::$widget_rendered = true;

        ob_start();
        ?>
        <div id="mdbk-booking-inline" class="mdbk-booking-inline<?php echo $flush ? ' mdbk-booking-inline--flush' : ''; ?>"<?php echo $doctor_id ? ' data-mdbk-doctor-id="' . esc_attr($doctor_id) . '"' : ''; ?>>
            <div class="mdbk-modal-message"></div>
            <?php $this->render_booking_widget_fields(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render Booking Modal (injected in footer)
     *
     * Skipped when render_form() already put the same widget (same element
     * IDs) inline on this page — see $widget_rendered.
     */
    public function render_modal() {
        if (self::$widget_rendered) {
            return;
        }
        ?>
        <div id="mdbk-booking-modal" class="mdbk-modal-overlay">
            <div class="mdbk-modal-card">
                <div class="mdbk-modal-header">
                    <h3><?php _e('Book Appointment', 'doctor-appointment'); ?></h3>
                    <span class="mdbk-modal-close">&times;</span>
                </div>
                <div class="mdbk-modal-body">
                    <div class="mdbk-modal-message"></div>
                    <?php $this->render_booking_widget_fields(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Shared "Today's Patients" modal for render_doctor_list()'s cards —
     * one instance regardless of how many doctor cards are on the page,
     * populated per click via AJAX (ajax_get_today_patient_summary()).
     * Only printed at all for a logged-in staff/manager/admin/doctor
     * viewer — the same people the button itself is restricted to — so a
     * logged-out visitor's page source never even contains this markup.
     */
    public function render_today_patients_modal() {
        if (!current_user_can(MDBK_CAP_QUEUE) && !current_user_can(MDBK_CAP_DOCTOR)) {
            return;
        }
        ?>
        <div id="mdbk-today-patients-modal" class="mdbk-modal-overlay">
            <div class="mdbk-modal-card">
                <div class="mdbk-modal-header">
                    <h3 id="mdbk-today-patients-modal-title"><?php _e("Today's Patients", 'doctor-appointment'); ?></h3>
                    <span class="mdbk-modal-close" id="mdbk-today-patients-modal-close">&times;</span>
                </div>
                <div class="mdbk-modal-body">
                    <div id="mdbk-today-patients-list"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * "View my booking" status view — reachable from the check-in link in
     * the confirmation email. Runs on every front-end page (same as
     * render_modal(), since there's no dedicated booking page to point the
     * link at) but emits nothing unless ?mdbk_token= is present.
     */
    public function render_status_view() {
        if (!isset($_GET['mdbk_token'])) {
            return;
        }

        $token       = sanitize_text_field(wp_unslash($_GET['mdbk_token']));
        $appointment = \MDBK\MDBK_Appointment_Manager::find_appointment_by_token($token);
        ?>
        <div id="mdbk-status-modal" class="mdbk-modal-overlay" style="display:flex">
            <div class="mdbk-modal-card">
                <div class="mdbk-modal-header">
                    <h3><?php _e('Your Booking', 'doctor-appointment'); ?></h3>
                    <span class="mdbk-modal-close" id="mdbk-status-modal-close">&times;</span>
                </div>
                <div class="mdbk-modal-body">
                <?php if (!$appointment): ?>
                    <p class="mdbk-modal-message mdbk-error" style="display:block"><?php _e('We could not find that booking. The link may be old or the booking may have been removed.', 'doctor-appointment'); ?></p>
                <?php else:
                    $doctor_id = intval(get_post_meta($appointment->ID, '_mdbk_doctor_id', true));
                    $date      = get_post_meta($appointment->ID, '_mdbk_appointment_date', true);
                    $slot_time = get_post_meta($appointment->ID, '_mdbk_slot_time', true);
                    $ticket    = \MDBK\MDBK_Appointment_Manager::format_ticket_number(\MDBK\MDBK_Appointment_Manager::display_ticket_number($appointment->ID));
                    // Empty under check-in-order queue mode until this
                    // patient actually checks in (see
                    // MDBK_Appointment_Manager::queue_serial_mode()) — a
                    // Booking ID (stable from the moment they booked) fills
                    // in for the ticket row until then.
                    $booking_id = \MDBK\MDBK_Appointment_Manager::format_booking_id($appointment->ID);
                    $checked_in = get_post_meta($appointment->ID, '_mdbk_checked_in', true) === 'yes';
                    ?>
                    <div class="mdbk-booking-confirmation" style="display:block">
                        <div class="mdbk-confirmation-icon">&#10003;</div>
                        <h4><?php echo $checked_in ? esc_html__('Checked In', 'doctor-appointment') : esc_html__('Booking Confirmed', 'doctor-appointment'); ?></h4>
                        <div class="mdbk-confirmation-details">
                            <?php if ($ticket): ?>
                            <div class="mdbk-confirmation-row"><span><?php _e('Ticket', 'doctor-appointment'); ?></span><strong><?php echo esc_html($ticket); ?></strong></div>
                            <?php else: ?>
                            <div class="mdbk-confirmation-row"><span><?php _e('Booking ID', 'doctor-appointment'); ?></span><strong><?php echo esc_html($booking_id); ?></strong></div>
                            <?php endif; ?>
                            <div class="mdbk-confirmation-row"><span><?php _e('Patient', 'doctor-appointment'); ?></span><strong><?php echo esc_html(get_post_meta($appointment->ID, '_mdbk_patient_name', true)); ?></strong></div>
                            <div class="mdbk-confirmation-row"><span><?php _e('Doctor', 'doctor-appointment'); ?></span><strong><?php echo esc_html(get_the_title($doctor_id)); ?></strong></div>
                            <div class="mdbk-confirmation-row"><span><?php _e('Date', 'doctor-appointment'); ?></span><strong><?php echo $date ? esc_html(date_i18n(get_option('date_format'), strtotime($date))) : ''; ?></strong></div>
                            <?php if ($slot_time): ?>
                            <div class="mdbk-confirmation-row"><span><?php _e('Time', 'doctor-appointment'); ?></span><strong><?php echo esc_html(date_i18n(get_option('time_format'), strtotime($slot_time))); ?></strong></div>
                            <?php endif; ?>
                        </div>
                        <div class="mdbk-confirmation-qr" id="mdbk-status-qr" data-checkin-url="<?php echo esc_attr(add_query_arg('mdbk_token', $token, home_url('/'))); ?>"></div>
                        <p class="mdbk-confirmation-hint"><?php _e('Show this QR code at check-in.', 'doctor-appointment'); ?></p>
                        <div class="mdbk-confirmation-actions">
                            <button type="button" class="mdbk-confirmation-secondary-btn" id="mdbk-status-download"
                                data-title="<?php echo esc_attr($checked_in ? __('Checked In', 'doctor-appointment') : __('Booking Confirmed', 'doctor-appointment')); ?>"
                                data-ticket="<?php echo esc_attr($ticket); ?>"
                                data-booking-id="<?php echo esc_attr($booking_id); ?>"
                                data-patient-name="<?php echo esc_attr(get_post_meta($appointment->ID, '_mdbk_patient_name', true)); ?>"
                                data-doctor-name="<?php echo esc_attr(get_the_title($doctor_id)); ?>"
                                data-date="<?php echo esc_attr($date ? date_i18n(get_option('date_format'), strtotime($date)) : ''); ?>"
                                data-slot-time="<?php echo esc_attr($slot_time ? date_i18n(get_option('time_format'), strtotime($slot_time)) : ''); ?>"
                            ><?php _e('Download as Image', 'doctor-appointment'); ?></button>
                            <button type="button" class="mdbk-confirmation-secondary-btn" id="mdbk-status-print"><?php _e('Print / Save as PDF', 'doctor-appointment'); ?></button>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var modal = document.getElementById('mdbk-status-modal');
            if (!modal) return;
            document.body.style.overflow = 'hidden';
            var closeBtn = document.getElementById('mdbk-status-modal-close');
            if (closeBtn) closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            });
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            });
            var qrEl = document.getElementById('mdbk-status-qr');
            if (qrEl && typeof qrcode === 'function') {
                var url = qrEl.getAttribute('data-checkin-url');
                var qr = qrcode(0, 'M');
                qr.addData(url);
                qr.make();
                qrEl.innerHTML = qr.createImgTag(5, 4);
            }

            var downloadBtn = document.getElementById('mdbk-status-download');
            if (downloadBtn && typeof mdbkDownloadBookingCard === 'function') {
                downloadBtn.addEventListener('click', function() {
                    var qrImg = qrEl ? qrEl.querySelector('img') : null;
                    mdbkDownloadBookingCard({
                        title: downloadBtn.getAttribute('data-title'),
                        ticket: downloadBtn.getAttribute('data-ticket'),
                        booking_id: downloadBtn.getAttribute('data-booking-id'),
                        patient_name: downloadBtn.getAttribute('data-patient-name'),
                        doctor_name: downloadBtn.getAttribute('data-doctor-name'),
                        date: downloadBtn.getAttribute('data-date'),
                        slot_time: downloadBtn.getAttribute('data-slot-time')
                    }, qrImg ? qrImg.src : '');
                });
            }

            var printBtn = document.getElementById('mdbk-status-print');
            if (printBtn) printBtn.addEventListener('click', function() {
                var qrImg = qrEl ? qrEl.querySelector('img') : null;
                if (typeof mdbkPrintBookingCard === 'function' && downloadBtn) {
                    mdbkPrintBookingCard({
                        title: downloadBtn.getAttribute('data-title'),
                        ticket: downloadBtn.getAttribute('data-ticket'),
                        booking_id: downloadBtn.getAttribute('data-booking-id'),
                        patient_name: downloadBtn.getAttribute('data-patient-name'),
                        doctor_name: downloadBtn.getAttribute('data-doctor-name'),
                        date: downloadBtn.getAttribute('data-date'),
                        slot_time: downloadBtn.getAttribute('data-slot-time')
                    }, qrImg ? qrImg.src : '');
                } else {
                    window.print();
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Doctor's-chamber walk-in check-in landing page — reached by
     * scanning the SAME static QR code posted in a doctor's chamber (one
     * per doctor, not per patient; see
     * MDBK_Admin_Dashboard::render_chamber_qr_page()). Since the QR
     * doesn't identify which patient scanned it, this asks for the phone
     * number they booked with to find their own today's booking — the
     * same identifier already used as the patient identity key in
     * MDBK_Appointment_Manager::find_or_create_patient().
     */
    public function render_chamber_checkin_view() {
        if (!isset($_GET['mdbk_chamber'])) {
            return;
        }

        $token     = sanitize_text_field(wp_unslash($_GET['mdbk_chamber']));
        $doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_by_chamber_token($token);
        $nonce     = wp_create_nonce('mdbk_chamber_checkin');
        ?>
        <div id="mdbk-chamber-modal" class="mdbk-modal-overlay" style="display:flex">
            <div class="mdbk-modal-card">
                <div class="mdbk-modal-header">
                    <h3><?php _e('Check In', 'doctor-appointment'); ?></h3>
                    <span class="mdbk-modal-close" id="mdbk-chamber-modal-close">&times;</span>
                </div>
                <div class="mdbk-modal-body">
                <?php if (!$doctor_id) : ?>
                    <p class="mdbk-modal-message mdbk-error" style="display:block"><?php _e('This check-in QR code is not valid.', 'doctor-appointment'); ?></p>
                <?php else: ?>
                    <p style="margin-bottom:16px;"><?php echo esc_html(sprintf(__('Checking in for %s', 'doctor-appointment'), get_the_title($doctor_id))); ?></p>
                    <div class="mdbk-form-group">
                        <label for="mdbk-chamber-phone"><?php _e('Enter the phone number you booked with', 'doctor-appointment'); ?></label>
                        <input type="tel" id="mdbk-chamber-phone" class="mdbk-form-control" placeholder="<?php esc_attr_e('01XXXXXXXXX', 'doctor-appointment'); ?>" autocomplete="off">
                    </div>
                    <button type="button" class="mdbk-submit-btn" id="mdbk-chamber-submit"><?php _e('Check In', 'doctor-appointment'); ?></button>
                    <div id="mdbk-chamber-result" style="margin-top:16px;"></div>
                <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var modal = document.getElementById('mdbk-chamber-modal');
            if (!modal) return;
            document.body.style.overflow = 'hidden';
            var closeBtn = document.getElementById('mdbk-chamber-modal-close');
            if (closeBtn) closeBtn.addEventListener('click', function() { modal.style.display = 'none'; document.body.style.overflow = ''; });
            modal.addEventListener('click', function(e) { if (e.target === modal) { modal.style.display = 'none'; document.body.style.overflow = ''; } });

            var submitBtn = document.getElementById('mdbk-chamber-submit');
            var resultEl = document.getElementById('mdbk-chamber-result');
            var phoneInput = document.getElementById('mdbk-chamber-phone');
            if (!submitBtn) return;

            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var chamberToken = <?php echo wp_json_encode($token); ?>;

            function submitCheckin(appointmentId) {
                var body = new URLSearchParams();
                body.set('action', 'mdbk_chamber_checkin');
                body.set('nonce', nonce);
                body.set('chamber_token', chamberToken);
                body.set('phone', phoneInput.value);
                if (appointmentId) body.set('appointment_id', appointmentId);

                fetch(ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        resultEl.innerHTML = '';
                        if (!res.success) {
                            var p = document.createElement('p');
                            p.className = 'mdbk-error';
                            p.textContent = (res.data && res.data.message) || '';
                            resultEl.appendChild(p);
                            return;
                        }
                        if (res.data.matches) {
                            var hint = document.createElement('p');
                            hint.textContent = <?php echo wp_json_encode(__('Multiple bookings match this number — select yours:', 'doctor-appointment')); ?>;
                            resultEl.appendChild(hint);
                            res.data.matches.forEach(function(m) {
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'mdbk-btn-add mdbk-btn-sm';
                                btn.style.display = 'block';
                                btn.style.width = '100%';
                                btn.style.marginBottom = '8px';
                                btn.textContent = m.ticket + ' — ' + m.name;
                                btn.addEventListener('click', function() { submitCheckin(m.id); });
                                resultEl.appendChild(btn);
                            });
                            return;
                        }
                        var ok = document.createElement('p');
                        ok.style.color = '#16A34A';
                        ok.style.fontWeight = '600';
                        ok.textContent = res.data.message || '';
                        resultEl.appendChild(ok);
                        submitBtn.style.display = 'none';
                        phoneInput.disabled = true;
                    })
                    .catch(function() {
                        resultEl.innerHTML = '';
                        var p = document.createElement('p');
                        p.className = 'mdbk-error';
                        p.textContent = 'Something went wrong, please try again.';
                        resultEl.appendChild(p);
                    });
            }

            submitBtn.addEventListener('click', function() { submitCheckin(''); });
        })();
        </script>
        <?php
    }

    /**
     * Shared specialty/doctor/booking/details form markup — identical
     * whether it ends up inside the popup modal or rendered inline by the
     * shortcode. Element IDs are fixed (not instance-namespaced): only one
     * of render_modal()/render_form() ever actually outputs this on a given
     * page, so there's never a collision to guard against.
     */
    private function render_booking_widget_fields() {
        // Via the shared helper — NOT a raw get_terms() with 'orderby' =>
        // 'meta_value_num' + 'meta_key' => '_mdbk_specialty_order': that
        // meta_key arg INNER-JOINS termmeta and silently drops any specialty
        // missing an order row (see get_specialty_terms()'s docblock).
        $specialties = \MDBK\MDBK_Appointment_Manager::get_specialty_terms(true);
        // A specialty toggled inactive in wp-admin isn't bookable either
        // (specialties default to active — the meta only ever gets written,
        // to 'no', once someone flips a card's toggle off).
        $specialties = array_values(array_filter($specialties, function($t) {
            return get_term_meta($t->term_id, '_mdbk_specialty_active', true) !== 'no';
        }));
        // get_terms() with hide_empty (and more so combined with the meta
        // orderby above) doesn't guarantee a 0-indexed return array — the
        // surviving terms can keep non-sequential keys, so $specialties[0]
        // below silently returns null whenever index 0 got filtered out.
        // Reindex so index 0 is reliable.
        $specialties = array_values($specialties);
        $first_spec_name = !empty($specialties) ? $specialties[0]->name : '';
        ?>
        <div class="mdbk-booking-confirmation" id="mdbk-booking-confirmation" style="display:none">
            <div class="mdbk-confirmation-icon">&#10003;</div>
            <h4><?php _e('Booking Confirmed', 'doctor-appointment'); ?></h4>
            <div class="mdbk-confirmation-details">
                <div class="mdbk-confirmation-row"><span id="mdbk-conf-ticket-label"><?php _e('Ticket', 'doctor-appointment'); ?></span><strong id="mdbk-conf-ticket"></strong></div>
                <div class="mdbk-confirmation-row"><span><?php _e('Patient', 'doctor-appointment'); ?></span><strong id="mdbk-conf-patient"></strong></div>
                <div class="mdbk-confirmation-row"><span><?php _e('Doctor', 'doctor-appointment'); ?></span><strong id="mdbk-conf-doctor"></strong></div>
                <div class="mdbk-confirmation-row"><span><?php _e('Date', 'doctor-appointment'); ?></span><strong id="mdbk-conf-date"></strong></div>
                <div class="mdbk-confirmation-row" id="mdbk-conf-time-row"><span><?php _e('Time', 'doctor-appointment'); ?></span><strong id="mdbk-conf-time"></strong></div>
            </div>
            <?php // Same notice as the pre-booking preview above — shown here
            // too when this booking's doctor had a hidden picker, so the
            // "approximate" framing carries through to the confirmation
            // instead of the Time row above suddenly reading as a firm,
            // patient-chosen appointment time. form-script.js fills this in. ?>
            <div class="mdbk-approx-time-notice" id="mdbk-conf-approx-time-notice" style="display:none">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <span id="mdbk-conf-approx-time-value"></span>
            </div>
            <div class="mdbk-confirmation-qr" id="mdbk-confirmation-qr"></div>
            <p class="mdbk-confirmation-hint"><?php _e('Show this QR code at check-in.', 'doctor-appointment'); ?></p>
            <div class="mdbk-confirmation-actions">
                <button type="button" class="mdbk-confirmation-secondary-btn" id="mdbk-confirmation-download"><?php _e('Download as Image', 'doctor-appointment'); ?></button>
                <button type="button" class="mdbk-confirmation-secondary-btn" id="mdbk-confirmation-print"><?php _e('Print / Save as PDF', 'doctor-appointment'); ?></button>
            </div>
            <button type="button" class="mdbk-confirmation-close-btn" id="mdbk-confirmation-close"><?php _e('Close', 'doctor-appointment'); ?></button>
        </div>
        <form id="mdbk-modal-form">
            <div class="mdbk-section" id="mdbk-specialty-doctor-section">
                <h4 class="mdbk-section-title"><?php _e('Choose Specialty & Doctor', 'doctor-appointment'); ?></h4>
                <div class="mdbk-doctor-columns">
                    <div class="mdbk-specialty-col">
                        <div class="mdbk-custom-select" id="mdbk-specialty-dropdown">
                            <button type="button" class="mdbk-custom-select-trigger" id="mdbk-specialty-trigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="mdbk-custom-select-value"><?php echo esc_html($first_spec_name); ?></span>
                                <span class="mdbk-custom-select-chevron"></span>
                            </button>
                            <div class="mdbk-custom-select-panel" id="mdbk-specialty-panel" role="listbox" hidden>
                                <?php foreach ($specialties as $index => $spec): ?>
                                    <div class="mdbk-custom-select-option<?php echo $index === 0 ? ' selected' : ''; ?>" role="option" data-value="<?php echo esc_attr($spec->term_id); ?>"><?php echo esc_html($spec->name); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <select name="specialty" id="mdbk-specialty-select" style="display:none">
                            <?php foreach ($specialties as $index => $spec): ?>
                                <option value="<?php echo esc_attr($spec->term_id); ?>" <?php echo $index === 0 ? 'selected' : ''; ?>><?php echo esc_html($spec->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mdbk-doctor-col">
                        <div class="mdbk-doctor-list-modal" id="mdbk-doctor-list"></div>
                        <div class="mdbk-selected-doctor" id="mdbk-selected-doctor" style="display:none"></div>
                        <input type="hidden" name="doctor" id="mdbk-doctor-id" value="">
                    </div>
                </div>
            </div>

            <div class="mdbk-section" id="mdbk-booking-section" style="display:none">
                <h4 class="mdbk-section-title"><?php _e('Pick Date & Time', 'doctor-appointment'); ?></h4>
                <div class="mdbk-booking-columns">
                    <div class="mdbk-calendar-col">
                        <div id="mdbk-calendar"></div>
                        <input type="hidden" name="date" id="mdbk-date-value">
                    </div>
                    <div class="mdbk-time-col">
                        <div id="mdbk-modal-slot-picker" class="mdbk-slot-picker mdbk-slot-picker-disabled">
                            <p class="mdbk-time-placeholder"><?php _e('Select a date first', 'doctor-appointment'); ?></p>
                        </div>
                        <input type="hidden" name="slot_time" id="mdbk-modal-slot-value">
                    </div>
                    <div class="mdbk-datetime-selected" id="mdbk-datetime-selected" style="display:none">
                        <span class="mdbk-datetime-label"><?php _e('Selected:', 'doctor-appointment'); ?></span>
                        <span class="mdbk-datetime-value" id="mdbk-datetime-value"></span>
                        <button type="button" class="mdbk-datetime-change" id="mdbk-datetime-change"><?php _e('Change', 'doctor-appointment'); ?></button>
                    </div>
                </div>
                <?php // Shown only for a hidden-picker doctor (is_slot_enabled()
                // off), once a date is picked — the patient never chose an
                // exact time, so this previews the time they'd likely be
                // seen at (find_next_available_slot(), same server-side
                // logic that assigns the real one on submit) rather than
                // leaving them with no time expectation at all until the
                // confirmation screen. "Approximate" on purpose: the real
                // assignment happens at submit time and could shift if
                // another booking lands first. form-script.js fills this in. ?>
                <div class="mdbk-approx-time-notice" id="mdbk-approx-time-notice" style="display:none">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span id="mdbk-approx-time-value"></span>
                </div>
            </div>

            <?php
            // Global Settings > Booking Form Fields — Full Name/Mobile stay
            // fixed (see MDBK_Appointment_Manager::field_settings()'s own
            // docblock for why); these four are the only ones a site can
            // turn off or make optional.
            $mdbk_show_email = \MDBK\MDBK_Appointment_Manager::is_field_visible('email');
            $mdbk_req_email = \MDBK\MDBK_Appointment_Manager::is_field_required('email');
            $mdbk_show_age = \MDBK\MDBK_Appointment_Manager::is_field_visible('age');
            $mdbk_req_age = \MDBK\MDBK_Appointment_Manager::is_field_required('age');
            $mdbk_show_gender = \MDBK\MDBK_Appointment_Manager::is_field_visible('gender');
            $mdbk_req_gender = \MDBK\MDBK_Appointment_Manager::is_field_required('gender');
            $mdbk_show_address = \MDBK\MDBK_Appointment_Manager::is_field_visible('address');
            $mdbk_req_address = \MDBK\MDBK_Appointment_Manager::is_field_required('address');
            ?>
            <div class="mdbk-section" id="mdbk-details-section" style="display:none">
                <div class="mdbk-card-section">
                    <div class="mdbk-form-group">
                        <label><?php _e('Full Name', 'doctor-appointment'); ?> <span class="mdbk-required">*</span></label>
                        <input type="text" name="full_name" class="mdbk-form-control" placeholder="<?php esc_attr_e('e.g. Shafiul Islam', 'doctor-appointment'); ?>" required>
                    </div>

                    <div class="mdbk-form-row">
                        <div class="mdbk-form-group">
                            <label><?php _e('Mobile Number', 'doctor-appointment'); ?> <span class="mdbk-required">*</span></label>
                            <input type="tel" name="mobile" class="mdbk-form-control" placeholder="<?php esc_attr_e('01XXXXXXXXX', 'doctor-appointment'); ?>" pattern="^(?:\+?880|0)1[3-9]\d{8}$" title="<?php esc_attr_e('Enter a valid Bangladeshi mobile number, e.g. 01XXXXXXXXX', 'doctor-appointment'); ?>" required>
                        </div>
                        <?php if ($mdbk_show_email): ?>
                        <div class="mdbk-form-group">
                            <label><?php _e('Email', 'doctor-appointment'); ?> <?php if ($mdbk_req_email): ?><span class="mdbk-required">*</span><?php else: ?><span class="mdbk-optional"><?php _e('(optional)', 'doctor-appointment'); ?></span><?php endif; ?></label>
                            <input type="email" name="email" class="mdbk-form-control" placeholder="<?php esc_attr_e('you@example.com', 'doctor-appointment'); ?>"<?php echo $mdbk_req_email ? ' required' : ''; ?>>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($mdbk_show_age || $mdbk_show_gender): ?>
                    <div class="mdbk-form-row">
                        <?php if ($mdbk_show_age): ?>
                        <div class="mdbk-form-group">
                            <label><?php _e('Age', 'doctor-appointment'); ?> <?php if ($mdbk_req_age): ?><span class="mdbk-required">*</span><?php else: ?><span class="mdbk-optional"><?php _e('(optional)', 'doctor-appointment'); ?></span><?php endif; ?></label>
                            <input type="number" name="age" class="mdbk-form-control" placeholder="<?php esc_attr_e('Age', 'doctor-appointment'); ?>" min="0" max="120"<?php echo $mdbk_req_age ? ' required' : ''; ?>>
                        </div>
                        <?php endif; ?>
                        <?php if ($mdbk_show_gender): ?>
                        <div class="mdbk-form-group">
                            <label><?php _e('Gender', 'doctor-appointment'); ?> <?php if ($mdbk_req_gender): ?><span class="mdbk-required">*</span><?php else: ?><span class="mdbk-optional"><?php _e('(optional)', 'doctor-appointment'); ?></span><?php endif; ?></label>
                            <div class="mdbk-custom-select" data-custom-select="gender">
                                <button type="button" class="mdbk-custom-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="mdbk-custom-select-value"><?php _e('Male', 'doctor-appointment'); ?></span>
                                    <span class="mdbk-custom-select-chevron"></span>
                                </button>
                                <div class="mdbk-custom-select-panel" role="listbox" hidden>
                                    <div class="mdbk-custom-select-option selected" role="option" data-value="Male"><?php _e('Male', 'doctor-appointment'); ?></div>
                                    <div class="mdbk-custom-select-option" role="option" data-value="Female"><?php _e('Female', 'doctor-appointment'); ?></div>
                                </div>
                                <select name="gender" class="mdbk-form-control" style="display:none"<?php echo $mdbk_req_gender ? ' required' : ''; ?>>
                                    <option value="Male" selected><?php _e('Male', 'doctor-appointment'); ?></option>
                                    <option value="Female"><?php _e('Female', 'doctor-appointment'); ?></option>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php // District + Thana instead of one free-text address box.
                    // Two selects rather than typing means the same place is
                    // always spelled the same way, which is what makes the
                    // data worth anything later. Thana is filled from whichever
                    // district is chosen (the whole map is handed to the page —
                    // see mdbk_form_obj.locations — so changing district costs
                    // no round trip). Visibility/required for the pair as a
                    // whole is one Global Settings switch ("Address"), not
                    // two — District and Thana are always saved together
                    // (find_or_create_patient()), so there's no coherent way
                    // to require one without the other. Server-side the pair
                    // is re-checked against MDBK_BD_Locations::is_valid(),
                    // since a select is as editable as any other input. ?>
                    <?php if ($mdbk_show_address): ?>
                    <div class="mdbk-form-row mdbk-form-group-last">
                        <div class="mdbk-form-group">
                            <label><?php _e('District', 'doctor-appointment'); ?> <?php if ($mdbk_req_address): ?><span class="mdbk-required">*</span><?php else: ?><span class="mdbk-optional"><?php _e('(optional)', 'doctor-appointment'); ?></span><?php endif; ?></label>
                            <div class="mdbk-custom-select" id="mdbk-district-dropdown" data-clearable>
                                <button type="button" class="mdbk-custom-select-trigger" id="mdbk-district-trigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="mdbk-custom-select-value mdbk-select-placeholder"><?php esc_html_e('Select district', 'doctor-appointment'); ?></span>
                                    <span class="mdbk-custom-select-chevron"></span>
                                </button>
                                <div class="mdbk-custom-select-panel" id="mdbk-district-panel" role="listbox" hidden>
                                    <?php foreach (\MDBK\MDBK_BD_Locations::districts() as $bd_district): ?>
                                        <div class="mdbk-custom-select-option" role="option" data-value="<?php echo esc_attr($bd_district); ?>"><?php echo esc_html($bd_district); ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <select name="district" id="mdbk-district-select" style="display:none">
                                    <option value=""></option>
                                    <?php foreach (\MDBK\MDBK_BD_Locations::districts() as $bd_district): ?>
                                        <option value="<?php echo esc_attr($bd_district); ?>"><?php echo esc_html($bd_district); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mdbk-form-group">
                            <label><?php _e('Thana', 'doctor-appointment'); ?> <?php if ($mdbk_req_address): ?><span class="mdbk-required">*</span><?php else: ?><span class="mdbk-optional"><?php _e('(optional)', 'doctor-appointment'); ?></span><?php endif; ?></label>
                            <div class="mdbk-custom-select is-disabled" id="mdbk-thana-dropdown" data-clearable>
                                <button type="button" class="mdbk-custom-select-trigger" id="mdbk-thana-trigger" aria-haspopup="listbox" aria-expanded="false" disabled>
                                    <span class="mdbk-custom-select-value mdbk-select-placeholder"><?php esc_html_e('Select district first', 'doctor-appointment'); ?></span>
                                    <span class="mdbk-custom-select-chevron"></span>
                                </button>
                                <div class="mdbk-custom-select-panel" id="mdbk-thana-panel" role="listbox" hidden></div>
                                <select name="thana" id="mdbk-thana-select" style="display:none"><option value=""></option></select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="mdbk-submit-btn">
                    <?php _e('Book Appointment', 'doctor-appointment'); ?>
                </button>
            </div>
        </form>
        <?php
    }

    /**
     * Render Queue Management
     *
     * Public/kiosk display — scoped to today, per doctor. `doctor` attribute
     * locks the embed to one doctor (e.g. a room's screen); omitted, it
     * defaults to the first doctor and renders a switcher dropdown.
     */
    public function render_queue($atts = []) {
        $atts = shortcode_atts(['doctor' => ''], $atts, 'mdbk_queue_management');
        $locked_doctor_id = intval($atts['doctor']);
        $doctor_id = $locked_doctor_id;

        if (!$doctor_id && isset($_GET['mdbk_doctor_id'])) {
            $doctor_id = intval($_GET['mdbk_doctor_id']);
        }
        if (!$doctor_id) {
            $first_doctor = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => 1, 'orderby' => 'menu_order', 'order' => 'ASC', 'fields' => 'ids']);
            $doctor_id = $first_doctor ? intval($first_doctor[0]) : 0;
        }

        $queue_js_ver = file_exists(MDBK_PATH . 'assets/js/queue-script.js') ? filemtime(MDBK_PATH . 'assets/js/queue-script.js') : MDBK_VERSION;
        wp_enqueue_script('mdbk-queue-script', MDBK_URL . 'assets/js/queue-script.js', [], $queue_js_ver, true);
        wp_localize_script('mdbk-queue-script', 'mdbk_queue_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mdbk_manage_queue'),
        ]);

        ob_start();
        ?>
        <div class="mdbk-queue-container" id="mdbk-queue-app" data-doctor="<?php echo esc_attr($doctor_id); ?>">
            <div class="mdbk-queue-header">
                <h2><?php _e('Queue Management', 'doctor-appointment'); ?></h2>
                <p><?php _e('Real-time patient flow.', 'doctor-appointment'); ?></p>
            </div>

            <div class="mdbk-checkin-box">
                <label for="mdbk-checkin-input"><?php _e('Check-In', 'doctor-appointment'); ?></label>
                <div class="mdbk-checkin-row">
                    <input type="text" id="mdbk-checkin-input" placeholder="<?php esc_attr_e('Scan or paste check-in code', 'doctor-appointment'); ?>" autocomplete="off">
                    <button type="button" id="mdbk-checkin-verify-btn"><?php _e('Verify', 'doctor-appointment'); ?></button>
                </div>
                <div id="mdbk-checkin-result"></div>
            </div>

            <?php if (!$locked_doctor_id) : $doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1]); if ($doctors) : ?>
            <div class="mdbk-queue-doctor-switch">
                <label for="mdbk-queue-doctor-select"><?php _e('Doctor', 'doctor-appointment'); ?></label>
                <select id="mdbk-queue-doctor-select">
                    <?php foreach ($doctors as $d) : ?>
                        <option value="<?php echo esc_attr($d->ID); ?>" <?php selected($doctor_id, $d->ID); ?>><?php echo esc_html($d->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; endif; ?>

            <div id="mdbk-queue-body"><?php echo self::render_queue_body($doctor_id); ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render Queue List (public, read-only)
     *
     * `doctor` attribute (or ?mdbk_doctor_id= already in the URL) locks this
     * to one doctor's full queue list. Omitted entirely, it stacks every
     * active doctor's full list one after another on the same page — this
     * is a deliberate, explicit choice (confirmed with the site owner) to
     * show full per-patient detail for every doctor on one public,
     * unauthenticated page, not just a summary.
     */
    public function render_queue_list($atts = []) {
        // Global Settings' "Enable Live Queue" toggle — when off, skip the
        // nonce/script entirely (not just hide the markup) so the public
        // polling endpoint has nothing live to poll from this page.
        if (get_option('mdbk_enable_live_queue', 'yes') === 'no') {
            return '<p class="mdbk-no-doctors">' . esc_html__('The live queue display is currently turned off.', 'doctor-appointment') . '</p>';
        }

        $atts = shortcode_atts(['doctor' => ''], $atts, 'mdbk_queue_list');
        $doctor_id = intval($atts['doctor']);
        if (!$doctor_id && isset($_GET['mdbk_doctor_id'])) {
            $doctor_id = intval($_GET['mdbk_doctor_id']);
        }

        // Per-doctor override (Today's Queue page's own Live Queue toggle,
        // next to each doctor's name) — a doctor explicitly opted out this
        // way, on an otherwise globally-enabled feature, shown as its own
        // distinct message rather than silently rendering nothing.
        if ($doctor_id && !\MDBK\MDBK_Appointment_Manager::is_doctor_live_queue_enabled($doctor_id)) {
            return '<p class="mdbk-no-doctors">' . esc_html__("This doctor's live queue display is currently turned off.", 'doctor-appointment') . '</p>';
        }

        $queue_js_ver = file_exists(MDBK_PATH . 'assets/js/queue-view-script.js') ? filemtime(MDBK_PATH . 'assets/js/queue-view-script.js') : MDBK_VERSION;
        wp_enqueue_script('mdbk-queue-view-script', MDBK_URL . 'assets/js/queue-view-script.js', [], $queue_js_ver, true);
        wp_localize_script('mdbk-queue-view-script', 'mdbk_queue_view_obj', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mdbk_view_queue'),
            // The "on break" notice is driven off each card's own
            // data-breaks rather than waiting for the next poll to
            // carry it (see render_queue_list_body()), so its wording
            // has to be available client-side too. %s = break name.
            'on_break' => __('On break — %s. Back shortly.', 'doctor-appointment'),
            // Seconds since midnight in the site's timezone, to the
            // millisecond — deliberately not a Unix timestamp; see
            // MDBK_Admin_Dashboard::render_break_countdown_el() for why.
            'now'      => self::server_now_seconds(),
        ]);

        if ($doctor_id) {
            return self::render_queue_list_instance($doctor_id);
        }

        $doctors = get_posts([
            'post_type'   => 'mdbk_doctor',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'meta_query'  => [
                'relation' => 'OR',
                ['key' => '_mdbk_doctor_active', 'compare' => 'NOT EXISTS'],
                ['key' => '_mdbk_doctor_active', 'value' => 'no', 'compare' => '!='],
            ],
        ]);

        // All-doctors mode: a doctor toggled off individually just drops
        // out of this stacked list (same as an inactive doctor already
        // does above), rather than showing an per-instance disabled block
        // among otherwise-live ones.
        $doctors = array_values(array_filter($doctors, function($doctor) {
            return \MDBK\MDBK_Appointment_Manager::is_doctor_live_queue_enabled($doctor->ID);
        }));

        ob_start();
        ?>
        <div class="mdbk-queue-list-all">
            <?php if ($doctors) : ?>
                <?php foreach ($doctors as $doctor) : ?>
                    <?php echo self::render_queue_list_instance($doctor->ID); ?>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="mdbk-no-doctors"><?php _e('No doctors found.', 'doctor-appointment'); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * One doctor's live-polling queue block — used standalone (single-doctor
     * mode) and repeated once per doctor (all-doctors mode). Each instance
     * polls independently via its own `data-doctor`/`.mdbk-queue-body-instance`
     * (see queue-view-script.js), since a page can have more than one.
     * Public (not private) so MDBK_Admin_Dashboard's doctor-restricted "My
     * Queue" page can reuse it too.
     */
    public static function render_queue_list_instance($doctor_id) {
        $body = self::render_queue_list_body($doctor_id);
        ob_start();
        ?>
        <div class="mdbk-queue-container mdbk-queue-app-instance" data-doctor="<?php echo esc_attr($doctor_id); ?>" data-patient-count="<?php echo esc_attr($body['count']); ?>">
            <div class="mdbk-queue-body-instance"><?php echo $body['html']; ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Two-column today's-queue list for one doctor — the public "Live
     * Queue" view. Deliberately separate from render_queue_body() (the
     * staff kiosk's card+action-button layout): this is a plain arrival
     * board — queue number + name per row, no "Now Serving" hero box, no
     * buttons. Each row shows one of three states — currently being served
     * (green), checked in and waiting (orange/"present"), or not yet
     * checked in ("not-present", muted/disabled) — driven by post_status
     * plus the QR check-in meta. A patient leaves this list entirely once
     * completed/no-show (the post_status filter below already excludes
     * both), so the row below naturally moves up into the freed spot.
     *
     * Returns ['html' => ..., 'count' => ...] rather than a plain string —
     * the count is needed alongside the markup by both callers: the
     * initial render (to set data-patient-count, so an empty doctor's card
     * can be hidden in grid mode) and the AJAX poll response (so a poll
     * that brings a doctor's count above zero can reveal their card live).
     */
    public static function render_queue_list_body($doctor_id) {
        $doctor_id = intval($doctor_id);
        $date = current_time('Y-m-d');

        // Not sorted via a top-level 'meta_key' => '_mdbk_ticket_number' +
        // orderby arg — that combination turns into an implicit INNER JOIN
        // requiring the meta row to exist, silently DROPPING (not just
        // leaving unordered) every appointment with no ticket yet, which
        // is the normal state for a not-checked-in patient under check-in-
        // order queue mode (MDBK_Appointment_Manager::queue_serial_mode()).
        // Sorted in PHP instead, via the usort() below (which already had
        // to exist anyway for the serving-first partition).
        $patients = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => ['mdbk_waiting', 'mdbk_serving'],
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_mdbk_appointment_date', 'value' => $date],
                ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
            ],
            'numberposts' => -1,
        ]);

        // Whoever's currently being seen always leads the list, regardless
        // of ticket number — a plain ticket-order sort could otherwise show
        // an earlier-numbered still-waiting patient above the one actually
        // in the room. Only one appointment is ever mdbk_serving per
        // doctor+date (enforced independently by each queue-advance action
        // — ajax_queue_call_next()/ajax_queue_set_status() below, and
        // MDBK_Appointment_Manager::start_visiting() on the admin
        // "Patients" page), so this is a single stable partition, not a
        // general-purpose sort. Everyone else within it: plain ticket order
        // (booking mode, unchanged from before this list's own top-level
        // meta_key sort was dropped above), or
        // MDBK_Appointment_Manager::checkin_order_sort_key() (check-in
        // mode: checked-in patients first in Q order, pending ones after
        // in slot-time order — see its docblock).
        $checkin_mode = \MDBK\MDBK_Appointment_Manager::queue_serial_mode($doctor_id) === 'checkin';
        usort($patients, function($a, $b) use ($checkin_mode) {
            $a_rank = $a->post_status === 'mdbk_serving' ? 0 : 1;
            $b_rank = $b->post_status === 'mdbk_serving' ? 0 : 1;
            if ($a_rank !== $b_rank) return $a_rank <=> $b_rank;
            if ($checkin_mode) {
                return \MDBK\MDBK_Appointment_Manager::checkin_order_sort_key($a->ID) <=> \MDBK\MDBK_Appointment_Manager::checkin_order_sort_key($b->ID);
            }
            $ticket_a = intval(get_post_meta($a->ID, '_mdbk_ticket_number', true));
            $ticket_b = intval(get_post_meta($b->ID, '_mdbk_ticket_number', true));
            if ($ticket_a !== $ticket_b) return $ticket_a <=> $ticket_b;
            return strcmp(
                (string) get_post_meta($a->ID, '_mdbk_slot_time', true),
                (string) get_post_meta($b->ID, '_mdbk_slot_time', true)
            );
        });

        $departments = get_the_terms($doctor_id, 'mdbk_department');
        // Same idea as the pulse dot on the admin Bookings page: lit and
        // pulsing only while this doctor actually has someone in "serving"
        // status right now, so a waiting patient can tell "doctor is with
        // someone" from "doctor's on a break" at a glance. $patients is
        // already sorted serving-first (see the usort() above), so index 0
        // being 'mdbk_serving' is enough — no extra query needed. Kept as a
        // sibling of <h2>, not a child of it — the h2 itself needs its own
        // overflow:hidden/text-overflow:ellipsis for long doctor names,
        // which would otherwise clip the dot's box-shadow ripple right at
        // its left edge (the dot sits flush against the h2's start with no
        // room for the ripple to expand into). Refreshed on every poll by
        // queue-view-script.js explicitly syncing this element — it isn't
        // inside .mdbk-queue-list-columns/-count/-updated, the only three
        // things that script already knew to reconcile.
        $doctor_is_visiting = !empty($patients) && $patients[0]->post_status === 'mdbk_serving';

        // "On break" runs from the break's own start time to its own end
        // time, and nothing else — the same window the admin side's own
        // countdown pill uses (render_break_countdown_el() in
        // admin-dashboard.php), so a patient watching this screen and
        // the staff watching the queue never disagree about whether a
        // break is still on. Also the same window get_available_slots()
        // blocks bookings in (appointment-manager.php), so what a
        // patient is told and what they can book stay one and the same
        // fact.
        //
        // This deliberately replaced an earlier "persist past the end
        // time until the doctor demonstrably resumes" rule, which keyed
        // off the latest slot_time among today's completed/no-show
        // appointments for this doctor. Staff work the queue out of
        // booked order all the time (checked-in patients get seen ahead
        // of their slot), so marking, say, a 2:20 PM patient done at
        // 12:45 pushed that watermark past every break left in the day
        // and silently killed their notices for good. Bounding the
        // notice by the break's own end time makes that guard
        // unnecessary anyway: a finished break can no longer outlive its
        // window, so there is nothing left for it to resurrect.
        //
        // The one live-state gate that remains is $doctor_is_visiting —
        // if someone is being seen *right now*, "on break" would
        // contradict the pulsing dot immediately next to it, and it
        // doubles as the natural "doctor came back early" clear.
        $active_break = null;
        if (!$doctor_is_visiting) {
            $breaks = get_post_meta($doctor_id, '_mdbk_breaks', true);
            if (is_array($breaks)) {
                $now = current_time('H:i');
                foreach ($breaks as $b) {
                    if (empty($b['from']) || empty($b['to'])) continue;
                    if ($now < $b['from'] || $now >= $b['to']) continue;
                    // Overlapping windows shouldn't happen, but if two
                    // are somehow in range at once the later-starting
                    // one is the more current fact.
                    if (!$active_break || $b['from'] > $active_break['from']) {
                        $active_break = $b;
                    }
                }
            }
        }

        // Handed to queue-view-script.js so it can flip the notice on
        // and off at the exact second the window opens and closes,
        // instead of it landing up to a whole poll interval late. Every
        // configured break goes over, not just the one in range now —
        // a waiting-room screen stays open all day and has to reach the
        // next break by itself.
        $breaks_for_js = [];
        $all_breaks = get_post_meta($doctor_id, '_mdbk_breaks', true);
        if (is_array($all_breaks)) {
            foreach ($all_breaks as $b) {
                if (empty($b['from']) || empty($b['to'])) continue;
                $breaks_for_js[] = ['name' => $b['name'], 'from' => $b['from'], 'to' => $b['to']];
            }
        }

        ob_start();
        ?>
        <div class="mdbk-queue-list-card" data-breaks="<?php echo esc_attr(wp_json_encode($breaks_for_js)); ?>">
        <div class="mdbk-queue-list-heading">
            <div class="mdbk-queue-list-heading-main">
                <div class="mdbk-queue-doctor-name-row">
                    <span class="mdbk-live-pulse-dot<?php echo $doctor_is_visiting ? ' mdbk-live-pulse-active' : ''; ?>" title="<?php esc_attr_e('Doctor is currently visiting a patient', 'doctor-appointment'); ?>"></span>
                    <h2><?php echo esc_html(get_the_title($doctor_id)); ?></h2>
                </div>
                <?php if (!empty($departments) && !is_wp_error($departments)) : ?>
                    <span class="mdbk-queue-list-specialty"><?php echo esc_html($departments[0]->name); ?></span>
                <?php endif; ?>
            </div>
            <span class="mdbk-queue-list-count"><?php echo count($patients); ?> <?php _e('patients', 'doctor-appointment'); ?></span>
        </div>

        <?php if ($active_break) : ?>
            <div class="mdbk-queue-break-notice">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                <span><?php echo esc_html(sprintf(__('On break — %s. Back shortly.', 'doctor-appointment'), $active_break['name'])); ?></span>
            </div>
        <?php endif; ?>

        <div class="mdbk-queue-list-columns">
            <?php if (!empty($patients)) : ?>
                <?php foreach ($patients as $patient) :
                    $ticket = \MDBK\MDBK_Appointment_Manager::format_ticket_number(\MDBK\MDBK_Appointment_Manager::display_ticket_number($patient->ID));
                    // No queue number yet under check-in-order mode (only
                    // assigned once this patient actually checks in) — the
                    // Booking ID badge fills that spot instead of a blank one.
                    $number_display = $ticket;
                    if (!$number_display && \MDBK\MDBK_Appointment_Manager::queue_serial_mode(get_post_meta($patient->ID, '_mdbk_doctor_id', true)) === 'checkin') {
                        $number_display = \MDBK\MDBK_Appointment_Manager::format_booking_id($patient->ID);
                    }
                    $name   = self::truncate_patient_name(get_post_meta($patient->ID, '_mdbk_patient_name', true));
                    $checked_in = get_post_meta($patient->ID, '_mdbk_checked_in', true) === 'yes';
                    $skipped = get_post_meta($patient->ID, '_mdbk_skipped', true) === 'yes';
                    // Four states: being examined right now (serving), checked
                    // in but temporarily stepped away (skipped — see the
                    // kiosk's Skip toggle), arrived and waiting their turn
                    // (present), or not yet arrived (not-present) —
                    // post_status already tells us "serving" vs "waiting"
                    // with zero extra queries ($patient is a WP_Post).
                    if ($patient->post_status === 'mdbk_serving') {
                        $status_class = 'mdbk-serving';
                        $status_label = __('Visiting', 'doctor-appointment');
                    } elseif ($skipped) {
                        $status_class = 'mdbk-skipped';
                        $status_label = __('Away', 'doctor-appointment');
                    } elseif ($checked_in) {
                        $status_class = 'mdbk-present';
                        $status_label = __('Waiting', 'doctor-appointment');
                    } else {
                        // "Normal" state — no badge, just the muted/disabled row style.
                        $status_class = 'mdbk-not-present';
                        $status_label = '';
                    }
                    ?>
                    <div class="mdbk-queue-list-row <?php echo esc_attr($status_class); ?>" data-appointment-id="<?php echo esc_attr($patient->ID); ?>">
                        <span class="mdbk-queue-list-number"><?php echo esc_html($number_display); ?></span>
                        <span class="mdbk-queue-list-name"><?php echo esc_html($name); ?></span>
                        <?php if ($status_label) : ?>
                            <span class="mdbk-queue-list-badge"><?php echo esc_html($status_label); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="mdbk-no-doctors"><?php _e('No patients in queue today.', 'doctor-appointment'); ?></p>
            <?php endif; ?>
        </div>

        <div class="mdbk-queue-updated"><?php echo esc_html(sprintf(__('Updated %s', 'doctor-appointment'), date_i18n(get_option('time_format')))); ?></div>
        </div>
        <?php
        return ['html' => ob_get_clean(), 'count' => count($patients)];
    }


    /**
     * The site's wall clock as seconds since midnight, to the
     * millisecond — the baseline queue-view-script.js counts forward
     * from so the "on break" notice flips on the real second rather
     * than on the visitor's own (possibly wrong) system clock.
     *
     * Not a Unix timestamp on purpose: current_time('timestamp')
     * already has the site's GMT offset baked in, so handing it to JS's
     * `new Date(ms)` — which expects true UTC and then re-applies the
     * browser's own timezone — double-applies the offset. "How far into
     * today is it" is all the client actually needs.
     */
    private static function server_now_seconds() {
        $now = new \DateTimeImmutable('now', wp_timezone());
        return round(
            intval($now->format('H')) * 3600
            + intval($now->format('i')) * 60
            + intval($now->format('s'))
            + intval($now->format('u')) / 1000000,
            3
        );
    }

    /**
     * Truncate a patient name to "First L." for public/kiosk display.
     */
    private static function truncate_patient_name($name) {
        $name = trim((string) $name);
        if (!$name) return '';
        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) return $parts[0];
        $first = array_shift($parts);
        $last  = array_shift($parts);
        return $first . ' ' . mb_substr($last, 0, 1) . '.';
    }

    /**
     * Render the "Now Serving" + "Upcoming" queue fragment for a doctor,
     * scoped to today. Shared by the initial shortcode render and the
     * mdbk_get_queue_state / mdbk_queue_call_next / mdbk_queue_set_status
     * AJAX handlers so polling and initial load never drift apart.
     */
    private static function render_queue_body($doctor_id) {
        $doctor_id = intval($doctor_id);
        $date = current_time('Y-m-d');

        $base_meta_query = ['relation' => 'AND', ['key' => '_mdbk_appointment_date', 'value' => $date]];
        if ($doctor_id) {
            $base_meta_query[] = ['key' => '_mdbk_doctor_id', 'value' => $doctor_id];
        }

        $waiting_patients = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => ['mdbk_waiting'],
            'meta_query'  => $base_meta_query,
            'meta_key'    => '_mdbk_slot_time',
            'orderby'     => 'meta_value',
            'order'       => 'ASC',
            'numberposts' => -1,
        ]);

        $serving_patients = get_posts([
            'post_type'   => 'mdbk_appointment',
            'post_status' => ['mdbk_serving'],
            'meta_query'  => $base_meta_query,
            'numberposts' => 1,
        ]);
        $serving = !empty($serving_patients) ? $serving_patients[0] : null;

        ob_start();
        ?>
        <div class="mdbk-queue-stats">
            <div class="mdbk-stat-content">
                <h3><?php _e('Active Queue', 'doctor-appointment'); ?></h3>
                <div class="mdbk-stat-value">
                    <span><?php echo count($waiting_patients) + ($serving ? 1 : 0); ?></span>
                    <span><?php _e('Patients waiting', 'doctor-appointment'); ?></span>
                </div>
            </div>
            <div class="mdbk-badge-live"><?php _e('Live', 'doctor-appointment'); ?></div>
        </div>

        <div class="mdbk-now-serving">
            <div class="mdbk-serving-label"><?php _e('Now Visiting', 'doctor-appointment'); ?></div>
            <div class="mdbk-serving-info">
                <?php if ($serving) :
                    $ticket = \MDBK\MDBK_Appointment_Manager::display_ticket_number($serving->ID);
                    $name   = self::truncate_patient_name(get_post_meta($serving->ID, '_mdbk_patient_name', true));
                    ?>
                    <div class="mdbk-serving-id">#<?php echo $ticket ? esc_html(str_pad($ticket, 2, '0', STR_PAD_LEFT)) : '—'; ?></div>
                    <div class="mdbk-serving-name"><?php echo esc_html($name); ?></div>

                    <div class="mdbk-serving-actions">
                        <button type="button" class="mdbk-btn-complete mdbk-queue-action" data-appointment-id="<?php echo esc_attr($serving->ID); ?>" data-status="completed" data-doctor-id="<?php echo esc_attr($doctor_id); ?>"><?php _e('Complete', 'doctor-appointment'); ?></button>
                        <button type="button" class="mdbk-btn-noshow mdbk-queue-action" data-appointment-id="<?php echo esc_attr($serving->ID); ?>" data-status="no-show" data-doctor-id="<?php echo esc_attr($doctor_id); ?>"><?php _e('No Show', 'doctor-appointment'); ?></button>
                    </div>
                <?php else : ?>
                    <div class="mdbk-empty-serving"><?php _e('No patient currently visiting.', 'doctor-appointment'); ?></div>
                    <?php if (!empty($waiting_patients)) : ?>
                        <button type="button" class="mdbk-btn-complete mdbk-queue-call-next" data-doctor-id="<?php echo esc_attr($doctor_id); ?>"><?php _e('Call Next Patient', 'doctor-appointment'); ?></button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="mdbk-upcoming-section">
            <div class="mdbk-section-title">
                <span><?php _e('Upcoming Patients', 'doctor-appointment'); ?></span>
                <span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($date))); ?></span>
            </div>
            <div class="mdbk-patient-list">
                <?php if (!empty($waiting_patients)) : ?>
                    <?php foreach ($waiting_patients as $patient) :
                        $ticket = \MDBK\MDBK_Appointment_Manager::display_ticket_number($patient->ID);
                        $name   = self::truncate_patient_name(get_post_meta($patient->ID, '_mdbk_patient_name', true));
                        $slot   = get_post_meta($patient->ID, '_mdbk_slot_time', true);
                        $checked_in = get_post_meta($patient->ID, '_mdbk_checked_in', true) === 'yes';
                        $skipped = get_post_meta($patient->ID, '_mdbk_skipped', true) === 'yes';
                        ?>
                        <div class="mdbk-patient-card">
                            <?php // No queue number yet under check-in-order mode (only
                            // assigned once this patient actually checks in) — the
                            // Booking ID fills the badge instead of a bare "—" until then. ?>
                            <?php $show_bid = !$ticket && \MDBK\MDBK_Appointment_Manager::queue_serial_mode(get_post_meta($patient->ID, '_mdbk_doctor_id', true)) === 'checkin'; ?>
                            <div class="mdbk-patient-id<?php echo $show_bid ? ' mdbk-patient-id-bid' : ''; ?>"><?php
                                if ($ticket) {
                                    echo '#' . esc_html(str_pad($ticket, 2, '0', STR_PAD_LEFT));
                                } elseif ($show_bid) {
                                    echo esc_html(\MDBK\MDBK_Appointment_Manager::format_booking_id($patient->ID));
                                } else {
                                    echo '—';
                                }
                            ?></div>
                            <div class="mdbk-patient-details">
                                <h4><?php echo esc_html($name); ?></h4>
                                <?php if ($slot) : ?><p><?php echo esc_html($slot); ?></p><?php endif; ?>
                            </div>
                            <?php if ($skipped) : ?>
                                <div class="mdbk-away-badge"><?php _e('Away', 'doctor-appointment'); ?></div>
                            <?php elseif ($checked_in) : ?>
                                <div class="mdbk-checkedin-badge"><?php _e('Checked In', 'doctor-appointment'); ?></div>
                            <?php endif; ?>
                            <div class="mdbk-patient-actions">
                                <?php if ($checked_in) : ?>
                                    <button type="button" class="mdbk-btn-small mdbk-btn-skip mdbk-queue-toggle-skip<?php echo $skipped ? ' is-skipped' : ''; ?>" data-appointment-id="<?php echo esc_attr($patient->ID); ?>" title="<?php echo $skipped ? esc_attr__('Recall to queue', 'doctor-appointment') : esc_attr__('Skip — temporarily away', 'doctor-appointment'); ?>">
                                        <?php if ($skipped) : ?>
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                                        <?php else : ?>
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="5 4 15 12 5 20 5 4"></polygon><rect x="17" y="4" width="3" height="16"></rect></svg>
                                        <?php endif; ?>
                                    </button>
                                <?php endif; ?>
                                <?php if (!$serving) : ?>
                                    <button type="button" class="mdbk-btn-small mdbk-queue-action" data-appointment-id="<?php echo esc_attr($patient->ID); ?>" data-status="serving" data-doctor-id="<?php echo esc_attr($doctor_id); ?>" title="<?php esc_attr_e('Visit Now', 'doctor-appointment'); ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="6 3 20 12 6 21 6 3"></polygon></svg></button>
                                <?php endif; ?>
                                <button type="button" class="mdbk-btn-small mdbk-btn-red mdbk-queue-action" data-appointment-id="<?php echo esc_attr($patient->ID); ?>" data-status="no-show" data-doctor-id="<?php echo esc_attr($doctor_id); ?>" title="<?php esc_attr_e('No Show', 'doctor-appointment'); ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p style="text-align:center; color:#94a3b8; font-size:14px;"><?php _e('No upcoming patients.', 'doctor-appointment'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="mdbk-queue-updated"><?php echo esc_html(sprintf(__('Updated %s', 'doctor-appointment'), date_i18n(get_option('time_format')))); ?></div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: return the queue fragment for polling (no login required — same
     * public-kiosk trust model the previous plain-POST version already had).
     */
    public function ajax_get_queue_state() {
        // The public read-only queue view (mdbk_queue_list) polls this same
        // action with its own mdbk_view_queue nonce — which must never also
        // work against the 3 mutating actions below, so it's a distinct
        // nonce action, not a shared secret. Which nonce actually verifies
        // is also what decides which fragment to return: the staff kiosk's
        // full card+action-button view, or the public two-column list.
        $is_staff = (bool) check_ajax_referer('mdbk_manage_queue', 'nonce', false);
        if (!$is_staff) {
            check_ajax_referer('mdbk_view_queue', 'nonce');
        }
        $doctor_id = intval($_POST['doctor_id']);
        if ($is_staff) {
            wp_send_json_success(['fragment' => self::render_queue_body($doctor_id)]);
        }
        $body = self::render_queue_list_body($doctor_id);
        // A fresh clock reading on every poll, so a waiting-room screen
        // left running for days re-anchors its "on break" timing off the
        // server every 12 seconds instead of trusting a baseline taken
        // once at page load — see queue-view-script.js's initServerBase().
        wp_send_json_success(['fragment' => $body['html'], 'count' => $body['count'], 'now' => self::server_now_seconds()]);
    }

    /**
     * AJAX: promote the earliest waiting patient (by slot time) to serving.
     * No-op if someone is already being served for this doctor+date scope.
     */
    public function ajax_queue_call_next() {
        check_ajax_referer('mdbk_manage_queue', 'nonce');
        $doctor_id = intval($_POST['doctor_id']);
        $date = current_time('Y-m-d');

        $meta_query = ['relation' => 'AND', ['key' => '_mdbk_appointment_date', 'value' => $date]];
        if ($doctor_id) $meta_query[] = ['key' => '_mdbk_doctor_id', 'value' => $doctor_id];

        $already_serving = get_posts(['post_type' => 'mdbk_appointment', 'post_status' => ['mdbk_serving'], 'meta_query' => $meta_query, 'numberposts' => 1]);
        if ($already_serving) {
            wp_send_json_error(__('Someone is already visiting. Complete or mark no-show first.', 'doctor-appointment'));
        }

        // Excludes anyone currently Skipped (stepped away — see the Skip
        // toggle on each waiting card) — otherwise "Call Next Patient"
        // could call someone who isn't actually in the room right now.
        $waiting = get_posts(['post_type' => 'mdbk_appointment', 'post_status' => ['mdbk_waiting'], 'meta_query' => array_merge($meta_query, [['key' => '_mdbk_skipped', 'compare' => 'NOT EXISTS']]), 'meta_key' => '_mdbk_slot_time', 'orderby' => 'meta_value', 'order' => 'ASC', 'numberposts' => 1]);
        if (!$waiting) {
            wp_send_json_error(__('No patients waiting.', 'doctor-appointment'));
        }

        wp_update_post(['ID' => $waiting[0]->ID, 'post_status' => 'mdbk_serving']);
        wp_send_json_success(['fragment' => self::render_queue_body($doctor_id)]);
    }

    /**
     * AJAX: set a specific appointment's status (complete / no-show / serve
     * this one out of order). Promoting to 'serving' is blocked if someone
     * is already being served for that doctor+date.
     */
    public function ajax_queue_set_status() {
        check_ajax_referer('mdbk_manage_queue', 'nonce');
        $appointment_id = intval($_POST['appointment_id']);
        $status         = sanitize_text_field($_POST['status']);
        $doctor_id      = intval($_POST['doctor_id']);

        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment' || !in_array($status, ['completed', 'no-show', 'serving'], true)) {
            wp_send_json_error(__('Invalid request.', 'doctor-appointment'));
        }

        if ($status === 'serving') {
            $date = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
            $appt_doctor = get_post_meta($appointment_id, '_mdbk_doctor_id', true);
            $meta_query = ['relation' => 'AND', ['key' => '_mdbk_appointment_date', 'value' => $date], ['key' => '_mdbk_doctor_id', 'value' => $appt_doctor]];
            $already_serving = get_posts(['post_type' => 'mdbk_appointment', 'post_status' => ['mdbk_serving'], 'meta_query' => $meta_query, 'numberposts' => 1]);
            if ($already_serving) {
                wp_send_json_error(__('Someone is already visiting.', 'doctor-appointment'));
            }
        }

        wp_update_post(['ID' => $appointment_id, 'post_status' => \MDBK\MDBK_Appointment_Manager::status_slug_to_post_status($status)]);
        wp_send_json_success(['fragment' => self::render_queue_body($doctor_id)]);
    }

    /**
     * AJAX: toggle a checked-in waiting patient's "Skip" flag — for a
     * patient who stepped away (toilet, phone call) after checking in.
     * While skipped, they're excluded from "Call Next Patient"'s
     * candidate query above, but they keep their ticket/place in the
     * list; staff can still serve them directly any time via "Visit Now",
     * which isn't gated on this flag at all. Same public/kiosk trust model
     * as the other queue actions (nonce + nopriv, no current_user_can()).
     */
    public function ajax_queue_toggle_skip() {
        check_ajax_referer('mdbk_manage_queue', 'nonce');

        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        $doctor_id      = isset($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;

        if (!$appointment_id || get_post_type($appointment_id) !== 'mdbk_appointment' || get_post_status($appointment_id) !== 'mdbk_waiting') {
            wp_send_json_error(__('Invalid request.', 'doctor-appointment'));
        }

        if (get_post_meta($appointment_id, '_mdbk_skipped', true) === 'yes') {
            delete_post_meta($appointment_id, '_mdbk_skipped');
        } else {
            update_post_meta($appointment_id, '_mdbk_skipped', 'yes');
        }

        wp_send_json_success(['fragment' => self::render_queue_body($doctor_id)]);
    }

    /**
     * AJAX: redeem a check-in token from the Queue Management kiosk's
     * Check-In box (typed by a USB/Bluetooth QR scanner or pasted by
     * staff). Same trust model as the other queue actions — nonce +
     * nopriv, no current_user_can() — but this one redeems a
     * bearer-token-like secret and returns PII, so it additionally refuses
     * to redeem anything that isn't still in the 'waiting' state, blocking
     * replay against an already-checked-in, completed, or no-show booking.
     */
    public function ajax_verify_checkin() {
        check_ajax_referer('mdbk_manage_queue', 'nonce');

        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        $appointment = \MDBK\MDBK_Appointment_Manager::find_appointment_by_token($token);

        if (!$appointment) {
            wp_send_json_error(__('Check-in code not found.', 'doctor-appointment'));
        }

        $result = \MDBK\MDBK_Appointment_Manager::mark_checked_in($appointment->ID);
        if ($result !== true) {
            wp_send_json_error($result);
        }

        $doctor_id = intval(get_post_meta($appointment->ID, '_mdbk_doctor_id', true));
        $slot_time = get_post_meta($appointment->ID, '_mdbk_slot_time', true);

        wp_send_json_success([
            'patient_name' => get_post_meta($appointment->ID, '_mdbk_patient_name', true),
            'doctor_name'  => get_the_title($doctor_id),
            'ticket'       => \MDBK\MDBK_Appointment_Manager::format_ticket_number(\MDBK\MDBK_Appointment_Manager::display_ticket_number($appointment->ID)),
            'slot_time'    => $slot_time ? date_i18n(get_option('time_format'), strtotime($slot_time)) : '',
            'fragment'     => self::render_queue_body($doctor_id),
        ]);
    }

    /**
     * AJAX: doctor's-chamber walk-in check-in — counterpart to the QR
     * landing page above. Public/nopriv (patient is never logged in),
     * scoped by its own dedicated nonce used for nothing else. The
     * chamber QR only identifies the DOCTOR (same code for every
     * patient), so the phone number submitted here is what actually
     * identifies which booking to check in.
     */
    public function ajax_chamber_checkin() {
        check_ajax_referer('mdbk_chamber_checkin', 'nonce');

        $chamber_token = isset($_POST['chamber_token']) ? sanitize_text_field($_POST['chamber_token']) : '';
        $doctor_id = \MDBK\MDBK_Appointment_Manager::get_doctor_id_by_chamber_token($chamber_token);
        if (!$doctor_id) {
            wp_send_json_error(['message' => __('This check-in QR code is not valid.', 'doctor-appointment')]);
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        if (!$phone) {
            wp_send_json_error(['message' => __('Please enter your phone number.', 'doctor-appointment')]);
        }

        $date = current_time('Y-m-d');
        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;

        if ($appointment_id) {
            // A follow-up pick from a multi-match list — never trust the
            // round-tripped ID alone (it passed through the client), so
            // re-verify it actually belongs to this doctor + phone before
            // finalizing.
            if (get_post_type($appointment_id) !== 'mdbk_appointment'
                || intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true)) !== $doctor_id
                || get_post_meta($appointment_id, '_mdbk_patient_phone', true) !== $phone) {
                wp_send_json_error(['message' => __('Booking not found.', 'doctor-appointment')]);
            }
            $candidate_id = $appointment_id;
        } else {
            $matches = get_posts([
                'post_type'   => 'mdbk_appointment',
                'post_status' => ['mdbk_waiting'],
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_query'  => [
                    'relation' => 'AND',
                    ['key' => '_mdbk_doctor_id', 'value' => $doctor_id],
                    ['key' => '_mdbk_appointment_date', 'value' => $date],
                    ['key' => '_mdbk_patient_phone', 'value' => $phone],
                ],
            ]);
            if (!$matches) {
                wp_send_json_error(['message' => __('No waiting booking found for today with this phone number.', 'doctor-appointment')]);
            }
            if (count($matches) > 1) {
                $list = [];
                foreach ($matches as $id) {
                    $list[] = [
                        'id'     => $id,
                        'name'   => get_post_meta($id, '_mdbk_patient_name', true),
                        'ticket' => \MDBK\MDBK_Appointment_Manager::format_ticket_number(\MDBK\MDBK_Appointment_Manager::display_ticket_number($id)),
                    ];
                }
                wp_send_json_success(['matches' => $list]);
            }
            $candidate_id = $matches[0];
        }

        $result = \MDBK\MDBK_Appointment_Manager::mark_checked_in($candidate_id);
        if ($result !== true) {
            wp_send_json_error(['message' => $result]);
        }

        // Reads the number back AFTER mark_checked_in() — under
        // check-in-order mode that call is what earns this patient a
        // number at all (their arrival position, see
        // checkin_ticket_number()), so it has to be read here rather than
        // captured before.
        $checked_in_name   = get_post_meta($candidate_id, '_mdbk_patient_name', true);
        $checked_in_ticket = \MDBK\MDBK_Appointment_Manager::format_ticket_number(\MDBK\MDBK_Appointment_Manager::display_ticket_number($candidate_id));
        wp_send_json_success([
            'message' => $checked_in_ticket
                ? sprintf(__('%1$s checked in — ticket %2$s.', 'doctor-appointment'), $checked_in_name, $checked_in_ticket)
                : sprintf(__('%s checked in.', 'doctor-appointment'), $checked_in_name),
        ]);
    }
}
new \MDBK\MDBK_Shortcode();
