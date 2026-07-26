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

        // Doctor: sees only their own restricted Booking queue (see
        // MDBK_Admin_Dashboard::render_schedule_page()) — read plus one
        // narrow capability, nothing else. A bare 'read' role already
        // can't see any core wp-admin menu (Posts/Media/Comments/etc. are
        // all gated by their own edit_*/upload_* caps), so no manual menu
        // hiding is needed beyond not granting those capabilities.
        add_role('mdbk_doctor_role', __('Doctor', 'doctor-appointment'), ['read' => true]);
        $doctor_role = get_role('mdbk_doctor_role');
        if ($doctor_role) {
            $doctor_role->add_cap(MDBK_CAP_DOCTOR);
            // Lets a doctor use their own Profile page's photo picker
            // (wp.media() — see admin-script.js) at all; WP core's own
            // upload/attachment-query AJAX handlers require this
            // capability regardless of what this plugin's own code
            // checks. Scoped down to "their own uploads only" separately
            // — see MDBK_Admin_Dashboard::restrict_media_to_own_uploads()
            // — so this doesn't turn into "browse/reuse the whole site's
            // media library".
            $doctor_role->add_cap('upload_files');
        }

        // Patient: record-keeping only today — no patient-facing feature
        // exists yet, so no extra capability beyond 'read'.
        add_role('mdbk_patient_role', __('Patient', 'doctor-appointment'), ['read' => true]);

        // Administrators keep full access alongside manage_options, plus
        // MDBK_CAP_ADMIN (see doctor-appointment.php) so this plugin's own
        // admin-only checks can gate on ONE capability that both a real
        // administrator and a Manager (below) share, instead of every
        // internal check needing an OR against manage_options separately.
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(MDBK_CAP_QUEUE);
            $admin->add_cap(MDBK_CAP_DOCTOR);
            $admin->add_cap(MDBK_CAP_ADMIN);
        }

        // Manager: full access to everything THIS PLUGIN's own admin
        // panel offers (Dashboard, Doctors, Staff, Specialties, Booking,
        // Patients, Profile, Change Password — anything gated on
        // MDBK_CAP_ADMIN) — but deliberately NOT WordPress's own
        // 'manage_options', so a Manager can never reach native wp-admin
        // areas (Plugins, Themes, Settings, Users) that check that core
        // capability directly; this plugin has no way to intercept core
        // code's own capability checks, only its own. Same immersive
        // panel UI as a doctor's/staff's — see
        // MDBK_Admin_Dashboard::is_restricted_panel_user().
        if (!get_role('mdbk_manager_role')) {
            add_role('mdbk_manager_role', __('Manager', 'doctor-appointment'), ['read' => true]);
        } else {
            // An earlier version of this role copied administrator's
            // ENTIRE capability set (including real manage_options,
            // install_plugins, etc.) — strip that back down to bare
            // 'read' before re-granting just the clinic-scoped set below,
            // so a site that already created this role before this
            // change doesn't keep the over-broad grant.
            $manager_role_reset = get_role('mdbk_manager_role');
            foreach (array_keys($manager_role_reset->capabilities) as $cap) {
                if ($cap !== 'read') {
                    $manager_role_reset->remove_cap($cap);
                }
            }
        }
        $manager_role = get_role('mdbk_manager_role');
        if ($manager_role) {
            $manager_role->add_cap(MDBK_CAP_ADMIN);
            $manager_role->add_cap(MDBK_CAP_QUEUE);
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
        remove_role('mdbk_manager_role');

        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap(MDBK_CAP_QUEUE);
            $admin->remove_cap(MDBK_CAP_DOCTOR);
            $admin->remove_cap(MDBK_CAP_ADMIN);
        }
    }
}
