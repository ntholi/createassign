<?php
namespace local_activity_utils\external\assignment;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_activity_utils\helper;

class release_assignment_grades extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'Assignment ID'),
        ]);
    }

    public static function execute(int $assignmentid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
        ]);

        [$assignment, , , $context] = helper::get_assign($params['assignmentid']);
        self::validate_context($context);
        require_capability('local/activity_utils:gradeassignment', $context);
        require_capability('mod/assign:grade', $context);

        $result = helper::release_unpublished_grades($assignment);

        return [
            'released' => $result['released'],
            'success' => true,
            'message' => 'Unpublished grades released',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'released' => new external_value(PARAM_INT, 'How many students were released'),
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
        ]);
    }
}
