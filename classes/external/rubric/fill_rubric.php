<?php
namespace local_activity_utils\external\rubric;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

class fill_rubric extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the assignment'),
            'userid' => new external_value(PARAM_INT, 'User ID of the student being graded'),
            'fillings' => new external_multiple_structure(
                new external_single_structure([
                    'criterionid' => new external_value(PARAM_INT, 'Criterion ID'),
                    'levelid' => new external_value(PARAM_INT, 'Level ID to select for this criterion', VALUE_DEFAULT, 0),
                    'score' => new external_value(PARAM_FLOAT, 'Custom score (optional, overrides levelid)', VALUE_DEFAULT, null),
                    'remark' => new external_value(PARAM_RAW, 'Remark/feedback for this criterion', VALUE_DEFAULT, ''),
                ]),
                'Rubric fillings (selected levels/scores and remarks for each criterion)'
            ),
            'overallremark' => new external_value(PARAM_RAW, 'Overall feedback/remark for the grading', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $cmid,
        int $userid,
        array $fillings,
        string $overallremark = ''
    ): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/grade/grading/lib.php');
        require_once($CFG->dirroot . '/grade/grading/form/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/lib/gradelib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'userid' => $userid,
            'fillings' => $fillings,
            'overallremark' => $overallremark,
        ]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('local/activity_utils:graderubric', $context);
        require_capability('mod/assign:grade', $context);

        $user = $DB->get_record('user', ['id' => $params['userid']], '*', MUST_EXIST);
        if (!is_enrolled($context, $user)) {
            return [
                'instanceid' => 0,
                'grade' => 0,
                'success' => false,
                'message' => 'User is not enrolled in this course',
            ];
        }

        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $activemethod = $gradingmanager->get_active_method();

        if ($activemethod !== 'fivedays') {
            return [
                'instanceid' => 0,
                'grade' => 0,
                'success' => false,
                'message' => 'Assignment does not use FiveDays rubric grading',
            ];
        }

        $controller = $gradingmanager->get_controller('fivedays');

        if (!$controller->is_form_defined()) {
            return [
                'instanceid' => 0,
                'grade' => 0,
                'success' => false,
                'message' => 'FiveDays rubric is not defined for this assignment',
            ];
        }

        $assignment = new \assign($context, $cm, $cm->course);
        $submission = $assignment->get_user_submission($params['userid'], false);

        if (!$submission) {
            return [
                'instanceid' => 0,
                'grade' => 0,
                'success' => false,
                'message' => 'No submission found for this user',
            ];
        }

        $grade = $assignment->get_user_grade($params['userid'], true);
        $definition = $controller->get_definition();
        $criteria = $DB->get_records('gradingform_fivedays_criteria', ['definitionid' => $definition->id], '', 'id');

        $validfillings = [];
        foreach ($params['fillings'] as $filling) {
            if (!isset($criteria[$filling['criterionid']])) {
                return [
                    'instanceid' => 0,
                    'grade' => 0,
                    'success' => false,
                    'message' => 'Invalid criterion ID: ' . $filling['criterionid'],
                ];
            }

            if (!empty($filling['levelid'])) {
                $level = $DB->get_record('gradingform_fivedays_levels', [
                    'id' => $filling['levelid'],
                    'criterionid' => $filling['criterionid']
                ]);

                if (!$level) {
                    return [
                        'instanceid' => 0,
                        'grade' => 0,
                        'success' => false,
                        'message' => 'Invalid level ID: ' . $filling['levelid'] . ' for criterion: ' . $filling['criterionid'],
                    ];
                }
            }

            $levelid = !empty($filling['levelid']) ? $filling['levelid'] : null;
            $score = $filling['score'] ?? null;

            if ($levelid && $score === null) {
                $level = $DB->get_record('gradingform_fivedays_levels', ['id' => $levelid]);
                if ($level) {
                    $score = (float) $level->score;
                }
            }

            $validfillings[$filling['criterionid']] = [
                'levelid' => $levelid,
                'score' => $score,
                'remark' => $filling['remark'] ?? '',
            ];
        }

        $existinginstance = $DB->get_record_sql(
            "SELECT gi.* FROM {grading_instances} gi
             WHERE gi.definitionid = :definitionid
             AND gi.itemid = :itemid
             ORDER BY gi.timemodified DESC
             LIMIT 1",
            ['definitionid' => $definition->id, 'itemid' => $grade->id]
        );

        if ($existinginstance) {
            $instance = $controller->get_or_create_instance($existinginstance->id, $USER->id, $grade->id);
        } else {
            $instance = $controller->get_or_create_instance(0, $USER->id, $grade->id);
        }

        $instanceid = $instance->get_id();

        foreach ($validfillings as $criterionid => $filling) {
            $existingfilling = $DB->get_record('gradingform_fivedays_fillings', [
                'instanceid' => $instanceid,
                'criterionid' => $criterionid,
            ]);

            if ($existingfilling) {
                $DB->update_record('gradingform_fivedays_fillings', [
                    'id' => $existingfilling->id,
                    'levelid' => $filling['levelid'],
                    'score' => $filling['score'],
                    'remark' => $filling['remark'],
                    'remarkformat' => FORMAT_HTML,
                ]);
            } else {
                $DB->insert_record('gradingform_fivedays_fillings', [
                    'instanceid' => $instanceid,
                    'criterionid' => $criterionid,
                    'levelid' => $filling['levelid'],
                    'score' => $filling['score'],
                    'remark' => $filling['remark'],
                    'remarkformat' => FORMAT_HTML,
                ]);
            }
        }

        $DB->set_field('grading_instances', 'status', \gradingform_instance::INSTANCE_STATUS_ACTIVE, ['id' => $instanceid]);
        $DB->set_field('grading_instances', 'timemodified', time(), ['id' => $instanceid]);

        $grademax = $assignment->get_instance()->grade;
        $controller->set_grade_range(make_grades_menu($grademax), $grademax > 0);
        $instance->get_fivedays_filling(true);
        $gradevalue = $instance->get_grade();
        $saved = \local_activity_utils\helper::save_assignment_grade(
            $assignment,
            $params['userid'],
            (float)$gradevalue
        );

        return [
            'instanceid' => $instanceid,
            'grade' => $saved['grade'],
            'releasestate' => $saved['releasestate'],
            'success' => true,
            'message' => 'FiveDays rubric filled and grade saved successfully',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'instanceid' => new external_value(PARAM_INT, 'Grading instance ID'),
            'grade' => new external_value(PARAM_FLOAT, 'Calculated grade from rubric'),
            'releasestate' => new external_value(PARAM_ALPHA, 'notgraded, notreleased, released, or beingedited'),
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
        ]);
    }
}
