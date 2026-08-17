<?php
namespace local_activity_utils;

defined('MOODLE_INTERNAL') || die();

class provision_helper {

    public static function require_system_capability(): \context_system {
        $context = \context_system::instance();
        \core_external\external_api::validate_context($context);
        require_capability('local/activity_utils:provision', $context);
        return $context;
    }

    public static function find_google_issuer(): ?\core\oauth2\issuer {
        $issuers = \core\oauth2\api::get_all_issuers(true);
        foreach ($issuers as $candidate) {
            if (strtolower((string) $candidate->get('name')) === 'google') {
                return $candidate;
            }
        }
        return null;
    }

    public static function require_google_issuer(): \core\oauth2\issuer {
        $issuer = self::find_google_issuer();
        if (!$issuer) {
            throw new \moodle_exception('googleissuermissing', 'local_activity_utils');
        }
        return $issuer;
    }

    public static function find_fivedays_service(): ?\stdClass {
        global $DB;

        foreach (['fivedays', 'registry_lms'] as $shortname) {
            $service = $DB->get_record('external_services', ['shortname' => $shortname]);
            if ($service) {
                return $service;
            }
        }

        return null;
    }

    public static function ensure_rest_service(): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/externallib.php');
        require_once($CFG->dirroot . '/webservice/lib.php');

        set_config('enablewebservices', 1);
        $protocols = array_filter(explode(',', get_config('core', 'webserviceprotocols') ?: ''));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }

        $webservicemanager = new \webservice();
        $service = self::find_fivedays_service();
        if (!$service) {
            $service = (object) [
                'name' => 'FiveDays',
                'shortname' => 'fivedays',
                'enabled' => 1,
                'restrictedusers' => 0,
                'downloadfiles' => 1,
                'uploadfiles' => 1,
            ];
            $service->id = $webservicemanager->add_external_service($service);
        } else if (
            $service->name !== 'FiveDays'
            || $service->shortname !== 'fivedays'
            || (int) $service->enabled !== 1
        ) {
            $service->name = 'FiveDays';
            $service->shortname = 'fivedays';
            $service->enabled = 1;
            $webservicemanager->update_external_service($service);
        }

        $pluginfunctions = $DB->get_fieldset_select('external_functions', 'name', "name LIKE 'local_activity_utils_%'");
        $core = [
            'core_enrol_get_enrolled_users',
        ];
        foreach (array_unique(array_merge($pluginfunctions, $core)) as $functionname) {
            if (!$DB->record_exists('external_functions', ['name' => $functionname])) {
                continue;
            }
            if (!$webservicemanager->service_function_exists($functionname, $service->id)) {
                $webservicemanager->add_external_function_to_service($functionname, $service->id);
            }
        }

        return $service;
    }

    public static function ensure_token(\stdClass $service, int $userid): string {
        global $CFG, $DB;

        require_once($CFG->libdir . '/externallib.php');

        $existing = $DB->get_record('external_tokens', [
            'userid' => $userid,
            'externalserviceid' => $service->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);
        if ($existing) {
            return $existing->token;
        }
        return external_generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $userid,
            \context_system::instance()
        );
    }

    public static function ensure_oauth_user(
        string $email,
        string $firstname,
        string $lastname,
        \core\oauth2\issuer $issuer
    ): \stdClass {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');

        $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        if (!$user) {
            $record = (object) [
                'auth' => 'oauth2',
                'username' => strtolower(str_replace('@', '.', $email)),
                'email' => $email,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'password' => AUTH_PASSWORD_NOT_CACHED,
            ];
            $id = user_create_user($record, false, false);
            $user = $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
        } else if ($user->auth !== 'oauth2') {
            $user->auth = 'oauth2';
            user_update_user($user, false, false);
        }

        try {
            \auth_oauth2\api::link_login([
                'username' => $email,
                'email' => $email,
            ], $issuer, $user->id, true);
        } catch (\moodle_exception $exception) {
            if ($exception->errorcode !== 'alreadylinked') {
                throw $exception;
            }
        }

        return $user;
    }

    public static function ensure_category(string $categoryname): \core_course_category {
        global $DB;

        $record = $DB->get_record('course_categories', ['name' => $categoryname], '*', IGNORE_MULTIPLE);
        if ($record) {
            return \core_course_category::get((int) $record->id);
        }

        return \core_course_category::create([
            'name' => $categoryname,
            'parent' => 0,
            'visible' => 1,
        ]);
    }

    public static function ensure_course(string $fullname, string $shortname, string $categoryname): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $category = self::ensure_category($categoryname);
        $existing = $DB->get_record('course', ['shortname' => $shortname]);
        if ($existing) {
            return [
                'courseId' => (int) $existing->id,
                'created' => 0,
                'categoryId' => (int) $category->id,
            ];
        }

        $course = create_course((object) [
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category->id,
            'visible' => 1,
        ]);

        return [
            'courseId' => (int) $course->id,
            'created' => 1,
            'categoryId' => (int) $category->id,
        ];
    }

    public static function find_user_by_email(string $email): ?\stdClass {
        global $DB;
        $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        return $user ?: null;
    }

    public static function ensure_manual_instance(\stdClass $course): \stdClass {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        if (!$instance) {
            $plugin->add_instance($course);
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        }
        return $instance;
    }

    public static function enrol_user_by_email(string $email, int $courseid, string $roleshortname): bool {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        $user = self::find_user_by_email($email);
        if (!$user) {
            throw new \moodle_exception('usernotfound', 'local_activity_utils', '', $email);
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $roleid = $DB->get_field('role', 'id', ['shortname' => $roleshortname], MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $instance = self::ensure_manual_instance($course);

        try {
            $plugin->enrol_user($instance, (int) $user->id, (int) $roleid);
        } catch (\moodle_exception $exception) {
            if ($exception->errorcode !== 'Message was not sent.') {
                throw $exception;
            }
        }

        return true;
    }

    public static function unenrol_user_by_email(string $email, int $courseid): bool {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        $user = self::find_user_by_email($email);
        if (!$user) {
            return false;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return false;
        }

        $context = \context_course::instance($course->id);
        if (!is_enrolled($context, $user)) {
            return false;
        }

        $plugin = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        if (!$instance) {
            return false;
        }

        $plugin->unenrol_user($instance, (int) $user->id);
        return true;
    }

    public static function ensure_google_login(string $clientid, string $clientsecret): int {
        $auths = array_values(array_filter(explode(',', get_config('core', 'auth') ?: 'manual')));
        if (!in_array('manual', $auths, true)) {
            array_unshift($auths, 'manual');
        }
        if (!in_array('oauth2', $auths, true)) {
            $auths[] = 'oauth2';
        }
        set_config('auth', implode(',', $auths));
        set_config('showloginform', 1);
        set_config('authpreventaccountcreation', 1);

        $issuer = self::find_google_issuer();
        if (!$issuer) {
            $issuer = \core\oauth2\api::create_standard_issuer('google');
        }
        $issuer->set('clientid', $clientid);
        $issuer->set('clientsecret', $clientsecret);
        $issuer->set('enabled', 1);
        $issuer->set('showonloginpage', \core\oauth2\issuer::EVERYWHERE);
        $issuer->set('requireconfirmation', 0);
        $issuer->update();

        return 1;
    }
}
