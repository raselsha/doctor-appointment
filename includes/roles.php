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

        // Administrators keep full access alongside manage_options.
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(MDBK_CAP_QUEUE);
        }
    }

    /**
     * Deliberately not called on deactivate — only on uninstall — so
     * deactivating the plugin for a moment doesn't strip an active
     * receptionist's role.
     */
    public static function uninstall() {
        remove_role('mdbk_receptionist');

        $admin = get_role('administrator');
        if ($admin) {
            $admin->remove_cap(MDBK_CAP_QUEUE);
        }
    }
}
