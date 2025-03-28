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
 * Contains the default section course format output class.
 *
 * @package   core_courseformat
 * @copyright 2020 Ferran Recio <ferran@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_courseformat\output\local\content;

use context_course;
use core\output\named_templatable;
use core_courseformat\base as course_format;
use core_courseformat\output\local\courseformat_named_templatable;
use renderable;
use renderer_base;
use section_info;
use stdClass;

/**
 * Base class to render a course section.
 *
 * @package   core_courseformat
 * @copyright 2020 Ferran Recio <ferran@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section implements named_templatable, renderable {
    use courseformat_named_templatable;

    /** @var course_format the course format */
    protected $format;

    /** @var section_info the section info */
    protected $section;

    /** @var section header output class */
    protected $headerclass;

    /** @var cm list output class */
    protected $cmlistclass;

    /** @var section summary output class */
    protected $summaryclass;

    /** @var activities summary output class */
    protected $cmsummaryclass;

    /** @var section control menu output class */
    protected $controlclass;

    /** @var section availability output class */
    protected $availabilityclass;

    /** @var optional visibility output class */
    protected $visibilityclass;

    /** @var bool if the title is hidden for some reason */
    protected $hidetitle = false;

    /** @var bool if the title is hidden for some reason */
    protected $hidecontrols = false;

    /** @var bool if the section is considered stealth */
    protected $isstealth = false;

    /** @var string control menu class. */
    protected $controlmenuclass;

    /**
     * Constructor.
     *
     * @param course_format $format the course format
     * @param section_info $section the section info
     */
    public function __construct(course_format $format, section_info $section) {
        $this->format = $format;
        $this->section = $section;

        $this->isstealth = $section->is_orphan();

        // Load output classes names from format.
        $this->headerclass = $format->get_output_classname('content\\section\\header');
        $this->cmlistclass = $format->get_output_classname('content\\section\\cmlist');
        $this->summaryclass = $format->get_output_classname('content\\section\\summary');
        $this->cmsummaryclass = $format->get_output_classname('content\\section\\cmsummary');
        $this->controlmenuclass = $format->get_output_classname('content\\section\\controlmenu');
        $this->availabilityclass = $format->get_output_classname('content\\section\\availability');
        $this->visibilityclass = $format->get_output_classname('content\\section\\visibility');
    }

    /**
     * Check if the section is considered stealth.
     *
     * @return bool
     */
    public function is_stealth(): bool {
        return $this->isstealth;
    }

    /**
     * Hide the section title.
     *
     * This is used on blocks or in the home page where an isolated section is displayed.
     */
    public function hide_title(): void {
        $this->hidetitle = true;
    }

    /**
     * Hide the section controls.
     *
     * This is used on blocks or in the home page where an isolated section is displayed.
     */
    public function hide_controls(): void {
        $this->hidecontrols = true;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param renderer_base $output typically, the renderer that's calling this function
     * @return stdClass data context for a mustache template
     */
    public function export_for_template(renderer_base $output): stdClass {
        global $USER, $PAGE;

        $format = $this->format;
        $course = $format->get_course();
        $section = $this->section;

        $summary = new $this->summaryclass($format, $section);

        $data = (object)[
            'num' => $section->section ?? '0',
            'id' => $section->id,
            'sectionreturnnum' => $format->get_sectionnum(),
            'insertafter' => false,
            'summary' => $summary->export_for_template($output),
            'highlightedlabel' => $format->get_section_highlighted_name(),
            'sitehome' => $course->id == SITEID,
            'editing' => $PAGE->user_is_editing(),
            'displayonesection' => ($course->id != SITEID && $format->get_sectionid() == $section->id),
            // Section name is used as data attribute is to facilitate behat locators.
            'sectionname' => $format->get_section_name($section),
        ];

        $haspartials = [];
        $haspartials['availability'] = $this->add_availability_data($data, $output);
        $haspartials['visibility'] = $this->add_visibility_data($data, $output);
        $haspartials['editor'] = $this->add_editor_data($data, $output);
        $haspartials['header'] = $this->add_header_data($data, $output);
        $haspartials['cm'] = $this->add_cm_data($data, $output);
        $this->add_format_data($data, $haspartials, $output);

        return $data;
    }

    /**
     * Add the section header to the data structure.
     *
     * @param stdClass $data the current cm data reference
     * @param renderer_base $output typically, the renderer that's calling this function
     * @return bool if the cm has name data
     */
    protected function add_header_data(stdClass &$data, renderer_base $output): bool {
        if (!empty($this->hidetitle)) {
            return false;
        }

        $section = $this->section;
        $format = $this->format;

        $header = new $this->headerclass($format, $section);
        $headerdata = $header->export_for_template($output);

        // When a section is displayed alone the title goes over the section, not inside it.
        if ($section->section != 0 && $section->section == $format->get_sectionnum()) {
            $data->singleheader = $headerdata;
        } else {
            $data->header = $headerdata;
        }
        return true;
    }

    /**
     * Add the section cm list to the data structure.
     *
     * @param stdClass $data the current cm data reference
     * @param renderer_base $output typically, the renderer that's calling this function
     * @return bool if the cm has name data
     */
    protected function add_cm_data(stdClass &$data, renderer_base $output): bool {
        $result = false;

        $section = $this->section;
        $format = $this->format;

        $showsummary = ($section->section != 0 &&
            $section->section != $format->get_sectionnum() &&
            $format->get_course_display() == COURSE_DISPLAY_MULTIPAGE &&
            !$format->show_editor()
        );

        $showcmlist = $section->uservisible;

        // Add activities summary if necessary.
        if ($showsummary) {
            $cmsummary = new $this->cmsummaryclass($format, $section);
            $data->cmsummary = $cmsummary->export_for_template($output);
            $data->onlysummary = true;
            $result = true;

            if (!$format->is_section_current($section)) {
                // In multipage, only the current section (and the section zero) has elements.
                $showcmlist = false;
            }
        }
        // Add the cm list.
        if ($showcmlist) {
            $cmlist = new $this->cmlistclass($format, $section);
            $data->cmlist = $cmlist->export_for_template($output);
            $result = true;
        }
        return $result;
    }

    /**
     * Add the section availability to the data structure.
     *
     * @param stdClass $data the current cm data reference
     * @param renderer_base $output typically, the renderer that's calling this function
     * @return bool if the cm has name data
     */
    protected function add_availability_data(stdClass &$data, renderer_base $output): bool {
        $availability = new $this->availabilityclass($this->format, $this->section);
        $data->availability = $availability->export_for_template($output);
        $data->restrictionlock = !empty($this->section->availableinfo);
        $data->hasavailability = $availability->has_availability($output);
        return true;
    }

    /**
     * Add the section vibility information to the data structure.
     *
     * @param stdClass $data the current cm data reference
     * @param renderer_base $output typically, the renderer that's calling this function
     * @return bool if the cm has name data
     */
    protected function add_visibility_data(stdClass &$data, renderer_base $output): bool {
        global $USER;
        $result = false;
        // Check if it is a stealth sections (orphaned).
        if ($this->isstealth) {
            $data->isstealth = true;
            $data->ishidden = true;
            $result = true;
        }
        if (!$this->section->visible) {
            $data->ishidden = true;
            $course = $this->format->get_course();
            $context = context_course::instance($course->id);
            if (has_capability('moodle/course:viewhiddensections', $context, $USER)) {
                $result = true;
            }
        }
        /* @var \core_courseformat\output\local\content\section\visibility $visibility By default the visibility class used
         * here but can be overriden by any course format */
        $visibility = new $this->visibilityclass($this->format, $this->section);
        $data->visibility = $visibility->export_for_template($output);
        return $result;
    }

    /**
     * Add the section editor attributes to the data structure.
     *
     * @param stdClass $data the current cm data reference
     * @param renderer_base $output typically, the renderer that's calling this function
     * @return bool if the cm has name data
     */
    protected function add_editor_data(stdClass &$data, renderer_base $output): bool {
        $course = $this->format->get_course();
        $coursecontext = context_course::instance($course->id);
        $editcaps = [];
        if (has_capability('moodle/course:sectionvisibility', $coursecontext)) {
            $editcaps = ['moodle/course:sectionvisibility'];
        }
        if (!$this->format->show_editor($editcaps)) {
            return false;
        }

        // In a single section page the control menu is located in the page header.
        if (empty($this->hidecontrols) && $this->format->get_sectionid() != $this->section->id) {
            $controlmenu = new $this->controlmenuclass($this->format, $this->section);
            $data->controlmenu = $controlmenu->export_for_template($output);
        }
        if (!$this->isstealth) {
            $data->cmcontrols = $output->course_section_add_cm_control(
                $course,
                $this->section->section,
                $this->format->get_sectionnum()
            );
        }
        return true;
    }

    /**
     * Add the section format attributes to the data structure.
     *
     * @param stdClass $data the current cm data reference
     * @param bool[] $haspartials the result of loading partial data elements
     * @param renderer_base $output typically, the renderer that's calling this function
     * @return bool if the cm has name data
     */
    protected function add_format_data(stdClass &$data, array $haspartials, renderer_base $output): bool {
        $section = $this->section;
        $format = $this->format;

        $data->iscoursedisplaymultipage = ($format->get_course_display() == COURSE_DISPLAY_MULTIPAGE);

        if ($data->num === 0 && !$data->iscoursedisplaymultipage) {
            $data->collapsemenu = true;
        }

        $data->contentcollapsed = $this->is_section_collapsed();

        if ($format->is_section_current($section)) {
            $data->iscurrent = true;
            $data->currentlink = get_accesshide(
                get_string('currentsection', 'format_' . $format->get_format())
            );
        }
        return true;
    }

    /**
     * Returns true if the current section should be shown collapsed.
     *
     * @return bool
     */
    protected function is_section_collapsed(): bool {
        global $PAGE;

        $contentcollapsed = false;
        $preferences = $this->format->get_sections_preferences();
        if (isset($preferences[$this->section->id])) {
            $sectionpreferences = $preferences[$this->section->id];
            if (!empty($sectionpreferences->contentcollapsed)) {
                $contentcollapsed = true;
            }
        }

        // No matter if the user's preference was to collapse the section or not: If the
        // 'expandsection' parameter has been specified, it will be shown uncollapsed.
        $expandsection = $PAGE->url->get_param('expandsection');
        if ($expandsection !== null && $this->section->section == $expandsection) {
            $contentcollapsed = false;
        }
        return $contentcollapsed;
    }
}
