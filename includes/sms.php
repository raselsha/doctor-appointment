<?php
/**
 * SMS notifications over the Alpha SMS gateway (sms.bd / api.sms.net.bd).
 *
 * Email already covers the same moments (notifications.php), but in a
 * chamber that isn't what reaches people: patients give a phone number far
 * more reliably than an email address, and a "you're next" that arrives
 * after the visit is worthless. SMS is the channel that actually lands, so
 * every event here is independently switchable and independently worded —
 * a clinic pays per message and shouldn't be forced to buy the ones it
 * doesn't want.
 *
 * Three things this module treats as load-bearing, because each one costs
 * real money when it goes wrong:
 *
 *   1. Never send the same event twice for the same booking. Post status
 *      transitions fire more often than they look like they do (a plain
 *      re-save counts), so every send is stamped on the appointment and
 *      checked before the next one — see already_sent().
 *   2. Count characters the way the gateway bills them, not the way
 *      strlen() does. A Bangla message is UCS-2 and fits 70 characters,
 *      not 160, so a template that looks fine in the box can quietly be
 *      three messages. See count_message() — the settings screen shows
 *      the same numbers live as you type.
 *   3. Never guess at a phone number. Normalise to the gateway's
 *      8801XXXXXXXXX form and refuse anything that doesn't survive it,
 *      rather than posting a malformed number and paying for the reject.
 *
 * @author Shahadat Hossain <raselsha@gmail.com>
 */
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_SMS {

    const OPTION      = 'mdbk_sms_settings';
    const LOG_CPT     = 'mdbk_sms_log';
    const API_SEND    = 'https://api.sms.net.bd/sendsms';
    const API_REPORT  = 'https://api.sms.net.bd/report/request/';
    const API_BALANCE = 'https://api.sms.net.bd/user/balance/';

    // The gateway's own documented ceiling is per-request, not per-part;
    // this is a sanity guard so a runaway template can't post 4KB and be
    // billed for it. 10 parts of Unicode is already an absurd SMS.
    const MAX_LENGTH = 700;

    public function __construct() {
        add_action('init', [$this, 'register_log_cpt']);

        // Status-driven events. Priority 20 so notifications.php (10) has
        // already run — not that order matters for correctness, but a
        // failing SMS gateway shouldn't be what stops the email going out.
        add_action('transition_post_status', [$this, 'on_status_transition'], 20, 3);

        // The two events with no status of their own to hang off; both are
        // fired explicitly by the code that performs them.
        add_action('mdbk_appointment_checked_in', [$this, 'on_checked_in'], 10, 1);
        add_action('mdbk_appointment_rescheduled', [$this, 'on_rescheduled'], 10, 3);

        add_action('mdbk_sms_reminder_cron', [$this, 'run_reminders']);
        add_action('mdbk_sms_prune_log_cron', [$this, 'prune_log']);
        add_filter('cron_schedules', [$this, 'add_cron_schedule']);
        add_action('init', [$this, 'schedule_crons'], 20);

        add_action('wp_ajax_mdbk_sms_balance', [$this, 'ajax_balance']);
        add_action('wp_ajax_mdbk_sms_test', [$this, 'ajax_test_send']);
    }

    /* ---------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------- */

    /**
     * Every event this module can send, in the order they happen to a
     * patient — which is also the order they're listed on the settings
     * screen, so the page reads as a timeline rather than a checklist.
     *
     * 'audience' decides whose number the message goes to, and is not a
     * setting: a "new booking" alert is for the doctor by definition.
     */
    public static function events() {
        return [
            'booking_confirmed' => [
                'label'       => __('Booking confirmed', 'doctor-appointment'),
                'description' => __('Sent to the patient the moment a booking is created, from the public form or the front desk.', 'doctor-appointment'),
                'audience'    => 'patient',
                'default_on'  => true,
                'template'    => __('Dear {patient_name}, your appointment with {doctor_name} is confirmed for {date} at {time}. Serial: {ticket}. - {clinic_name}', 'doctor-appointment'),
            ],
            'doctor_new_booking' => [
                'label'       => __('New booking (to doctor)', 'doctor-appointment'),
                'description' => __("Sent to the doctor's own number when a new booking lands in their queue.", 'doctor-appointment'),
                'audience'    => 'doctor',
                'default_on'  => false,
                'template'    => __('New booking: {patient_name} ({patient_phone}) on {date} at {time}. Serial: {ticket}.', 'doctor-appointment'),
            ],
            'reminder' => [
                'label'       => __('Appointment reminder', 'doctor-appointment'),
                'description' => __('Sent once, a set number of hours before the appointment time. Never sent for a booking that is already checked in, served or cancelled.', 'doctor-appointment'),
                'audience'    => 'patient',
                'default_on'  => false,
                'template'    => __('Reminder: {patient_name}, your appointment with {doctor_name} is today at {time}. Serial: {ticket}. - {clinic_name}', 'doctor-appointment'),
            ],
            'checked_in' => [
                'label'       => __('Checked in', 'doctor-appointment'),
                'description' => __('Sent when the patient is checked in at the desk or by QR scan.', 'doctor-appointment'),
                'audience'    => 'patient',
                'default_on'  => false,
                'template'    => __('{patient_name}, you are checked in. Your serial is {ticket}. Please wait to be called. - {clinic_name}', 'doctor-appointment'),
            ],
            'serving' => [
                'label'       => __("Patient's turn", 'doctor-appointment'),
                'description' => __('Sent when the doctor starts the visit — the "you are next, please come in" message.', 'doctor-appointment'),
                'audience'    => 'patient',
                'default_on'  => true,
                'template'    => __('{patient_name}, it is your turn now. Please go to {doctor_name}\'s room. - {clinic_name}', 'doctor-appointment'),
            ],
            'completed' => [
                'label'       => __('Visit completed', 'doctor-appointment'),
                'description' => __('Sent after the visit is marked complete.', 'doctor-appointment'),
                'audience'    => 'patient',
                'default_on'  => false,
                'template'    => __('Thank you for visiting {clinic_name}, {patient_name}. Get well soon. For any query: {clinic_contact}', 'doctor-appointment'),
            ],
            'no_show' => [
                'label'       => __('Marked absent', 'doctor-appointment'),
                'description' => __('Sent when a booking is marked as a no-show.', 'doctor-appointment'),
                'audience'    => 'patient',
                'default_on'  => false,
                'template'    => __('{patient_name}, we missed you at {clinic_name} on {date}. Please contact {clinic_contact} to rebook.', 'doctor-appointment'),
            ],
            'rescheduled' => [
                'label'       => __('Rescheduled', 'doctor-appointment'),
                'description' => __("Sent when a booking's date or time is changed from the admin panel.", 'doctor-appointment'),
                'audience'    => 'patient',
                'default_on'  => true,
                'template'    => __('{patient_name}, your appointment with {doctor_name} has been moved to {date} at {time}. - {clinic_name}', 'doctor-appointment'),
            ],
        ];
    }

    /**
     * Stored settings merged over the defaults, so a site that has never
     * opened the settings screen still has a complete, usable shape — and
     * an event added in a later version arrives switched to its own
     * default rather than to "off" just because the stored array predates
     * it.
     */
    public static function settings() {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) $stored = [];

        $settings = [
            'enabled'         => !empty($stored['enabled']),
            'api_key'         => isset($stored['api_key']) ? (string) $stored['api_key'] : '',
            'sender_id'       => isset($stored['sender_id']) ? (string) $stored['sender_id'] : '',
            'reminder_hours'  => isset($stored['reminder_hours']) ? max(1, min(168, intval($stored['reminder_hours']))) : 3,
            'log_retention'   => isset($stored['log_retention']) ? max(0, min(365, intval($stored['log_retention']))) : 30,
            'events'          => [],
        ];

        foreach (self::events() as $key => $event) {
            $stored_event = isset($stored['events'][$key]) && is_array($stored['events'][$key]) ? $stored['events'][$key] : [];
            $template = isset($stored_event['template']) ? (string) $stored_event['template'] : $event['template'];
            $settings['events'][$key] = [
                'enabled'  => array_key_exists('enabled', $stored_event) ? !empty($stored_event['enabled']) : $event['default_on'],
                'template' => $template,
            ];
        }

        return $settings;
    }

    public static function is_enabled() {
        $s = self::settings();
        return $s['enabled'] && $s['api_key'] !== '';
    }

    public static function is_event_enabled($event) {
        $s = self::settings();
        return self::is_enabled()
            && !empty($s['events'][$event]['enabled'])
            && trim($s['events'][$event]['template']) !== '';
    }

    /* ---------------------------------------------------------------
     * Templates
     * ------------------------------------------------------------- */

    /**
     * Every token a template may use, with the one-line explanation shown
     * beside the editor. Kept here rather than in the view so the settings
     * screen and build_tokens() can't drift apart — anything listed here
     * is guaranteed to be replaced, and anything not listed is left in the
     * message as literal text rather than silently blanked.
     */
    public static function placeholders() {
        return [
            '{patient_name}'   => __("Patient's name", 'doctor-appointment'),
            '{patient_phone}'  => __("Patient's phone number", 'doctor-appointment'),
            '{doctor_name}'    => __("Doctor's name", 'doctor-appointment'),
            '{date}'           => __('Appointment date', 'doctor-appointment'),
            '{time}'           => __('Appointment time', 'doctor-appointment'),
            '{ticket}'         => __('Queue serial, e.g. Q01', 'doctor-appointment'),
            '{booking_id}'     => __('Booking ID, e.g. B000123', 'doctor-appointment'),
            '{status}'         => __('Current booking status', 'doctor-appointment'),
            '{clinic_name}'    => __('Clinic name from Settings', 'doctor-appointment'),
            '{clinic_contact}' => __('Clinic contact from Settings', 'doctor-appointment'),
        ];
    }

    /**
     * The values behind the placeholders for one booking.
     *
     * Date and time are formatted with the site's own date/time format
     * rather than the raw stored Y-m-d / H:i, because this text is read by
     * a patient on a phone, not by the code. Everything is cast to string
     * so a missing meta renders as empty rather than as "0" or "Array".
     */
    private static function build_tokens($appointment_id) {
        $doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
        $date      = get_post_meta($appointment_id, '_mdbk_appointment_date', true);
        $slot      = get_post_meta($appointment_id, '_mdbk_slot_time', true);

        // Whichever numbering mode this doctor is on, ask for the number
        // the patient actually sees on screen — a message quoting a
        // different serial to the one on the queue board is worse than no
        // message at all.
        $ticket = 0;
        if (method_exists('\MDBK\MDBK_Appointment_Manager', 'queue_serial_mode')
            && MDBK_Appointment_Manager::queue_serial_mode($doctor_id) === 'checkin') {
            $ticket = MDBK_Appointment_Manager::checkin_ticket_number($appointment_id);
        } else {
            $ticket = get_post_meta($appointment_id, '_mdbk_ticket_number', true);
        }

        return [
            '{patient_name}'   => (string) get_post_meta($appointment_id, '_mdbk_patient_name', true),
            '{patient_phone}'  => (string) get_post_meta($appointment_id, '_mdbk_patient_phone', true),
            '{doctor_name}'    => $doctor_id ? (string) get_the_title($doctor_id) : '',
            '{date}'           => $date ? date_i18n(get_option('date_format'), strtotime($date)) : '',
            '{time}'           => $slot ? date_i18n(get_option('time_format'), strtotime('1970-01-01 ' . $slot)) : '',
            '{ticket}'         => (string) MDBK_Appointment_Manager::format_ticket_number($ticket),
            '{booking_id}'     => (string) MDBK_Appointment_Manager::format_booking_id($appointment_id),
            '{status}'         => (string) MDBK_Appointment_Manager::post_status_to_slug(get_post_status($appointment_id)),
            '{clinic_name}'    => (string) get_option('mdbk_clinic_name', ''),
            '{clinic_contact}' => (string) get_option('mdbk_clinic_contact', ''),
        ];
    }

    /**
     * Substitutes tokens and tidies what's left.
     *
     * The whitespace collapse at the end is not cosmetic: a template
     * written as "... Serial: {ticket}. ..." on a booking with no serial
     * yet would otherwise send a double space and a stranded full stop,
     * and every wasted character is billed on a 70-character Unicode
     * message.
     */
    public static function render_template($template, $tokens) {
        $out = strtr((string) $template, $tokens);
        $out = preg_replace('/[ \t]+/u', ' ', $out);
        $out = preg_replace('/ ([.,])/u', '$1', $out);
        return trim($out);
    }

    /* ---------------------------------------------------------------
     * Character counting
     * ------------------------------------------------------------- */

    /** GSM 03.38 basic set — one billed character each. */
    private static function gsm_basic() {
        return '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
    }

    /** GSM 03.38 extension set — two billed characters each (escape + char). */
    private static function gsm_extended() {
        return '^{}\\[~]|€';
    }

    /**
     * How the gateway will actually bill this message.
     *
     * The whole point of surfacing this is Bangla: the moment a single
     * Bangla character appears the message becomes UCS-2 and the limit
     * drops from 160 to 70, so a template that reads as one comfortable
     * SMS in English becomes three the moment it's translated. Returns
     * the same shape the settings screen's live counter shows, so what
     * the admin sees while typing is what gets billed.
     */
    public static function count_message($message) {
        $message = (string) $message;
        $basic    = preg_split('//u', self::gsm_basic(), -1, PREG_SPLIT_NO_EMPTY);
        $extended = preg_split('//u', self::gsm_extended(), -1, PREG_SPLIT_NO_EMPTY);
        $chars    = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY);
        $chars    = is_array($chars) ? $chars : [];

        $unicode = false;
        $length  = 0;
        foreach ($chars as $c) {
            if (in_array($c, $basic, true)) {
                $length += 1;
            } elseif (in_array($c, $extended, true)) {
                $length += 2;
            } else {
                $unicode = true;
                break;
            }
        }

        if ($unicode) {
            $length  = count($chars);
            $single  = 70;
            $per_part = 67;
        } else {
            $single  = 160;
            $per_part = 153;
        }

        if ($length === 0) {
            $parts = 0;
        } elseif ($length <= $single) {
            $parts = 1;
        } else {
            $parts = (int) ceil($length / $per_part);
        }

        return [
            'encoding'  => $unicode ? 'unicode' : 'gsm',
            'length'    => $length,
            'parts'     => $parts,
            'per_part'  => $parts > 1 ? $per_part : $single,
            'remaining' => max(0, ($parts > 1 ? $per_part * $parts : $single) - $length),
        ];
    }

    /* ---------------------------------------------------------------
     * Phone numbers
     * ------------------------------------------------------------- */

    /**
     * A Bangladeshi mobile number in the form the gateway wants
     * (8801XXXXXXXXX), or '' if what came in can't be one.
     *
     * Patient numbers in this system are typed by a receptionist under
     * time pressure and arrive in every shape people write them —
     * 01712-345678, +8801712345678, 8801712345678, and, because half the
     * UI is Bangla, ০১৭১২৩৪৫৬৭৮. All of those are the same number and
     * all of them should send; anything that isn't a real BD mobile is
     * rejected here rather than paid for at the gateway.
     */
    public static function normalize_number($raw) {
        $raw = (string) $raw;

        // Bangla-Indic digits first — str_replace on the UTF-8 sequences,
        // not a locale-dependent conversion, so this behaves the same on
        // any server. (Same class of bug the tailors' migration hit.)
        $bn = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $raw = str_replace($bn, $en, $raw);

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') return '';

        if (strpos($digits, '00880') === 0)   $digits = substr($digits, 2);
        if (strpos($digits, '880') === 0)     $digits = substr($digits, 3);
        if (strpos($digits, '0') === 0)       $digits = substr($digits, 1);

        // What's left must be a bare 10-digit mobile: 1 + operator digit
        // (3-9) + 8 subscriber digits.
        if (!preg_match('/^1[3-9]\d{8}$/', $digits)) return '';

        return '880' . $digits;
    }

    /* ---------------------------------------------------------------
     * Gateway
     * ------------------------------------------------------------- */

    /**
     * The gateway's documented error codes, in words.
     *
     * Worth spelling out rather than showing the bare number: "417" on a
     * settings screen tells a clinic manager nothing, and "Insufficient
     * balance" tells them exactly what to do next.
     */
    public static function error_message($code) {
        $map = [
            0   => __('Success', 'doctor-appointment'),
            400 => __('Request rejected — a parameter is missing or invalid.', 'doctor-appointment'),
            403 => __('Permission denied for this request.', 'doctor-appointment'),
            404 => __('The requested resource was not found.', 'doctor-appointment'),
            405 => __('Authorization required — check the API key.', 'doctor-appointment'),
            409 => __('Unknown error on the SMS gateway.', 'doctor-appointment'),
            410 => __('SMS account expired.', 'doctor-appointment'),
            411 => __('Reseller account expired or suspended.', 'doctor-appointment'),
            412 => __('Invalid schedule time.', 'doctor-appointment'),
            413 => __('Invalid Sender ID — it must be approved by the gateway first.', 'doctor-appointment'),
            414 => __('Message is empty.', 'doctor-appointment'),
            415 => __('Message is too long.', 'doctor-appointment'),
            416 => __('No valid number found.', 'doctor-appointment'),
            417 => __('Insufficient balance.', 'doctor-appointment'),
            420 => __('Content blocked by the gateway.', 'doctor-appointment'),
        ];
        $code = intval($code);
        return isset($map[$code]) ? $map[$code] : sprintf(__('Gateway error %d.', 'doctor-appointment'), $code);
    }

    /**
     * One HTTP call to the gateway, normalised into a predictable array.
     *
     * The gateway answers HTTP 200 with an "error" field inside the JSON
     * even when the request failed, so an HTTP-level check alone would
     * read every rejection as a success. Both layers are checked, and
     * both failure kinds come back in the same shape so callers never
     * have to care which one happened.
     */
    private static function api_request($url, $body = null) {
        $args = [
            'timeout'   => 20,
            'sslverify' => true,
        ];

        if (is_array($body)) {
            $args['body'] = $body;
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => -1, 'message' => $response->get_error_message(), 'data' => []];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            return [
                'ok'      => false,
                'error'   => -1,
                'message' => sprintf(__('Unreadable response from the SMS gateway (HTTP %s).', 'doctor-appointment'), $code),
                'data'    => [],
            ];
        }

        $error = isset($json['error']) ? intval($json['error']) : -1;
        return [
            'ok'      => $error === 0,
            'error'   => $error,
            'message' => $error === 0
                ? (isset($json['msg']) ? (string) $json['msg'] : '')
                : self::error_message($error),
            'data'    => isset($json['data']) && is_array($json['data']) ? $json['data'] : [],
        ];
    }

    /**
     * Sends one message and writes one log entry, whatever the outcome.
     *
     * $context is only ever used for the log row (which event, which
     * booking) — it never changes what is sent, so a caller with nothing
     * useful to say can omit it.
     */
    public static function send($to, $message, $context = []) {
        $settings = self::settings();
        $number   = self::normalize_number($to);
        $message  = trim((string) $message);

        if (!self::is_enabled()) {
            return ['ok' => false, 'message' => __('SMS is turned off, or no API key is set.', 'doctor-appointment')];
        }
        if ($number === '') {
            $result = ['ok' => false, 'error' => 416, 'message' => self::error_message(416)];
            self::log($to, $message, $result, $context);
            return $result;
        }
        if ($message === '') {
            $result = ['ok' => false, 'error' => 414, 'message' => self::error_message(414)];
            self::log($number, $message, $result, $context);
            return $result;
        }
        if (mb_strlen($message) > self::MAX_LENGTH) {
            $result = ['ok' => false, 'error' => 415, 'message' => self::error_message(415)];
            self::log($number, $message, $result, $context);
            return $result;
        }

        $body = [
            'api_key' => $settings['api_key'],
            'msg'     => $message,
            'to'      => $number,
        ];
        // Only sent when the clinic actually has an approved one — posting
        // an empty sender_id is rejected as error 413 rather than ignored.
        if ($settings['sender_id'] !== '') {
            $body['sender_id'] = $settings['sender_id'];
        }

        $result = self::api_request(self::API_SEND, $body);
        self::log($number, $message, $result, $context);

        // A send changes the balance, so the cached figure on the settings
        // screen is stale the moment this returns.
        delete_transient('mdbk_sms_balance');

        return $result;
    }

    /** Account balance, cached briefly so opening the settings page repeatedly isn't a request each time. */
    public static function balance($force = false) {
        $settings = self::settings();
        if ($settings['api_key'] === '') {
            return ['ok' => false, 'message' => __('No API key set.', 'doctor-appointment'), 'balance' => ''];
        }

        if (!$force) {
            $cached = get_transient('mdbk_sms_balance');
            if (is_array($cached)) return $cached;
        }

        $result = self::api_request(self::API_BALANCE . '?api_key=' . rawurlencode($settings['api_key']));
        $out = [
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'balance' => isset($result['data']['balance']) ? (string) $result['data']['balance'] : '',
        ];
        if ($out['ok']) set_transient('mdbk_sms_balance', $out, 5 * MINUTE_IN_SECONDS);
        return $out;
    }

    /** Delivery report for one earlier send, by the request_id the gateway returned. */
    public static function report($request_id) {
        $settings = self::settings();
        if ($settings['api_key'] === '' || !$request_id) {
            return ['ok' => false, 'message' => __('No API key set.', 'doctor-appointment'), 'data' => []];
        }
        return self::api_request(
            self::API_REPORT . rawurlencode($request_id) . '/?api_key=' . rawurlencode($settings['api_key'])
        );
    }

    /* ---------------------------------------------------------------
     * Events
     * ------------------------------------------------------------- */

    /**
     * The one place a template becomes a real message.
     *
     * Guards, in order, are all "don't spend money" guards: the event has
     * to be switched on, the booking has to have a number worth sending
     * to, and the same event must not already have gone out for this
     * booking. That last one is what makes this safe to call from a
     * status transition, which fires more often than the status actually
     * changes in a way anyone would call an event.
     */
    public static function send_event($event, $appointment_id, $force = false) {
        $appointment_id = intval($appointment_id);
        if (!$appointment_id || !self::is_event_enabled($event)) return false;
        if (!$force && self::already_sent($event, $appointment_id)) return false;

        $events = self::events();
        if (!isset($events[$event])) return false;

        $settings = self::settings();
        $tokens   = self::build_tokens($appointment_id);
        $message  = self::render_template($settings['events'][$event]['template'], $tokens);

        if ($events[$event]['audience'] === 'doctor') {
            $doctor_id = intval(get_post_meta($appointment_id, '_mdbk_doctor_id', true));
            $to = $doctor_id ? get_post_meta($doctor_id, '_mdbk_doc_phone', true) : '';
        } else {
            $to = get_post_meta($appointment_id, '_mdbk_patient_phone', true);
            if (!$to) {
                // Older bookings kept the number only on the linked patient
                // record; fall back to it rather than silently not sending.
                $patient_id = intval(get_post_meta($appointment_id, '_mdbk_patient_id', true));
                if ($patient_id) $to = get_post_meta($patient_id, '_mdbk_patient_phone', true);
            }
        }

        if (!$to) return false;

        // Stamped BEFORE the call, not after: a gateway timeout that still
        // delivered would otherwise be retried on the next transition and
        // billed twice. A genuine failure is visible in the log and can be
        // resent deliberately.
        self::mark_sent($event, $appointment_id);

        $result = self::send($to, $message, ['event' => $event, 'appointment_id' => $appointment_id]);
        return !empty($result['ok']);
    }

    private static function already_sent($event, $appointment_id) {
        return get_post_meta($appointment_id, '_mdbk_sms_sent_' . $event, true) === 'yes';
    }

    private static function mark_sent($event, $appointment_id) {
        update_post_meta($appointment_id, '_mdbk_sms_sent_' . $event, 'yes');
    }

    /**
     * Booking created, called in, finished, or marked absent — all four
     * are a post status change, so all four are read off the same hook.
     */
    public function on_status_transition($new_status, $old_status, $post) {
        if (!$post || $post->post_type !== 'mdbk_appointment') return;
        if ($new_status === $old_status) return;
        if (!in_array($new_status, MDBK_CPT::APPOINTMENT_STATUSES, true)) return;

        $slug = MDBK_Appointment_Manager::post_status_to_slug($new_status);

        if ($slug === 'waiting') {
            // Only a genuinely NEW booking is a "booking confirmed" — a
            // patient sent back to Waiting from Visiting (a misclick being
            // undone, say) has not just booked anything, and telling them
            // so would be both confusing and paid for.
            if (!in_array($old_status, ['new', 'auto-draft', 'draft', ''], true)) return;
            self::send_event('booking_confirmed', $post->ID);
            self::send_event('doctor_new_booking', $post->ID);
            return;
        }

        if ($slug === 'serving')   self::send_event('serving', $post->ID);
        if ($slug === 'completed') self::send_event('completed', $post->ID);
        if ($slug === 'no_show')   self::send_event('no_show', $post->ID);
    }

    public function on_checked_in($appointment_id) {
        self::send_event('checked_in', $appointment_id);
    }

    /**
     * A reschedule is the one event that can legitimately happen more than
     * once for the same booking, so the "already sent" stamp is cleared
     * first — a patient moved twice should hear about it twice. The
     * reminder stamp goes with it: the old one was for a date that is no
     * longer happening.
     */
    public function on_rescheduled($appointment_id, $old_date, $old_slot) {
        delete_post_meta($appointment_id, '_mdbk_sms_sent_rescheduled');
        delete_post_meta($appointment_id, '_mdbk_sms_sent_reminder');
        self::send_event('rescheduled', $appointment_id);
    }

    /* ---------------------------------------------------------------
     * Reminders
     * ------------------------------------------------------------- */

    public function add_cron_schedule($schedules) {
        if (!isset($schedules['mdbk_quarter_hour'])) {
            $schedules['mdbk_quarter_hour'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 minutes (MedBook SMS)', 'doctor-appointment'),
            ];
        }
        return $schedules;
    }

    public function schedule_crons() {
        if (!wp_next_scheduled('mdbk_sms_reminder_cron')) {
            wp_schedule_event(time() + 60, 'mdbk_quarter_hour', 'mdbk_sms_reminder_cron');
        }
        if (!wp_next_scheduled('mdbk_sms_prune_log_cron')) {
            wp_schedule_event(time() + 300, 'daily', 'mdbk_sms_prune_log_cron');
        }
    }

    /**
     * Sends whatever reminders are due right now.
     *
     * Runs every 15 minutes and looks at a 15-minute-wide window ending at
     * "reminder_hours from now", so each booking falls into exactly one
     * pass. The per-booking stamp is still what actually guarantees
     * once-only — the window just keeps the query small — because cron on
     * a quiet WordPress site fires whenever someone happens to visit, not
     * on the quarter hour.
     *
     * Only ever looks at bookings still Waiting: someone already checked
     * in is standing in the room, and someone already served or marked
     * absent has nothing left to be reminded about.
     */
    public function run_reminders() {
        if (!self::is_event_enabled('reminder')) return;

        $settings = self::settings();
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);

        $target_start = $now->modify('+' . $settings['reminder_hours'] . ' hours');
        $target_end   = $target_start->modify('+15 minutes');

        // A window can straddle midnight, so both dates are queried.
        $dates = array_unique([$target_start->format('Y-m-d'), $target_end->format('Y-m-d')]);

        $appointments = get_posts([
            'post_type'      => 'mdbk_appointment',
            'post_status'    => ['mdbk_waiting'],
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_mdbk_appointment_date',
                    'value'   => $dates,
                    'compare' => 'IN',
                ],
            ],
        ]);

        foreach ($appointments as $id) {
            if (self::already_sent('reminder', $id)) continue;

            $date = get_post_meta($id, '_mdbk_appointment_date', true);
            $slot = get_post_meta($id, '_mdbk_slot_time', true);
            if (!$date || !$slot) continue;

            $when = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . substr($slot, 0, 5), $tz);
            if (!$when) continue;

            if ($when >= $target_start && $when < $target_end) {
                self::send_event('reminder', $id);
            }
        }
    }

    /* ---------------------------------------------------------------
     * Log
     * ------------------------------------------------------------- */

    /**
     * The log is a private post type rather than a table of its own — the
     * rest of this plugin models everything in WordPress's own primitives,
     * and a log is exactly the kind of thing post meta already does well
     * (queryable, exportable, deleted with the site).
     */
    public function register_log_cpt() {
        register_post_type(self::LOG_CPT, [
            'label'               => __('SMS Log', 'doctor-appointment'),
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'supports'            => ['title'],
            'capability_type'     => 'post',
        ]);
    }

    private static function log($to, $message, $result, $context = []) {
        $counts = self::count_message($message);
        $id = wp_insert_post([
            'post_type'   => self::LOG_CPT,
            'post_status' => 'publish',
            'post_title'  => $to . ' — ' . (isset($context['event']) ? $context['event'] : 'manual'),
            'post_content' => '',
        ]);
        if (!$id || is_wp_error($id)) return;

        update_post_meta($id, '_mdbk_sms_to', $to);
        update_post_meta($id, '_mdbk_sms_message', $message);
        update_post_meta($id, '_mdbk_sms_event', isset($context['event']) ? $context['event'] : 'manual');
        update_post_meta($id, '_mdbk_sms_appointment_id', isset($context['appointment_id']) ? intval($context['appointment_id']) : 0);
        update_post_meta($id, '_mdbk_sms_status', !empty($result['ok']) ? 'sent' : 'failed');
        update_post_meta($id, '_mdbk_sms_error', !empty($result['ok']) ? '' : (isset($result['message']) ? $result['message'] : ''));
        update_post_meta($id, '_mdbk_sms_request_id', isset($result['data']['request_id']) ? $result['data']['request_id'] : '');
        update_post_meta($id, '_mdbk_sms_parts', $counts['parts']);
        update_post_meta($id, '_mdbk_sms_encoding', $counts['encoding']);
    }

    /** Most recent log rows, newest first, for the settings screen. */
    public static function recent_log($limit = 25) {
        return get_posts([
            'post_type'      => self::LOG_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => intval($limit),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
    }

    /**
     * Drops log rows past the retention window. A retention of 0 means
     * "keep everything" — an explicit choice a clinic can make, not an
     * accident, since the setting's own field refuses negatives.
     */
    public function prune_log() {
        $settings = self::settings();
        if ($settings['log_retention'] < 1) return;

        $cutoff = (new \DateTimeImmutable('now', wp_timezone()))
            ->modify('-' . $settings['log_retention'] . ' days')
            ->format('Y-m-d H:i:s');

        $old = get_posts([
            'post_type'      => self::LOG_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'date_query'     => [['before' => $cutoff, 'inclusive' => false]],
        ]);
        foreach ($old as $id) wp_delete_post($id, true);
    }

    /* ---------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------- */

    public function ajax_balance() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }
        $result = self::balance(true);
        if (!$result['ok']) wp_send_json_error(['message' => $result['message']]);
        wp_send_json_success(['balance' => $result['balance']]);
    }

    public function ajax_test_send() {
        check_ajax_referer('mdbk_admin_nonce', 'nonce');
        if (!current_user_can(MDBK_CAP_ADMIN)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'doctor-appointment')]);
        }

        $to      = isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if (self::normalize_number($to) === '') {
            wp_send_json_error(['message' => __('That does not look like a Bangladeshi mobile number.', 'doctor-appointment')]);
        }
        if (trim($message) === '') {
            wp_send_json_error(['message' => __('Write a message to send.', 'doctor-appointment')]);
        }

        $result = self::send($to, $message, ['event' => 'test']);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message']]);
        }
        wp_send_json_success([
            'message' => sprintf(
                __('Test message sent to %s.', 'doctor-appointment'),
                self::normalize_number($to)
            ),
        ]);
    }
}

new MDBK_SMS();
