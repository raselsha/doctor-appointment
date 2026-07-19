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
        add_action('wp_ajax_mdbk_toggle_doctor_active', [$this, 'ajax_toggle_doctor_active']);
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
        if (!current_user_can('manage_options')) wp_die(__('You do not have permission to do this.', 'doctor-appointment'));
        check_admin_referer('mdbk_save_doctor');
        $doctor_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        $post_data = ['post_title' => sanitize_text_field($_POST['doc_name']), 'post_type' => 'mdbk_doctor', 'post_status' => 'publish'];
        if ($doctor_id) $post_data['ID'] = $doctor_id;
        $id = $doctor_id ? wp_update_post($post_data) : wp_insert_post($post_data);
        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_doc_email', sanitize_email($_POST['doc_email']));
            update_post_meta($id, '_mdbk_doc_phone', sanitize_text_field($_POST['doc_phone']));
            update_post_meta($id, '_mdbk_doc_bio', sanitize_textarea_field($_POST['doc_bio']));
            update_post_meta($id, '_mdbk_show_phone', isset($_POST['show_phone']) ? 'yes' : 'no');
            update_post_meta($id, '_mdbk_show_email', isset($_POST['show_email']) ? 'yes' : 'no');
            if (!empty($_POST['slot_duration'])) update_post_meta($id, '_mdbk_slot_duration', intval($_POST['slot_duration']));
            if (isset($_POST['schedule'])) update_post_meta($id, '_mdbk_schedule', $_POST['schedule']);
            if (isset($_POST['specialty'])) wp_set_object_terms($id, [intval($_POST['specialty'])], 'mdbk_department');
            $photo_id = !empty($_POST['photo_id']) ? intval($_POST['photo_id']) : 0;
            if ($photo_id) { set_post_thumbnail($id, $photo_id); } else { delete_post_thumbnail($id); }
            wp_redirect(admin_url('admin.php?page=mdbk-doctors&success=1'));
            exit;
        }
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
        $old_doctor_id = intval(get_post_meta($app_id, '_mdbk_doctor_id', true));

        if (\MDBK\MDBK_Appointment_Manager::is_slot_taken($doctor_id, $date, $slot_time, $app_id)) {
            wp_redirect(admin_url('admin.php?page=mdbk-schedule&error=' . urlencode(__('That time slot is already booked.', 'doctor-appointment'))));
            exit;
        }

        $p_email = isset($_POST['patient_email']) ? sanitize_email($_POST['patient_email']) : '';
        $patient_id = \MDBK\MDBK_Appointment_Manager::find_or_create_patient($p_name, $p_phone, ['email' => $p_email]);

        $post_status = \MDBK\MDBK_Appointment_Manager::status_slug_to_post_status(sanitize_text_field($_POST['status']));
        $id = wp_update_post(['ID' => $app_id, 'post_title' => "Booking: " . $p_name, 'post_status' => $post_status]);

        if ($id && !is_wp_error($id)) {
            update_post_meta($id, '_mdbk_patient_id', $patient_id);
            update_post_meta($id, '_mdbk_patient_name', $p_name);
            update_post_meta($id, '_mdbk_patient_phone', $p_phone);
            update_post_meta($id, '_mdbk_doctor_id', $doctor_id);
            update_post_meta($id, '_mdbk_appointment_date', $date);
            update_post_meta($id, '_mdbk_slot_time', $slot_time);
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
        add_menu_page('MedBook', 'MedBook', 'manage_options', 'mdbk-dashboard', [$this, 'render_dashboard'], 'dashicons-plus-alt', 25);
        add_submenu_page('mdbk-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'mdbk-dashboard', [$this, 'render_dashboard']);

        // Queue/appointments gets its own top-level menu (rather than a
        // hidden submenu of the manage_options-only MedBook parent) so the
        // front-desk role, which lacks manage_options, can still reach it.
        add_menu_page(__('Queue', 'doctor-appointment'), __('Queue', 'doctor-appointment'), MDBK_CAP_QUEUE, 'mdbk-schedule', [$this, 'render_schedule_page'], 'dashicons-groups', 26);

        $hidden_pages = ['mdbk-doctors' => 'render_doctors_page', 'mdbk-patients' => 'render_patients_page', 'mdbk-specialties' => 'render_specialties_page'];
        foreach($hidden_pages as $slug => $cb) add_submenu_page(null, $slug, $slug, 'manage_options', $slug, [$this, $cb]);
    }

    public function render_dashboard() {
        $appointment_count = array_sum(array_map(function($status) {
            return (int) wp_count_posts('mdbk_appointment')->$status;
        }, \MDBK\MDBK_CPT::APPOINTMENT_STATUSES));
        $stats = ['doctors' => wp_count_posts('mdbk_doctor')->publish, 'appointments' => $appointment_count, 'patients' => wp_count_posts('mdbk_patient')->publish];
        $today = current_time('Y-m-d');
        $today_apps = get_posts(['post_type' => 'mdbk_appointment', 'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES, 'meta_query' => [['key' => '_mdbk_appointment_date', 'value' => $today, 'compare' => '=']]]);
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
                <?php if($today_apps): foreach($today_apps as $app): $doc_id = get_post_meta($app->ID, '_mdbk_doctor_id', true); $status = \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($app)); ?>
                <tr><td><strong><?php echo esc_html(get_post_meta($app->ID, '_mdbk_patient_name', true)); ?></strong></td><td><?php echo $doc_id ? esc_html(get_the_title($doc_id)) : 'N/A'; ?></td><td><span class="mdbk-badge mdbk-badge-blue"><?php echo esc_html(ucfirst($status)); ?></span></td></tr>
                <?php endforeach; else: ?><tr><td colspan="3" style="text-align:center; padding: 40px; opacity:0.6;"><?php _e('No bookings for today.', 'doctor-appointment'); ?></td></tr><?php endif; ?>
                </tbody></table></div>
            </div></div><?php $this->render_appointment_modal_html(); ?></div>
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
        // Doctors default to active — the meta only ever gets written (to 'no')
        // the first time someone flips the card's toggle off.
        $active = get_post_meta($d->ID, '_mdbk_doctor_active', true) !== 'no';
        $thumb = get_the_post_thumbnail_url($d->ID, 'thumbnail');
        $thumb_id = get_post_thumbnail_id($d->ID);
        $colors = self::specialty_colors($spec_id);
        ob_start();
        ?>
        <div class="mdbk-admin-doctor-card<?php echo $active ? '' : ' is-inactive'; ?>" data-id="<?php echo esc_attr($d->ID); ?>" data-name="<?php echo esc_attr($d->post_title); ?>" data-email="<?php echo esc_attr($email); ?>" data-phone="<?php echo esc_attr($phone); ?>" data-bio="<?php echo esc_attr($bio); ?>" data-show-phone="<?php echo esc_attr($show_phone ? $show_phone : 'yes'); ?>" data-show-email="<?php echo esc_attr($show_email ? $show_email : 'yes'); ?>" data-schedule='<?php echo esc_attr(json_encode($schedule)); ?>' data-slot-duration="<?php echo esc_attr($slot_duration ? $slot_duration : 20); ?>" data-specialty="<?php echo esc_attr($spec_id); ?>" data-thumbnail="<?php echo esc_url($thumb ?: ''); ?>" data-thumbnail-id="<?php echo esc_attr($thumb_id ?: 0); ?>">
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
                <div class="mdbk-admin-doctor-card-status">
                    <label class="mdbk-toggle mdbk-admin-doctor-active-toggle">
                        <input type="checkbox" <?php checked($active); ?>>
                        <span class="mdbk-toggle-slider"></span>
                        <span class="mdbk-admin-doctor-active-text"><?php echo $active ? esc_html__('Active', 'doctor-appointment') : esc_html__('Inactive', 'doctor-appointment'); ?></span>
                    </label>
                </div>
                <div class="mdbk-admin-doctor-card-actions">
                    <a href="#" class="mdbk-icon-btn mdbk-view-doctor" data-id="<?php echo esc_attr($d->ID); ?>" title="<?php esc_attr_e('View', 'doctor-appointment'); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </a>
                    <a href="#" class="mdbk-icon-btn mdbk-edit-doctor" data-id="<?php echo esc_attr($d->ID); ?>" title="<?php esc_attr_e('Edit', 'doctor-appointment'); ?>">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path></svg>
                    </a>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-doctors&action=mdbk_delete_doctor&id=' . $d->ID), 'mdbk_delete_action')); ?>" class="mdbk-icon-btn mdbk-icon-btn-danger" title="<?php esc_attr_e('Delete', 'doctor-appointment'); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this doctor?', 'doctor-appointment')); ?>')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>
                    </a>
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

    public function render_schedule_page() {
        // Bug: get_posts() with no post_status defaults to 'publish' — none
        // of mdbk_waiting/mdbk_serving/mdbk_completed/mdbk_no_show is ever
        // 'publish', so this silently returned zero rows regardless of how
        // many bookings existed. Always pass the explicit status list.
        $filter_doctor = isset($_GET['filter_doctor']) ? intval($_GET['filter_doctor']) : 0;
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';

        $args = [
            'post_type'   => 'mdbk_appointment',
            'numberposts' => -1,
            'post_status' => \MDBK\MDBK_CPT::APPOINTMENT_STATUSES,
            'meta_key'    => '_mdbk_appointment_date',
            'orderby'     => 'meta_value',
            'order'       => 'DESC',
        ];
        if ($filter_doctor) {
            $args['meta_query'] = [['key' => '_mdbk_doctor_id', 'value' => $filter_doctor]];
        }
        if ($filter_status) {
            $mapped_status = \MDBK\MDBK_Appointment_Manager::status_slug_to_post_status($filter_status);
            if (in_array($mapped_status, \MDBK\MDBK_CPT::APPOINTMENT_STATUSES, true)) {
                $args['post_status'] = [$mapped_status];
            }
        }

        $apps = get_posts($args);
        $all_doctors = get_posts(['post_type' => 'mdbk_doctor', 'numberposts' => -1]);
        ?>
        <div id="mdbk-admin-dashboard"><div class="mdbk-admin-wrapper"><?php $this->render_sidebar('schedule'); ?>
            <div class="mdbk-main-content">
                <div class="mdbk-header"><h1><?php _e('Patient Bookings', 'doctor-appointment'); ?></h1><a href="#" class="mdbk-btn-add mdbk-add-appointment"><?php _e('+ New Booking', 'doctor-appointment'); ?></a></div>
                <form method="get" style="display:flex; gap:10px; align-items:center; margin-bottom:16px;">
                    <input type="hidden" name="page" value="mdbk-schedule">
                    <select name="filter_doctor" class="mdbk-input" style="width:auto;">
                        <option value=""><?php _e('All Doctors', 'doctor-appointment'); ?></option>
                        <?php foreach ($all_doctors as $d) : ?>
                            <option value="<?php echo esc_attr($d->ID); ?>" <?php selected($filter_doctor, $d->ID); ?>><?php echo esc_html($d->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_status" class="mdbk-input" style="width:auto;">
                        <option value=""><?php _e('All Statuses', 'doctor-appointment'); ?></option>
                        <option value="waiting" <?php selected($filter_status, 'waiting'); ?>><?php _e('Waiting', 'doctor-appointment'); ?></option>
                        <option value="serving" <?php selected($filter_status, 'serving'); ?>><?php _e('Serving', 'doctor-appointment'); ?></option>
                        <option value="completed" <?php selected($filter_status, 'completed'); ?>><?php _e('Completed', 'doctor-appointment'); ?></option>
                        <option value="no-show" <?php selected($filter_status, 'no-show'); ?>><?php _e('No Show', 'doctor-appointment'); ?></option>
                    </select>
                    <button type="submit" class="mdbk-btn-add"><?php _e('Filter', 'doctor-appointment'); ?></button>
                    <?php if ($filter_doctor || $filter_status) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule')); ?>" class="mdbk-action-btn"><?php _e('Clear', 'doctor-appointment'); ?></a>
                    <?php endif; ?>
                </form>
                <div class="mdbk-card"><table class="mdbk-table"><thead><tr><th><?php _e('#', 'doctor-appointment'); ?></th><th><?php _e('Patient', 'doctor-appointment'); ?></th><th><?php _e('Doctor', 'doctor-appointment'); ?></th><th><?php _e('When', 'doctor-appointment'); ?></th><th><?php _e('Status', 'doctor-appointment'); ?></th><th><?php _e('Actions', 'doctor-appointment'); ?></th></tr></thead><tbody>
                <?php if ($apps) : foreach($apps as $a): $p_name = get_post_meta($a->ID, '_mdbk_patient_name', true); $doc_id = get_post_meta($a->ID, '_mdbk_doctor_id', true); $date = get_post_meta($a->ID, '_mdbk_appointment_date', true); $slot_time = get_post_meta($a->ID, '_mdbk_slot_time', true); $ticket = get_post_meta($a->ID, '_mdbk_ticket_number', true); $status = \MDBK\MDBK_Appointment_Manager::post_status_to_slug(get_post_status($a)); $patient_id = get_post_meta($a->ID, '_mdbk_patient_id', true); $p_email = $patient_id ? get_post_meta($patient_id, '_mdbk_patient_email', true) : ''; ?>
                <tr data-id="<?php echo esc_attr($a->ID); ?>" data-patient="<?php echo esc_attr($p_name); ?>" data-phone="<?php echo esc_attr(get_post_meta($a->ID, '_mdbk_patient_phone', true)); ?>" data-email="<?php echo esc_attr($p_email); ?>" data-doctor="<?php echo esc_attr($doc_id); ?>" data-date="<?php echo esc_attr($date); ?>" data-slot-time="<?php echo esc_attr($slot_time); ?>" data-status="<?php echo esc_attr($status); ?>">
                    <td><?php echo $ticket ? esc_html('#' . str_pad($ticket, 2, '0', STR_PAD_LEFT)) : '—'; ?></td><td><strong><?php echo esc_html($p_name); ?></strong></td><td><?php echo $doc_id ? esc_html(get_the_title($doc_id)) : 'N/A'; ?></td><td><?php echo esc_html($date . ($slot_time ? ' ' . $slot_time : '')); ?></td><td><span class="mdbk-badge mdbk-badge-blue"><?php echo esc_html(ucfirst(str_replace('-', ' ', $status))); ?></span></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-appointment" data-id="<?php echo esc_attr($a->ID); ?>">✎</a><?php if (current_user_can('manage_options')) : ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-schedule&action=mdbk_delete_appointment&id='.$a->ID), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')">🗑</a><?php endif; ?></div></td>
                </tr><?php endforeach; else: ?>
                <tr><td colspan="6" style="text-align:center; padding:40px; opacity:0.6;"><?php _e('No bookings found.', 'doctor-appointment'); ?></td></tr>
                <?php endif; ?>
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
                    <td><strong><?php echo esc_html($p->post_title); ?></strong></td><td><?php echo esc_html($phone); ?></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-patient" data-id="<?php echo esc_attr($p->ID); ?>">✎</a><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-patients&action=mdbk_delete_patient&id='.$p->ID), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')">🗑</a></div></td>
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
                <?php foreach($terms as $t): ?><tr data-id="<?php echo esc_attr($t->term_id); ?>" data-name="<?php echo esc_attr($t->name); ?>"><td><strong><?php echo esc_html($t->name); ?></strong></td><td><?php echo esc_html($t->count); ?> <?php _e('doctors', 'doctor-appointment'); ?></td><td><div class="mdbk-actions"><a href="#" class="mdbk-action-btn mdbk-edit-specialty" data-id="<?php echo esc_attr($t->term_id); ?>">✎</a><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mdbk-specialties&action=mdbk_delete_specialty&id='.$t->term_id), 'mdbk_delete_action')); ?>" class="mdbk-action-btn mdbk-action-btn-red" onclick="return confirm('<?php esc_attr_e('Delete?', 'doctor-appointment'); ?>')">🗑</a></div></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div></div><?php $this->render_specialty_modal_html(); ?></div>
        <?php
    }

    private function render_sidebar($active_page) {
        ?>
        <div class="mdbk-sidebar"><div class="mdbk-sidebar-logo">MedBook</div><ul class="mdbk-sidebar-menu">
            <?php if (current_user_can('manage_options')) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'dashboard' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-dashboard')); ?>'"><?php _e('Dashboard', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'doctors' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-doctors')); ?>'"><?php _e('Doctors', 'doctor-appointment'); ?></li>
            <?php endif; ?>
            <?php if (current_user_can(MDBK_CAP_QUEUE)) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'schedule' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-schedule')); ?>'"><?php _e('Booking', 'doctor-appointment'); ?></li>
            <?php endif; ?>
            <?php if (current_user_can('manage_options')) : ?>
            <li class="mdbk-menu-item <?php echo $active_page == 'patients' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-patients')); ?>'"><?php _e('Patients', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item <?php echo $active_page == 'specialties' ? 'active' : ''; ?>" onclick="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mdbk-specialties')); ?>'"><?php _e('Specialties', 'doctor-appointment'); ?></li>
            <li class="mdbk-menu-item"><?php _e('Global Settings', 'doctor-appointment'); ?></li>
            <?php endif; ?>
        </ul><div class="mdbk-sidebar-footer"><div class="mdbk-user-avatar"></div><div class="mdbk-user-info"><div style="font-weight: 700; font-size: 13px;"><?php echo esc_html(wp_get_current_user()->display_name); ?></div><div style="font-size: 11px; opacity: 0.6;"><?php _e('Medical Center', 'doctor-appointment'); ?></div></div></div></div>
        <?php
    }

    private function render_doctor_modal_html() { ?>
        <div id="mdbk-doctor-modal" class="mdbk-modal"><div class="mdbk-modal-content">
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
                    <div><label class="mdbk-form-label" for="mdbk-doc-spec"><?php _e('Specialty', 'doctor-appointment'); ?></label><select name="specialty" id="mdbk-doc-spec"><?php foreach(get_terms(['taxonomy'=>'mdbk_department','hide_empty'=>false]) as $t) echo "<option value='".esc_attr($t->term_id)."'>".esc_html($t->name)."</option>"; ?></select></div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div>
                        <div class="mdbk-form-label-row"><label class="mdbk-form-label" for="mdbk-doc-email"><?php _e('Email', 'doctor-appointment'); ?></label><label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="show_email" id="mdbk-show-email" value="1" checked><span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Public', 'doctor-appointment'); ?></span></label></div>
                        <input type="email" name="doc_email" id="mdbk-doc-email">
                    </div>
                    <div>
                        <div class="mdbk-form-label-row"><label class="mdbk-form-label" for="mdbk-doc-phone"><?php _e('Phone', 'doctor-appointment'); ?></label><label class="mdbk-toggle mdbk-mini-toggle"><input type="checkbox" name="show_phone" id="mdbk-show-phone" value="1" checked><span class="mdbk-toggle-slider"></span><span class="mdbk-mini-toggle-text"><?php _e('Public', 'doctor-appointment'); ?></span></label></div>
                        <input type="text" name="doc_phone" id="mdbk-doc-phone">
                    </div>
                </div>

                <div class="mdbk-form-row mdbk-form-row-duo">
                    <div><label class="mdbk-form-label" for="mdbk-doc-slot-duration"><?php _e('Slot Duration (minutes)', 'doctor-appointment'); ?></label><input type="number" name="slot_duration" id="mdbk-doc-slot-duration" min="5" step="5" value="20"></div>
                </div>

                <div class="mdbk-form-row">
                    <label class="mdbk-form-label" for="mdbk-doc-bio"><?php _e('Bio / Description', 'doctor-appointment'); ?></label>
                    <textarea name="doc_bio" id="mdbk-doc-bio" rows="3" placeholder="<?php esc_attr_e('Specialty, experience, qualifications...', 'doctor-appointment'); ?>"></textarea>
                </div>

                <details class="mdbk-modal-schedule" open>
                    <summary class="mdbk-modal-schedule-summary"><?php _e('Weekly Availability', 'doctor-appointment'); ?></summary>
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
        <div id="mdbk-patient-modal" class="mdbk-modal"><div class="mdbk-modal-content"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Patient Record', 'doctor-appointment'); ?></h2><form id="mdbk-patient-form" method="POST"><?php wp_nonce_field('mdbk_save_patient'); ?><input type="hidden" name="patient_id" id="mdbk-patient-id"><div class="mdbk-form-section">
            <div class="mdbk-input-group"><label><?php _e('Full Name', 'doctor-appointment'); ?></label><input type="text" name="patient_name" id="mdbk-patient-name" class="mdbk-input" required></div>
            <div class="mdbk-input-group"><label><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-patient-phone" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="patient_email" id="mdbk-patient-email" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Address', 'doctor-appointment'); ?></label><textarea name="patient_address" id="mdbk-patient-address" class="mdbk-input"></textarea></div>
        </div><button type="submit" name="mdbk_save_patient" class="mdbk-btn-save"><?php _e('Save Record', 'doctor-appointment'); ?></button></form></div></div>
    <?php }

    private function render_appointment_modal_html() { ?>
        <div id="mdbk-appointment-modal" class="mdbk-modal"><div class="mdbk-modal-content"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Booking', 'doctor-appointment'); ?></h2><form id="mdbk-appointment-form" method="POST"><?php wp_nonce_field('mdbk_save_appointment'); ?><input type="hidden" name="app_id" id="mdbk-app-id"><div class="mdbk-form-section">
            <div class="mdbk-input-group"><label><?php _e('Patient', 'doctor-appointment'); ?></label><input type="text" name="patient_name" id="mdbk-app-patient" class="mdbk-input" required></div>
            <div class="mdbk-input-group"><label><?php _e('Phone', 'doctor-appointment'); ?></label><input type="text" name="patient_phone" id="mdbk-app-phone" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Email', 'doctor-appointment'); ?></label><input type="email" name="patient_email" id="mdbk-app-email" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Doctor', 'doctor-appointment'); ?></label><select name="doctor_id" id="mdbk-app-doctor" class="mdbk-input"><?php foreach(get_posts(['post_type'=>'mdbk_doctor','numberposts'=>-1]) as $d) echo "<option value='".esc_attr($d->ID)."'>".esc_html($d->post_title)."</option>"; ?></select></div>
            <div class="mdbk-input-group"><label><?php _e('Date', 'doctor-appointment'); ?></label><input type="date" name="app_date" id="mdbk-app-date" class="mdbk-input" required></div>
            <div class="mdbk-input-group"><label><?php _e('Slot Time', 'doctor-appointment'); ?></label><input type="time" name="slot_time" id="mdbk-app-slot-time" class="mdbk-input"></div>
            <div class="mdbk-input-group"><label><?php _e('Status', 'doctor-appointment'); ?></label><select name="status" id="mdbk-app-status" class="mdbk-input"><option value="waiting"><?php _e('Waiting', 'doctor-appointment'); ?></option><option value="serving"><?php _e('Serving', 'doctor-appointment'); ?></option><option value="completed"><?php _e('Completed', 'doctor-appointment'); ?></option><option value="no-show"><?php _e('No Show', 'doctor-appointment'); ?></option></select></div>
        </div><button type="submit" name="mdbk_save_appointment" class="mdbk-btn-save"><?php _e('Save Booking', 'doctor-appointment'); ?></button></form></div></div>
    <?php }

    private function render_specialty_modal_html() { ?>
        <div id="mdbk-specialty-modal" class="mdbk-modal"><div class="mdbk-modal-content" style="max-width:400px;"><span class="mdbk-modal-close">&times;</span><h2><?php _e('Specialty', 'doctor-appointment'); ?></h2><form id="mdbk-specialty-form" method="POST"><?php wp_nonce_field('mdbk_save_specialty'); ?><input type="hidden" name="term_id" id="mdbk-spec-id"><div class="mdbk-input-group"><label><?php _e('Name', 'doctor-appointment'); ?></label><input type="text" name="spec_name" id="mdbk-spec-name" class="mdbk-input" required></div><button type="submit" name="mdbk_save_specialty" class="mdbk-btn-save"><?php _e('Save Specialty', 'doctor-appointment'); ?></button></form></div></div>
    <?php }
}
new MDBK_Admin_Dashboard();
