<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Shortcode {

    public function __construct() {
        add_shortcode('mdbk_appointment_form', [$this, 'render_form']);
        add_shortcode('mdbk_queue_management', [$this, 'render_queue']);
    }

    /**
     * Render the Appointment Form
     */
    public function render_form() {
        ob_start();

        $success_msg = '';
        $error_msg   = '';

        // Handle POST Submission
        if (isset($_POST['mdbk_submit_appointment']) && wp_verify_nonce($_POST['mdbk_form_nonce'], 'mdbk_submit_form')) {
            $required_fields = ['full_name', 'mobile', 'doctor', 'date'];
            $valid = true;
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    $valid = false;
                    break;
                }
            }

            if ($valid) {
                $appointment_id = MDBK_Appointment_Manager::handle_submission($_POST);
                if ($appointment_id) {
                    $success_msg = __('Appointment booked successfully! We will contact you soon.', 'doctor-appointment');
                } else {
                    $error_msg = __('Something went wrong. Please try again.', 'doctor-appointment');
                }
            } else {
                $error_msg = __('Please fill in all required fields.', 'doctor-appointment');
            }
        }

        // Fetch Specialties
        $specialties = get_terms([
            'taxonomy'   => 'mdbk_department',
            'hide_empty' => false,
        ]);

        // Fetch Doctors
        $doctors = get_posts([
            'post_type'   => 'mdbk_doctor',
            'numberposts' => -1,
        ]);

        ?>
        <div class="mdbk-booking-form">
            <h2><?php _e('Book Appointment', 'doctor-appointment'); ?></h2>
            <p class="description"><?php _e('Fill in the details below to schedule your consultation.', 'doctor-appointment'); ?></p>

            <?php if ($success_msg): ?>
                <div class="mdbk-message mdbk-success"><?php echo $success_msg; ?></div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="mdbk-message mdbk-error"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <form action="" method="POST">
                <?php wp_nonce_field('mdbk_submit_form', 'mdbk_form_nonce'); ?>

                <!-- Specialty -->
                <div class="mdbk-form-group">
                    <label><?php _e('Select Specialty', 'doctor-appointment'); ?></label>
                    <div class="mdbk-specialty-options">
                        <?php foreach ($specialties as $index => $spec): ?>
                            <div class="mdbk-specialty-item">
                                <input type="radio" name="specialty" id="spec-<?php echo $spec->term_id; ?>" value="<?php echo $spec->term_id; ?>" <?php echo $index === 0 ? 'checked' : ''; ?>>
                                <label for="spec-<?php echo $spec->term_id; ?>"><?php echo $spec->name; ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Doctor -->
                <div class="mdbk-form-group">
                    <label><?php _e('Doctor', 'doctor-appointment'); ?></label>
                    <select name="doctor" class="mdbk-form-control" required>
                        <option value=""><?php _e('Select a practitioner', 'doctor-appointment'); ?></option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?php echo $doctor->ID; ?>"><?php echo $doctor->post_title; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Full Name -->
                <div class="mdbk-form-group">
                    <label><?php _e('Full Name', 'doctor-appointment'); ?></label>
                    <input type="text" name="full_name" class="mdbk-form-control" placeholder="e.g. John Doe" required>
                </div>

                <!-- Age & Mobile -->
                <div class="mdbk-form-row">
                    <div class="mdbk-form-group">
                        <label><?php _e('Age', 'doctor-appointment'); ?></label>
                        <input type="number" name="age" class="mdbk-form-control" placeholder="Age">
                    </div>
                    <div class="mdbk-form-group">
                        <label><?php _e('Mobile Number', 'doctor-appointment'); ?></label>
                        <input type="text" name="mobile" class="mdbk-form-control" placeholder="+1 (555) 000-0000" required>
                    </div>
                </div>

                <!-- Gender -->
                <div class="mdbk-form-group">
                    <label><?php _e('Gender', 'doctor-appointment'); ?></label>
                    <div class="mdbk-gender-options">
                        <div class="mdbk-gender-item">
                            <input type="radio" name="gender" id="gender-male" value="Male" checked>
                            <label for="gender-male"><?php _e('Male', 'doctor-appointment'); ?></label>
                        </div>
                        <div class="mdbk-gender-item">
                            <input type="radio" name="gender" id="gender-female" value="Female">
                            <label for="gender-female"><?php _e('Female', 'doctor-appointment'); ?></label>
                        </div>
                        <div class="mdbk-gender-item">
                            <input type="radio" name="gender" id="gender-other" value="Other">
                            <label for="gender-other"><?php _e('Other', 'doctor-appointment'); ?></label>
                        </div>
                    </div>
                </div>

                <!-- Preferred Date -->
                <div class="mdbk-form-group">
                    <label><?php _e('Preferred Date', 'doctor-appointment'); ?></label>
                    <input type="date" name="date" class="mdbk-form-control" required>
                </div>

                <!-- Symptoms -->
                <div class="mdbk-form-group">
                    <label><?php _e('Description of Symptoms', 'doctor-appointment'); ?></label>
                    <textarea name="symptoms" class="mdbk-form-control" rows="4" placeholder="Briefly describe your symptoms or reason for visit..."></textarea>
                </div>

                <button type="submit" name="mdbk_submit_appointment" class="mdbk-submit-btn">
                    <?php _e('Book Appointment', 'doctor-appointment'); ?>
                </button>

                <a href="#" class="mdbk-cancel-link"><?php _e('Cancel', 'doctor-appointment'); ?></a>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render Queue Management
     */
    public function render_queue() {
        ob_start();

        // Handle Status Updates
        if (isset($_POST['mdbk_update_status']) && wp_verify_nonce($_POST['mdbk_queue_nonce'], 'mdbk_manage_queue')) {
            $appointment_id = intval($_POST['appointment_id']);
            $new_status     = sanitize_text_field($_POST['new_status']);
            update_post_meta($appointment_id, '_mdbk_status', $new_status);
        }

        // Fetch Queue Data (More robust query)
        $waiting_patients = get_posts([
            'post_type'      => 'mdbk_appointment',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_mdbk_status',
                    'value'   => 'waiting',
                    'compare' => '='
                ],
                [
                    'key'     => '_mdbk_status',
                    'compare' => 'NOT EXISTS'
                ]
            ],
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'numberposts'    => -1
        ]);

        $serving_patient = get_posts([
            'post_type'   => 'mdbk_appointment',
            'meta_key'    => '_mdbk_status',
            'meta_value'  => 'serving',
            'numberposts' => 1
        ]);

        // Auto-promote first waiting to serving if none is serving
        if (empty($serving_patient) && !empty($waiting_patients)) {
            $next_up = $waiting_patients[0];
            update_post_meta($next_up->ID, '_mdbk_status', 'serving');
            $serving_patient = [$next_up];
            array_shift($waiting_patients); // Remove from upcoming
        }

        $serving = !empty($serving_patient) ? $serving_patient[0] : null;

        ?>
        <div class="mdbk-queue-container">
            <div class="mdbk-queue-header">
                <h2><?php _e('Queue Management', 'doctor-appointment'); ?></h2>
                <p><?php _e('Real-time patient flow.', 'doctor-appointment'); ?></p>
            </div>

            <!-- Stats -->
            <div class="mdbk-queue-stats">
                <div class="mdbk-stat-content">
                    <h3><?php _e('Active Queue', 'doctor-appointment'); ?></h3>
                    <div class="mdbk-stat-value">
                        <span><?php echo count($waiting_patients) + ($serving ? 1 : 0); ?></span>
                        <span><?php _e('Patients waiting', 'doctor-appointment'); ?></span>
                    </div>
                </div>
                <div class="mdbk-badge-live">Live</div>
            </div>

            <!-- Now Serving -->
            <div class="mdbk-now-serving">
                <div class="mdbk-serving-label"><?php _e('Now Serving', 'doctor-appointment'); ?></div>
                <div class="mdbk-serving-info">
                    <?php if ($serving): 
                        $p_name = get_post_meta($serving->ID, '_mdbk_patient_name', true);
                        $p_phone = get_post_meta($serving->ID, '_mdbk_patient_phone', true);
                        ?>
                        <div class="mdbk-serving-id">#<?php echo str_pad($serving->ID % 100, 2, '0', STR_PAD_LEFT); ?></div>
                        <div class="mdbk-serving-name"><?php echo esc_html($p_name); ?></div>
                        <div class="mdbk-serving-phone"><?php echo esc_html($p_phone); ?></div>

                        <div class="mdbk-serving-actions">
                            <form action="" method="POST">
                                <?php wp_nonce_field('mdbk_manage_queue', 'mdbk_queue_nonce'); ?>
                                <input type="hidden" name="appointment_id" value="<?php echo $serving->ID; ?>">
                                <input type="hidden" name="mdbk_update_status" value="1">
                                <button type="submit" name="new_status" value="completed" class="mdbk-btn-complete">
                                    <?php _e('Complete', 'doctor-appointment'); ?>
                                </button>
                                <button type="submit" name="new_status" value="no-show" class="mdbk-btn-noshow">
                                    <?php _e('No Show', 'doctor-appointment'); ?>
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="mdbk-empty-serving">
                            <?php _e('No patient currently being served.', 'doctor-appointment'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upcoming -->
            <div class="mdbk-upcoming-section">
                <div class="mdbk-section-title">
                    <span><?php _e('Upcoming Patients', 'doctor-appointment'); ?></span>
                    <span><?php _e('Est. Wait: --', 'doctor-appointment'); ?></span>
                </div>
                <div class="mdbk-patient-list">
                    <?php if (!empty($waiting_patients)): ?>
                        <?php foreach ($waiting_patients as $patient): 
                            $u_name = get_post_meta($patient->ID, '_mdbk_patient_name', true);
                            $u_phone = get_post_meta($patient->ID, '_mdbk_patient_phone', true);
                            ?>
                            <div class="mdbk-patient-card">
                                <div class="mdbk-patient-id">#<?php echo str_pad($patient->ID % 100, 2, '0', STR_PAD_LEFT); ?></div>
                                <div class="mdbk-patient-details">
                                    <h4><?php echo esc_html($u_name); ?></h4>
                                    <p>📱 <?php echo esc_html($u_phone); ?></p>
                                </div>
                                <div class="mdbk-patient-actions">
                                    <form action="" method="POST" style="margin:0;">
                                        <?php wp_nonce_field('mdbk_manage_queue', 'mdbk_queue_nonce'); ?>
                                        <input type="hidden" name="appointment_id" value="<?php echo $patient->ID; ?>">
                                        <input type="hidden" name="mdbk_update_status" value="1">
                                        <button type="submit" name="new_status" value="serving" class="mdbk-btn-small" title="Serve Now">▶</button>
                                        <button type="submit" name="new_status" value="no-show" class="mdbk-btn-small mdbk-btn-red" title="No Show">✕</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center; color:#94a3b8; font-size:14px;"><?php _e('No upcoming patients.', 'doctor-appointment'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <a href="?refresh=<?php echo time(); ?>" class="mdbk-refresh-btn">
                🔄 <?php _e('Refresh Queue', 'doctor-appointment'); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}

new \MDBK\MDBK_Shortcode();
