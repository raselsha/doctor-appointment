=== Doctor Appointment Booking ===
Contributors: raselsha
Donate link: https://shahadat.com.bd
Tags: doctor, appointment, booking, medical, clinic, hospital, patient, schedule
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.1.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional administrative dashboard and booking system for doctors, clinics, and medical centers.

== Description ==

Doctor Appointment Booking is a high-end, bespoke administrative solution designed for medical practitioners who want a clean, professional, and clutter-free management experience. 

Unlike standard plugins, MedBook provides a completely custom administrative dashboard that hides WordPress clutter and focuses entirely on your medical practice.

= Key Features =
* **Premium Admin Dashboard:** A custom-built, unified interface for managing your entire clinic.
* **Smart Booking System:** AJAX-powered frontend booking form with dynamic doctor filtering by specialty.
* **Patient CRM:** Automatic patient profile creation and permanent record management (Name, Phone, Email, Address).
* **Queue Management:** Live queue tracking to manage patient flow in real-time.
* **Day-wise Scheduling:** Set complex availability for practitioners with a premium interactive interface.
* **Specialty Management:** Organize your clinic into departments or medical specialties.
* **Developer Friendly:** Built with clean, object-oriented PHP and modern JavaScript.

== Installation ==

1. Upload the `doctor-appointment` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Use the shortcode `[mdbk_appointment_form]` to display the booking form on any page.
4. Use the shortcode `[mdbk_queue_management]` to display the live queue on your reception display.
5. Go to the 'MedBook' menu in your admin dashboard to manage everything.

== Frequently Asked Questions ==

= How do I display the booking form? =
Simply paste the shortcode `[mdbk_appointment_form]` into any post, page, or widget.

= Where do I find the administrative dashboard? =
Look for the "MedBook" icon in your WordPress sidebar. It provides a full-screen, premium management experience.

== Screenshots ==

1. Premium Administrative Dashboard Overview.
2. AJAX-powered Frontend Booking Form.
3. Live Queue Management Screen.
4. Doctor Availability and Schedule Setup.

== Changelog ==

= 1.1.0 =
* Added real time-slot booking (per-doctor slot duration) with server-side double-booking prevention.
* Fixed: frontend bookings now always create/link a Patient CRM record (previously only admin-created bookings did).
* Appointment lifecycle now uses registered post statuses instead of a plain meta field, enabling reliable status-change notifications.
* Queue: fixed a bug where simply viewing the queue page could silently advance the line; replaced with an explicit "Call Next" action.
* Queue: now scoped to today and per-doctor, with real sequential ticket numbers, live AJAX auto-refresh, and truncated patient names on the public display.
* Added email notifications for booking confirmation, "you're up next", and visit completion.
* Added a Front Desk role that can run the queue and manage bookings without full admin access.
* Fixed missing CSRF protection on admin delete actions.

= 1.0.0 =
* Initial release.
* Added Premium Admin Dashboard.
* Added AJAX Doctor filtering.
* Added Patient CRM and Address tracking.
* Added Dummy Data Seeder for easy setup.

== Upgrade Notice ==

= 1.0.0 =
First version of MedBook Doctor Appointment Booking.
