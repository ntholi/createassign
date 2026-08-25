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

    public static function get_assign_for_comments(int $assignmentid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assign = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $assign->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $assignment = new \assign($context, $cm, $course);

        return [$assignment, $cm, $course, $context];
    }

    public static function enable_assignment_comments(\assign $assignment): void {
        $plugin = $assignment->get_submission_plugin_by_type('comments');
        if ($plugin && !$plugin->is_enabled()) {
            $plugin->enable();
        }
    }

    public static function assignment_comment_manager(
        \assign $assignment,
        \stdClass $cm,
        \stdClass $course,
        int $submissionid
    ): \core_comment\manager {
        $options = new \stdClass();
        $options->context = $assignment->get_context();
        $options->component = 'assignsubmission_comments';
        $options->area = 'submission_comments';
        $options->itemid = $submissionid;
        $options->course = $course;
        $options->cm = $cm;
        return new \core_comment\manager($options);
    }

    public static function format_assignment_comment(\stdClass $comment, \stdClass $user): array {
        return [
            'id' => (int)$comment->id,
            'content' => (string)$comment->content,
            'userid' => (int)$comment->userid,
            'author' => fullname($user),
            'timecreated' => (int)$comment->timecreated,
        ];
    }

    public static function list_assignment_comments(\assign $assignment, int $userid): array {
        global $DB;

        $submission = $assignment->get_user_submission($userid, false);
        if (!$submission) {
            return [];
        }

        $comments = $DB->get_records_select(
            'comments',
            'contextid = :contextid AND component = :component AND commentarea = :commentarea AND itemid = :itemid',
            [
                'contextid' => $assignment->get_context()->id,
                'component' => 'assignsubmission_comments',
                'commentarea' => 'submission_comments',
                'itemid' => $submission->id,
            ],
            'timecreated ASC, id ASC'
        );
        if (!$comments) {
            return [];
        }

        $userids = array_unique(array_map(static fn($comment) => (int)$comment->userid, $comments));
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $users = $DB->get_records_select('user', "id $insql", $inparams);

        $out = [];
        foreach ($comments as $comment) {
            $user = $users[$comment->userid] ?? null;
            if (!$user) {
                continue;
            }
            $out[] = self::format_assignment_comment($comment, $user);
        }
        return $out;
    }

    public static function require_assignment_comment(int $commentid): array {
        global $DB;

        $comment = $DB->get_record('comments', ['id' => $commentid], '*', MUST_EXIST);
        if (
            $comment->component !== 'assignsubmission_comments'
            || $comment->commentarea !== 'submission_comments'
        ) {
            throw new \moodle_exception('invalidcommentarea');
        }

        $submission = $DB->get_record('assign_submission', ['id' => $comment->itemid], '*', MUST_EXIST);
        [$assignment, $cm, $course, $context] = self::get_assign_for_comments((int)$submission->assignment);

        return [$comment, $submission, $assignment, $cm, $course, $context];
    }
}
