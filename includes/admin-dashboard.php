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
    }

    public function handle_delete_actions() {
        if (!isset($_GET['action'])) return;
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) return;
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
        $post_data = ['post_title' => sanitize_text_field($_POST['doc_name']), 'post_type' => 'mdbk_doctor', 'post_status' => 'publish'];
        if ($doctor_id) $post_data['ID'] = $doctor_id;
        $id = $doctor_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_doc_email', sanitize_email($_POST['doc_email']));
            update_post_meta($id, '_mdbk_doc_phone', sanitize_text_field($_POST['doc_phone']));
            if (isset($_POST['schedule'])) update_post_meta($id, '_mdbk_schedule', $_POST['schedule']);
            if (isset($_POST['specialty'])) wp_set_object_terms($id, [intval($_POST['specialty'])], 'mdbk_department');
            wp_redirect(admin_url('admin.php?page=mdbk-doctors&success=1'));
            exit;
        }
    }

    public function handle_patient_save() {
        if (!isset($_POST['mdbk_save_patient'])) return;
        $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        $post_data = ['post_title' => sanitize_text_field($_POST['patient_name']), 'post_type' => 'mdbk_patient', 'post_status' => 'publish'];
        if ($patient_id) $post_data['ID'] = $patient_id;
        $id = $patient_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_patient_phone', sanitize_text_field($_POST['patient_phone']));
            update_post_meta($id, '_mdbk_patient_email', sanitize_email($_POST['patient_email']));
            update_post_meta($id, '_mdbk_patient_address', sanitize_textarea_field($_POST['patient_address']));
            wp_redirect(admin_url('admin.php?page=mdbk-patients&success=1'));
            exit;
        }
    }

    public function handle_appointment_save() {
        if (!isset($_POST['mdbk_save_appointment'])) return;
        $app_id = !empty($_POST['app_id']) ? intval($_POST['app_id']) : 0;
        $p_name = sanitize_text_field($_POST['patient_name']);
        $p_phone = sanitize_text_field($_POST['patient_phone']);
        $existing_patient = get_posts(['post_type' => 'mdbk_patient', 'meta_query' => [['key' => '_mdbk_patient_phone', 'value' => $p_phone]], 'numberposts' => 1]);
        $patient_id = $existing_patient ? $existing_patient[0]->ID : wp_insert_post(['post_title' => $p_name, 'post_type' => 'mdbk_patient', 'post_status' => 'publish']);
        if (!$existing_patient) update_post_meta($patient_id, '_mdbk_patient_phone', $p_phone);
        $post_data = ['post_title' => "Booking: " . $p_name, 'post_type' => 'mdbk_appointment', 'post_status' => 'publish'];
        if ($app_id) $post_data['ID'] = $app_id;
        $id = $app_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_patient_id', $patient_id);
            update_post_meta($id, '_mdbk_patient_name', $p_name);
            update_post_meta($id, '_mdbk_patient_phone', $p_phone);
            update_post_meta($id, '_mdbk_doctor_id', intval($_POST['doctor_id']));
            update_post_meta($id, '_mdbk_appointment_date', sanitize_text_field($_POST['app_date']));
            update_post_meta($id, '_mdbk_status', sanitize_text_field($_POST['status']));
            wp_redirect(admin_url('admin.php?page=mdbk-schedule&success=1'));
            exit;
        }
    }

    public function handle_specialty_save() {
        if (!isset($_POST['mdbk_save_specialty'])) return;
        $term_id = !empty($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $name = sanitize_text_field($_POST['spec_name']);
        $term_id ? wp_update_term($term_id, 'mdbk_department', ['name' => $name]) : wp_insert_term($name, 'mdbk_department');
        wp_redirect(admin_url('admin.php?page=mdbk-specialties&success=1'));
        exit;
    }

    public function register_admin_menu() {
        add_menu_page('MedBook', 'MedBook', 'manage_options', 'mdbk-dashboard', [$this, 'render_dashboard'], 'dashicons-plus-alt', 25);
        add_submenu_page('mdbk-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'mdbk-dashboard', [$this, 'render_dashboard']);
        $hidden_pages = ['mdbk-doctors' => 'render_doctors_page', 'mdbk-schedule' => 'render_schedule_page', 'mdbk-patients' => 'render_patients_page', 'mdbk-specialties' => 'render_specialties_page'];
        foreach($hidden_pages as $slug => $cb) add_submenu_page(null, $slug, $slug, 'manage_options', $slug, [$this, $cb]);
    }

    public function render_dashboard() {
        $stats = ['doctors' => wp_count_posts('mdbk_doctor')->publish, 'appointments' => wp_count_posts('mdbk_appointment')->publish, 'patients' => wp_count_posts('mdbk_patient')->publish];
        $today = date('Y-m-d');
        $today_apps = get_posts(['post_type' => 'mdbk_appointment', 'meta_query' => [['key' => '_mdbk_appointment_date', 'value' => $today, 'compare' => '=']]]);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('dashboard'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><div class="mdbk-header-left"><h1><?php _e('Medical Overview', 'doctor-appointment'); ?></h1><p><?php _e('Track your daily operations.', 'doctor-appointment'); ?></p></div><div class="mdbk-header-right"><input type="text" class="mdbk-search-box" placeholder="<?php esc_attr_e('Quick search...', 'doctor-appointment'); ?>"><a href="#" class="mdbk-btn-add mdbk-add-appointment"><?php _e('+ New Booking', 'doctor-appointment'); ?></a></div></div>
                <div class="mdbk-stats-grid">
                    <div class="mdbk-stat-card"><h4><?php _e('Doctors', 'doctor-appointment'); ?></h4><div class="value"><?php echo esc_html($stats['doctors']); ?></div><div class="trend positive"><?php _e('↑ practitioners', 'doctor-appointment'); ?></div></div>
                    <div class="mdbk-stat-card"><h4><?php _e('Patients', 'doctor-appointment'); ?></h4><div class="value"><?php echo esc_html($stats['patients']); ?></div><div class="trend"><?php _e('Total records', 'doctor-appointment'); ?></div></div>
                    <div class="mdbk-stat-card"><h4><?php _e('Bookings', 'doctor-appointment'); ?></h4><div class="value"><?php echo esc_html($stats['appointments']); ?></div><div class="trend positive"><?php _e('Total processed', 'doctor-appointment'); ?></div></div>
                    <div class="mdbk-stat-card"><h4><?php _e('Rating', 'doctor-appointment'); ?></h4><div class="value">4.9</div><div class="trend"><?php _e('★ score', 'doctor-appointment'); ?></div></div>
                </div>
                <div class="mdbk-card"><div class="mdbk-card-header"><h3><?php _e("Today's Booked Patients", 'doctor-appointment'); ?></h3></div><table class="mdbk-table"><thead><tr><th><?php _e('Patient', 'doctor-appointment'); ?></th><th><?php _e('Doctor', 'doctor-appointment'); ?></th><th><?php _e('Status', 'doctor-appointment'); ?></th></tr></thead><tbody>
                <?php if($today_apps): foreach($today_apps as $app): $doc_id = get_post_meta($app->ID, '_mdbk_doctor_id', true); $status = get_post_meta($app->ID, '_mdbk_status', true); ?>
                <tr><td><strong><?php echo esc_html(get_post_meta($app->ID, '_mdbk_patient_name', true)); ?></strong></td><td><?php echo $doc_id ? esc_html(get_the_title($doc_id)) : 'N/A'; ?></td><td><span class="mdbk-badge mdbk-badge-blue"><?php echo esc_html(ucfirst($status)); ?></span></td></tr>
                <?php endforeach; else: ?><tr><td colspan="3" style="text-align:center; padding: 40px; opacity:0.6;"><?php _e('No bookings for today.', 'doctor-appointment'); ?></td></tr><?php endif; ?>
                </tbody></table></div>
            </div></div><?php $this->render_appointment_modal_html(); ?></div>
        <?php
    }

    public function render_doctors_page() {
        $doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1]);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('doctors'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('Staff Management', 'doctor-appointment'); ?></h1><a href="#" class="mdbk-btn-add mdbk-add-doctor"><?php _e('+ Add New Doctor', 'doctor-appointment'); ?></a></div>
                <div class="mdbk-card"><table class="mdbk-table"><thead><tr><th><?php _e('Name', 'doctor-appointment'); ?></th><th><?php _e('Specialty', 'doctor-appointment'); ?></th><th><?php _e('Actions', 'doctor-appointment'); ?></th></tr></thead><tbody>
                <?php foreach($doctors as $d): $spec = get_the_terms($d->ID, 'mdbk_department'); $spec_name = $spec ? $spec[0]->name : 'General'; $phone = get_post_meta($d->ID, '_mdbk_doc_phone', true); $email = get_post_meta($d->ID, '_mdbk_doc_email', true); $schedule = get_post_meta($d->ID, '_mdbk_schedule', true); ?>
                <tr data-id="<?php echo esc_attr($d->ID); ?>" data-name="<?php echo esc_attr($d->post_title); ?>" data-email="<?php echo esc_attr($email); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-schedule='<?php echo esc_attr(json_encode($schedule)); ?>'>
                    <td><strong><?php echo esc_html($d->post_title); ?></strong></td><td><span class="mdbk-badge"><?php echo esc_html($spec_name); ?></span></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-doctor" data-id="<?php echo esc_attr($d->ID); ?>">✎</a><a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-doctors&action=mdbk_delete_doctor&id='.$d->ID)); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')">🗑</a></div></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            </div></div><?php $this->render_doctor_modal_html(); ?></div>
        <?php
    }

    public function render_schedule_page() {
        $apps = get_posts(['post_type' => 'mdbk_appointment', 'numberposts' => -1]);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('schedule'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('Patient Bookings', 'doctor-appointment'); ?></h1><a href="#" class="mdbk-btn-add mdbk-add-appointment"><?php _e('+ New Booking', 'doctor-appointment'); ?></a></div>
                <div class="mdbk-card"><table class="mdbk-table"><thead><tr><th><?php _e('Patient', 'doctor-appointment'); ?></th><th><?php _e('Doctor', 'doctor-appointment'); ?></th><th><?php _e('Status', 'doctor-appointment'); ?></th><th><?php _e('Actions', 'doctor-appointment'); ?></th></tr></thead><tbody>
                <?php foreach($apps as $a): $p_name = get_post_meta($a->ID, '_mdbk_patient_name', true); $doc_id = get_post_meta($a->ID, '_mdbk_doctor_id', true); $date = get_post_meta($a->ID, '_mdbk_appointment_date', true); $status = get_post_meta($a->ID, '_mdbk_status', true); ?>
                <tr data-id="<?php echo esc_attr($a->ID); ?>" data-patient="<?php echo esc_attr($p_name); ?>" data-phone="<?php echo esc_attr(get_post_meta($a->ID, '_mdbk_patient_phone', true)); ?>" data-doctor="<?php echo esc_attr($doc_id); ?>" data-date="<?php echo esc_attr($date); ?>" data-status="<?php echo esc_attr($status); ?>">
                    <td><strong><?php echo esc_html($p_name); ?></strong></td><td><?php echo $doc_id ? esc_html(get_the_title($doc_id)) : 'N/A'; ?></td><td><span class="mdbk-badge mdbk-badge-blue"><?php echo esc_html(ucfirst($status)); ?></span></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-appointment" data-id="<?php echo esc_attr($a->ID); ?>">✎</a><a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule&action=mdbk_delete_appointment&id='.$a->ID)); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')">🗑</a></div></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            </div></div><?php $this->render_appointment_modal_html(); ?></div>
        <?php
    }

    public function render_patients_page() {
        $patients = get_posts(['post_type' => 'mdbk_patient', 'numberposts' => -1]);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('patients'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('Patient Directory', 'doctor-appointment'); ?></h1><a href="#" class="mdbk-btn-add mdbk-add-patient"><?php _e('+ Add Patient', 'doctor-appointment'); ?></a></div>
                <div class="mdbk-card"><table class="mdbk-table"><thead><tr><th><?php _e('Name', 'doctor-appointment'); ?></th><th><?php _e('Phone', 'doctor-appointment'); ?></th><th><?php _e('Actions', 'doctor-appointment'); ?></th></tr></thead><tbody>
                <?php foreach($patients as $p): $phone = get_post_meta($p->ID, '_mdbk_patient_phone', true); $email = get_post_meta($p->ID, '_mdbk_patient_email', true); $address = get_post_meta($p->ID, '_mdbk_patient_address', true); ?>
                <tr data-id="<?php echo esc_attr($p->ID); ?>" data-name="<?php echo esc_attr($p->post_title); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-email="<?php echo esc_attr($email); ?>" data-address="<?php echo esc_attr($address); ?>">
                    <td><strong><?php echo esc_html($p->post_title); ?></strong></td><td><?php echo esc_html($phone); ?></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-patient" data-id="<?php echo esc_attr($p->ID); ?>">✎</a><a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-patients&action=mdbk_delete_patient&id='.$p->ID)); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')">🗑</a></div></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
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
                <?php foreach($terms as $t): ?><tr data-id="<?php echo esc_attr($t->term_id); ?>" data-name="<?php echo esc_attr($t->name); ?>"><td><strong><?php echo esc_html($t->name); ?></strong></td><td><?php echo esc_html($t->count); ?> <?php _e('doctors', 'doctor-appointment'); ?></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-specialty" data-id="<?php echo esc_attr($t->term_id); ?>">✎</a><a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-specialties&action=mdbk_delete_specialty&id='.$t->term_id)); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')">🗑</a></div></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div></div><?php $this->render_specialty_modal_html(); ?></div>
        <?php
    }

    private function render_sidebar($active_page) {
        ?>
        <div class="mdbk-sidebar"><div class="mdbk-sidebar-logo">MedBook</div><ul class="mdbk-sidebar-menu">
            <li class="mdbk-menu-item <?php echo $active_page == 'dashboard' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-dashboard')); ?>'"><?php _e('Dashboard', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'doctors' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-doctors')); ?>'"><?php _e('Doctors', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'schedule' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule')); ?>'"><?php _e('Booking', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'patients' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-patients')); ?>'"><?php _e('Patients', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'specialties' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-specialties')); ?>'"><?php _e('Specialties', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item"><?php _e('Global Settings', 'doctor-appointment'); ?></li>
        </ul><div class="mdbk-sidebar-footer"><div class="mdbk-user-avatar"></div><div class="mdbk-user-info"><div style="font-weight: 700; font-size: 13px;"><?php _e('Dr. Admin', 'doctor-appointment'); ?></div><div style="font-size: 11px; opacity: 0.6;"><?php _e('Medical Center', 'doctor-appointment'); ?></div></div></div></div>
        <?php
    }

    private function render_doctor_modal_html() { ?>
        <div id="mdbk-doctor-modal" class="mdbk-modal"><div class="mdbk-modal-content"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Doctor Profile', 'doctor-appointment'); ?></h2><form id="mdbk-doctor-form" method="POST"><input type="hidden" name="doctor_id" id="mdbk-doctor-id"><div class="mdbk-form-section">
            <div class="mdbk-input-group"><label><?php _e('Full Name', 'doctor-appointment'); ?></label><input type="text" name="doc_name" id="mdbk-doc-name" class="mdbk-input" required></div>
            <div class="mdbk-input-group"><label><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="doc_email" id="mdbk-doc-email" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="doc_phone" id="mdbk-doc-phone" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Specialty', 'doctor-appointment'); ?></label><select name="specialty" id="mdbk-doc-spec" class="mdbk-input"><?php foreach(get_terms(['taxonomy'=>'mdbk_department','hide_empty'=>false]) as $t) echo "<option value='".esc_attr($t->term_id)."'>".esc_html($t->name)."</option>"; ?></select></div>
            <div class="mdbk-schedule-setup"><label style="display:block; margin-bottom:15px; font-weight:800; font-size:15px;"><?php _e('Weekly Availability', 'doctor-appointment'); ?></label>
            <?php foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
            <div class="mdbk-schedule-row"><div class="mdbk-day-label"><label class="mdbk-switch"><input type="checkbox" name="schedule[<?php echo esc_attr($day); ?>][active]" value="1" class="mdbk-day-check"><span class="mdbk-slider"></span></label><span><?php echo esc_html($day); ?></span></div><div class="mdbk-time-inputs"><input type="time" name="schedule[<?php echo esc_attr($day); ?>][from]" class="mdbk-time-field"><span class="mdbk-time-separator"><?php _e('to', 'doctor-appointment'); ?></span><input type="time" name="schedule[<?php echo esc_attr($day); ?>][to]" class="mdbk-time-field"></div></div>
            <?php endforeach; ?></div>
        </div><button type="submit" name="mdbk_save_doctor" class="mdbk-btn-save"><?php _e('Save Profile', 'doctor-appointment'); ?></button></form></div></div>
    <?php }

    private function render_patient_modal_html() { ?>
        <div id="mdbk-patient-modal" class="mdbk-modal"><div class="mdbk-modal-content"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Patient Record', 'doctor-appointment'); ?></h2><form id="mdbk-patient-form" method="POST"><input type="hidden" name="patient_id" id="mdbk-patient-id"><div class="mdbk-form-section">
            <div class="mdbk-input-group"><label><?php _e('Full Name', 'doctor-appointment'); ?></label><input type="text" name="patient_name" id="mdbk-patient-name" class="mdbk-input" required></div>
            <div class="mdbk-input-group"><label><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-patient-phone" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="patient_email" id="mdbk-patient-email" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Address', 'doctor-appointment'); ?></label><textarea name="patient_address" id="mdbk-patient-address" class="mdbk-input"></textarea></div>
        </div><button type="submit" name="mdbk_save_patient" class="mdbk-btn-save"><?php _e('Save Record', 'doctor-appointment'); ?></button></form></div></div>
    <?php }

    private function render_appointment_modal_html() { ?>
        <div id="mdbk-appointment-modal" class="mdbk-modal"><div class="mdbk-modal-content"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Booking', 'doctor-appointment'); ?></h2><form id="mdbk-appointment-form" method="POST"><input type="hidden" name="app_id" id="mdbk-app-id"><div class="mdbk-form-section">
            <div class="mdbk-input-group"><label><?php _e('Patient', 'doctor-appointment'); ?></label><input type="text" name="patient_name" id="mdbk-app-patient" class="mdbk-input" required></div>
            <div class="mdbk-input-group"><label><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-app-phone" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Doctor', 'doctor-appointment'); ?></label><select name="doctor_id" id="mdbk-app-doctor" class="mdbk-input"><?php foreach(get_posts(['post_type'=>'mdbk_doctor','numberposts'=>-1]) as $d) echo "<option value='".esc_attr($d->ID)."'>".esc_html($d->post_title)."</option>"; ?></select></div>
            <div class="mdbk-input-group"><label><?php _e('Date', 'doctor-appointment'); ?></label><input type="date" name="app_date" id="mdbk-app-date" class="mdbk-input" required></div>
            <div class="mdbk-input-group"><label><?php _e('Status', 'doctor-appointment'); ?></label><select name="status" id="mdbk-app-status" class="mdbk-input"><option value="waiting"><?php _e('Waiting', 'doctor-appointment'); ?></option><option value="serving"><?php _e('Serving', 'doctor-appointment'); ?></option><option value="completed"><?php _e('Completed', 'doctor-appointment'); ?></option><option value="no-show"><?php _e('No Show', 'doctor-appointment'); ?></option></select></div>
        </div><button type="submit" name="mdbk_save_appointment" class="mdbk-btn-save"><?php _e('Save Booking', 'doctor-appointment'); ?></button></form></div></div>
    <?php }

    private function render_specialty_modal_html() { ?>
        <div id="mdbk-specialty-modal" class="mdbk-modal"><div class="mdbk-modal-content" style="max-width:400px;"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Specialty', 'doctor-appointment'); ?></h2><form id="mdbk-specialty-form" method="POST"><input type="hidden" name="term_id" id="mdbk-spec-id"><div class="mdbk-input-group"><label><?php _e('Name', 'doctor-appointment'); ?></label><input type="text" name="spec_name" id="mdbk-spec-name" class="mdbk-input" required></div><button type="submit" name="mdbk_save_specialty" class="mdbk-btn-save"><?php _e('Save Specialty', 'doctor-appointment'); ?></button></form></div></div>
    <?php }
}
new MDBK_Admin_Dashboard();
