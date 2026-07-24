<?php
/**
 * Plugin Name: Doctor Appointment Booking
 * Plugin URI: https://wordpress.org/plugins/doctor-appointment
 * Description: Create doctor appointment booking forms for clinics, hospitals, and medical centers with an easy scheduling system.
 * Version: 1.1.0
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
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
            // Self-heals existing installs that pull code updates without
            // reactivating the plugin (activate() alone would miss them).
            add_action( 'init', array( '\MDBK\MDBK_Migrations', 'maybe_migrate' ), 20 );
        }

        /**
         * Define Plugin Constants
         */
        public function define_constants() {

            define( 'MDBK_PATH', plugin_dir_path( __FILE__ ) );
            define( 'MDBK_URL', plugin_dir_url( __FILE__ ) );
            define( 'MDBK_VERSION', '1.1.0' );
            define( 'MDBK_CAP_QUEUE', 'manage_mdbk_queue' );
            define( 'MDBK_CAP_DOCTOR', 'view_own_mdbk_queue' );
        }

        /**
         * Include Required Files
         */
        public static function include_plugin_files() {

            require_once MDBK_PATH . 'includes/cpt-register.php';
            require_once MDBK_PATH . 'includes/migrations.php';
            require_once MDBK_PATH . 'includes/roles.php';
            require_once MDBK_PATH . 'includes/shortcode.php';
            require_once MDBK_PATH . 'includes/appointment-manager.php';
            require_once MDBK_PATH . 'includes/admin-dashboard.php';
            require_once MDBK_PATH . 'includes/notifications.php';
            require_once MDBK_PATH . 'includes/seeder.php';
        }

        /**
         * Enqueue Admin Styles
         */
        public function admin_enqueue_scripts($hook) {
            if (strpos($hook, 'mdbk') === false) {
                return;
            }
            $admin_js_ver = filemtime(MDBK_PATH . 'assets/js/admin-script.js');
            wp_enqueue_media(); // needed for the doctor photo picker's wp.media() uploader
            wp_enqueue_style('mdbk-admin-style', MDBK_URL . 'assets/css/admin-style.css', array(), filemtime( MDBK_PATH . 'assets/css/admin-style.css' ));
            wp_enqueue_style('front-end-style', MDBK_URL . 'assets/css/front-end.css', array(), filemtime( MDBK_PATH . 'assets/css/front-end.css' ));
            wp_enqueue_script('mdbk-admin-script', MDBK_URL . 'assets/js/admin-script.js', array(), $admin_js_ver, true);
            wp_localize_script( 'mdbk-admin-script', 'mdbk_admin_obj', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'mdbk_admin_nonce' ),
                // The admin scheduling calendar's "today" must follow the
                // site's configured timezone (Settings > General), not
                // whatever timezone the admin's own browser happens to be
                // in — current_time() is WP's timezone-aware clock.
                'today'    => current_time( 'Y-m-d' ),
            ]);

            // The doctor-restricted "My Queue" page embeds the same
            // live-polling queue widget the public Live Queue shortcode
            // uses — give it the same CSS/JS + read-only nonce here.
            // Uses $_GET['page'] since $hook differs between top-level
            // and submenu registration contexts.
            if (isset($_GET['page']) && $_GET['page'] === 'mdbk-my-queue') {
                wp_enqueue_style('mdbk-queue-style', MDBK_URL . 'assets/css/queue-style.css', array(), filemtime( MDBK_PATH . 'assets/css/queue-style.css' ));
                $queue_view_js_ver = filemtime(MDBK_PATH . 'assets/js/queue-view-script.js');
                wp_enqueue_script('mdbk-queue-view-script', MDBK_URL . 'assets/js/queue-view-script.js', array(), $queue_view_js_ver, true);
                wp_localize_script('mdbk-queue-view-script', 'mdbk_queue_view_obj', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('mdbk_view_queue'),
                ]);
            }

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
            wp_enqueue_style( 'mdbk-front-end', MDBK_URL . 'assets/css/front-end.css', array(), filemtime( MDBK_PATH . 'assets/css/front-end.css' ) );
            wp_enqueue_style( 'mdbk-form-style', MDBK_URL . 'assets/css/form-style.css', array(), filemtime( MDBK_PATH . 'assets/css/form-style.css' ) );
            wp_enqueue_style( 'mdbk-queue-style', MDBK_URL . 'assets/css/queue-style.css', array(), filemtime( MDBK_PATH . 'assets/css/queue-style.css' ) );

            // Vendored qrcode-generator (kazuhikoarase, MIT) — renders the
            // check-in QR entirely client-side, no third-party service ever
            // sees patient/appointment data.
            wp_enqueue_script( 'mdbk-qrcode', MDBK_URL . 'assets/js/vendor/qrcode.js', array(), filemtime( MDBK_PATH . 'assets/js/vendor/qrcode.js' ), true );

            // Hand-built calendar (no flatpickr) — sidesteps the active theme's
            // aggressive button/select/input[type=number] resets entirely by
            // using <span> day cells instead of form controls.
            wp_enqueue_script( 'mdbk-form-script', MDBK_URL . 'assets/js/form-script.js', array( 'mdbk-qrcode' ), $form_js_ver, true );
            wp_localize_script( 'mdbk-form-script', 'mdbk_form_obj', [
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
            ]);
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