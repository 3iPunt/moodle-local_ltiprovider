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
 * General plugin functions.
 *
 * @package    local
 * @subpackage ltiprovider
 * @copyright  2011 Juan Leyva <juanleyvadelgado@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') or die;
require_once($CFG->dirroot.'/local/ltiprovider/ims-blti/blti_util.php');
require_once($CFG->dirroot.'/local/ltiprovider/locallib.php');

use moodle\local\ltiprovider as ltiprovider;

/**
 * Display the LTI settings in the course settings block
 * For 2.3 and onwards
 *
 * @param  settings_navigation $nav     The settings navigatin object
 * @param  stdclass            $context Course context
 */
function local_ltiprovider_extend_settings_navigation(settings_navigation $nav, $context) {
    if (
        ($context->contextlevel == CONTEXT_COURSE || $context->contextlevel == CONTEXT_MODULE)
        and ($branch = $nav->get('courseadmin'))
        and has_capability('local/ltiprovider:view', $context)) {
        $course_id = 0;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $course_id = $context->instanceid;
        } else if ($context->contextlevel == CONTEXT_MODULE) {
            global $PAGE;
            $course_id = $PAGE->__get('course')->id;
        }
        $ltiurl = new moodle_url('/local/ltiprovider/index.php', array('courseid' => $course_id));
        $branch->add(get_string('pluginname', 'local_ltiprovider'), $ltiurl, $nav::TYPE_CONTAINER, null, 'ltiprovider'.$context->instanceid);
    }
}

/**
 * Change the navigation block and bar only for external users
 * Force course or activity navigation and modify CSS also
 * Please note that this function is only called in pages where the navigation block is present
 *
 * @global moodle_user $USER
 * @global moodle_database $DB
 * @param navigation_node $nav Current navigation object
 */
function local_ltiprovider_extend_navigation ($nav) {
    global $CFG, $USER, $PAGE, $SESSION, $ME;

    if (isset($USER) and isset($USER->auth) and strpos($USER->username, 'ltiprovider') === 0) {
        // Force course or activity navigation.
        if (isset($SESSION->ltiprovider) and $SESSION->ltiprovider->forcenavigation) {
            $context = $SESSION->ltiprovider->context;
            $urltogo = '';
            if ($context->contextlevel == CONTEXT_COURSE and $PAGE->course->id != $SESSION->ltiprovider->courseid) {
                $urltogo = new moodle_url('/course/view.php', array('id' => $SESSION->ltiprovider->courseid));
            } else if ($context->contextlevel == CONTEXT_MODULE and $PAGE->context->id != $context->id) {
                $cm = get_coursemodule_from_id(false, $context->instanceid, 0, false, MUST_EXIST);
                $urltogo = new moodle_url('/mod/'.$cm->modname.'/view.php', array('id' => $cm->id));
            }

            // Special case, user policy, we don't have to do nothing to avoid infinites loops.
            if (strpos($ME, 'user/policy.php')) {
                return;
            }

            if ($urltogo) {
                local_ltiprovider_call_hook("navigation", $nav);
                if (!$PAGE->requires->is_head_done()) {
                    $PAGE->set_state($PAGE::STATE_IN_BODY);
                }
                redirect($urltogo);
            }
        }

        // Delete all the navigation nodes except the course one.
        if ($coursenode = $nav->find($PAGE->course->id, $nav::TYPE_COURSE)) {
            foreach (array('myprofile', 'users', 'site', 'home', 'myhome', 'mycourses', 'courses', '1') as $nodekey) {
                if ($node = $nav->get($nodekey)) {
                    $node->remove();
                }
            }
            $nav->children->add($coursenode);
        }

        // Custom CSS.
        if (isset($SESSION->ltiprovider) and !$PAGE->requires->is_head_done()) {
            $PAGE->requires->css(new moodle_url('/local/ltiprovider/styles.php', array('id' => $SESSION->ltiprovider->id)));
        } elseif (isset($SESSION->ltiprovider) && isset($SESSION->ltiprovider->id)) {
            $url = new moodle_url('/local/ltiprovider/styles.js.php',
                array('id' => $SESSION->ltiprovider->id, 'rand' => rand(0, 1000)));
            $PAGE->requires->js($url);
        }

        local_ltiprovider_call_hook("navigation", $nav);
    }
}

/**
 * Add new tool.
 *
 * @param  object $tool
 * @return int
 */
function local_ltiprovider_add_tool($tool) {
    global $DB;

    if (!isset($tool->disabled)) {
        $tool->disabled = 0;
    }
    if (!isset($tool->timecreated)) {
        $tool->timecreated = time();
    }
    if (!isset($tool->timemodified)) {
        $tool->timemodified = $tool->timecreated;
    }

    if (!isset($tool->sendgrades)) {
        $tool->sendgrades = 0;
    }
    if (!isset($tool->forcenavigation)) {
        $tool->forcenavigation = 0;
    }
    if (!isset($tool->enrolinst)) {
        $tool->enrolinst = 0;
    }
    if (!isset($tool->enrollearn)) {
        $tool->enrollearn = 0;
    }
    if (!isset($tool->hidepageheader)) {
        $tool->hidepageheader = 0;
    }
    if (!isset($tool->hidepagefooter)) {
        $tool->hidepagefooter = 0;
    }
    if (!isset($tool->hideleftblocks)) {
        $tool->hideleftblocks = 0;
    }
    if (!isset($tool->hiderightblocks)) {
        $tool->hiderightblocks = 0;
    }
    if (!isset($tool->syncmembers)) {
        $tool->syncmembers = 0;
    }

    $tool->id = $DB->insert_record('local_ltiprovider', $tool);
    local_ltiprovider_call_hook('save_settings', $tool);

    return $tool->id;
}

/**
 * Update existing tool.
 * @param  object $tool
 * @return void
 */
function local_ltiprovider_update_tool($tool) {
    global $DB;

    $tool->timemodified = time();

    if (!isset($tool->sendgrades)) {
        $tool->sendgrades = 0;
    }
    if (!isset($tool->forcenavigation)) {
        $tool->forcenavigation = 0;
    }
    if (!isset($tool->enrolinst)) {
        $tool->enrolinst = 0;
    }
    if (!isset($tool->enrollearn)) {
        $tool->enrollearn = 0;
    }
    if (!isset($tool->hidepageheader)) {
        $tool->hidepageheader = 0;
    }
    if (!isset($tool->hidepagefooter)) {
        $tool->hidepagefooter = 0;
    }
    if (!isset($tool->hideleftblocks)) {
        $tool->hideleftblocks = 0;
    }
    if (!isset($tool->hiderightblocks)) {
        $tool->hiderightblocks = 0;
    }
    if (!isset($tool->syncmembers)) {
        $tool->syncmembers = 0;
    }

    local_ltiprovider_call_hook('save_settings', $tool);
    $DB->update_record('local_ltiprovider', $tool);
}

/**
 * Delete tool.
 * @param  object $tool
 * @return void
 */
function local_ltiprovider_delete_tool($tool) {
    global $DB;
    $DB->delete_records('local_ltiprovider_user', array('toolid' => $tool->id));
    $DB->delete_records('local_ltiprovider', array('id' => $tool->id));
}

/**
 * Checks if a course linked to a tool is missing, is so, delete the lti entries
 * @param  stdclass $tool Tool record
 * @return bool      True if the course was missing
 */
function local_ltiprovider_check_missing_course($tool) {
    global $DB;

    if (! $course = $DB->get_record('course', array('id' => $tool->courseid))) {
        $DB->delete_records('local_ltiprovider', array('courseid' => $tool->courseid));
        $DB->delete_records('local_ltiprovider_user', array('toolid' => $tool->id));
        mtrace("Tool: $tool->id deleted (courseid: $tool->courseid missing)");
        return true;
    }
    return false;
}

/**
 * Cron function for sync grades
 * @return void
 */
function local_ltiprovider_cron() {
    /** MOVED TO SCHEDULED TASKS **/
}

/**
 * Call a hook present in a subplugin
 *
 * @param  string $hookname The hookname (function without franken style prefix)
 * @param  object $data     Object containing data to be used by the hook function
 * @return bool             True or false if call is successfully
 */
function local_ltiprovider_call_hook($hookname, $data) {
    // Try the standard way first (for Moodle < 5.0 compatibility)
    $plugins = get_plugin_list_with_function('ltiproviderextension', $hookname);
    
    // If no plugins found, manually load them (Moodle 5.0 compatibility)
    if (empty($plugins)) {
        $extensiondir = __DIR__ . '/extension';
        if (is_dir($extensiondir)) {
            $items = new DirectoryIterator($extensiondir);
            foreach ($items as $item) {
                if ($item->isDot() || !$item->isDir()) {
                    continue;
                }
                
                $pluginname = $item->getFilename();
                $libfile = $extensiondir . '/' . $pluginname . '/lib.php';
                
                if (file_exists($libfile)) {
                    require_once($libfile);
                    $functionname = 'ltiproviderextension_' . $pluginname . '_' . $hookname;
                    if (function_exists($functionname)) {
                        $plugins[$functionname] = $functionname;
                    }
                }
            }
        }
    }
    
    $r = true;
    if (!empty($plugins)) {
        foreach ($plugins as $plugin) {
            $r = call_user_func($plugin, $data) && $r;
        }
    }
    return $r;
}