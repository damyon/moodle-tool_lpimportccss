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
 * Import a framework.
 *
 * @package    tool_lpimportccss
 * @copyright  2016 Damyon Wiese
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

$pagetitle = get_string('pluginname', 'tool_lpimportccss');

$context = context_system::instance();

$url = new moodle_url("/admin/tool/lpimportccss/index.php");
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title($pagetitle);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading($pagetitle);

$form = new \tool_lpimportccss\form\import();

if ($data = $form->get_data()) {

    $importer = new \tool_lpimportccss\framework_importer($data->scaleid, $data->scaleconfiguration);

    require_sesskey();

    $importer->import();

    $error = $importer->get_error();
    if ($error) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading($pagetitle);

        echo $OUTPUT->notification($error, 'error');

        echo $OUTPUT->footer();
    } else {
        $frameworksurl = new moodle_url('/admin/tool/lp/competencyframeworks.php', ['pagecontextid' => $context->id]);
        \core\notification::add(get_string('frameworkscreated', 'tool_lpimportccss'));
        redirect($frameworksurl);
    }

} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($pagetitle);

    $form->display();

    echo $OUTPUT->footer();
}

