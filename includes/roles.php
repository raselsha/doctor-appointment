<?php
/**
 * @author Shahadat Hossain <raselsha@gmail.com>
 */
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Roles {

    /**
     * Front-desk role: can run the live queue and manage appointments, but
     * not doctors/patients/specialties CRUD (stays manage_options-only).
     */
    public static function activate() {
        add_role('mdbk_receptionist', __('Front Desk', 'doctor-appointment'), ['read' => true]);

        $receptionist = get_role('mdbk_receptionist');
        if ($receptionist) {
            $receptionist->add_cap(MDBK_CAP_QUEUE);
        }

        // Doctor: sees only their own restricted "My Queue" page (see
        // MDBK_Admin_Dashboard::render_my_queue_page()) — read plus one
        // narrow capability, nothing else. A bare 'read' role already
        // can't see any core wp-admin menu (Posts/Media/Comments/etc. are
        // all gated by their own edit_*/upload_* caps), so no manual menu
        // hiding is needed beyond not granting those capabilities.
        add_role('mdbk_doctor_role', __('Doctor', 'doctor-appointment'), ['read' => true]);
        $doctor_role = get_role('mdbk_doctor_role');
        if ($doctor_role) {
            $doctor_role->add_cap(MDBK_CAP_DOCTOR);
        }

        // Patient: record-keeping only today — no patient-facing feature
        // exists yet, so no extra capability beyond 'read'.
        add_role('mdbk_patient_role', __('Patient', 'doctor-appointment'), ['read' => true]);

        // Administrators keep full access alongside manage_options.
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(MDBK_CAP_QUEUE);
            $admin->add_cap(MDBK_CAP_DOCTOR);
        }
    }

    /**
     * Deliberately not called on deactivate — only on uninstall — so
     * deactivating the plugin for a moment doesn't strip an active
     * receptionist's/doctor's role.
     */
    public static function uninstall() {
        remove_role('mdbk_receptionist');
        remove_role('mdbk_doctor_role');
        remove_role('mdbk_patient_role');

        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap(MDBK_CAP_QUEUE);
            $admin->remove_cap(MDBK_CAP_DOCTOR);
        }
    }
}
