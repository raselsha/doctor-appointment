<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Appointment_Manager {

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_boxes']);
        add_filter('manage_mdbk_appointment_posts_columns', [$this, 'add_columns']);
        add_action('manage_mdbk_appointment_posts_custom_column', [$this, 'render_columns'], 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_mdbk_get_doctors_by_specialty', [$this, 'get_doctors_by_specialty']);
        add_action('wp_ajax_nopriv_mdbk_get_doctors_by_specialty', [$this, 'get_doctors_by_specialty']);
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
        $status         = get_post_meta($post->ID, '_mdbk_status', true);
        $status         = $status ? $status : 'waiting';
        $app_date      = get_post_meta($post->ID, '_mdbk_appointment_date', true);
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

            <div class="mdbk-meta-field">
                <label><?php _e('Appointment Date', 'doctor-appointment'); ?></label>
                <input type="date" name="mdbk_appointment_date" value="<?php echo esc_attr($app_date); ?>">
            </div>

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

        $fields = [
            'mdbk_patient_name'   => '_mdbk_patient_name',
            'mdbk_patient_age'    => '_mdbk_patient_age',
            'mdbk_patient_phone'  => '_mdbk_patient_phone',
            'mdbk_patient_gender' => '_mdbk_patient_gender',
            'mdbk_status'         => '_mdbk_status',
            'mdbk_appointment_date' => '_mdbk_appointment_date',
            'mdbk_doctor_id'      => '_mdbk_doctor_id',
            'mdbk_symptoms'       => '_mdbk_symptoms'
        ];

        foreach ($fields as $key => $meta_key) {
            if (isset($_POST[$key])) {
                update_post_meta($post_id, $meta_key, sanitize_text_field($_POST[$key]));
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
                echo get_post_meta($post_id, '_mdbk_appointment_date', true);
                break;
            case 'status':
                $status = get_post_meta($post_id, '_mdbk_status', true);
                $status = $status ? $status : 'waiting';
                echo ucfirst($status);
                break;
        }
    }

    /**
     * Handle Frontend Submission
     */
    public static function handle_submission($data) {
        $appointment_id = wp_insert_post([
            'post_type'   => 'mdbk_appointment',
            'post_title'  => sprintf(__('Booking: %s', 'doctor-appointment'), sanitize_text_field($data['full_name'])),
            'post_status' => 'publish',
        ]);

        if (is_wp_error($appointment_id)) return false;

        update_post_meta($appointment_id, '_mdbk_patient_name', sanitize_text_field($data['full_name']));
        update_post_meta($appointment_id, '_mdbk_patient_age', sanitize_text_field($data['age']));
        update_post_meta($appointment_id, '_mdbk_patient_phone', sanitize_text_field($data['mobile']));
        update_post_meta($appointment_id, '_mdbk_patient_gender', sanitize_text_field($data['gender']));
        update_post_meta($appointment_id, '_mdbk_status', 'waiting');
        update_post_meta($appointment_id, '_mdbk_appointment_date', sanitize_text_field($data['date']));
        update_post_meta($appointment_id, '_mdbk_doctor_id', intval($data['doctor']));
        update_post_meta($appointment_id, '_mdbk_symptoms', sanitize_textarea_field($data['symptoms']));

        return $appointment_id;
    }

    /**
     * AJAX: Get Doctors by Specialty
     */
    public function get_doctors_by_specialty() {
        check_ajax_referer('mdbk_form_nonce', 'nonce');
        
        $spec_id = intval($_POST['specialty_id']);
        
        $doctors = get_posts([
            'post_type' => 'mdbk_doctor',
            'numberposts' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'mdbk_department',
                    'field'    => 'term_id',
                    'terms'    => $spec_id
                ]
            ]
        ]);

        $options = '<option value="">' . __('Select a practitioner', 'doctor-appointment') . '</option>';
        if ($doctors) {
            foreach ($doctors as $doctor) {
                $options .= sprintf('<option value="%d">%s</option>', $doctor->ID, $doctor->post_title);
            }
            wp_send_json_success($options);
        } else {
            wp_send_json_error(__('No doctors found for this specialty.', 'doctor-appointment'));
        }
    }
}

new \MDBK\MDBK_Appointment_Manager();
