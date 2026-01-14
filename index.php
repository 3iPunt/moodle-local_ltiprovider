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
 * List the tool provided in a course
 *
 * @package    local
 * @subpackage ltiprovider
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/local/ltiprovider/lib.php');

$courseid = required_param('courseid', PARAM_INT);

if (! ($course = $DB->get_record('course', array('id'=>$courseid)))) {
    print_error('invalidcourseid', 'error');
}

$PAGE->set_url('/local/ltiprovider/index.php', array('courseid' => $courseid));

context_helper::preload_course($course->id);
if (!$context = context_course::instance($course->id)) {
    print_error('nocontext');
}

require_login($course);
require_capability('local/ltiprovider:view', $context);

echo $OUTPUT->header();

$tools = $DB->get_records('local_ltiprovider', array('courseid' => $course->id));

$data = array();
foreach ($tools as $tool) {
    if (!$toolcontext = context::instance_by_id($tool->contextid, IGNORE_MISSING)) {
        local_ltiprovider_delete_tool($tool);
        continue;
    }

    $tooldata = new stdClass();
    $tooldata->id = $tool->id;
    $tooldata->name = $toolcontext->get_context_name();
    $tooldata->secret = $tool->secret;
    $tooldata->launchurl = (new moodle_url('/local/ltiprovider/tool.php', array('id' => $tool->id)))->out(false);

    if (has_capability('local/ltiprovider:manage', $context)) {
        // Botón editar
        $tooldata->editurl = (new moodle_url('/local/ltiprovider/edit.php', array('id'=>$tool->id, 'sesskey'=>sesskey())))->out(false);

        // Botón eliminar
        $tooldata->deleteurl = (new moodle_url('/local/ltiprovider/edit.php', array('id'=>$tool->id, 'delete'=>1, 'sesskey'=>sesskey())))->out(false);

        // Botón show/hide
        if ($tool->disabled) {
            $tooldata->showurl = (new moodle_url('/local/ltiprovider/edit.php', array('id'=>$tool->id, 'show'=>1, 'sesskey'=>sesskey())))->out(false);
        } else {
            $tooldata->hideurl = (new moodle_url('/local/ltiprovider/edit.php', array('id'=>$tool->id, 'hide'=>1, 'sesskey'=>sesskey())))->out(false);
        }

        // Botón grades sync
        if ($tool->sendgrades) {
            $tooldata->gradesurl = (new moodle_url('/local/ltiprovider/syncreport.php', array('id'=>$tool->id)))->out(false);
        }

        // Botón members sync
        if ($tool->syncmembers) {
            $tooldata->membersurl = (new moodle_url('/local/ltiprovider/syncmembers.php', array('id'=>$tool->id)))->out(false);
        }
    }

    $data[] = $tooldata;
}

// Moodle 2.2 and onwards
if (isset($CFG->allowframembedding) and !$CFG->allowframembedding) {
    echo $OUTPUT->box(get_string('allowframembedding', 'local_ltiprovider'));
}

// Renderizar con el nuevo template
$templatecontext = [
    'tools' => $data,
    'addurl' => (new moodle_url('/local/ltiprovider/edit.php', array('id' => -1, 'courseid' => $course->id)))->out(false)
];

echo $OUTPUT->render_from_template('ltiproviderextension_amnh/tools', $templatecontext);

echo $OUTPUT->footer();