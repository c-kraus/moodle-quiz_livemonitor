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
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\quiz_livemonitor\local\progress_provider::class)]
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
        $this->finish_attempt((int) $user->id, time() - 120);

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
        $this->finish_attempt((int) $user->id, time() - 60);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('finished', $row['statuskey']);
        $this->assertSame(0, $row['answered'], 'gaveup must not count as answered');
        $this->assertSame(0, $row['progresspercent']);
    }

    /**
     * A handed-in but not yet graded attempt must not look like someone still writing.
     *
     * Moodle 5.0 added the intermediate 'submitted' state between 'inprogress'
     * and 'finished'. For an invigilator the distinction that matters is handed
     * in versus still working, so 'submitted' has to read as submitted -- and
     * must never fall through to active/idle, which would tell the supervisor
     * that a student who has handed in is still answering questions.
     */
    public function test_submitted_but_ungraded_attempt_counts_as_submitted(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 4, time() - 1200);
        $this->mark_submitted_awaiting_grading($attempt, time() - 120);

        $data = $this->snapshot();
        $row = $this->row_for($data, $user->id);

        $this->assertSame(
            'finished',
            $row['statuskey'],
            'a submitted attempt must not be reported as still in progress'
        );
        $this->assertFalse($row['isactive']);
        $this->assertFalse($row['isoverrun']);
        $this->assertFalse($row['hastimelimit'], 'a handed-in attempt has no remaining time');
        $this->assertSame('', $row['timeleftlabel']);
        $this->assertSame(1, $data['summary']['finished']);
        $this->assertSame(0, $data['summary']['inprogress']);
        $this->assertSame(0, $data['summary']['active']);
    }

    public function test_only_the_latest_attempt_of_a_user_is_reported(): void {
        $this->setAdminUser();
        $this->create_quiz(['attempts' => 0]);
        $user = $this->create_student();

        // First attempt: everything answered, then submitted.
        $this->start_attempt($user, 4, time() - 3600);
        $this->finish_attempt((int) $user->id, time() - 3000);

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
        $this->finish_attempt((int) $finished->id, time() - 120);
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

    /**
     * Separate groups: an invigilator must not see who else is sitting the exam.
     *
     * With separate groups a teacher without moodle/site:accessallgroups is confined to
     * their own group. Showing the whole cohort would disclose the names and the exam
     * progress of students they are not supervising.
     */
    public function test_separate_groups_confine_the_snapshot_to_the_viewers_group(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();

        $mine = $this->create_student('Meine', 'Gruppe');
        $theirs = $this->create_student('Fremde', 'Gruppe');
        $teacher = $this->create_teacher('teacher');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $mine->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $theirs->id]);

        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);

        $this->start_attempt($mine, 2, time() - 300);
        $this->start_attempt($theirs, 3, time() - 300);

        $this->setUser($teacher);
        $data = $this->snapshot();

        $userids = array_column($data['rows'], 'userid');
        $this->assertContains((int) $mine->id, $userids);
        $this->assertNotContains(
            (int) $theirs->id,
            $userids,
            'a participant from another group must not appear in the snapshot'
        );
        $this->assertSame(1, $data['summary']['total']);
    }

    /**
     * The same restriction must not fire for someone allowed to see every group.
     */
    public function test_accessallgroups_still_sees_every_group(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();

        $one = $this->create_student('Gruppe', 'Eins');
        $two = $this->create_student('Gruppe', 'Zwei');
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $one->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $two->id]);

        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);

        // An editing teacher has moodle/site:accessallgroups by default.
        $this->setUser($this->create_teacher('editingteacher'));
        $data = $this->snapshot();

        $this->assertSame(2, $data['summary']['total']);
    }

    /**
     * Visible groups are a display grouping, not an access restriction.
     */
    public function test_visible_groups_do_not_restrict_the_snapshot(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();

        $one = $this->create_student('Sichtbar', 'Eins');
        $two = $this->create_student('Sichtbar', 'Zwei');
        $teacher = $this->create_teacher('teacher');
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $one->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $two->id]);

        $DB->set_field('course_modules', 'groupmode', VISIBLEGROUPS, ['id' => $this->cm->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);

        $this->setUser($teacher);

        $this->assertSame(2, $this->snapshot()['summary']['total']);
    }

    /**
     * A quiz that shows every question on one page must still report progress.
     *
     * Nothing is submitted until the very end there, so the only server-side trace is the
     * browser's autosave. Those steps carry a negative sequencenumber, which any
     * MAX(sequencenumber) lookup silently skips.
     */
    public function test_an_autosaved_answer_counts_as_answered(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 0, time() - 300);

        $this->autosave_answers($attempt, [1, 2], time() - 10);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame(2, $row['answered'], 'autosaved answers must count towards progress');
        $this->assertSame(50, $row['progresspercent']);
        $this->assertSame('2 / 4', $row['progresslabel']);
    }

    /**
     * The core defect the invigilators reported: someone typing looks idle.
     *
     * quiz_attempt::process_auto_save() writes no quiz_attempts row at all, so an attempt whose
     * student has been typing for a quarter of an hour still carries the timemodified of the
     * page they last submitted -- the very first one.
     */
    public function test_an_autosave_keeps_an_attempt_active_without_any_submit(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 0, time() - 900);
        $this->set_last_activity($attempt, time() - 900);

        $this->autosave_answers($attempt, [1], time() - 10);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('active', $row['statuskey'], 'a student who is typing is not idle');
        $this->assertTrue($row['isactive']);
        $this->assertLessThanOrEqual(30, $row['lastactivityseconds']);

        // Pin the cause in the test itself: the attempt row genuinely has not moved.
        $this->assertLessThanOrEqual(
            time() - 900,
            (int) $DB->get_field('quiz_attempts', 'timemodified', ['id' => $attempt->id]),
            'the fixture must not have touched quiz_attempts -- that is what makes this a bug'
        );
    }

    /**
     * Of several autosaved steps the most negative one is the live one, as core loads it.
     */
    public function test_the_most_negative_autosave_step_wins(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 0, time() - 300);

        // One real step (0, todo) plus two autosaves. Core keeps the most negative.
        $this->forge_autosave_step($attempt, 1, 'complete', -2, time() - 10);
        $this->forge_autosave_step($attempt, 1, 'todo', -1, time() - 20);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame(1, $row['answered'], 'the -2 step is the live one, not the -1 step');
    }

    /**
     * Stale autosaves must be dropped, live ones must not -- both in the same snapshot.
     *
     * Slot 1 has been submitted for real, so its leftover autosave at -1 is overtaken and must
     * be ignored. Slot 2 has only ever been autosaved, so its step at -1 is the live one. An
     * implementation that keeps MAX(sequencenumber) fails on slot 2; one that blindly prefers
     * any negative step fails on slot 1.
     */
    public function test_a_stale_autosave_step_is_ignored_but_a_valid_one_is_not(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();

        // Slot 1 answered for real -> steps 0 (todo) and 1 (complete).
        $attempt = $this->start_attempt($user, 1, time() - 300);
        // A leftover autosave from a concurrent request, already overtaken.
        $this->forge_autosave_step($attempt, 1, 'todo', -1, time() - 200);
        // Slot 2 only autosaved -> steps 0 (todo) and -1 (complete).
        $this->autosave_answers($attempt, [2], time() - 10);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame(2, $row['answered'], 'slot 1 submitted, slot 2 autosaved -- both count');
    }

    /**
     * Last activity is the newer of the attempt row and the question engine's steps.
     */
    public function test_last_activity_takes_the_newest_of_attempt_and_step_timestamps(): void {
        $this->setAdminUser();
        $this->create_quiz();

        // (a) attempt row newer than the steps.
        $recent = $this->create_student('Anna', 'Frisch');
        $attempta = $this->start_attempt($recent, 1, time() - 900);
        $this->set_last_activity($attempta, time() - 900);
        $this->clamp_step_timestamps($attempta, time() - 900);
        $GLOBALS['DB']->set_field('quiz_attempts', 'timemodified', time() - 5, ['id' => $attempta->id]);

        // (b) steps newer than the attempt row -- the autosave case.
        $typing = $this->create_student('Bernd', 'Tippt');
        $attemptb = $this->start_attempt($typing, 0, time() - 900);
        $this->set_last_activity($attemptb, time() - 900);
        $this->autosave_answers($attemptb, [1], time() - 5);

        $data = $this->snapshot();

        $this->assertLessThanOrEqual(30, $this->row_for($data, $recent->id)['lastactivityseconds']);
        $this->assertLessThanOrEqual(30, $this->row_for($data, $typing->id)['lastactivityseconds']);
    }

    /**
     * A multi-response question that has been autosaved counts too.
     */
    public function test_an_autosaved_multi_response_answer_counts_as_answered(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 0, time() - 300);

        // Slot 3 is the two-of-four question.
        $this->autosave_answers($attempt, [3], time() - 10);

        $this->assertSame(1, $this->row_for($this->snapshot(), $user->id)['answered']);
    }

    /**
     * A half-finished answer counts as begun, deliberately.
     *
     * deferredfeedback marks a gradable but incomplete response 'invalid'. For a progress bar
     * during an exam that is "started", not "nothing there".
     */
    public function test_an_invalid_autosaved_answer_counts_as_begun(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 0, time() - 300);

        $this->forge_autosave_step($attempt, 1, 'invalid', -1, time() - 10);

        $this->assertSame(1, $this->row_for($this->snapshot(), $user->id)['answered']);
    }

    /**
     * The report deliberately disagrees with core reports while an attempt is running.
     *
     * Every core quiz report resolves the current state through
     * question_engine_data_mapper::latest_step_for_qa_subquery(), which is a plain
     * MAX(sequencenumber) and therefore cannot see an autosave. That is structural, not an
     * oversight. This report looks at unsubmitted work on purpose, because that is the only
     * thing worth watching during an exam. Once an attempt is submitted the autosaved step is
     * discarded or promoted, and both views agree again.
     */
    public function test_the_report_deliberately_diverges_from_core_reports(): void {
        global $DB;
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 0, time() - 300);
        $this->autosave_answers($attempt, [1, 2], time() - 10);

        // What core's MAX(sequencenumber) approach would report.
        $corecount = $DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {question_attempts} qa
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                 WHERE qa.questionusageid = ?
                   AND qas.sequencenumber = (
                           SELECT MAX(qas2.sequencenumber)
                             FROM {question_attempt_steps} qas2
                            WHERE qas2.questionattemptid = qa.id)
                   AND qas.state <> 'todo'", [$attempt->uniqueid]);

        $this->assertSame(0, $corecount, 'core reports see nothing before a submit');
        $this->assertSame(
            2,
            $this->row_for($this->snapshot(), $user->id)['answered'],
            'this report sees the autosaved work -- that is the whole point'
        );
    }

    /**
     * Guard (green before the fix too): leftovers on a finished attempt change nothing.
     */
    public function test_a_finished_attempt_ignores_leftover_autosave_steps(): void {
        $this->setAdminUser();
        $this->create_quiz();
        $user = $this->create_student();
        $attempt = $this->start_attempt($user, 4, time() - 1200);
        $this->finish_attempt((int) $user->id, time() - 120);
        $this->forge_autosave_step($attempt, 1, 'todo', -1, time() - 5);

        $row = $this->row_for($this->snapshot(), $user->id);

        $this->assertSame('finished', $row['statuskey']);
        $this->assertSame(4, $row['answered']);
        $this->assertFalse($row['isactive'], 'a submitted attempt cannot be active');
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
        $attempt = $this->start_attempt($user, 2, time() - 300);
        $this->set_last_activity($attempt, time() - 5);
        // Include an autosaved step: the derived table must stay read-only over those too.
        $this->autosave_answers($attempt, [3], time() - 5);

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
            $attempt = $this->start_attempt($this->create_student('Small' . $i, 'Cohort'), 1, time() - 300);
            // Mixed states, so the derived table is exercised on both branches.
            $this->autosave_answers($attempt, [2], time() - 20);
        }

        // Warm the language string and context caches so their one-off reads do
        // not land inside a measured window.
        $this->snapshot();

        $before = $DB->perf_get_queries();
        $this->snapshot();
        $smallcohort = $DB->perf_get_queries() - $before;

        for ($i = 0; $i < 20; $i++) {
            $attempt = $this->start_attempt($this->create_student('Large' . $i, 'Cohort'), 1, time() - 300);
            $this->autosave_answers($attempt, [2], time() - 20);
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
        // A snapshot costs seven queries: the group mode, the group itself when separate
        // groups apply, two for the enrolled users, the slot count, the attempts, and one
        // aggregate for progress and activity. Growing that is a decision worth making on
        // purpose, not by accident.
        $this->assertLessThanOrEqual(
            7,
            $largecohort,
            "a snapshot now costs {$largecohort} queries; the documented figure is 7"
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
