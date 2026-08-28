<?php
/**
 * Plugin Name: Doctor Appointment Booking
 * Plugin URI: https://wordpress.org/plugins/doctor-appointment
 * Description: Create doctor appointment booking forms for clinics, hospitals, and medical centers with an easy scheduling system.
 * Version: 1.2.0
 * Stable Tag: trunk
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Author: Shahadat Hossain
 * Author URI: https://shahadat.com.bd
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: doctor-appointment
 * Domain Path: /languages
 */

/*
Doctor Appointment Booking is free software:
you can redistribute it and/or modify it under the terms
of the GNU General Public License as published by the
Free Software Foundation, either version 2 of the License,
or any later version.

This plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty
of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this plugin. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MDBK_Doctor_Appointment' ) ) {

    class MDBK_Doctor_Appointment {

        public function __construct() {

            $this->define_constants();
            $this->include_plugin_files();
            // Not a WordPress.org-hosted plugin, so translations are never
            // auto-loaded the way core/WP.org plugins get for free — this
            // is the one line that actually makes languages/*.mo get read.
            // Language itself just follows WordPress's own site/per-user
            // settings (Settings > General, or each admin's own Profile
            // page) — no separate in-plugin language switcher.
            add_action( 'init', array( $this, 'load_textdomain' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
            // Self-heals existing installs that pull code updates without
            // reactivating the plugin (activate() alone would miss them).
            add_action( 'init', array( '\MDBK\MDBK_Migrations', 'maybe_migrate' ), 20 );
        }

        public function load_textdomain() {
            load_plugin_textdomain( 'doctor-appointment', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        /**
         * Define Plugin Constants
         */
        public function define_constants() {

            define( 'MDBK_PATH', plugin_dir_path( __FILE__ ) );
            define( 'MDBK_URL', plugin_dir_url( __FILE__ ) );
            define( 'MDBK_VERSION', '1.2.0' );
            define( 'MDBK_CAP_QUEUE', 'manage_mdbk_queue' );
            define( 'MDBK_CAP_DOCTOR', 'view_own_mdbk_queue' );
            // Everything this plugin's own admin-only pages/actions check
            // internally (Dashboard, Doctors, Staff, Specialties, delete
            // actions, etc.) — deliberately a SEPARATE capability from core
            // WordPress's own 'manage_options', not an alias for it, so
            // MDBK_Roles::activate() can grant this to the Manager role
            // WITHOUT also granting them 'manage_options' itself (which
            // would additionally open every native wp-admin area — Plugins,
            // Themes, Settings, Users — 'manage_options' is checked
            // directly by that core code, which this plugin has no way to
            // intercept/redefine). An administrator gets both.
            define( 'MDBK_CAP_ADMIN', 'manage_mdbk_clinic' );
        }

        /**
         * Include Required Files
         */
        public static function include_plugin_files() {

            // Plain lookup data (BD districts/thanas) with no side effects,
            // loaded before anything that renders a form using it.
            require_once MDBK_PATH . 'includes/bd-locations.php';
            require_once MDBK_PATH . 'includes/cpt-register.php';
            require_once MDBK_PATH . 'includes/migrations.php';
            require_once MDBK_PATH . 'includes/roles.php';
            require_once MDBK_PATH . 'includes/shortcode.php';
            require_once MDBK_PATH . 'includes/appointment-manager.php';
            require_once MDBK_PATH . 'includes/admin-dashboard.php';
            require_once MDBK_PATH . 'includes/notifications.php';
            require_once MDBK_PATH . 'includes/seeder.php';
            require_once MDBK_PATH . 'includes/licensing.php';
        }

        /**
         * Enqueue Admin Styles
         */
        /**
         * Clinic branding (Global Settings) as a plain array — shared by
         * both the admin and frontend localized script data, since the
         * per-doctor print/image report (admin) and the patient-facing
         * booking confirmation print/image (frontend) both need to show
         * the same logo/name/contact/address header.
         */
        private function clinic_branding_data() {
            $logo_id = get_option( 'mdbk_clinic_logo', 0 );
            return [
                'clinic_name'    => get_option( 'mdbk_clinic_name', '' ),
                'clinic_logo'    => $logo_id ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : '',
                'clinic_contact' => get_option( 'mdbk_clinic_contact', '' ),
                'clinic_address' => get_option( 'mdbk_clinic_address', '' ),
                // Whole-system 12/24-hour display is WordPress's own
                // Settings > General > Time Format — deliberately not a
                // second, separate setting in this plugin's own Global
                // Settings. No 'a'/'A' (meridiem) token in the admin's
                // configured format is treated as an explicit 24-hour
                // preference; JS-side time displays (doctor availability
                // list, schedule "View" popup, booking slot buttons) key
                // off this same flag so they never disagree with what
                // date_i18n(get_option('time_format'), ...) already
                // produces server-side.
                'time_format_24h' => stripos( get_option( 'time_format', 'g:i a' ), 'a' ) === false,
            ];
        }

        public function admin_enqueue_scripts($hook) {
            if (strpos($hook, 'mdbk') === false) {
                return;
            }
            $admin_js_ver = filemtime(MDBK_PATH . 'assets/js/admin-script.js');
            wp_enqueue_media(); // needed for the doctor photo picker's wp.media() uploader

            // Doctor Directory's card-grid drag-to-reorder — SortableJS (MIT,
            // vendored — RubaXa/SortableJS), not jQuery UI Sortable: jQuery
            // UI's own sortable was built for normal block/list flow and
            // drags items via absolute-positioning math that doesn't account
            // for CSS Grid track layout (.mdbk-admin-doctor-grid is
            // `display: grid`) — it would intermittently miscalculate the
            // drop target and silently fail to reorder. SortableJS moves the
            // real DOM node during drag instead, which the grid just
            // reflows around, and needs no jQuery UI dependency at all.
            // Registered as an explicit admin-script.js dependency (not just
            // enqueued alongside it) so window.Sortable is always defined
            // before admin-script.js's own DOMContentLoaded handler runs,
            // regardless of enqueue call order.
            $admin_script_deps = array();
            if (isset($_GET['page']) && $_GET['page'] === 'mdbk-doctors') {
                wp_enqueue_script('mdbk-sortable', MDBK_URL . 'assets/js/vendor/sortable.min.js', array(), filemtime( MDBK_PATH . 'assets/js/vendor/sortable.min.js' ), true);
                $admin_script_deps[] = 'mdbk-sortable';
            }
            // Bengali web font — 'Inter' (this panel's own font stack, see
            // admin-style.css) has no Bengali glyphs at all, so without this
            // a Bangla-translated UI (see languages/doctor-appointment-bn_BD.mo)
            // would fall back to whatever Bengali font the visitor's OS
            // happens to ship, which varies wildly in quality/availability.
            // Self-hosted Kalpurush was tried first, per the site owner's
            // own request, but its sizing didn't read consistently against
            // Inter across the panel — Hind Siliguri (Google Fonts) has
            // metrics deliberately balanced against Latin UI sans fonts, so
            // its size actually matches Inter/system fonts at the same
            // font-size instead of looking mismatched from one spot to
            // the next.
            wp_enqueue_style('mdbk-bn-font', 'https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap', array(), null);
            wp_enqueue_style('mdbk-admin-style', MDBK_URL . 'assets/css/admin-style.css', array('mdbk-bn-font'), filemtime( MDBK_PATH . 'assets/css/admin-style.css' ));
            wp_enqueue_style('front-end-style', MDBK_URL . 'assets/css/front-end.css', array(), filemtime( MDBK_PATH . 'assets/css/front-end.css' ));
            wp_enqueue_script('mdbk-admin-script', MDBK_URL . 'assets/js/admin-script.js', $admin_script_deps, $admin_js_ver, true);

            wp_localize_script( 'mdbk-admin-script', 'mdbk_admin_obj', array_merge( [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mdbk_admin_nonce' ),
                // Lets the Add/Edit Booking modal's date calendar call the
                // SAME get_doctor_schedule() AJAX handler the public booking
                // form already uses (appointment-manager.php) — one nonce
                // action name, valid for either the current admin user or a
                // logged-out visitor, so there's no reason to duplicate that
                // handler just to gate it under mdbk_admin_nonce instead.
                'form_nonce' => wp_create_nonce( 'mdbk_form_nonce' ),
                // The admin scheduling calendar's "today" must follow the
                // site's configured timezone (Settings > General), not
                // whatever timezone the admin's own browser happens to be
                // in — current_time() is WP's timezone-aware clock.
                'today'    => current_time( 'Y-m-d' ),
                // Doctor Edit form's Weekly Availability day order — one
                // source of truth (Settings > General > "Week Starts On")
                // instead of a second hardcoded Monday-first list drifting
                // out of sync with the PHP-rendered form's own order.
                'week_days' => \MDBK\MDBK_Appointment_Manager::get_week_day_order(),
                // District => thanas map for the Booking and Patient
                // modals' dependent address selects — same data the public
                // form gets (mdbk_form_obj.locations), so the two can't
                // disagree about what a valid pair is.
                'locations' => \MDBK\MDBK_BD_Locations::all(),
                'i18n_select_district' => __( 'Select district', 'doctor-appointment' ),
                'i18n_select_thana'    => __( 'Select thana', 'doctor-appointment' ),
                'i18n_no_thana'        => __( 'No thana found', 'doctor-appointment' ),
                'i18n_district_first'  => __( 'Select district first', 'doctor-appointment' ),
                'i18n_search'          => __( 'Search…', 'doctor-appointment' ),
                'i18n_no_match'        => __( 'No match found', 'doctor-appointment' ),
                'i18n_clear'           => __( 'Clear selection', 'doctor-appointment' ),
                'i18n_location_required' => __( 'Please choose a district and thana.', 'doctor-appointment' ),
                // Global Settings > Booking Form Fields — read by the
                // Add/Edit Booking modal's own submit-guard
                // (admin-script.js), same source of truth
                // MDBK_Appointment_Manager::is_field_required('address')
                // gives the server. render_appointment_modal_html()'s own
                // PHP conditional is what actually removes the District/
                // Thana elements from the DOM when the field is hidden.
                'address_required' => \MDBK\MDBK_Appointment_Manager::is_field_required( 'address' ),
                // Translated day-name labels for the JS-rendered "Doctor
                // View" read-only modal (admin-script.js) — the day names
                // themselves (this array's KEYS) have to stay literal
                // English everywhere else (the _mdbk_schedule meta's own
                // array keys, the Edit form's schedule[Monday][active]
                // field names), so only these VALUES are ever shown to a
                // user as text. See get_day_labels() in
                // appointment-manager.php.
                'day_labels' => \MDBK\MDBK_Appointment_Manager::get_day_labels(),
                'off_label'  => __( 'Off', 'doctor-appointment' ),
            ], $this->clinic_branding_data() ) );

            // The "Chamber QR" page renders its QR client-side with the same
            // vendored encoder the frontend booking confirmation already
            // uses — it's only ever enqueued on the frontend otherwise, so
            // it needs its own enqueue here. Checked via $_GET['page'] (not
            // $hook) since add_submenu_page(null, ...) hidden pages don't
            // have a predictable, easy-to-match hook suffix.
            if (isset($_GET['page']) && $_GET['page'] === 'mdbk-chamber-qr') {
                wp_enqueue_script('mdbk-qrcode', MDBK_URL . 'assets/js/vendor/qrcode.js', array(), filemtime( MDBK_PATH . 'assets/js/vendor/qrcode.js' ), true);
            }

            // "Change Password" reuses WP core's OWN password-strength-meter
            // script (zxcvbn — the same library/scoring wp-admin's native
            // profile.php uses) rather than a hand-rolled scorer, for a
            // genuinely standard strength calculation instead of an
            // approximation. dashicons is core's own icon font, already
            // available everywhere in wp-admin — no extra enqueue needed
            // for the show/hide icon.
            if (isset($_GET['page']) && $_GET['page'] === 'mdbk-change-password') {
                wp_enqueue_script('password-strength-meter');
            }
        }

        /**
         * Enqueue Styles and Scripts
         */
        public function enqueue_scripts() {
            $form_js_ver = filemtime(MDBK_PATH . 'assets/js/form-script.js');
            // Same Google Fonts Hind Siliguri as admin_enqueue_scripts() —
            // see that comment. Patient-facing pages (doctor list, booking
            // widget, Live Queue) are exactly where a Bangla-translated UI
            // actually gets read by end users, not just staff.
            wp_enqueue_style('mdbk-bn-font', 'https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap', array(), null);
            wp_enqueue_style( 'mdbk-front-end', MDBK_URL . 'assets/css/front-end.css', array('mdbk-bn-font'), filemtime( MDBK_PATH . 'assets/css/front-end.css' ) );
            wp_enqueue_style( 'mdbk-form-style', MDBK_URL . 'assets/css/form-style.css', array('mdbk-bn-font'), filemtime( MDBK_PATH . 'assets/css/form-style.css' ) );
            wp_enqueue_style( 'mdbk-queue-style', MDBK_URL . 'assets/css/queue-style.css', array('mdbk-bn-font'), filemtime( MDBK_PATH . 'assets/css/queue-style.css' ) );

            // Global Settings' Primary/Secondary Color as CSS custom
            // properties — every frontend color declaration across
            // front-end.css/form-style.css/queue-style.css reads from
            // these (each file also has its own hardcoded :root fallback,
            // so nothing breaks if this option is somehow missing), so
            // changing the two settings re-themes every patient-facing
            // button/highlight/link at once instead of needing a CSS edit.
            // Re-validated through sanitize_hex_color() here too (not just
            // at save time) since this gets echoed directly into a <style>
            // tag — a corrupted or hand-edited option value should just
            // fall back to the default, never reach the page as raw CSS.
            $primary_color   = sanitize_hex_color( get_option( 'mdbk_color_primary', '' ) ) ?: \MDBK\MDBK_Admin_Dashboard::DEFAULT_COLOR_PRIMARY;
            $secondary_color = sanitize_hex_color( get_option( 'mdbk_color_secondary', '' ) ) ?: \MDBK\MDBK_Admin_Dashboard::DEFAULT_COLOR_SECONDARY;
            wp_add_inline_style( 'mdbk-front-end', ':root{--mdbk-primary:' . $primary_color . ';--mdbk-primary-dark:color-mix(in srgb, var(--mdbk-primary) 85%, black);--mdbk-secondary:' . $secondary_color . ';}' );

            // Vendored qrcode-generator (kazuhikoarase, MIT) — renders the
            // check-in QR entirely client-side, no third-party service ever
            // sees patient/appointment data.
            wp_enqueue_script( 'mdbk-qrcode', MDBK_URL . 'assets/js/vendor/qrcode.js', array(), filemtime( MDBK_PATH . 'assets/js/vendor/qrcode.js' ), true );

            // Hand-built calendar (no flatpickr) — sidesteps the active theme's
            // aggressive button/select/input[type=number] resets entirely by
            // using <span> day cells instead of form controls.
            wp_enqueue_script( 'mdbk-form-script', MDBK_URL . 'assets/js/form-script.js', array( 'mdbk-qrcode' ), $form_js_ver, true );
            wp_localize_script( 'mdbk-form-script', 'mdbk_form_obj', array_merge( [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mdbk_form_nonce' ),
                // Booking calendar's "today" must follow the site's
                // configured timezone (Settings > General), not the
                // visitor's own browser/device timezone — a patient
                // browsing from a different timezone than the clinic must
                // still see the clinic's actual "today" as the cutoff for
                // past/bookable dates. current_time() is WP's
                // timezone-aware clock (get_option('timezone_string')/
                // gmt_offset), unlike JS's new Date().
                'today'    => current_time( 'Y-m-d' ),
                // The whole district => thanas map, so picking a district
                // refills the Thana select instantly instead of waiting on
                // a round trip for a list that never changes.
                'locations' => \MDBK\MDBK_BD_Locations::all(),
                // The Thana trigger's own label is rewritten in JS as the
                // district changes, so its two states have to be
                // translatable from here rather than from the template.
                'i18n_select_thana' => __( 'Select thana', 'doctor-appointment' ),
                'i18n_no_thana'     => __( 'No thana found', 'doctor-appointment' ),
                'i18n_district_first' => __( 'Select district first', 'doctor-appointment' ),
                'i18n_search'       => __( 'Search…', 'doctor-appointment' ),
                'i18n_no_match'     => __( 'No match found', 'doctor-appointment' ),
                'i18n_clear'        => __( 'Clear selection', 'doctor-appointment' ),
                'i18n_location_required' => __( 'Please choose a district and thana.', 'doctor-appointment' ),
                // Global Settings > Booking Form Fields — read by the
                // public form's own submit-guard (form-script.js); the
                // template's own PHP conditional (render_booking_widget_fields())
                // is what actually removes the District/Thana elements
                // from the DOM when the field is hidden.
                'address_required' => \MDBK\MDBK_Appointment_Manager::is_field_required( 'address' ),
            ], $this->clinic_branding_data() ) );
        }


        /**
         * Plugin Activation
         */
        public static function activate() {
            
            if (!defined('MDBK_PATH')) {
                define( 'MDBK_PATH', plugin_dir_path( __FILE__ ) );
            }

            self::include_plugin_files();

            $cpt = new \MDBK\MDBK_CPT();
            $cpt->create_taxonomy();
            $cpt->create_post_type();
            $cpt->register_appointment_statuses();

            \MDBK\MDBK_Roles::activate();
            \MDBK\MDBK_Migrations::maybe_migrate();
            \MDBK\MDBK_Seeder::seed_data();

            update_option( 'rewrite_rules', '' );
            flush_rewrite_rules();
        }

        /**
         * Plugin Deactivation
         */
        public static function deactivate() {

            \MDBK\MDBK_Licensing::clear_scheduled_check();
            flush_rewrite_rules();
        }

        /**
         * Plugin Uninstall
         */
        public static function uninstall() {

            if (!defined('MDBK_PATH')) {
                define( 'MDBK_PATH', plugin_dir_path( __FILE__ ) );
            }
            require_once MDBK_PATH . 'includes/roles.php';
            \MDBK\MDBK_Roles::uninstall();

            unregister_post_type( 'mdbk_appointment' );

            delete_option( 'mdbk_archive_template_lists' );
            delete_option( 'mdbk_archive_title' );
            delete_option( 'mdbk_archive_slug' );
            delete_option( 'mdbk_archive_template' );
            delete_option( 'mdbk_archive_booking' );
            delete_option( 'mdbk_css_colors' );
            delete_option( 'mdbk_db_version' );
            delete_option( 'mdbk_dummy_data_seeded' );
        }
    }
}

/**
 * Initialize Plugin
 */
if ( class_exists( 'MDBK_Doctor_Appointment' ) ) {

    register_activation_hook(
        __FILE__,
        array( 'MDBK_Doctor_Appointment', 'activate' )
    );

    register_deactivation_hook(
        __FILE__,
        array( 'MDBK_Doctor_Appointment', 'deactivate' )
    );

    register_uninstall_hook(
        __FILE__,
        array( 'MDBK_Doctor_Appointment', 'uninstall' )
    );

    new MDBK_Doctor_Appointment();
}