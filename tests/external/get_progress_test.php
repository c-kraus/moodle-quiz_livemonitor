<?php
// This file is part of the quiz_livemonitor plugin for Moodle.
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace quiz_livemonitor\external;

use core_external\external_api;
use mod_quiz\quiz_attempt;
use quiz_livemonitor\exam_fixture_trait;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/exam_fixture_trait.php');

/**
 * Tests for the polling web service.
 *
 * The teacher's browser calls this every few seconds during an exam, so the
 * contract that matters most is that the data actually returned still validates
 * against the declared return structure: a mismatch there breaks the live
 * refresh in the browser while the server-side first render still looks fine.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_livemonitor\external\get_progress
 */
final class get_progress_test extends \advanced_testcase {
    use exam_fixture_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Call the service and validate the result against its own declaration.
     *
     * @param int|null $cmid the course module id, defaulting to the fixture quiz.
     * @return array the cleaned return value.
     */
    protected function call_service(?int $cmid = null): array {
        $result = get_progress::execute($cmid ?? (int) $this->cm->id);
        return external_api::clean_returnvalue(get_progress::execute_returns(), $result);
    }

    public function test_returned_data_validates_against_the_declared_structure(): void {
        $this->create_quiz();
        $users = $this->seed_every_status();
        $this->setUser($this->create_teacher());

        // clean_returnvalue() throws if a key is missing, extra, or the wrong type.
        $data = $this->call_service();

        $this->assertSame((int) $this->cm->id, $data['cmid']);
        $this->assertTrue($data['hasrows']);
        $this->assertCount(5, $data['rows']);
        $this->assertNotEmpty($data['generatedatstr']);
        $this->assertGreaterThan(0, $data['generatedat']);
        $this->assertSame(5, $data['summary']['total']);
        $this->assertSame('active', $this->row_for($data, $users['active']->id)['statuskey']);
    }

    /**
     * The declaration must not drop a field the polled template renders.
     *
     * clean_returnvalue() rejects a declared key whose data is missing or badly
     * typed, but it silently *strips* anything the declaration does not mention.
     * So omitting a field from execute_returns() does not fail loudly: the first
     * server-side render still shows it, and it disappears on the first poll.
     * The field list is read out of the template at run time so this test keeps
     * telling the truth as the template changes.
     */
    public function test_the_declaration_keeps_every_field_the_polled_template_renders(): void {
        global $CFG;
        $this->create_quiz();
        $this->seed_every_status();
        $this->setUser($this->create_teacher());

        $template = file_get_contents(
            $CFG->dirroot . '/mod/quiz/report/livemonitor/templates/monitor_body.mustache'
        );
        preg_match_all('/\{\{[#\/^&]?\s*([a-zA-Z_][a-zA-Z0-9_.]*)\s*\}\}/', $template, $matches);
        $fields = array_unique($matches[1]);
        $this->assertGreaterThan(10, count($fields), 'the template scan found suspiciously few fields');

        $data = $this->call_service();
        $row = $data['rows'][0];

        // {{#str}} is the Mustache string helper and {{#rows}} opens the loop.
        $notdata = ['str', 'rows'];
        $toplevel = ['hasrows', 'generatedatstr', 'generatedat', 'cmid'];

        foreach ($fields as $field) {
            if (in_array($field, $notdata, true)) {
                continue;
            }
            if (str_starts_with($field, 'summary.')) {
                $key = substr($field, strlen('summary.'));
                $this->assertArrayHasKey(
                    $key,
                    $data['summary'],
                    "the template renders summary.{$key} but the web service does not return it"
                );
            } else if (in_array($field, $toplevel, true)) {
                $this->assertArrayHasKey(
                    $field,
                    $data,
                    "the template renders {$field} but the web service does not return it"
                );
            } else {
                $this->assertArrayHasKey(
                    $field,
                    $row,
                    "the template renders {$field} per row but the web service does not return it"
                );
            }
        }

        // Nothing the provider produces may be lost in transit either.
        $providerrow = $this->snapshot()['rows'][0];
        $servicekeys = array_keys($row);
        $providerkeys = array_keys($providerrow);
        sort($servicekeys);
        sort($providerkeys);
        $this->assertSame(
            $providerkeys,
            $servicekeys,
            'the declared row structure and the provider row have drifted apart'
        );
    }

    /**
     * Every status key must survive the declared PARAM_ALPHA unchanged.
     *
     * statuskey is declared PARAM_ALPHA, so a future key containing an
     * underscore or a digit would be silently mangled on the way out while the
     * server-side render kept showing the right thing.
     */
    public function test_every_status_key_survives_the_declared_type(): void {
        global $DB;
        $this->create_quiz();
        $users = $this->seed_every_status();
        // Add the two states seed_every_status() does not produce.
        $abandoned = $this->create_student('Frank', 'Aufgegeben');
        $DB->set_field(
            'quiz_attempts',
            'state',
            'abandoned',
            ['id' => $this->start_attempt($abandoned, 1, time() - 300)->id]
        );
        $overduestate = $this->create_student('Greta', 'Faellig');
        $DB->set_field(
            'quiz_attempts',
            'state',
            'overdue',
            ['id' => $this->start_attempt($overduestate, 1, time() - 300)->id]
        );
        $this->setUser($this->create_teacher());

        $data = $this->call_service();

        $keys = array_column($data['rows'], 'statuskey');
        foreach (['active', 'idle', 'finished', 'overdue', 'abandoned', 'notstarted'] as $expected) {
            $this->assertContains($expected, $keys, "status '{$expected}' missing from the payload");
        }
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z]+$/',
                $key,
                "status key '{$key}' is not PARAM_ALPHA-safe and would be mangled in transit"
            );
        }
        $this->assertSame('abandoned', $this->row_for($data, $abandoned->id)['statuskey']);
        $this->assertSame('overdue', $this->row_for($data, $overduestate->id)['statuskey']);
        $this->assertSame(
            (int) $users['finished']->id,
            (int) $this->row_for($data, $users['finished']->id)['userid']
        );
    }

    /**
     * The polled payload and the first server-side render must not diverge.
     *
     * Both go through progress_provider, and the README promises the teacher sees
     * the same thing before and after the first poll.
     */
    public function test_service_returns_what_the_server_side_render_shows(): void {
        $this->create_quiz();
        $this->seed_every_status();
        $this->setUser($this->create_teacher());

        $fromservice = $this->call_service();
        $fromprovider = $this->snapshot();

        $this->assertSame($fromprovider['summary'], $fromservice['summary']);
        $this->assertSame(
            array_column($fromprovider['rows'], 'statuskey'),
            array_column($fromservice['rows'], 'statuskey')
        );
        $this->assertSame(
            array_column($fromprovider['rows'], 'progresslabel'),
            array_column($fromservice['rows'], 'progresslabel')
        );
        $this->assertSame(
            array_column($fromprovider['rows'], 'fullname'),
            array_column($fromservice['rows'], 'fullname')
        );
    }

    public function test_a_teacher_may_call_it(): void {
        $this->create_quiz();
        $this->create_student();
        $this->setUser($this->create_teacher('editingteacher'));

        $this->assertSame(1, $this->call_service()['summary']['total']);
    }

    public function test_a_non_editing_teacher_may_call_it(): void {
        $this->create_quiz();
        $this->create_student();
        $this->setUser($this->create_teacher('teacher'));

        $this->assertSame(1, $this->call_service()['summary']['total']);
    }

    public function test_a_student_is_refused(): void {
        $this->create_quiz();
        $student = $this->create_student();
        $this->start_attempt($student, 2, time() - 300);
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        get_progress::execute((int) $this->cm->id);
    }

    /**
     * Someone teaching a different course must not reach this quiz.
     *
     * validate_context() refuses them for not being enrolled, before the
     * capability check is even reached, so this asserts the portable base class
     * rather than a specific one: Moodle 4.5 throws
     * core\exception\require_login_exception where older branches throw the
     * global require_login_exception.
     */
    public function test_a_teacher_of_another_course_is_refused(): void {
        $this->create_quiz();
        $this->create_student();
        $othercourse = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($outsider->id, $othercourse->id, 'editingteacher');
        $this->setUser($outsider);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/not accessible|Not enrolled|nopermission/i');
        get_progress::execute((int) $this->cm->id);
    }

    public function test_a_teacher_whose_capability_is_prevented_is_refused(): void {
        $this->create_quiz();
        $this->create_student();
        $teacher = $this->create_teacher();
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $teacher->id, $this->context->id);
        assign_capability('quiz/livemonitor:view', CAP_PROHIBIT, $roleid, $this->context->id, true);
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        get_progress::execute((int) $this->cm->id);
    }

    public function test_an_unknown_cmid_is_rejected(): void {
        $this->create_quiz();
        $this->setUser($this->create_teacher());

        $this->expectException(\dml_missing_record_exception::class);
        get_progress::execute(999999);
    }

    public function test_a_module_that_is_not_a_quiz_is_rejected(): void {
        $this->create_quiz();
        $this->setUser($this->create_teacher());
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $assigncm = get_coursemodule_from_instance('assign', $assign->id);

        $this->expectException(\dml_missing_record_exception::class);
        get_progress::execute((int) $assigncm->id);
    }

    public function test_a_non_numeric_cmid_is_rejected_by_parameter_validation(): void {
        $this->create_quiz();
        $this->setUser($this->create_teacher());

        $this->expectException(\invalid_parameter_exception::class);
        external_api::validate_parameters(get_progress::execute_parameters(), ['cmid' => 'not-an-id']);
    }

    public function test_it_honours_the_configured_active_window(): void {
        $this->create_quiz();
        $student = $this->create_student();
        // Two minutes of silence: idle under the default 60s, active under 300s.
        $this->set_last_activity($this->start_attempt($student, 1, time() - 600), time() - 120);
        $this->setUser($this->create_teacher());

        set_config('activewindow', 60, 'quiz_livemonitor');
        $this->assertSame('idle', $this->row_for($this->call_service(), $student->id)['statuskey']);

        set_config('activewindow', 300, 'quiz_livemonitor');
        $this->assertSame('active', $this->row_for($this->call_service(), $student->id)['statuskey']);
    }

    public function test_it_falls_back_to_a_default_active_window_when_unset(): void {
        $this->create_quiz();
        $student = $this->create_student();
        $this->set_last_activity($this->start_attempt($student, 1, time() - 600), time() - 5);
        $this->setUser($this->create_teacher());

        unset_config('activewindow', 'quiz_livemonitor');

        $this->assertSame('active', $this->row_for($this->call_service(), $student->id)['statuskey']);
    }

    public function test_it_writes_nothing_to_the_database(): void {
        global $DB;
        $this->create_quiz();
        $this->seed_every_status();
        $this->setUser($this->create_teacher());

        $before = [
            'attempts' => $DB->get_records('quiz_attempts', [], 'id'),
            'steps' => $DB->count_records('question_attempt_steps'),
            'users' => $DB->count_records('user'),
        ];

        $this->call_service();

        $this->assertEquals($before['attempts'], $DB->get_records('quiz_attempts', [], 'id'));
        $this->assertSame($before['steps'], $DB->count_records('question_attempt_steps'));
        $this->assertSame($before['users'], $DB->count_records('user'));
    }

    public function test_a_finished_attempt_is_reported_over_the_service(): void {
        $this->create_quiz();
        $student = $this->create_student();
        $this->start_attempt($student, 4, time() - 1200);
        quiz_attempt::create($this->attempt_id_of($student->id))->process_finish(time() - 60, false);
        $this->setUser($this->create_teacher());

        $row = $this->row_for($this->call_service(), $student->id);

        $this->assertSame('finished', $row['statuskey']);
        $this->assertSame(4, $row['answered']);
        $this->assertSame('4 / 4', $row['progresslabel']);
        $this->assertSame(100, $row['progresspercent']);
    }

    public function test_an_empty_quiz_returns_no_rows_over_the_service(): void {
        $this->create_quiz();
        $this->setUser($this->create_teacher());

        $data = $this->call_service();

        $this->assertFalse($data['hasrows']);
        $this->assertSame([], $data['rows']);
        $this->assertSame(0, $data['summary']['total']);
    }

    /**
     * The declaration in db/services.php is what the RZ review will read.
     *
     * A 'write' type, a missing capability, or ajax being off would each be a
     * substantive change to how the plugin presents itself for deployment.
     */
    public function test_the_service_is_declared_read_only_ajax_and_capability_gated(): void {
        global $CFG;

        $functions = [];
        require($CFG->dirroot . '/mod/quiz/report/livemonitor/db/services.php');

        $this->assertArrayHasKey('quiz_livemonitor_get_progress', $functions);
        $definition = $functions['quiz_livemonitor_get_progress'];
        $this->assertSame('read', $definition['type'], 'the service must not declare itself as writing');
        $this->assertTrue($definition['ajax'], 'the browser polls this directly, so ajax must stay on');
        $this->assertSame('quiz/livemonitor:view', $definition['capabilities']);
        $this->assertSame(get_progress::class, ltrim($definition['classname'], '\\'));
        $this->assertSame('execute', $definition['methodname']);
    }
}
