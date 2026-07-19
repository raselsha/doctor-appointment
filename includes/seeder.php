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

        // 3. Seed Patients (at least 5 per doctor = 30+)
        $patients = [
            ['name' => 'John Doe', 'phone' => '111-2222', 'email' => 'john@example.com', 'address' => '123 Medical St'],
            ['name' => 'Jane Smith', 'phone' => '111-3333', 'email' => 'jane@example.com', 'address' => '456 Health Ave'],
            ['name' => 'Mike Ross', 'phone' => '111-4444', 'email' => 'mike@example.com', 'address' => '789 Care Blvd'],
            ['name' => 'Rachel Zane', 'phone' => '111-5555', 'email' => 'rachel@example.com', 'address' => '101 Wellness Rd'],
            ['name' => 'Harvey Specter', 'phone' => '111-6666', 'email' => 'harvey@example.com', 'address' => '202 Recovery Ln'],
            ['name' => 'Donna Paulsen', 'phone' => '111-7777', 'email' => 'donna@example.com', 'address' => '303 Vitality Dr'],
            ['name' => 'Walter White', 'phone' => '111-8888', 'email' => 'walter@example.com', 'address' => '505 Chemistry Ln'],
            ['name' => 'Skyler White', 'phone' => '111-9999', 'email' => 'skyler@example.com', 'address' => '606 Accounting Ave'],
            ['name' => 'Jesse Pinkman', 'phone' => '222-1111', 'email' => 'jesse@example.com', 'address' => '707 Mesa Dr'],
            ['name' => 'Saul Goodman', 'phone' => '222-2222', 'email' => 'saul@example.com', 'address' => '808 Legal Blvd'],
            ['name' => 'Gus Fring', 'phone' => '222-3333', 'email' => 'gus@example.com', 'address' => '909 Chicken Rd'],
            ['name' => 'Hank Schrader', 'phone' => '222-4444', 'email' => 'hank@example.com', 'address' => '1010 DEA Ave'],
            ['name' => 'Marie Schrader', 'phone' => '222-5555', 'email' => 'marie@example.com', 'address' => '1111 Purple St'],
            ['name' => 'Todd Alquist', 'phone' => '222-6666', 'email' => 'todd@example.com', 'address' => '1212 Vamonos Dr'],
            ['name' => 'Lydia Rodarte', 'phone' => '222-7777', 'email' => 'lydia@example.com', 'address' => '1313 Madrigal Ave'],
            ['name' => 'Mike Ehrmantraut', 'phone' => '222-8888', 'email' => 'mike@example.com', 'address' => '1414 Half Measure Rd'],
            ['name' => 'Kim Wexler', 'phone' => '222-9999', 'email' => 'kim@example.com', 'address' => '1515 Stanford Ave'],
            ['name' => 'Howard Hamlin', 'phone' => '333-1111', 'email' => 'howard@example.com', 'address' => '1616 HHM Blvd'],
            ['name' => 'Chuck McGill', 'phone' => '333-2222', 'email' => 'chuck@example.com', 'address' => '1717 Mesa Verde St'],
            ['name' => 'Nacho Varga', 'phone' => '333-3333', 'email' => 'nacho@example.com', 'address' => '1818 Cartel Dr'],
            ['name' => 'Lalo Salamanca', 'phone' => '333-4444', 'email' => 'lalo@example.com', 'address' => '1919 Worms Rd'],
            ['name' => 'Hector Salamanca', 'phone' => '333-5555', 'email' => 'hector@example.com', 'address' => '2020 Bell Ave'],
            ['name' => 'Tuco Salamanca', 'phone' => '333-6666', 'email' => 'tuco@example.com', 'address' => '2121 Crazy St'],
            ['name' => 'Jane Margolis', 'phone' => '333-7777', 'email' => 'jane@example.com', 'address' => '2222 Apartment Dr'],
            ['name' => 'Badger Mayhew', 'phone' => '333-8888', 'email' => 'badger@example.com', 'address' => '2323 Star Trek Ln'],
            ['name' => 'Skinny Pete', 'phone' => '333-9999', 'email' => 'pete@example.com', 'address' => '2424 Gaming Ave'],
            ['name' => 'Gale Boetticher', 'phone' => '444-1111', 'email' => 'gale@example.com', 'address' => '2525 Lab Rd'],
            ['name' => 'Victor', 'phone' => '444-2222', 'email' => 'victor@example.com', 'address' => '2626 Chicken St'],
            ['name' => 'Tyrus Kitt', 'phone' => '444-3333', 'email' => 'tyrus@example.com', 'address' => '2727 Security Blvd'],
            ['name' => 'Francesca Liddy', 'phone' => '444-4444', 'email' => 'francesca@example.com', 'address' => '2828 Reception Ave'],
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

        // 4. Seed Appointments (5 per doctor across 3 days)
        $statuses = ['waiting', 'serving', 'completed', 'no-show', 'waiting'];
        $slot_times = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '14:00', '14:30', '15:00', '15:30'];
        $appointment_date = current_time('Y-m-d');
        $patient_index = 0;

        foreach ($doctor_ids as $doc_id) {
            for ($b = 0; $b < 5; $b++) {
                $day_offset = intdiv($b, 2);
                $date = date('Y-m-d', strtotime($appointment_date . " +{$day_offset} day"));
                $status = $statuses[$b % count($statuses)];
                $slot_time = $slot_times[($b * 2 + $doc_id) % count($slot_times)];

                if (!isset($patient_ids[$patient_index])) break;

                $p = $patient_ids[$patient_index];
                $patient_index++;

                $app_id = wp_insert_post([
                    'post_title'  => "Booking: " . $p['name'],
                    'post_type'   => 'mdbk_appointment',
                    'post_status' => MDBK_Appointment_Manager::status_slug_to_post_status($status),
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
        }

        update_option('mdbk_dummy_data_seeded', true);
    }
}
