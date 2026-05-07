<?php
/**
 * Plugin Name: Doctor Appointment Booking
 * Plugin URI: https://wordpress.org/plugins/doctor-appointment
 * Description: Create doctor appointment booking forms for clinics, hospitals, and medical centers with an easy scheduling system.
 * Version: 1.0.0
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
        }

        /**
         * Define Plugin Constants
         */
        public function define_constants() {

            define( 'MDBK_PATH', plugin_dir_path( __FILE__ ) );
            define( 'MDBK_URL', plugin_dir_url( __FILE__ ) );
            define( 'MDBK_VERSION', '1.0.0' );
        }

        /**
         * Include Required Files
         */
        public static function include_plugin_files() {

            require_once MDBK_PATH . 'includes/cpt-register.php';
            require_once MDBK_PATH . 'includes/shortcode.php';
            require_once MDBK_PATH . 'includes/appointment-manager.php';
            require_once MDBK_PATH . 'includes/admin-dashboard.php';
        }

        /**
         * Enqueue Admin Styles
         */
        public function admin_enqueue_scripts($hook) {
            if (strpos($hook, 'mdbk') === false) {
                return;
            }
            wp_enqueue_style('mdbk-admin-style', MDBK_URL . 'assets/css/admin-style.css', array(), MDBK_VERSION);
            wp_enqueue_script('mdbk-admin-script', MDBK_URL . 'assets/js/admin-script.js', array(), MDBK_VERSION, true);
        }

        /**
         * Enqueue Styles and Scripts
         */
        public function enqueue_scripts() {
            wp_enqueue_style( 'mdbk-form-style', MDBK_URL . 'assets/css/form-style.css', array(), MDBK_VERSION );
            wp_enqueue_style( 'mdbk-queue-style', MDBK_URL . 'assets/css/queue-style.css', array(), MDBK_VERSION );
        }


        /**
         * Plugin Activation
         */
        public static function activate() {

            update_option( 'rewrite_rules', '' );

            if ( ! get_option( 'mdbk_dummy_import_done' ) ) {
                update_option( 'mdbk_dummy_import_notice', true );
            }

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

            unregister_post_type( 'mdbk_appointment' );

            delete_option( 'mdbk_archive_template_lists' );
            delete_option( 'mdbk_archive_title' );
            delete_option( 'mdbk_archive_slug' );
            delete_option( 'mdbk_archive_template' );
            delete_option( 'mdbk_archive_booking' );
            delete_option( 'mdbk_css_colors' );
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