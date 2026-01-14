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
 * Force to sync members of current tool
 *
 * @package    local
 * @subpackage ltiprovider
 * @copyright  2017 Antoni Bertran <antoni@tresipunt.com> Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/lib.php');

$id = required_param('id', PARAM_INT);

$tool = $DB->get_record('local_ltiprovider', array('id' => $id), '*', MUST_EXIST);
$courseid = $tool->courseid;
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

$params_url = array('id' => $id);

$PAGE->set_url('/local/ltiprovider/syncmembers.php', $params_url );

$context = context_course::instance($course->id);

require_login($course);
require_capability('local/ltiprovider:manage', $context);

$returnurl = new moodle_url('/local/ltiprovider/index.php', array('courseid' => $courseid));

$strheading = get_string('forcesyncmembers', 'local_ltiprovider');
$PAGE->set_context($context);
$PAGE->navbar->add(get_string('pluginname', 'local_ltiprovider'), new moodle_url('/local/ltiprovider/index.php', array('courseid'=>$course->id)));
$PAGE->navbar->add($strheading);
$PAGE->set_title($strheading);
$PAGE->set_heading($course->fullname . ': '.$strheading);

echo $OUTPUT->header();

// Set up return URL
$returnurl = new moodle_url('/local/ltiprovider/index.php', array('courseid' => $courseid));

// Initialize template context
$templatecontext = array();
$templatecontext['heading'] = $strheading;
$templatecontext['backurl'] = $returnurl->out(false);
$templatecontext['status_loading'] = false;
$templatecontext['status_success'] = false;
$templatecontext['status_error'] = false;

// Check if we already ran the sync
$didsync = optional_param('didsync', 0, PARAM_INT);

if (!$didsync) {
    // First load - show loading and redirect to execute sync
    $templatecontext['status_loading'] = true;
    echo $OUTPUT->render_from_template('ltiproviderextension_amnh/syncmembers', $templatecontext);
    
    // Redirect to execute sync
    $syncurl = new moodle_url('/local/ltiprovider/syncmembers.php', array('id' => $id, 'didsync' => 1));
    echo '<script>setTimeout(function() { window.location.href = "' . $syncurl->out(false) . '"; }, 500);</script>';
} else {
    // Second load - execute sync and show result
    $userphotos = array();
    $consumers = array();
    $timenow = time();

    try {
        $ret = local_ltiprovider_membership_service($tool, $timenow, $userphotos, $consumers);
        
        // Check if there's any response or consumers processed
        if (!empty($ret['consumers']) || !empty($ret['userphotos'])) {
            // Sync was successful
            $userphotos = $ret['userphotos'];
            local_ltiprovider_membership_service_update_userphotos($userphotos);
            
            // Show success state
            $templatecontext['status_success'] = true;
            echo $OUTPUT->render_from_template('ltiproviderextension_amnh/syncmembers', $templatecontext);
        } else {
            // No data to sync
            $templatecontext['status_error'] = true;
            $templatecontext['errormessage'] = 'No users to sync or no memberships URL configured.';
            $retryurl = new moodle_url('/local/ltiprovider/syncmembers.php', array('id' => $id));
            $templatecontext['retryurl'] = $retryurl->out(false);
            echo $OUTPUT->render_from_template('ltiproviderextension_amnh/syncmembers', $templatecontext);
        }
    } catch (Exception $e) {
        // Sync failed - show error state
        $templatecontext['status_error'] = true;
        $templatecontext['errormessage'] = $e->getMessage();
        $retryurl = new moodle_url('/local/ltiprovider/syncmembers.php', array('id' => $id));
        $templatecontext['retryurl'] = $retryurl->out(false);
        echo $OUTPUT->render_from_template('ltiproviderextension_amnh/syncmembers', $templatecontext);
    }
}

echo $OUTPUT->footer();
