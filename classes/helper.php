<?php
namespace local_activity_utils;

class helper {

    public static function get_section_by_number(int $courseid, int $sectionnum): ?\stdClass {
        global $DB;
        
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $sectionnum
        ]);
        
        return $section;
    }

    public static function resolve_section_id(int $courseid, int $sectionnum): ?int {
        $section = self::get_section_by_number($courseid, $sectionnum);
        return $section ? (int)$section->id : null;
    }

    public static function create_module(
        \stdClass $course,
        string $modulename,
        int $sectionnum,
        string $name,
        int $visible,
        array $properties = []
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = $modulename;
        $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => $modulename], MUST_EXIST);
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->name = $name;
        $moduleinfo->visible = $visible;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 0;
        $moduleinfo->completionview = 0;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->completionpassgrade = 0;
        $moduleinfo->completiongradeitemnumber = null;
        $moduleinfo->showdescription = 0;
        $moduleinfo->availability = null;
        $moduleinfo->downloadcontent = 1;

        foreach ($properties as $property => $value) {
            $moduleinfo->$property = $value;
        }

        return add_moduleinfo($moduleinfo, $course);
    }

    public static function add_module_to_section(int $courseid, int $sectionnum, int $cmid, int $coursemodule_visible): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $section = self::get_section_by_number($courseid, $sectionnum);
        if (!$section) {
            throw new \moodle_exception('sectionnotfound', 'local_activity_utils', '', $sectionnum);
        }

        course_add_cm_to_section($courseid, $cmid, $sectionnum);

        if (!empty($section->component) && $section->component === 'mod_subsection') {
            self::inherit_subsection_visibility($cmid, $section, $coursemodule_visible);
        }
    }

    private static function inherit_subsection_visibility(int $cmid, \stdClass $delegated_section, int $requested_visibility): void {
        global $DB;

        $subsection_cm = $DB->get_record('course_modules', [
            'instance' => (int)$delegated_section->itemid,
            'module' => $DB->get_field('modules', 'id', ['name' => 'subsection'])
        ]);

        if ($subsection_cm) {
            $final_visibility = $requested_visibility && $subsection_cm->visible ? 1 : 0;
            set_coursemodule_visible($cmid, $final_visibility, 1, false);
        }
    }
}
