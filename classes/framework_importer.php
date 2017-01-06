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
 * This file contains the class to import a competency framework.
 *
 * @package   tool_lpimportcsv
 * @copyright 2015 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_lpimportccss;

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

use core_competency\api;
use grade_scale;
use stdClass;
use context_system;
use DOMDocument;

/**
 * This file contains the class to import a competency framework.
 *
 * @package   tool_lpimportccss
 * @copyright 2017 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class framework_importer {

    /** @var string $error The errors message from reading the xml */
    protected $error = '';

    /** @var array $flat The flat competencies tree */
    protected $flat = [];

    /** @var int $scaleid The scale id */
    protected $scaleid = 0;

    /** @var array $parents The known parent ids */
    protected $parents = [];

    /** @var string $scaleconfiguration The scale configuration */
    protected $scaleconfiguration = '';

    const LITERACY_FILE = "/admin/tool/lpimportccss/ela-literacy.xml";
    const MATH_FILE = "/admin/tool/lpimportccss/math.xml";

    /**
     * Store an error message for display later
     * @param string $msg
     */
    public function fail($msg) {
        $this->error = $msg;
        return false;
    }

    /**
     * Constructor - parses the raw text for sanity.
     */
    public function __construct($scaleid, $scaleconfiguration) {
        $this->scaleid = $scaleid;
        $this->scaleconfiguration = $scaleconfiguration;
    }

    /**
     * Get parse errors.
     * @return array of errors from parsing the xml.
     */
    public function get_error() {
        return $this->error;
    }

    public function import_from_file($filename, $progress) {
        $progress->start_progress('One', 4);

        // Maths first.

        $doc = new DOMDocument();

        @$doc->load($filename);

        $nodes = $doc->getElementsByTagName('LearningStandardItem');

        foreach ($nodes as $node) {
            $competency = new stdClass();
            // Get the required data about each competency.
            $idnodes = $node->getElementsByTagName('RefURI');
            $competency->idnumber = $idnodes->item(0)->textContent;

            $competency->parentidnumber = '';
            if (substr_count($competency->idnumber, '/') > 4) {
                $competency->parentidnumber = substr($competency->idnumber, 0, strrpos($competency->idnumber, '/', -2) + 1);
            }

            $descriptionnodes = $node->getElementsByTagName('Statement');
            $description = '';
            foreach ($descriptionnodes as $descriptionnode) {
                $description .= '<p>' . $descriptionnode->textContent . '</p>';
            }
            $competency->description = trim($description);

            $codenodes = $node->getElementsByTagName('StatementCode');
            $codes = [];
            foreach ($codenodes as $codenode) {
                $codes[] = $codenode->textContent;
            }
            $competency->shortname = implode(', ', $codes);

            $levelnodes = $node->getElementsByTagName('GradeLevel');
            $levels = [];
            foreach ($levelnodes as $levelnode) {
                $levels[] = $levelnode->textContent;
            }
            $competency->gradelevels = implode(', ', $levels);

            $this->flat[$competency->idnumber] = $competency;
        }
        $progress->progress(1);
        $this->generate_missing();
        $progress->progress(2);

        // Sort by key length so parent competencies are created before their children.

        $keys = array_map('strlen', array_keys($this->flat));
        array_multisort($keys, SORT_ASC, $this->flat);

        $record = reset($this->flat);
        $framework = $this->create_framework($record);
        $progress->progress(3);

        while ($competency = next($this->flat)) {
            $this->create_competency($competency, $framework);
        }
        $progress->progress(4);

        // Repeat for ELA Literacy.

        $progress->end_progress();
    }

    /**
     * Do the job.
     * @return competency_framework
     */
    public function import() {
        global $CFG;

        $progress = new \core\progress\display_if_slow(get_string('importingframeworks', 'tool_lpimportccss'));

        $this->import_from_file($CFG->dirroot . self::MATH_FILE, $progress);

        $this->import_from_file($CFG->dirroot . self::LITERACY_FILE, $progress);
    }

    protected function create_framework($framework) {
        $framework->descriptionformat = FORMAT_HTML;
        $framework->contextid = context_system::instance()->id;
        $framework->scaleid = $this->scaleid;
        $framework->scaleconfiguration = $this->scaleconfiguration;
        unset($framework->parentidnumber);
        unset($framework->gradelevels);

        try {
            $framework = api::create_framework($framework);
        } catch (invalid_persistent_exception $ip) {
            return $this->fail($ip->getMessage());
        }
        return $framework;
    }

    protected function create_competency($competency, $framework) {
        $competency->descriptionformat = FORMAT_HTML;
        $competency->competencyframeworkid = $framework->get_id();
        if (!empty($competency->gradelevels)) {
            $competency->description .= '<p>' . get_string('gradelevels', 'tool_lpimportccss') . $competency->gradelevels . '</p>';
        }
        if (isset($this->parents[$competency->parentidnumber])) {
            $competency->parentid = $this->parents[$competency->parentidnumber];
        }
        unset($competency->parentidnumber);
        unset($competency->gradelevels);

        try {
            $competency = api::create_competency($competency);

            $this->parents[$competency->get_idnumber()] = $competency->get_id();
        } catch (invalid_persistent_exception $ip) {
            return $this->fail($ip->getMessage());
        }
        return $competency;
    }

    protected function generate_missing() {
        $missing = [];
        // Find missing parents.
        foreach ($this->flat as $competency) {
            if (!empty($competency->parentidnumber) && !isset($this->flat[$competency->parentidnumber])) {
                $missing[$competency->parentidnumber] = $competency->parentidnumber;
            }
        }

        foreach ($missing as $idnumber) {
            $competency = new stdClass();
            $competency->idnumber = $idnumber;
            $competency->parentidnumber = '';

            if (substr_count($competency->idnumber, '/') > 4) {
                $competency->parentidnumber = substr($competency->idnumber, 0, strrpos($competency->idnumber, '/', -2) + 1);
            }

            // The idnumber is a uri to a live resource.
            $htmlstr = file_get_contents($competency->idnumber);
            $dom = new DOMDocument();
            @$dom->loadHTML($htmlstr);
            $headings = $dom->getElementsByTagName('h1');
            $competency->description = $headings->item(0)->textContent;

            $competency->shortname = $this->url_to_code($competency->idnumber);
            $competency->gradelevels = '';
            $this->flat[$competency->idnumber] = $competency;
        }
        if (count($missing)) {
            return $this->generate_missing();
        } else {
            return true;
        }
    }

    protected function url_to_code($url) {
        $url = 'CCSS' . substr($url, strlen('http://corestandards.org'), -1);
        $code = str_replace('/', '.', $url);
        return $code;
    }
}
