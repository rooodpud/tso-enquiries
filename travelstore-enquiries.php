<?php
/**
 * Plugin Name: TS Enquiries
 * Description: Saves every enquiry sent with the TS Enquiry Form, emails the office, and lets the team assign each enquiry and send the acknowledgement email with one click (never automatically). Daily summary email.
 * Version:     1.1.1
 * Author:      Pradeep Hingorani
 * GitHub Plugin URI: https://github.com/rooodpud/tso-enquiries
 * Primary Branch: main
 * Text Domain: travelstore-enquiries
 * Requires PHP: 7.4
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TSE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TSE_URL', plugin_dir_url( __FILE__ ) );
define( 'TSE_VERSION', '1.1.1' );

require_once TSE_PATH . 'includes/class-settings.php';
require_once TSE_PATH . 'includes/class-store.php';
require_once TSE_PATH . 'includes/class-mailer.php';
require_once TSE_PATH . 'includes/class-submit.php';
require_once TSE_PATH . 'includes/class-flyout.php';
require_once TSE_PATH . 'includes/class-chatbot.php';
require_once TSE_PATH . 'includes/class-digest.php';
require_once TSE_PATH . 'includes/class-staff.php';
require_once TSE_PATH . 'includes/class-admin.php';

/**
 * Wires the parts together. Its presence also tells the TS Enquiry Form widget that enquiries are saved.
 */
final class Plugin {

	public static function init() {
		Store::register();
		Submit::register();
		Flyout::register();
		Chatbot::register();
		Digest::register();
		if ( is_admin() ) {
			Settings::register();
			Staff::register();
			Admin::register();
		}
	}
}

Plugin::init();

register_deactivation_hook( __FILE__, array( '\TravelStore\Enquiries\Digest', 'unschedule' ) );
