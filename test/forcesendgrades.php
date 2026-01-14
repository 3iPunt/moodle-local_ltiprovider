<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Force send grades back (overwritten). You can force by course, tool and userid (paremeters $courseid, $toolid, $userid)
 * Completion check can be omitted too ($omitcompletion)
 *
 * @package    local
 * @subpackage ltiprovider
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once( dirname( __FILE__ ) . '/../../../config.php' );

require_once( $CFG->dirroot . "/local/ltiprovider/lib.php" );
require_once( $CFG->dirroot . "/local/ltiprovider/locallib.php" );
require_once( $CFG->dirroot . "/local/ltiprovider/ims-blti/OAuth.php" );
require_once( $CFG->dirroot . "/local/ltiprovider/ims-blti/OAuthBody.php" );
require_once( $CFG->libdir . "/gradelib.php" );
require_once( $CFG->dirroot . "/grade/querylib.php" );

ini_set( "display_erros", 1 );
error_reporting( E_ALL );

$courseid         = optional_param( 'courseid', 0, PARAM_INT );
$toolid           = optional_param( 'toolid', 0, PARAM_INT );
$userid           = optional_param( 'userid', 0, PARAM_INT );
$omitcompletion   = optional_param( 'omitcompletion', 0, PARAM_BOOL );
$printresponse    = optional_param( 'printresponse', 0, PARAM_BOOL );
$sesskey          = optional_param( 'sesskey', null, PARAM_RAW );
$user_force_grade = optional_param( 'user_force_grade', null, PARAM_RAW );
$selected_users   = optional_param( 'selected_users', 0, PARAM_BOOL );
$forceerror       = optional_param( 'forceerror', 0, PARAM_BOOL );

if ( ! empty( $sesskey ) ) {
	require_sesskey();
}

if ( empty( $toolid ) ) {
	require_login();
	require_capability( 'moodle/site:config', context_system::instance() );
} else {
	$tool          = $DB->get_record( 'local_ltiprovider', array( 'id' => $toolid ), '*', MUST_EXIST );
	$course        = $DB->get_record( 'course', array( 'id' => $tool->courseid ), '*', MUST_EXIST );
	$coursecontext = context_course::instance( $course->id );
	require_login( $course );
	require_capability( 'local/ltiprovider:manage', $coursecontext );
}

$log = array();

if ( $selected_users && strlen( $user_force_grade ) == 0 ) {
	$log[] = s( " Error, there are not selected users" );
} else {

	$select        = 'disabled = ? AND sendgrades = ?';
	$params_select = array( 0, 1 );
	if ( ! empty( $courseid ) ) {
		$select .= ' AND courseid=?';
		array_push( $params_select, $courseid );
	}
	if ( ! empty( $toolid ) ) {
		$select .= ' AND id=?';
		array_push( $params_select, $toolid );
	}
	if ( $tools = $DB->get_records_select( 'local_ltiprovider', $select, $params_select ) ) {
		foreach ( $tools as $tool ) {

			if ( $omitcompletion ) {
				$tool->requirecompletion = 0;
			}

			if ( $tool->requirecompletion ) {
				$log[] = s( "  Grades require activity or course completion" );
			}
			$user_count  = 0;
			$send_count  = 0;
			$error_count = 0;

			$select_user = 'toolid = :toolid';
			$params_user = array( 'toolid' => $tool->id );
			if ( ! empty( $userid ) ) {
				$select_user .= ' AND userid = :userid';
				$params_user += array( 'userid' => $userid );
			} elseif ( $selected_users ) {
				list( $sql_in, $params_in ) = $DB->get_in_or_equal( explode( ",", $user_force_grade ),
					SQL_PARAMS_NAMED );
				$select_user .= ' AND userid ' . $sql_in;
				$params_user += $params_in;
			}

			$coursecontext = context_course::instance( $tool->courseid );
			$PAGE->set_context( $coursecontext );
			$link = new moodle_url( '/local/ltiprovider/syncreport.php', array( 'id' => $toolid ) );
			$PAGE->set_url( $link );
			$users = $DB->get_records_select( 'local_ltiprovider_user', $select_user, $params_user );
			$log  = array_merge( $log, local_ltiprovier_do_grades_sync( $tool, $users, time(), true ) );

		}
	}

}

// If forceerror flag is set, inject a test error log line to visualize UI state.
if ( $forceerror ) {
	$log[] = 'error: forced error for testing UI state';
}

// Check if we requested this by URL or via the sync grades report (printresponse=1).
if ( $toolid && ( ! empty( $sesskey ) || $printresponse ) ) {
	$link = new moodle_url( '/local/ltiprovider/syncreport.php', array( 'id' => $toolid ) );

	$PAGE->set_context( $coursecontext );
	$PAGE->set_url( $link );
	
	// Parse log messages to detect errors and success
	$messages = array();
	$has_errors = false;
	$success_count = 0;
	$error_count = 0;
	
	foreach ($log as $logline) {
		$type = 'info';
		$errmatch = null;
		$sentmatch = null;

		// Detect explicit non-zero errors like "errors=3".
		if (preg_match('/errors=([1-9][0-9]*)/i', $logline, $errmatch)) {
			$type = 'error';
			$has_errors = true;
			$error_count += intval($errmatch[1]);
		}
		// Fallback: generic error/failed, but ignore harmless "errors=0" substrings.
		else if ((stripos($logline, 'error') !== false || stripos($logline, 'failed') !== false)
		    && stripos($logline, 'errors=0') === false
		    && stripos($logline, 'error=0') === false) {
			$type = 'error';
			$has_errors = true;
			$error_count++;
		}
		// Success: sent=X or completed, count sent if available.
		else if (preg_match('/sent=(\d+)/i', $logline, $sentmatch)) {
			$type = 'success';
			$success_count += intval($sentmatch[1]);
		}
		else if (stripos($logline, 'completed') !== false) {
			$type = 'success';
			$success_count++;
		}

		$messages[] = array('text' => $logline, 'type' => $type);
	}
	
	// Build template context
	$templatecontext = array(
		'heading' => get_string('forcesendgrades', 'local_ltiprovider'),
		'messages' => $messages,
		'backurl' => $link->out(false),
		'has_errors' => $has_errors,
		'success_count' => $success_count > 0 ? $success_count : null,
		'error_count' => $error_count > 0 ? $error_count : null,
		'auto_open' => count($log) < 10
	);
	
	echo $OUTPUT->header();
	echo $OUTPUT->render_from_template('ltiproviderextension_amnh/forcesendresult', $templatecontext);
	echo $OUTPUT->footer();
} else {
	// @header( 'Content-Type: text/plain; charset=utf-8' );
	notice( implode( "<br />", $log ) );
}
