<?php
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Seeder {

    public static function seed_data() {
        if (get_option('mdbk_dummy_data_seeded')) return;

        // 1. Seed Specialties
        $specialties = ['Cardiology', 'Dermatology', 'Neurology', 'Pediatrics', 'Orthopedics', 'General Surgery'];
        $term_ids = [];
        foreach ($specialties as $name) {
            $term = wp_insert_term($name, 'mdbk_department');
            if (!is_wp_error($term)) $term_ids[] = $term['term_id'];
            elseif (isset($term->error_data['term_exists'])) $term_ids[] = $term->error_data['term_exists'];
        }

        // 2. Seed Doctors
        $doctors = [
            ['name' => 'Dr. James Wilson', 'email' => 'wilson@medbook.com', 'phone' => '555-0101'],
            ['name' => 'Dr. Sarah Connor', 'email' => 'sarah@medbook.com', 'phone' => '555-0102'],
            ['name' => 'Dr. Robert Chase', 'email' => 'chase@medbook.com', 'phone' => '555-0103'],
            ['name' => 'Dr. Lisa Cuddy', 'email' => 'cuddy@medbook.com', 'phone' => '555-0104'],
            ['name' => 'Dr. Eric Foreman', 'email' => 'foreman@medbook.com', 'phone' => '555-0105'],
            ['name' => 'Dr. Allison Cameron', 'email' => 'cameron@medbook.com', 'phone' => '555-0106'],
        ];
        $doctor_ids = [];
        foreach ($doctors as $i => $doc) {
            $id = wp_insert_post([
                'post_title' => $doc['name'],
                'post_type' => 'mdbk_doctor',
                'post_status' => 'publish'
            ]);
            if ($id) {
                update_post_meta($id, '_mdbk_doc_email', $doc['email']);
                update_post_meta($id, '_mdbk_doc_phone', $doc['phone']);
                if (isset($term_ids[$i])) wp_set_object_terms($id, [$term_ids[$i]], 'mdbk_department');
                
                // Set a default schedule
                $schedule = [
                    'Monday' => ['active' => 1, 'from' => '09:00', 'to' => '17:00'],
                    'Wednesday' => ['active' => 1, 'from' => '09:00', 'to' => '17:00'],
                    'Friday' => ['active' => 1, 'from' => '09:00', 'to' => '17:00'],
                ];
                update_post_meta($id, '_mdbk_schedule', $schedule);
                $doctor_ids[] = $id;
            }
        }

        // 3. Seed Patients
        $patients = [
            ['name' => 'John Doe', 'phone' => '111-2222', 'email' => 'john@example.com', 'address' => '123 Medical St'],
            ['name' => 'Jane Smith', 'phone' => '111-3333', 'email' => 'jane@example.com', 'address' => '456 Health Ave'],
            ['name' => 'Mike Ross', 'phone' => '111-4444', 'email' => 'mike@example.com', 'address' => '789 Care Blvd'],
            ['name' => 'Rachel Zane', 'phone' => '111-5555', 'email' => 'rachel@example.com', 'address' => '101 Wellness Rd'],
            ['name' => 'Harvey Specter', 'phone' => '111-6666', 'email' => 'harvey@example.com', 'address' => '202 Recovery Ln'],
            ['name' => 'Donna Paulsen', 'phone' => '111-7777', 'email' => 'donna@example.com', 'address' => '303 Vitality Dr'],
        ];
        $patient_ids = [];
        foreach ($patients as $p) {
            $id = wp_insert_post([
                'post_title' => $p['name'],
                'post_type' => 'mdbk_patient',
                'post_status' => 'publish'
            ]);
            if ($id) {
                update_post_meta($id, '_mdbk_patient_phone', $p['phone']);
                update_post_meta($id, '_mdbk_patient_email', $p['email']);
                update_post_meta($id, '_mdbk_patient_address', $p['address']);
                $patient_ids[] = ['id' => $id, 'name' => $p['name'], 'phone' => $p['phone'], 'email' => $p['email']];
            }
        }

        // 4. Seed Appointments (today and tomorrow)
        $statuses = ['waiting', 'serving', 'completed', 'waiting', 'waiting', 'waiting'];
        $slot_times = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30'];
        foreach ($patient_ids as $i => $p) {
            $date = ($i < 3) ? current_time('Y-m-d') : date('Y-m-d', strtotime(current_time('Y-m-d') . ' +1 day'));
            $doc_id = $doctor_ids[$i % count($doctor_ids)];
            $slot_time = $slot_times[$i % count($slot_times)];

            $app_id = wp_insert_post([
                'post_title'  => "Booking: " . $p['name'],
                'post_type'   => 'mdbk_appointment',
                'post_status' => MDBK_Appointment_Manager::status_slug_to_post_status($statuses[$i]),
            ]);
            if ($app_id) {
                update_post_meta($app_id, '_mdbk_patient_id', $p['id']);
                update_post_meta($app_id, '_mdbk_patient_name', $p['name']);
                update_post_meta($app_id, '_mdbk_patient_phone', $p['phone']);
                update_post_meta($app_id, '_mdbk_patient_email', $p['email']);
                update_post_meta($app_id, '_mdbk_doctor_id', $doc_id);
                update_post_meta($app_id, '_mdbk_appointment_date', $date);
                update_post_meta($app_id, '_mdbk_slot_time', $slot_time);
                update_post_meta($app_id, '_mdbk_ticket_number', MDBK_Appointment_Manager::next_ticket_number($doc_id, $date, $app_id));
            }
        }

        update_option('mdbk_dummy_data_seeded', true);
    }
}
