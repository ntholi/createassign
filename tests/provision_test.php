<?php
namespace local_activity_utils\tests;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use local_activity_utils\external\provision\enrol_users;
use local_activity_utils\external\provision\ensure_course;
use local_activity_utils\external\provision\ensure_google_login;
use local_activity_utils\external\provision\provision_users;
use local_activity_utils\external\provision\unenrol_users;

class provision_test extends advanced_testcase {

    protected function setUp(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    public function test_ensure_google_login_keeps_password_form_and_blocks_signup(): void {
        $result = ensure_google_login::execute('test-client-id', 'test-client-secret');

        $this->assertSame(1, $result['ok']);
        $this->assertStringContainsString('oauth2', get_config('core', 'auth'));
        $this->assertStringContainsString('manual', get_config('core', 'auth'));
        $this->assertEquals(1, get_config('core', 'showloginform'));
        $this->assertEquals(1, get_config('core', 'authpreventaccountcreation'));
    }

    public function test_ensure_course_reuses_idnumber_and_creates_category(): void {
        $first = ensure_course::execute('Programming I', '2026-01_CS101_BSCSMY1S1', 'FICT', '12:34');
        $this->assertSame(1, $first['created']);
        $this->assertGreaterThan(0, $first['courseId']);
        $this->assertGreaterThan(0, $first['categoryId']);

        $second = ensure_course::execute('Programming I', '2026-01_CS101_BSCSMY1S1', 'fict', '12:34');
        $this->assertSame(0, $second['created']);
        $this->assertSame($first['courseId'], $second['courseId']);
        $this->assertSame($first['categoryId'], $second['categoryId']);
    }

    public function test_ensure_course_adopts_legacy_shortname_and_splits_collisions(): void {
        global $DB;

        $legacy = $this->getDataGenerator()->create_course([
            'fullname' => 'Programming I',
            'shortname' => '2026-01_CS101_BSCSMY1S1',
        ]);
        $this->assertSame('', (string) $legacy->idnumber);

        $adopted = ensure_course::execute('Programming I', '2026-01_CS101_BSCSMY1S1', 'FICT', '12:34');
        $this->assertSame(0, $adopted['created']);
        $this->assertSame((int) $legacy->id, $adopted['courseId']);
        $this->assertSame('12:34', $DB->get_field('course', 'idnumber', ['id' => $legacy->id]));

        $other = ensure_course::execute('Programming I', '2026-01_CS101_BSCSMY1S1', 'FICT', '12:99');
        $this->assertSame(1, $other['created']);
        $this->assertNotSame($adopted['courseId'], $other['courseId']);
        $this->assertSame(
            '2026-01_CS101_BSCSMY1S1_12-99',
            $DB->get_field('course', 'shortname', ['id' => $other['courseId']])
        );
    }

    public function test_provision_enrol_and_unenrol(): void {
        ensure_google_login::execute('test-client-id', 'test-client-secret');
        $course = ensure_course::execute('Programming I', '2026-01_CS101_BSCSMY1S1', 'FICT', '12:34');

        $provisioned = provision_users::execute([
            [
                'email' => 'lecturer@example.com',
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
            ],
            [
                'email' => 'student@example.com',
                'firstname' => 'Alan',
                'lastname' => 'Turing',
            ],
        ]);

        $this->assertCount(2, $provisioned['users']);
        $this->assertNotEmpty($provisioned['users'][0]['token']);
        $this->assertNotEmpty($provisioned['users'][1]['token']);

        $enrolled = enrol_users::execute([
            [
                'email' => 'lecturer@example.com',
                'courseid' => $course['courseId'],
                'roleshortname' => 'editingteacher',
            ],
            [
                'email' => 'student@example.com',
                'courseid' => $course['courseId'],
                'roleshortname' => 'student',
            ],
        ]);
        $this->assertSame(2, $enrolled['enrolled']);

        $unenrolled = unenrol_users::execute([
            [
                'email' => 'student@example.com',
                'courseid' => $course['courseId'],
            ],
        ]);
        $this->assertSame(1, $unenrolled['unenrolled']);

        $noop = unenrol_users::execute([
            [
                'email' => 'missing@example.com',
                'courseid' => $course['courseId'],
            ],
        ]);
        $this->assertSame(0, $noop['unenrolled']);
    }
}
