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
 * LTI Provider grades synchronization report rendered with AMNH design.
 *
 * @package    local
 * @subpackage ltiprovider
 * @copyright  2016 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/ltiprovider/lib.php');
require_once($CFG->dirroot . '/local/ltiprovider/classes/table_syncreport.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/querylib.php');

$id = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$search_firstname = optional_param('search_firstname', '', PARAM_TEXT);
$search_lastname = optional_param('search_lastname', '', PARAM_TEXT);
$sifirst = optional_param('sifirst', '', PARAM_TEXT);
$silast = optional_param('silast', '', PARAM_TEXT);

$tool = $DB->get_record('local_ltiprovider', array('id' => $id), '*', MUST_EXIST);
$courseid = $tool->courseid;
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

$paramsurl = array('id' => $id);
if ($search_firstname !== '') {
    $paramsurl['search_firstname'] = $search_firstname;
}
if ($search_lastname !== '') {
    $paramsurl['search_lastname'] = $search_lastname;
}
if ($sifirst !== '') {
    $paramsurl['sifirst'] = $sifirst;
}
if ($silast !== '') {
    $paramsurl['silast'] = $silast;
}
if ($download !== '') {
    $paramsurl['download'] = $download;
}
if ($page) {
    $paramsurl['page'] = $page;
}

$PAGE->set_url('/local/ltiprovider/syncreport.php', $paramsurl);

$context = context_course::instance($course->id);

require_login($course);
require_capability('local/ltiprovider:manage', $context);

$strheading = get_string('gradessyncreport', 'local_ltiprovider');
$PAGE->set_context($context);
$PAGE->navbar->add(get_string('pluginname', 'local_ltiprovider'), new moodle_url('/local/ltiprovider/index.php', array('courseid' => $course->id)));
$PAGE->navbar->add($strheading);
$PAGE->set_title($strheading);
$PAGE->set_heading($course->fullname . ': ' . $strheading);

$filterparams = new stdClass();
$filterparams->firstname = $search_firstname;
$filterparams->sifirst = $sifirst;
$filterparams->lastname = $search_lastname;
$filterparams->silast = $silast;

$table = new local_ltiprovider_table_syncreport('local_ltiprovider_syncreport', $tool, $filterparams);
$table->is_downloading($download, 'ltiprovider-syncreport', 'ltiprovider syncreport');

// If downloading, output directly.
if ($table->is_downloading()) {
    $table->out(50, true);
    exit;
}

echo $OUTPUT->header();

// Build custom query without lastsync > 0 restriction for AMNH template.
$sql = "SELECT u.*, g.serviceurl, g.sourceid, g.lastgrade, g.lastsync, g.id as ltiprovideruserid
          FROM {local_ltiprovider_user} g
          JOIN {user} u ON u.id = g.userid
         WHERE g.toolid = :toolid";
$params = ['toolid' => $tool->id];

// Apply filters.
if (!empty($search_firstname)) {
    $sql .= " AND " . $DB->sql_like('u.firstname', ':firstname', false, false);
    $params['firstname'] = "%$search_firstname%";
}
if (!empty($search_lastname)) {
    $sql .= " AND " . $DB->sql_like('u.lastname', ':lastname', false, false);
    $params['lastname'] = "%$search_lastname%";
}
if (!empty($sifirst)) {
    $sql .= " AND " . $DB->sql_like('u.firstname', ':sifirst', false, false);
    $params['sifirst'] = "$sifirst%";
}
if (!empty($silast)) {
    $sql .= " AND " . $DB->sql_like('u.lastname', ':silast', false, false);
    $params['silast'] = "$silast%";
}
$sql .= " ORDER BY u.lastname, u.firstname";

$users = $DB->get_records_sql($sql, $params);

// Build table HTML manually.
$tablehtml = '<div class="table-responsive"><table class="generaltable">';
$tablehtml .= '<thead><tr>';
$tablehtml .= '<th>' . get_string('select') . '</th>';
$tablehtml .= '<th>' . get_string('fullnameuser') . '</th>';
$tablehtml .= '<th>' . get_string('time') . '</th>';
$tablehtml .= '<th>' . get_string('grade') . '</th>';
$tablehtml .= '<th></th>';
$tablehtml .= '<th>' . get_string('gradessourceid', 'local_ltiprovider') . '</th>';
$tablehtml .= '<th>' . get_string('gradesserviceurl', 'local_ltiprovider') . '</th>';
$tablehtml .= '</tr></thead><tbody>';

if (empty($users)) {
    $tablehtml .= '<tr><td colspan="7" class="text-muted">' . get_string('nothingtodisplay') . '</td></tr>';
} else {
    foreach ($users as $user) {
        // Get current grade from course (not just lastgrade from sync table).
        $currentgrade = '-';
        $gradeitem = grade_item::fetch_course_item($course->id);
        if ($gradeitem) {
            $grade = new grade_grade(['itemid' => $gradeitem->id, 'userid' => $user->id]);
            $grade->load_optional_fields();
            if (!empty($grade->finalgrade)) {
                $currentgrade = round($grade->finalgrade, 2);
            }
        }
        
        $tablehtml .= '<tr>';
        $tablehtml .= '<td><input type="checkbox" class="usercheckbox" name="user_force_grade_checkbox" value="' . $user->id . '"/></td>';
        $tablehtml .= '<td>' . fullname($user) . '</td>';
        $tablehtml .= '<td>' . ($user->lastsync > 0 ? userdate($user->lastsync, get_string('strftimerecent', 'core_langconfig')) : '-') . '</td>';
        $tablehtml .= '<td>' . $currentgrade . '</td>';
        $forcesendurl = new moodle_url('/local/ltiprovider/test/forcesendgrades.php', [
            'toolid' => $tool->id,
            'userid' => $user->id,
            'printresponse' => 1
        ]);
        $tablehtml .= '<td>' . $OUTPUT->single_button($forcesendurl, get_string('forcesendgrades', 'local_ltiprovider'), 'get') . '</td>';
        $tablehtml .= '<td>' . s($user->sourceid) . '</td>';
        $tablehtml .= '<td>' . s($user->serviceurl) . '</td>';
        $tablehtml .= '</tr>';
    }
}
$tablehtml .= '</tbody></table></div>';

// Check completion requirements to show the warning.
$requirecompletion = false;
$completionmessage = '';
if ($tool->requirecompletion) {
    $requirecompletion = true;
    $completionmessage = get_string('notifycompletion', 'local_ltiprovider');
}

// Template context for AMNH design.
$templatecontext = [
    'heading' => get_string('gradessent', 'local_ltiprovider'),
    'toolid' => $id,
    'tablehtml' => $tablehtml,
    'search_firstname' => $search_firstname,
    'search_lastname' => $search_lastname,
    'sifirst' => $sifirst,
    'silast' => $silast,
    'actionurl' => (new moodle_url('/local/ltiprovider/syncreport.php', $paramsurl))->out(false),
    'forcesendurl_selected' => (new moodle_url('/local/ltiprovider/test/forcesendgrades.php', array(
        'toolid' => $tool->id,
        'printresponse' => 1,
        'selected_users' => 1,
        'user_force_grade' => '',
        'sesskey' => sesskey()
    )))->out(false),
    'forcesendurl_all' => (new moodle_url('/local/ltiprovider/test/forcesendgrades.php', array(
        'toolid' => $tool->id,
        'printresponse' => 1,
        'sesskey' => sesskey()
    )))->out(false),
    'forcesendurl_omit' => (new moodle_url('/local/ltiprovider/test/forcesendgrades.php', array(
        'toolid' => $tool->id,
        'printresponse' => 1,
        'omitcompletion' => 1,
        'sesskey' => sesskey()
    )))->out(false),
    'sendselectedtext' => get_string('forcesendgradesselectedusers', 'local_ltiprovider'),
    'sendalltext' => get_string('forcesendgradesallusers', 'local_ltiprovider'),
    'sendomittext' => get_string('forcesendgradesallusersomittingcompletion', 'local_ltiprovider'),
    'requirecompletion' => $requirecompletion,
    'completionmessage' => $completionmessage,
    'sesskey' => sesskey(),
    'backurl' => (new moodle_url('/local/ltiprovider/index.php', array('courseid' => $courseid)))->out(false)
];

// Render using the AMNH Mustache template.
echo $OUTPUT->render_from_template('ltiproviderextension_amnh/syncreport', $templatecontext);

echo $OUTPUT->footer();
