<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Shortcode {

    public function __construct() {
        add_shortcode('mdbk_appointment_form', [$this, 'render_form']);
        add_shortcode('mdbk_queue_management', [$this, 'render_queue']);
        add_shortcode('mdbk_doctor_list', [$this, 'render_doctor_list']);
        add_action('wp_footer', [$this, 'render_modal']);

        // Queue AJAX endpoints — nopriv because the queue is a public/kiosk
        // display, same trust model the plain-POST version already had
        // (nonce-gated, no login required).
        add_action('wp_ajax_mdbk_get_queue_state', [$this, 'ajax_get_queue_state']);
        add_action('wp_ajax_nopriv_mdbk_get_queue_state', [$this, 'ajax_get_queue_state']);
        add_action('wp_ajax_mdbk_queue_call_next', [$this, 'ajax_queue_call_next']);
        add_action('wp_ajax_nopriv_mdbk_queue_call_next', [$this, 'ajax_queue_call_next']);
        add_action('wp_ajax_mdbk_queue_set_status', [$this, 'ajax_queue_set_status']);
        add_action('wp_ajax_nopriv_mdbk_queue_set_status', [$this, 'ajax_queue_set_status']);
    }

    /**
     * Render Doctor List
     */
    public function render_doctor_list($atts = []) {

        $atts = shortcode_atts([
            'department'  => '',
            'limit'       => -1,
            'orderby'     => 'title',
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
            // once someone flips a card's toggle off in wp-admin.
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_mdbk_doctor_active', 'compare' => 'NOT EXISTS'],
                ['key' => '_mdbk_doctor_active', 'value' => 'no', 'compare' => '!='],
            ],
        ];

        if (!empty($atts['department'])) {
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

                                        <?php foreach ($schedule as $day => $time) : ?>

                                            <?php if (!empty($time['active'])) : ?>

                                                <li>
                                                    <span class="day">
                                                        <?php echo esc_html($day); ?>
                                                    </span>

                                                    <span class="time">
                                                        <?php echo esc_html($time['from'] ?? ''); ?>
                                                        -
                                                        <?php echo esc_html($time['to'] ?? ''); ?>
                                                    </span>
                                                </li>

                                            <?php endif; ?>

                                        <?php endforeach; ?>

                                    </ul>

                                </div>

                            <?php endif; ?>

                            <div class="mdbk-doctor-footer">

                                <button type="button" class="mdbk-doctor-book-btn mdbk-book-trigger"
                                data-mdbk-doctor-id="<?php echo esc_attr($doctor_id); ?>">

                                    <?php _e('Book Appointment', 'doctor-appointment'); ?>

                                </button>

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
        ], $atts, 'mdbk_appointment_form');

        $doctor_id = $atts['doctor'] !== '' ? absint($atts['doctor']) : (isset($_GET['doctor']) ? absint(wp_unslash($_GET['doctor'])) : 0);

        self::$widget_rendered = true;

        ob_start();
        ?>
        <div id="mdbk-booking-inline" class="mdbk-booking-inline"<?php echo $doctor_id ? ' data-mdbk-doctor-id="' . esc_attr($doctor_id) . '"' : ''; ?>>
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
     * Shared specialty/doctor/booking/details form markup — identical
     * whether it ends up inside the popup modal or rendered inline by the
     * shortcode. Element IDs are fixed (not instance-namespaced): only one
     * of render_modal()/render_form() ever actually outputs this on a given
     * page, so there's never a collision to guard against.
     */
    private function render_booking_widget_fields() {
        $specialties = get_terms([
            'taxonomy'   => 'mdbk_department',
            'hide_empty' => false,
        ]);
        $first_spec_name = !empty($specialties) ? $specialties[0]->name : '';
        ?>
        <form id="mdbk-modal-form">
            <div class="mdbk-section">
                <h4 class="mdbk-section-title"><?php _e('Choose Specialty', 'doctor-appointment'); ?></h4>
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

            <div class="mdbk-section">
                <h4 class="mdbk-section-title"><?php _e('Choose Doctor', 'doctor-appointment'); ?></h4>
                <div class="mdbk-doctor-list-modal" id="mdbk-doctor-list"></div>
                <div class="mdbk-selected-doctor" id="mdbk-selected-doctor" style="display:none"></div>
                <input type="hidden" name="doctor" id="mdbk-doctor-id" value="">
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
                </div>
            </div>

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
                        <div class="mdbk-form-group">
                            <label><?php _e('Email', 'doctor-appointment'); ?> <span class="mdbk-optional"><?php _e('(optional)', 'doctor-appointment'); ?></span></label>
                            <input type="email" name="email" class="mdbk-form-control" placeholder="<?php esc_attr_e('you@example.com', 'doctor-appointment'); ?>">
                        </div>
                    </div>

                    <div class="mdbk-form-row">
                        <div class="mdbk-form-group">
                            <label><?php _e('Age', 'doctor-appointment'); ?></label>
                            <input type="number" name="age" class="mdbk-form-control" placeholder="<?php esc_attr_e('Age', 'doctor-appointment'); ?>">
                        </div>
                        <div class="mdbk-form-group">
                            <label><?php _e('Gender', 'doctor-appointment'); ?></label>
                            <select name="gender" class="mdbk-form-control">
                                <option value="Male"><?php _e('Male', 'doctor-appointment'); ?></option>
                                <option value="Female"><?php _e('Female', 'doctor-appointment'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="mdbk-form-group mdbk-form-group-last">
                        <label><?php _e('Description of Symptoms', 'doctor-appointment'); ?></label>
                        <textarea name="symptoms" class="mdbk-form-control" rows="3" placeholder="<?php esc_attr_e('Briefly describe your symptoms...', 'doctor-appointment'); ?>"></textarea>
                    </div>
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

        if (!$doctor_id && isset($_GET['mdbk_doctor'])) {
            $doctor_id = intval($_GET['mdbk_doctor']);
        }
        if (!$doctor_id) {
            $first_doctor = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids']);
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
            <div class="mdbk-serving-label"><?php _e('Now Serving', 'doctor-appointment'); ?></div>
            <div class="mdbk-serving-info">
                <?php if ($serving) :
                    $ticket = get_post_meta($serving->ID, '_mdbk_ticket_number', true);
                    $name   = self::truncate_patient_name(get_post_meta($serving->ID, '_mdbk_patient_name', true));
                    ?>
                    <div class="mdbk-serving-id">#<?php echo $ticket ? esc_html(str_pad($ticket, 2, '0', STR_PAD_LEFT)) : '—'; ?></div>
                    <div class="mdbk-serving-name"><?php echo esc_html($name); ?></div>

                    <div class="mdbk-serving-actions">
                        <button type="button" class="mdbk-btn-complete mdbk-queue-action" data-appointment-id="<?php echo esc_attr($serving->ID); ?>" data-status="completed" data-doctor-id="<?php echo esc_attr($doctor_id); ?>"><?php _e('Complete', 'doctor-appointment'); ?></button>
                        <button type="button" class="mdbk-btn-noshow mdbk-queue-action" data-appointment-id="<?php echo esc_attr($serving->ID); ?>" data-status="no-show" data-doctor-id="<?php echo esc_attr($doctor_id); ?>"><?php _e('No Show', 'doctor-appointment'); ?></button>
                    </div>
                <?php else : ?>
                    <div class="mdbk-empty-serving"><?php _e('No patient currently being served.', 'doctor-appointment'); ?></div>
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
                        $ticket = get_post_meta($patient->ID, '_mdbk_ticket_number', true);
                        $name   = self::truncate_patient_name(get_post_meta($patient->ID, '_mdbk_patient_name', true));
                        $slot   = get_post_meta($patient->ID, '_mdbk_slot_time', true);
                        ?>
                        <div class="mdbk-patient-card">
                            <div class="mdbk-patient-id">#<?php echo $ticket ? esc_html(str_pad($ticket, 2, '0', STR_PAD_LEFT)) : '—'; ?></div>
                            <div class="mdbk-patient-details">
                                <h4><?php echo esc_html($name); ?></h4>
                                <?php if ($slot) : ?><p><?php echo esc_html($slot); ?></p><?php endif; ?>
                            </div>
                            <div class="mdbk-patient-actions">
                                <?php if (!$serving) : ?>
                                    <button type="button" class="mdbk-btn-small mdbk-queue-action" data-appointment-id="<?php echo esc_attr($patient->ID); ?>" data-status="serving" data-doctor-id="<?php echo esc_attr($doctor_id); ?>" title="<?php esc_attr_e('Serve Now', 'doctor-appointment'); ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="6 3 20 12 6 21 6 3"></polygon></svg></button>
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
        check_ajax_referer('mdbk_manage_queue', 'nonce');
        $doctor_id = intval($_POST['doctor_id']);
        wp_send_json_success(['fragment' => self::render_queue_body($doctor_id)]);
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
            wp_send_json_error(__('Someone is already being served. Complete or mark no-show first.', 'doctor-appointment'));
        }

        $waiting = get_posts(['post_type' => 'mdbk_appointment', 'post_status' => ['mdbk_waiting'], 'meta_query' => $meta_query, 'meta_key' => '_mdbk_slot_time', 'orderby' => 'meta_value', 'order' => 'ASC', 'numberposts' => 1]);
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
                wp_send_json_error(__('Someone is already being served.', 'doctor-appointment'));
            }
        }

        wp_update_post(['ID' => $appointment_id, 'post_status' => \MDBK\MDBK_Appointment_Manager::status_slug_to_post_status($status)]);
        wp_send_json_success(['fragment' => self::render_queue_body($doctor_id)]);
    }
}
new \MDBK\MDBK_Shortcode();
