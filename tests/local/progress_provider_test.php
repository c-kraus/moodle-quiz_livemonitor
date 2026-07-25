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

namespace quiz_livemonitor\local;

use mod_quiz\quiz_attempt;
use quiz_livemonitor\exam_fixture_trait;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/exam_fixture_trait.php');

/**
 * Tests for the read-only progress snapshot.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \quiz_livemonitor\local\progress_provider
 */
final class progress_provider_test extends \advanced_testcase {
    use exam_fixture_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_enrolled_student_without_attempt_is_not_started(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('notstarted', $row['statuskey']);
        $this->assertSame(0, $row['answered']);
        $this->assertSame(4, $row['total']);
        $this->assertSame(0, $row['progresspercent']);
        // -1 distinguishes "never active" from "active zero seconds ago".
        $this->assertSame(-1, $row['lastactivityseconds']);
        $this->assertFalse($row['isactive']);
        $this->assertFalse($row['isoverrun']);
        $this->assertFalse($row['hastimelimit']);
    }

    public function test_recent_activity_counts_as_active(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 3, time() - 300);
        $this->set_last_activity($attempt, time() - 5);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('active', $row['statuskey']);
        $this->assertTrue($row['isactive']);
        $this->assertSame(3, $row['answered']);
        $this->assertSame(75, $row['progresspercent']);
        $this->assertSame('3 / 4', $row['progresslabel']);
    }

    public function test_activity_older_than_the_active_window_is_idle(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 1, time() - 900);
        $this->set_last_activity($attempt, time() - 600);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('idle', $row['statuskey']);
        $this->assertFalse($row['isactive']);
        $this->assertSame(1, $row['answered']);
        $this->assertSame(25, $row['progresspercent']);
    }

    public function test_active_window_setting_is_honoured(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 1, time() - 600);
        $this->set_last_activity($attempt, time() - 120);

        // 120s of silence: idle under a 60s window, active under a 300s one.
        $this->assertSame('idle', $this->row_for($this->snapshot(60), $user->id)['statuskey']);
        $this->assertSame('active', $this->row_for($this->snapshot(300), $user->id)['statuskey']);
    }

    public function test_submitted_attempt_is_finished_and_has_no_remaining_time(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $this->start_attempt($user, 4, time() - 1200);
        quiz_attempt::create(
            $this->attempt_id_of($user->id)
        )->process_finish(time() - 120, false);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('finished', $row['statuskey']);
        $this->assertSame(4, $row['answered']);
        $this->assertSame(100, $row['progresspercent']);
        $this->assertFalse($row['hastimelimit']);
        $this->assertSame('', $row['timeleftlabel']);
        $this->assertFalse($row['isactive']);
    }


    public function test_exceeding_the_time_limit_is_reported_as_overrun(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        // Started an hour ago against a 30 minute limit.
        $attempt = $this->start_attempt($user, 2, time() - 3600);
        $this->set_last_activity($attempt, time() - 1500);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('overdue', $row['statuskey']);
        $this->assertTrue($row['isoverrun']);
        $this->assertTrue($row['hastimelimit']);
        $this->assertLessThan(0, $row['timeleftseconds']);
        $this->assertNotSame('', $row['timeleftlabel']);
    }

    public function test_overdue_attempt_state_is_reported_as_overrun(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 1, time() - 300);
        $DB->set_field('quiz_attempts', 'state', 'overdue', ['id' => $attempt->id]);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('overdue', $row['statuskey']);
        $this->assertTrue($row['isoverrun']);
    }

    public function test_abandoned_attempt_is_reported_as_abandoned(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 1, time() - 300);
        $DB->set_field('quiz_attempts', 'state', 'abandoned', ['id' => $attempt->id]);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('abandoned', $row['statuskey']);
        $this->assertFalse($row['isactive']);
        $this->assertFalse($row['isoverrun']);
    }

    public function test_blank_submission_counts_as_nothing_answered(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        // Start but answer nothing, then submit: every question ends up "gaveup".
        $this->start_attempt($user, 0, time() - 600);
        quiz_attempt::create($this->attempt_id_of($user->id))->process_finish(time() - 60, false);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('finished', $row['statuskey']);
        $this->assertSame(0, $row['answered'], 'gaveup must not count as answered');
        $this->assertSame(0, $row['progresspercent']);
    }

    public function test_only_the_latest_attempt_of_a_user_is_reported(): void {
        $this->setAdminUser();
        $this->create_quiz(['attempts' => 0]);
        $user = $this->create_student();

        // First attempt: everything answered, then submitted.
        $this->start_attempt($user, 4, time() - 3600);
        quiz_attempt::create($this->attempt_id_of($user->id))->process_finish(time() - 3000, false);

        // Second attempt: only one answer so far, still in progress.
        $second = $this->start_attempt($user, 1, time() - 300, 2);
        $this->set_last_activity($second, time() - 5);

        $data = $this->snapshot();
        $rows = array_filter($data['rows'], static fn($r) => $r['userid'] === (int) $user->id);

        $this->assertCount(1, $rows, 'a user must appear exactly once');
        $row = reset($rows);
        $this->assertSame('active', $row['statuskey'], 'the newer, in-progress attempt must win');
        $this->assertSame(1, $row['answered']);
        $this->assertSame(1, $data['summary']['inprogress']);
        $this->assertSame(0, $data['summary']['finished']);
    }

    public function test_preview_attempts_are_ignored(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $this->start_attempt($user, 3, time() - 300, 1, true);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('notstarted', $row['statuskey'], 'a preview must not look like a real attempt');
        $this->assertSame(0, $row['answered']);
        $this->assertSame(1, $this->snapshot()['summary']['notstarted']);
    }

    public function test_summary_counts_every_status(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();

        $active = $this->create_student('Anna', 'Aktiv');
        $idle = $this->create_student('Bernd', 'Untaetig');
        $finished = $this->create_student('Clara', 'Abgegeben');
        $overrun = $this->create_student('David', 'Ueberzogen');
        $this->create_student('Eva', 'Nichtda');

        $this->set_last_activity($this->start_attempt($active, 3, time() - 300), time() - 5);
        $this->set_last_activity($this->start_attempt($idle, 1, time() - 900), time() - 600);
        $this->start_attempt($finished, 4, time() - 1200);
        quiz_attempt::create($this->attempt_id_of($finished->id))->process_finish(time() - 120, false);
        $this->set_last_activity($this->start_attempt($overrun, 2, time() - 3600), time() - 1500);

        $data = $this->snapshot();

        $this->assertSame([
            'total' => 5,
            'active' => 1,
            'inprogress' => 3,
            'finished' => 1,
            'overrun' => 1,
            'notstarted' => 1,
        ], $data['summary']);
        $this->assertTrue($data['hasrows']);
        $this->assertSame((int) $this->cm->id, $data['cmid']);
        $this->assertCount(5, $data['rows']);
        $this->assertSame('overdue', $this->row_for($data, $overrun->id)['statuskey']);
        $this->assertSame(0, (int) $DB->count_records(
            'quiz_attempts',
            ['quiz' => $this->quiz->id, 'preview' => 1]
        ));
    }

    public function test_rows_are_sorted_by_displayed_name(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $this->create_student('Zoe', 'Zuletzt');
        $this->create_student('Anna', 'Anfang');
        $this->create_student('Mia', 'Mitte');

        $names = array_column($this->snapshot()['rows'], 'fullname');

        $sorted = $names;
        usort($sorted, 'strcasecmp');
        $this->assertSame($sorted, $names);
        $this->assertSame('Anna Anfang', $names[0]);
    }

    public function test_quiz_without_time_limit_reports_no_remaining_time(): void {
        $this->setAdminUser();
        $this->create_quiz(['timelimit' => 0, 'timeclose' => 0]);
        $user = $this->create_student();
        $this->set_last_activity($this->start_attempt($user, 1, time() - 300), time() - 5);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('active', $row['statuskey']);
        $this->assertFalse($row['hastimelimit']);
        $this->assertSame('', $row['timeleftlabel']);
        $this->assertSame(0, $row['timeleftseconds']);
        $this->assertFalse($row['isoverrun']);
    }

    public function test_an_earlier_close_date_wins_over_the_time_limit(): void {
        $this->setAdminUser();
        // 30 minute limit, but the quiz closes in 5 minutes.
        $closein = time() + 300;
        $this->create_quiz(['timeclose' => $closein]);
        $user = $this->create_student();
        $this->set_last_activity($this->start_attempt($user, 1, time() - 60), time() - 5);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertTrue($row['hastimelimit']);
        // Remaining time must follow timeclose (~300s), not the 1800s limit.
        $this->assertLessThanOrEqual(300, $row['timeleftseconds']);
        $this->assertGreaterThan(240, $row['timeleftseconds']);
        $this->assertFalse($row['isoverrun']);
    }

    public function test_teachers_are_not_listed_as_participants(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $student = $this->create_student('Sara', 'Studentin');
        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Tanja', 'lastname' => 'Dozentin']);
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');

        $data = $this->snapshot();

        $userids = array_column($data['rows'], 'userid');
        $this->assertContains((int) $student->id, $userids);
        $this->assertNotContains(
            (int) $teacher->id,
            $userids,
            'the report lists people who can attempt the quiz, not those supervising it'
        );
        $this->assertSame(1, $data['summary']['total']);
    }

    public function test_quiz_without_participants_reports_no_rows(): void {
        $this->setAdminUser();
        $this->create_quiz();

        $data = $this->snapshot();

        $this->assertFalse($data['hasrows']);
        $this->assertSame([], $data['rows']);
        $this->assertSame(0, $data['summary']['total']);
    }

    public function test_answered_count_never_exceeds_the_slot_count(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $this->set_last_activity($this->start_attempt($user, 4, time() - 300), time() - 5);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame(4, $row['answered']);
        $this->assertSame(4, $row['total']);
        $this->assertSame(100, $row['progresspercent']);
        $this->assertSame('4 / 4', $row['progresslabel']);
    }

    public function test_every_row_carries_a_status_label_and_badge(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $this->set_last_activity($this->start_attempt($user, 1, time() - 300), time() - 5);

        foreach ($this->snapshot()['rows'] as $row) {
            $this->assertNotEmpty($row['statuslabel']);
            $this->assertStringNotContainsString(
                '[[',
                $row['statuslabel'],
                'a missing language string would surface as [[...]]'
            );
            $this->assertStringContainsString('badge', $row['statusbadgeclass']);
        }
    }

    public function test_snapshot_writes_nothing_to_the_database(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $this->set_last_activity($this->start_attempt($user, 2, time() - 300), time() - 5);

        $before = [
            'attempts' => $DB->get_records('quiz_attempts', [], 'id'),
            'steps' => $DB->count_records('question_attempt_steps'),
            'questionattempts' => $DB->count_records('question_attempts'),
        ];

        $this->snapshot();

        $this->assertEquals($before['attempts'], $DB->get_records('quiz_attempts', [], 'id'));
        $this->assertSame($before['steps'], $DB->count_records('question_attempt_steps'));
        $this->assertSame($before['questionattempts'], $DB->count_records('question_attempts'));
    }
    /**
     * The snapshot must cost a fixed number of queries, whatever the cohort size.
     *
     * Every participant is resolved from the same three reads plus one aggregate
     * count of answered questions, so a per-user query slipping into build_row()
     * would show up here long before it hurt a real exam sitting.
     */
    public function test_query_count_does_not_grow_with_participants(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();

        for ($i = 0; $i < 3; $i++) {
            $this->start_attempt($this->create_student('Small' . $i, 'Cohort'), 1, time() - 300);
        }

        // Warm the language string and context caches so their one-off reads do
        // not land inside a measured window.
        $this->snapshot();

        $before = $DB->perf_get_queries();
        $this->snapshot();
        $smallcohort = $DB->perf_get_queries() - $before;

        for ($i = 0; $i < 20; $i++) {
            $this->start_attempt($this->create_student('Large' . $i, 'Cohort'), 1, time() - 300);
        }

        $before = $DB->perf_get_queries();
        $data = $this->snapshot();
        $largecohort = $DB->perf_get_queries() - $before;

        $this->assertCount(23, $data['rows']);
        $this->assertSame(
            $smallcohort,
            $largecohort,
            "the snapshot took {$smallcohort} queries for 3 participants but {$largecohort} for 23; "
            . 'something now queries per participant'
        );
    }

    /**
     * Known gap, pinned deliberately so a change to it is a conscious decision.
     *
     * An abandoned attempt gets its own row and badge, but increments no summary
     * counter except the participant total, so the tiles do not account for
     * everyone. Abandoned attempts are rare during a supervised exam (the
     * cron-driven overdue handling produces them), which is why this is recorded
     * rather than papered over: giving them a tile, or folding them into a
     * "not submitted" bucket, is a product decision.
     */
    public function test_abandoned_attempt_is_absent_from_every_summary_tile(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 1, time() - 300);
        $DB->set_field('quiz_attempts', 'state', 'abandoned', ['id' => $attempt->id]);

        $data = $this->snapshot();

        $this->assertSame('abandoned', $this->row_for($data, $user->id)['statuskey']);
        $this->assertSame([
            'total' => 1,
            'active' => 0,
            'inprogress' => 0,
            'finished' => 0,
            'overrun' => 0,
            'notstarted' => 0,
        ], $data['summary'], 'if this changes, the summary tiles gained or lost a bucket');
    }
}
