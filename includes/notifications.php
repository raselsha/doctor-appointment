<?php
/**
 * @author Shahadat Hossain <raselsha@gmail.com>
 */
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_Notifications {

    public function __construct() {
        add_action('transition_post_status', [$this, 'on_status_transition'], 10, 3);
    }

    /**
     * Fires on every wp_update_post()/wp_insert_post() touching post_status,
     * including a no-op resave — guard on new !== old so we don't double-send.
     */
    public function on_status_transition($new_status, $old_status, $post) {
        if (!$post || $post->post_type !== 'mdbk_appointment') return;
        if ($new_status === $old_status) return;
        if (!in_array($new_status, MDBK_CPT::APPOINTMENT_STATUSES, true)) return;

        $event = MDBK_Appointment_Manager::post_status_to_slug($new_status);
        if (!in_array($event, ['waiting', 'serving', 'completed'], true)) return; // no-show: no notification

        $this->notify($event, $post->ID);
    }

    private function notify($event, $appointment_id) {
        $patient_email = get_post_meta($appointment_id, '_mdbk_patient_email', true);
        if (!$patient_email) {
            $patient_id = get_post_meta($appointment_id, '_mdbk_patient_id', true);
            if ($patient_id) {
                $patient_email = get_post_meta($patient_id, '_mdbk_patient_email', true);
            }
        }

        $patient_name = get_post_meta($appointment_id, '_mdbk_patient_name', true);
        $doctor_id    = get_post_meta($appointment_id, '_mdbk_doctor_id', true);
        $doctor_name  = $doctor_id ? get_the_title($doctor_id) : '';
        $date         = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        $slot         = get_post_meta($appointment_id, '_mdbk_slot_time', true);
        $when         = trim($date . ' ' . $slot);

        if (is_email($patient_email)) {
            $this->send($event, $appointment_id, $patient_email, $patient_name, $doctor_name, $when, 'patient');
        }

        // Doctor is only notified about new bookings, not every status change.
        if ($event === 'waiting' && $doctor_id) {
            $doctor_email = get_post_meta($doctor_id, '_mdbk_doc_email', true);
            if (is_email($doctor_email)) {
                $this->send($event, $appointment_id, $doctor_email, $patient_name, $doctor_name, $when, 'doctor');
            }
        }
    }

    private function send($event, $appointment_id, $to, $patient_name, $doctor_name, $when, $recipient_type) {
        $subjects = [
            'waiting'   => $recipient_type === 'doctor' ? __('New Appointment Booked', 'doctor-appointment') : __('Your Appointment is Confirmed', 'doctor-appointment'),
            'serving'   => __("You're Up Next", 'doctor-appointment'),
            'completed' => __('Thank You for Your Visit', 'doctor-appointment'),
        ];
        $subject = isset($subjects[$event]) ? $subjects[$event] : __('Appointment Update', 'doctor-appointment');
        $subject = apply_filters('mdbk_email_subject', $subject, $event, $appointment_id, $recipient_type);

        if ($recipient_type === 'doctor') {
            $body = sprintf(
                __("A new appointment has been booked.\n\nPatient: %1\$s\nWhen: %2\$s", 'doctor-appointment'),
                $patient_name,
                $when
            );
        } else {
            switch ($event) {
                case 'waiting':
                    $body = sprintf(
                        __("Hi %1\$s,\n\nYour appointment with %2\$s is confirmed for %3\$s.\n\nSee you then!", 'doctor-appointment'),
                        $patient_name,
                        $doctor_name,
                        $when
                    );
                    break;
                case 'serving':
                    $body = sprintf(
                        __("Hi %1\$s,\n\nYou're up next with %2\$s. Please head to the reception area.", 'doctor-appointment'),
                        $patient_name,
                        $doctor_name
                    );
                    break;
                case 'completed':
                    $body = sprintf(
                        __("Hi %1\$s,\n\nThank you for visiting %2\$s today. We hope you feel better soon!", 'doctor-appointment'),
                        $patient_name,
                        $doctor_name
                    );
                    break;
                default:
                    $body = '';
            }
        }

        $body = apply_filters('mdbk_email_body', $body, $event, $appointment_id, $recipient_type);

        // Extensibility hook for future channels (e.g. SMS) without touching this file.
        do_action('mdbk_appointment_notify', $event, $appointment_id, 'email');

        wp_mail($to, $subject, $body);
    }
}

new MDBK_Notifications();
