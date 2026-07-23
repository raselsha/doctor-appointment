<?php
/**
 * @author Shahadat Hossain <raselsha@gmail.com>
 * @version 1.0.1
 */
namespace MDBK;

defined('ABSPATH') || exit;

class MDBK_CPT {

    /**
     * All registered appointment lifecycle statuses.
     *
     * Use this instead of post_status => 'any' anywhere mdbk_appointment is
     * queried by status — 'any' silently excludes exclude_from_search statuses.
     */
    const APPOINTMENT_STATUSES = [ 'mdbk_waiting', 'mdbk_serving', 'mdbk_completed', 'mdbk_no_show' ];

    public function __construct() {
        add_action( 'init', [$this, 'create_post_type'] );
        add_action( 'init', [$this, 'create_taxonomy'] );
        add_action( 'init', [$this, 'register_appointment_statuses'] );
        add_action( 'admin_menu', [$this, 'remove_default_menus'], 999 );
    }

    /**
     * Register Appointment Lifecycle Statuses
     *
     * Note: exclude_from_search => true means WP_Query's post_status => 'any'
     * silently drops these statuses. Callers must always pass an explicit
     * status array (see MDBK_APPOINTMENT_STATUSES) instead of relying on 'any'.
     */
    public function register_appointment_statuses() {
        register_post_status( 'mdbk_waiting', [
            'label'                     => _x( 'Waiting', 'appointment status', 'doctor-appointment' ),
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Waiting <span class="count">(%s)</span>', 'Waiting <span class="count">(%s)</span>', 'doctor-appointment' ),
        ] );

        register_post_status( 'mdbk_serving', [
            'label'                     => _x( 'Visiting', 'appointment status', 'doctor-appointment' ),
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Visiting <span class="count">(%s)</span>', 'Visiting <span class="count">(%s)</span>', 'doctor-appointment' ),
        ] );

        register_post_status( 'mdbk_completed', [
            'label'                     => _x( 'Completed', 'appointment status', 'doctor-appointment' ),
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Completed <span class="count">(%s)</span>', 'Completed <span class="count">(%s)</span>', 'doctor-appointment' ),
        ] );

        register_post_status( 'mdbk_no_show', [
            'label'                     => _x( 'No Show', 'appointment status', 'doctor-appointment' ),
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'No Show <span class="count">(%s)</span>', 'No Show <span class="count">(%s)</span>', 'doctor-appointment' ),
        ] );
    }

    /**
     * Remove Default Menus
     */
    public function remove_default_menus() {
        remove_menu_page( 'edit.php?post_type=mdbk_doctor' );
        remove_menu_page( 'edit.php?post_type=mdbk_appointment' );
    }        

    /**
     * Create Taxonomy
     */
    public function create_taxonomy() {
        $labels = [
            'name'              => _x( 'Departments', 'taxonomy general name', 'doctor-appointment' ),
            'singular_name'     => _x( 'Department', 'taxonomy singular name', 'doctor-appointment' ),
            'search_items'      => __( 'Search Departments', 'doctor-appointment' ),
            'all_items'         => __( 'All Departments', 'doctor-appointment' ),
            'parent_item'       => __( 'Parent Department', 'doctor-appointment' ),
            'parent_item_colon' => __( 'Parent Department:', 'doctor-appointment' ),
            'edit_item'         => __( 'Edit Department', 'doctor-appointment' ),
            'update_item'       => __( 'Update Department', 'doctor-appointment' ),
            'add_new_item'      => __( 'Add New Department', 'doctor-appointment' ),
            'new_item_name'     => __( 'New Department Name', 'doctor-appointment' ),
            'menu_name'         => __( 'Departments', 'doctor-appointment' ),
        ];

        $args = [
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_in_menu'      => false,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => [ 'slug' => 'department' ],
            'show_in_rest'      => true,
        ];

        register_taxonomy( 'mdbk_department', [ 'mdbk_doctor' ], $args );
    }

    /**
     * Create Post Type
     */
    public function create_post_type() {
        
        // Doctor Post Type
        $doctor_labels = [
            "name"               => __( "Doctors", 'doctor-appointment' ),
            "singular_name"      => __( "Doctor", 'doctor-appointment' ),
            "menu_name"          => __( "Doctors", 'doctor-appointment' ),
            "all_items"          => __( "All Doctors", 'doctor-appointment' ),
            "add_new"            => __( "Add New Doctor", 'doctor-appointment' ),
            "add_new_item"       => __( "Add New Doctor", 'doctor-appointment' ),
            "edit_item"          => __( "Edit Doctor", 'doctor-appointment' ),
            "new_item"           => __( "New Doctor", 'doctor-appointment' ),
            "view_item"          => __( "View Doctor", 'doctor-appointment' ),
            "search_items"       => __( "Search Doctors", 'doctor-appointment' ),
            "not_found"          => __( "No Doctors found", 'doctor-appointment' ),
            "not_found_in_trash" => __( "No Doctors found in Trash", 'doctor-appointment' ),
        ];

        $doctor_args = [
            "label"               => __( "Doctors", 'doctor-appointment' ),
            "labels"              => $doctor_labels,
            "public"              => true,
            "supports"            => [ "title", "editor", "thumbnail", "excerpt" ],
            "show_ui"             => true,
            "show_in_menu"        => false,
            "menu_position"       => 5,
            "menu_icon"           => 'dashicons-businessman',
            "show_in_admin_bar"   => true,
            "show_in_nav_menus"   => true,
            "can_export"          => true,
            "has_archive"         => true,
            "exclude_from_search" => false,
            "publicly_queryable"  => true,
            "show_in_rest"        => true,
            "rewrite"             => [ "slug" => "doctors", "with_front" => true ],
        ];

        register_post_type( "mdbk_doctor", $doctor_args );

        // Appointment Post Type
        $appointment_labels = [
            "name"               => __( "Appointments", 'doctor-appointment' ),
            "singular_name"      => __( "Appointment", 'doctor-appointment' ),
            "menu_name"          => __( "Appointments", 'doctor-appointment' ),
            "all_items"          => __( "All Appointments", 'doctor-appointment' ),
            "add_new"            => __( "Add New Appointment", 'doctor-appointment' ),
            "add_new_item"       => __( "Add New Appointment", 'doctor-appointment' ),
            "edit_item"          => __( "Edit Appointment", 'doctor-appointment' ),
            "new_item"           => __( "New Appointment", 'doctor-appointment' ),
            "view_item"          => __( "View Appointment", 'doctor-appointment' ),
            "search_items"       => __( "Search Appointments", 'doctor-appointment' ),
            "not_found"          => __( "No Appointments found", 'doctor-appointment' ),
            "not_found_in_trash" => __( "No Appointments found in Trash", 'doctor-appointment' ),
        ];

        $appointment_args = [
            "label"               => __( "Appointments", 'doctor-appointment' ),
            "labels"              => $appointment_labels,
            "public"              => false, // Usually appointments are private records
            "show_ui"             => true,
            "show_in_menu"        => false, // Show as sub-menu of Doctors
            "supports"            => [ "title", "editor" ],
            "menu_icon"           => 'dashicons-calendar-alt',
            "can_export"          => true,
            "has_archive"         => false,
            "exclude_from_search" => true,
            "publicly_queryable"  => false,
            "show_in_rest"        => true,
        ];

        register_post_type( "mdbk_appointment", $appointment_args );

        // Patient Post Type
        $patient_labels = [
            "name"               => __( "Patients", 'doctor-appointment' ),
            "singular_name"      => __( "Patient", 'doctor-appointment' ),
            "menu_name"          => __( "Patients", 'doctor-appointment' ),
            "all_items"          => __( "All Patients", 'doctor-appointment' ),
            "add_new"            => __( "Add New Patient", 'doctor-appointment' ),
            "add_new_item"       => __( "Add New Patient", 'doctor-appointment' ),
            "edit_item"          => __( "Edit Patient", 'doctor-appointment' ),
            "new_item"           => __( "New Patient", 'doctor-appointment' ),
            "view_item"          => __( "View Patient", 'doctor-appointment' ),
            "search_items"       => __( "Search Patients", 'doctor-appointment' ),
            "not_found"          => __( "No Patients found", 'doctor-appointment' ),
            "not_found_in_trash" => __( "No Patients found in Trash", 'doctor-appointment' ),
        ];

        $patient_args = [
            "label"               => __( "Patients", 'doctor-appointment' ),
            "labels"              => $patient_labels,
            "public"              => false,
            "show_ui"             => true,
            "show_in_menu"        => false,
            "supports"            => [ "title" ],
            "can_export"          => true,
            "has_archive"         => false,
            "exclude_from_search" => true,
            "publicly_queryable"  => false,
            "show_in_rest"        => true,
        ];

        register_post_type( "mdbk_patient", $patient_args );
    }
}

new \MDBK\MDBK_CPT();