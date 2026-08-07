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

namespace quiz_livemonitor;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// lib.php defines the QUIZ_GRADE* constants the mod_quiz generator relies on;
// locallib.php provides quiz_add_quiz_question() and the attempt helpers.
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Shared exam fixture for the plugin's tests.
 *
 * Builds a course, a quiz and attempts in controlled states, so the provider
 * tests and the web service tests exercise identical data.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait exam_fixture_trait {
    /** @var int seconds within which activity counts as "active" in these tests. */
    const ACTIVE_WINDOW = 60;

    /** @var int the quiz time limit used by most tests, in seconds. */
    const TIME_LIMIT = 1800;

    /** @var \stdClass the course. */
    protected $course;

    /** @var \stdClass the quiz. */
    protected $quiz;

    /** @var \stdClass the course module. */
    protected $cm;

    /** @var \context_module the module context. */
    protected $context;

    /**
     * Create a course and a four-question quiz.
     *
     * Slot 3 is a two-of-four multi-response question, so the answered-question
     * heuristic is exercised against a multi-part question and not only against
     * single-answer ones.
     *
     * @param array $quizoverrides settings to override on the quiz.
     * @return void
     */
    protected function create_quiz(array $quizoverrides = []): void {
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        $this->quiz = $generator->get_plugin_generator('mod_quiz')->create_instance($quizoverrides + [
            'course' => $this->course->id,
            'timelimit' => self::TIME_LIMIT,
            'grade' => 100,
            'preferredbehaviour' => 'deferredfeedback',
        ]);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);
        $this->context = \context_module::instance($this->cm->id);

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $this->context->id]);
        foreach (['one_of_four', 'one_of_four', 'two_of_four', 'one_of_four'] as $which) {
            $question = $questiongenerator->create_question('multichoice', $which, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $this->quiz, 0, 1);
        }

        // An attempt cannot start while the quiz grade and the question grades disagree.
        quiz_settings::create($this->quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        $this->quiz = $this->get_quiz_record();
    }

    /**
     * Reload the quiz record from the database.
     *
     * @return \stdClass
     */
    protected function get_quiz_record(): \stdClass {
        global $DB;
        return $DB->get_record('quiz', ['id' => $this->quiz->id], '*', MUST_EXIST);
    }

    /**
     * Create a student enrolled on the course.
     *
     * @param string $firstname the first name, so tests can assert on ordering.
     * @param string $lastname the last name.
     * @return \stdClass the user record.
     */
    protected function create_student(string $firstname = 'Test', string $lastname = 'Student'): \stdClass {
        $user = $this->getDataGenerator()->create_user([
            'firstname' => $firstname,
            'lastname' => $lastname,
        ]);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
        return $user;
    }

    /**
     * Create a teacher enrolled on the course.
     *
     * @param string $role the role shortname to enrol with.
     * @return \stdClass the user record.
     */
    protected function create_teacher(string $role = 'editingteacher'): \stdClass {
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Tanja',
            'lastname' => 'Dozentin',
        ]);
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id, $role);
        return $user;
    }

    /**
     * Start an attempt for a user and answer the first $answercount slots correctly.
     *
     * The raw question-engine field names (q<usageid>:<slot>_<name>) are posted
     * rather than using the simulated-response API, whose per-qtype
     * prepare_simulated_post_data() expects the answer text rather than the
     * choice index that get_correct_response() returns, and silently records
     * nothing when they do not match.
     *
     * @param \stdClass $user the user to attempt as.
     * @param int $answercount how many slots to answer.
     * @param int|null $timestart unix time to backdate the attempt start to.
     * @param int $attemptnumber the attempt number.
     * @param bool $preview whether to mark the attempt as a preview.
     * @return \stdClass the attempt record.
     */
    protected function start_attempt(
        \stdClass $user,
        int $answercount = 0,
        ?int $timestart = null,
        int $attemptnumber = 1,
        bool $preview = false
    ): \stdClass {
        global $DB;

        $timestart = $timestart ?? time();

        $quizobj = quiz_settings::create($this->quiz->id, $user->id);
        $lastattempt = $attemptnumber > 1
            ? $DB->get_record(
                'quiz_attempts',
                ['quiz' => $this->quiz->id, 'userid' => $user->id, 'attempt' => $attemptnumber - 1]
            )
            : null;
        $attempt = quiz_prepare_and_start_new_attempt(
            $quizobj,
            $attemptnumber,
            $lastattempt,
            false,
            [],
            [],
            $user->id
        );

        $DB->set_field('quiz_attempts', 'timestart', $timestart, ['id' => $attempt->id]);
        if ($preview) {
            $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $attempt->id]);
        }

        if ($answercount > 0) {
            $attemptobj = quiz_attempt::create($attempt->id);
            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
            $slots = $attemptobj->get_slots();
            $postdata = ['slots' => implode(',', $slots)];
            $done = 0;
            foreach ($slots as $slot) {
                if ($done >= $answercount) {
                    break;
                }
                $questionattempt = $quba->get_question_attempt($slot);
                $prefix = 'q' . $attempt->uniqueid . ':' . $slot . '_';
                $postdata[$prefix . ':sequencecheck'] = $questionattempt->get_sequence_check_count();
                foreach ($questionattempt->get_question()->get_correct_response() as $name => $value) {
                    $postdata[$prefix . $name] = $value;
                }
                $done++;
            }
            $quba->process_all_actions($timestart + 60, $postdata);
            \question_engine::save_questions_usage_by_activity($quba);
            $DB->set_field('quiz_attempts', 'timemodified', $timestart + 60, ['id' => $attempt->id]);
        }

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);

        // Step 0 of every question carries the real clock, not $timestart. Backdate it too, or
        // an attempt started "an hour ago" still looks like it was touched a moment ago.
        $this->clamp_step_timestamps($attempt, (int) $attempt->timemodified);

        return $attempt;
    }

    /**
     * Force an attempt's last-activity time.
     *
     * Backdating quiz_attempts alone is not enough: the provider also reads the question
     * engine's step timestamps, and quiz_prepare_and_start_new_attempt() stamps step 0 of
     * every question with the real clock. An attempt backdated by fifteen minutes would
     * otherwise still carry steps created "just now" and read as active.
     *
     * The step timestamps are clamped rather than overwritten, so a deliberately placed
     * autosave timestamp survives a later call.
     *
     * @param \stdClass $attempt the attempt record.
     * @param int $timemodified unix time to set.
     * @return void
     */
    protected function set_last_activity(\stdClass $attempt, int $timemodified): void {
        global $DB;
        $DB->set_field('quiz_attempts', 'timemodified', $timemodified, ['id' => $attempt->id]);
        $this->clamp_step_timestamps($attempt, $timemodified);
    }

    /**
     * Push every step timestamp of an attempt back to at most $timestamp.
     *
     * @param \stdClass $attempt the attempt record.
     * @param int $timestamp the newest step timestamp to allow.
     * @return void
     */
    protected function clamp_step_timestamps(\stdClass $attempt, int $timestamp): void {
        global $DB;
        $qaids = $DB->get_fieldset_select(
            'question_attempts',
            'id',
            'questionusageid = ?',
            [$attempt->uniqueid]
        );
        if (empty($qaids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($qaids, SQL_PARAMS_NAMED, 'qa');
        $DB->set_field_select(
            'question_attempt_steps',
            'timecreated',
            $timestamp,
            "timecreated > :newest AND questionattemptid {$insql}",
            $params + ['newest' => $timestamp]
        );
    }

    /**
     * Autosave answers to the given slots without submitting anything.
     *
     * This reproduces what a student does on a quiz that shows every question on one page:
     * they type, the browser autosaves in the background, and no page is ever submitted until
     * the very end. quiz_attempt::process_auto_save() writes no quiz_attempts row at all, so
     * this method deliberately leaves timemodified alone -- that is the whole point.
     *
     * Three traps, all of which fail silently:
     *  - The ':sequencecheck' field is mandatory. Without it,
     *    question_usage_by_activity::is_autosave_required() skips the slot without a word,
     *    and the resulting test failure looks like a provider bug that is not there.
     *  - Only autosave slots that are still unanswered, or post a different response:
     *    question_behaviour_with_save::process_autosave() discards an unchanged answer.
     *  - An autosaved step is stored with a negative sequencenumber, so anything that
     *    selects MAX(sequencenumber) will never see it.
     *
     * @param \stdClass $attempt the attempt record.
     * @param int[] $slots the slot numbers to autosave.
     * @param int $timestamp unix time to record on the autosaved steps.
     * @return void
     */
    protected function autosave_answers(\stdClass $attempt, array $slots, int $timestamp): void {
        global $DB;

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

        $postdata = ['slots' => implode(',', $slots)];
        foreach ($slots as $slot) {
            $questionattempt = $quba->get_question_attempt($slot);
            $prefix = 'q' . $attempt->uniqueid . ':' . $slot . '_';
            $postdata[$prefix . ':sequencecheck'] = $questionattempt->get_sequence_check_count();
            foreach ($questionattempt->get_question()->get_correct_response() as $name => $value) {
                $postdata[$prefix . $name] = $value;
            }
        }

        $quba->process_all_autosaves($timestamp, $postdata);
        \question_engine::save_questions_usage_by_activity($quba);

        // Guard against the silent failures above: if no autosaved step was written, the test
        // that follows would blame the provider for a fixture problem.
        $qaids = $DB->get_fieldset_select(
            'question_attempts',
            'id',
            'questionusageid = ?',
            [$attempt->uniqueid]
        );
        [$insql, $params] = $DB->get_in_or_equal($qaids, SQL_PARAMS_NAMED, 'qa');
        $this->assertGreaterThan(
            0,
            $DB->count_records_select(
                'question_attempt_steps',
                "sequencenumber < 0 AND questionattemptid {$insql}",
                $params
            ),
            'the fixture produced no autosaved step -- check the :sequencecheck field'
        );
    }

    /**
     * Insert an autosaved step directly, bypassing the question engine.
     *
     * Stale autosaves -- ones later overtaken by real steps -- only arise from concurrent
     * requests and cannot be produced through the API, because process_action() calls
     * discard_autosaved_step() first. They have to be forged to be tested.
     *
     * No question_attempt_step_data row is needed: the provider reads sequencenumber, state
     * and timecreated only. The (questionattemptid, sequencenumber) pair must stay unique.
     *
     * @param \stdClass $attempt the attempt record.
     * @param int $slot the slot to attach the step to.
     * @param string $state the question state to record, e.g. 'complete'.
     * @param int $sequencenumber the (negative) sequence number.
     * @param int $timecreated unix time to record.
     * @return int the new step id.
     */
    protected function forge_autosave_step(
        \stdClass $attempt,
        int $slot,
        string $state,
        int $sequencenumber,
        int $timecreated
    ): int {
        global $DB;

        $questionattemptid = $DB->get_field(
            'question_attempts',
            'id',
            ['questionusageid' => $attempt->uniqueid, 'slot' => $slot],
            MUST_EXIST
        );

        return (int) $DB->insert_record('question_attempt_steps', (object) [
            'questionattemptid' => $questionattemptid,
            'sequencenumber' => $sequencenumber,
            'state' => $state,
            'fraction' => null,
            'timecreated' => $timecreated,
            'userid' => $DB->get_field('quiz_attempts', 'userid', ['id' => $attempt->id]),
        ]);
    }

    /**
     * Submit and grade a user's attempt, across Moodle versions.
     *
     * Moodle 5.0 split quiz_attempt::process_finish() into process_submit() plus
     * process_grade_submission() and deprecated the old method, so calling it
     * directly makes the suite emit debugging notices on 5.x.
     *
     * @param int $userid the user whose attempt to finish.
     * @param int $timestamp unix time to record as the finish time.
     * @return void
     */
    protected function finish_attempt(int $userid, int $timestamp): void {
        $attemptobj = quiz_attempt::create($this->attempt_id_of($userid));
        if (method_exists($attemptobj, 'process_submit')) {
            $attemptobj->process_submit($timestamp, false);
            $attemptobj->process_grade_submission($timestamp);
        } else {
            $attemptobj->process_finish($timestamp, false);
        }
    }

    /**
     * Leave a user's attempt submitted but not yet graded.
     *
     * Moodle 5.0 added the intermediate 'submitted' state: process_submit() sets
     * it and stamps timefinish, and grading moves the attempt on to 'finished'
     * afterwards -- possibly in the mod_quiz\task\grade_submission ad-hoc task,
     * which is also how the 5.0 upgrade grades pre-existing attempts. So an
     * attempt can genuinely sit in 'submitted' while a teacher is watching.
     *
     * The state is written directly rather than through the API so the fixture
     * works on 4.x too, where the state does not exist but the report must still
     * not misread it.
     *
     * @param \stdClass $attempt the attempt record.
     * @param int $timefinish unix time the student handed in.
     * @return void
     */
    protected function mark_submitted_awaiting_grading(\stdClass $attempt, int $timefinish): void {
        global $DB;
        $DB->update_record('quiz_attempts', (object) [
            'id' => $attempt->id,
            'state' => 'submitted',
            'timefinish' => $timefinish,
            'timemodified' => $timefinish,
        ]);
    }

    /**
     * Get the single non-preview attempt id of a user.
     *
     * @param int $userid the user id.
     * @return int the attempt id.
     */
    protected function attempt_id_of(int $userid): int {
        global $DB;
        return (int) $DB->get_field(
            'quiz_attempts',
            'id',
            ['quiz' => $this->quiz->id, 'userid' => $userid, 'preview' => 0],
            IGNORE_MULTIPLE
        );
    }

    /**
     * Build the provider snapshot directly, bypassing the web service.
     *
     * @param int|null $activewindow override the active window.
     * @return array
     */
    protected function snapshot(?int $activewindow = null): array {
        return \quiz_livemonitor\local\progress_provider::get_data(
            $this->get_quiz_record(),
            $this->cm,
            $this->context,
            $activewindow ?? self::ACTIVE_WINDOW
        );
    }

    /**
     * Find one row of a snapshot by user id.
     *
     * @param array $data the snapshot.
     * @param int $userid the user id.
     * @return array the row.
     */
    protected function row_for(array $data, int $userid): array {
        foreach ($data['rows'] as $row) {
            if ((int) $row['userid'] === (int) $userid) {
                return $row;
            }
        }
        $this->fail('No row found for user ' . $userid);
    }

    /**
     * Seed one participant per status the report distinguishes.
     *
     * @return array map of status key => user record, plus 'quizcmid'.
     */
    protected function seed_every_status(): array {
        $active = $this->create_student('Anna', 'Aktiv');
        $idle = $this->create_student('Bernd', 'Untaetig');
        $finished = $this->create_student('Clara', 'Abgegeben');
        $overrun = $this->create_student('David', 'Ueberzogen');
        $notstarted = $this->create_student('Eva', 'Nichtda');

        $this->set_last_activity($this->start_attempt($active, 3, time() - 300), time() - 5);
        $this->set_last_activity($this->start_attempt($idle, 1, time() - 900), time() - 600);
        $this->start_attempt($finished, 4, time() - 1200);
        $this->finish_attempt((int) $finished->id, time() - 120);
        $this->set_last_activity($this->start_attempt($overrun, 2, time() - 3600), time() - 1500);

        return [
            'active' => $active,
            'idle' => $idle,
            'finished' => $finished,
            'overrun' => $overrun,
            'notstarted' => $notstarted,
        ];
    }
}
