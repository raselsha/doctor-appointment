<?php
/**
 * @author Shahadat Hossain <raselsha@gmail.com>
 */
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Migrations {

    const DB_VERSION = 4;

    /**
     * Run pending migrations, gated on mdbk_db_version. This is the only
     * reliable self-heal path for sites that pull code updates without
     * deactivating/reactivating the plugin — register_activation_hook does
     * NOT fire just because new plugin code was deployed while the plugin
     * stayed continuously active.
     */
    public static function maybe_migrate() {
        $current = (int) get_option('mdbk_db_version', 0);

        if ($current >= self::DB_VERSION) {
            return;
        }

        if ($current < 2) {
            self::migrate_to_v2();
        }

        if ($current < 3) {
            // Grants the front-desk role/capability. MDBK_Roles::activate()
            // is also called on register_activation_hook, but that alone
            // misses already-active installs that never get reactivated.
            MDBK_Roles::activate();
        }

        if ($current < 4) {
            // Adds the Doctor/Patient roles + MDBK_CAP_DOCTOR — re-running
            // MDBK_Roles::activate() is harmless/idempotent (add_role() and
            // add_cap() both no-op if already present), so sites already at
            // v3 just pick up the two new roles for free here.
            MDBK_Roles::activate();
        }

        update_option('mdbk_db_version', self::DB_VERSION);
    }

    /**
     * v2: postmeta _mdbk_status -> registered post_status, plus backfill of
     * _mdbk_patient_id and _mdbk_ticket_number for still-active appointments.
     *
     * Uses $wpdb->update() directly (not wp_update_post()) so transition_post_status
     * does not fire for historic records — we don't want to email every legacy
     * appointment on upgrade.
     */
    private static function migrate_to_v2() {
        global $wpdb;

        $status_map = [
            'waiting'   => 'mdbk_waiting',
            'serving'   => 'mdbk_serving',
            'completed' => 'mdbk_completed',
            'no-show'   => 'mdbk_no_show',
        ];

        $post_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mdbk_appointment' AND post_status = 'publish' ORDER BY ID ASC"
        );

        // Track per doctor+date counts so backfilled ticket numbers are sequential.
        $ticket_counters = [];

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $legacy_status = get_post_meta($post_id, '_mdbk_status', true);
            $legacy_status = $legacy_status ? $legacy_status : 'waiting';
            $new_status = isset($status_map[$legacy_status]) ? $status_map[$legacy_status] : 'mdbk_waiting';

            $wpdb->update(
                $wpdb->posts,
                ['post_status' => $new_status],
                ['ID' => $post_id],
                ['%s'],
                ['%d']
            );
            clean_post_cache($post_id);

            // Backfill patient link + ticket number only for still-active appointments.
            if (in_array($new_status, ['mdbk_waiting', 'mdbk_serving'], true)) {
                if (!get_post_meta($post_id, '_mdbk_patient_id', true)) {
                    $name  = get_post_meta($post_id, '_mdbk_patient_name', true);
                    $phone = get_post_meta($post_id, '_mdbk_patient_phone', true);
                    if ($name && $phone) {
                        $patient_id = MDBK_Appointment_Manager::find_or_create_patient($name, $phone);
                        if ($patient_id) {
                            update_post_meta($post_id, '_mdbk_patient_id', $patient_id);
                        }
                    }
                }

                if (!get_post_meta($post_id, '_mdbk_ticket_number', true)) {
                    $doctor_id = (int) get_post_meta($post_id, '_mdbk_doctor_id', true);
                    $date      = get_post_meta($post_id, '_mdbk_appointment_date', true);
                    $key       = $doctor_id . '_' . $date;
                    if (!isset($ticket_counters[$key])) {
                        $ticket_counters[$key] = 0;
                    }
                    $ticket_counters[$key]++;
                    update_post_meta($post_id, '_mdbk_ticket_number', $ticket_counters[$key]);
                }
            }
        }
    }
}
