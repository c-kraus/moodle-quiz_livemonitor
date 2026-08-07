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

/**
 * Development helper: seed a quiz with participants in every status the report shows.
 *
 * Creates a course, a quiz with four questions (one of them a two-of-four
 * multi-response question, to exercise the answered-question heuristic against
 * a multi-part question) and seven students, one per case the report distinguishes:
 * active, idle, submitted, time overrun, not started, submitted-awaiting-grading,
 * and one who has only autosaved. Re-running replaces the previous run.
 *
 * NOT part of the plugin's runtime. Development instances only -- it creates
 * users with a known password. See TESTING.md.
 *
 * Usage, from the Moodle root:
 *     php mod/quiz/report/livemonitor/tests/fixtures/seed_testdata.php
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// The question generators pull in the PHPUnit testcase base classes, and the
// plain CLI bootstrap does not load the Composer autoloader. Moodle 5.1 moved the
// servable code into public/, so from then on $CFG->dirroot points there while
// vendor/ stays at the repository root one level up.
$autoloadcandidates = [
    $CFG->dirroot . '/vendor/autoload.php',
    dirname($CFG->dirroot) . '/vendor/autoload.php',
];
$autoloader = null;
foreach ($autoloadcandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoloader = $candidate;
        break;
    }
}
if ($autoloader === null) {
    cli_error("Composer dependencies are missing. Run 'composer install' in the Moodle "
        . 'checkout first -- the test-data generators need the PHPUnit base classes.');
}
require_once($autoloader);
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

if (!debugging('', DEBUG_DEVELOPER)) {
    cli_error('Refusing to run: this seeds test users with a known password. '
        . 'Development instances only (developer debugging must be on).');
}

$password = 'Dev#Local1234';
$now = time();
$timelimit = 1800;

// One source of truth for the participants: first name, last name, status key.
// The cleanup below and the creation loop further down both derive from this, so
// they cannot drift apart. They did once -- a sixth student was added to the
// creation loop but not to a hard-coded deletion list, and the next run died on
// the username unique index, which is exactly the repeatability this script
// promises.
$studentspecs = [
    ['Anna', 'Aktiv', 'active'],
    ['Bernd', 'Untaetig', 'idle'],
    ['Clara', 'Abgegeben', 'finished'],
    ['David', 'Ueberzogen', 'overrun'],
    ['Eva', 'Nichtda', 'notstarted'],
    ['Frank', 'Abgegebenungewertet', 'submitted'],
    ['Greta', 'Tippt', 'autosaved'],
];
$usernames = ['dozentin'];
foreach (array_keys($studentspecs) as $i) {
    $usernames[] = 'stud' . ($i + 1);
}

cli_heading('quiz_livemonitor test data');

// Remove a previous run so the script is repeatable. delete_user() is a soft
// delete that renames the account, which frees the username for reuse.
if ($old = $DB->get_record('course', ['shortname' => 'KLAUSUR1'])) {
    delete_course($old->id, false);
    cli_writeln("Deleted previous course {$old->id}");
}
foreach ($usernames as $username) {
    if ($olduser = $DB->get_record('user', ['username' => $username, 'deleted' => 0])) {
        delete_user($olduser);
    }
}

$generator = new testing_data_generator();
$questiongenerator = $generator->get_plugin_generator('core_question');
$quizgenerator = $generator->get_plugin_generator('mod_quiz');

$course = $generator->create_course([
    'fullname' => 'Klausur-Testkurs',
    'shortname' => 'KLAUSUR1',
    'category' => 1,
]);
cli_writeln("Course: {$course->fullname} (id {$course->id})");

$quiz = $quizgenerator->create_instance([
    'course' => $course->id,
    'name' => 'Probeklausur Live-Monitor',
    'timelimit' => $timelimit,
    'attempts' => 1,
    'grade' => 100,
    'preferredbehaviour' => 'deferredfeedback',
]);
$cm = get_coursemodule_from_instance('quiz', $quiz->id);
cli_writeln("Quiz: {$quiz->name} (cmid {$cm->id}, time limit {$timelimit}s)");

$category = $questiongenerator->create_question_category(
    ['contextid' => context_module::instance($cm->id)->id]
);
$questionspecs = [
    ['multichoice', 'one_of_four'],
    ['multichoice', 'one_of_four'],
    ['multichoice', 'two_of_four'],
    ['multichoice', 'one_of_four'],
];
foreach ($questionspecs as $i => $spec) {
    $question = $questiongenerator->create_question($spec[0], $spec[1], [
        'category' => $category->id,
        'name' => 'Question ' . ($i + 1),
    ]);
    quiz_add_quiz_question($question->id, $quiz, 0, 1);
}

// quiz_add_quiz_question() does not update quiz.sumgrades, and an attempt
// cannot start while the quiz grade and the question grades disagree.
quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
$quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
$slotcount = $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);
cli_writeln("Questions: {$slotcount} slots, sumgrades={$quiz->sumgrades}");

$teacher = $generator->create_user([
    'firstname' => 'Tanja',
    'lastname' => 'Dozentin',
    'username' => 'dozentin',
    'password' => $password,
]);
$generator->enrol_user($teacher->id, $course->id, 'editingteacher');

$students = [];
foreach ($studentspecs as $i => $spec) {
    $user = $generator->create_user([
        'firstname' => $spec[0],
        'lastname' => $spec[1],
        'username' => 'stud' . ($i + 1),
        'password' => $password,
    ]);
    $generator->enrol_user($user->id, $course->id, 'student');
    $students[$spec[2]] = $user;
}
cli_writeln('Users: ' . implode(', ', $usernames) . " -- password {$password}");

/**
 * Start an attempt and answer the first $answercount slots correctly.
 *
 * Writes the raw question-engine field names (q<usageid>:<slot>_<name>) rather
 * than going through the simulated-response API: the qtypes'
 * prepare_simulated_post_data() expects the answer TEXT, not the choice index
 * that get_correct_response() returns, and silently saves nothing on a mismatch.
 *
 * @param stdClass $quiz the quiz record.
 * @param stdClass $user the user to attempt as.
 * @param int $answercount how many slots to answer.
 * @param int $timestart unix time to backdate the attempt start to.
 * @return quiz_attempt the reloaded attempt.
 */
function quiz_livemonitor_seed_attempt($quiz, $user, int $answercount, int $timestart): quiz_attempt {
    global $DB;

    $quizobj = quiz_settings::create($quiz->id, $user->id);
    $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, null, false, [], [], $user->id);
    $DB->set_field('quiz_attempts', 'timestart', $timestart, ['id' => $attempt->id]);

    if ($answercount > 0) {
        $attemptobj = quiz_attempt::create($attempt->id);
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
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
        question_engine::save_questions_usage_by_activity($quba);
        $DB->set_field('quiz_attempts', 'timemodified', $timestart + 60, ['id' => $attempt->id]);
    }

    return quiz_attempt::create($attempt->id);
}

// Active: answering right now.
$attempt = quiz_livemonitor_seed_attempt($quiz, $students['active'], 3, $now - 300);
$DB->set_field('quiz_attempts', 'timemodified', $now - 5, ['id' => $attempt->get_attemptid()]);
cli_writeln('Anna Aktiv       -> inprogress, 3/4, last activity 5s ago    => active');

// Idle: in progress but nothing for ten minutes.
$attempt = quiz_livemonitor_seed_attempt($quiz, $students['idle'], 1, $now - 900);
$DB->set_field('quiz_attempts', 'timemodified', $now - 600, ['id' => $attempt->get_attemptid()]);
cli_writeln('Bernd Untaetig   -> inprogress, 1/4, last activity 10m ago   => idle');

// Submitted.
$attempt = quiz_livemonitor_seed_attempt($quiz, $students['finished'], 4, $now - 1200);
// Moodle 5.0 split process_finish() into process_submit() plus
// process_grade_submission() and deprecated the old method.
if (method_exists($attempt, 'process_submit')) {
    $attempt->process_submit($now - 120, false);
    $attempt->process_grade_submission($now - 120);
} else {
    $attempt->process_finish($now - 120, false);
}
cli_writeln('Clara Abgegeben  -> finished, 4/4                            => submitted');

// Time overrun: started an hour ago against a 30 minute limit.
$attempt = quiz_livemonitor_seed_attempt($quiz, $students['overrun'], 2, $now - 3600);
$DB->set_field('quiz_attempts', 'timemodified', $now - 1500, ['id' => $attempt->get_attemptid()]);
cli_writeln('David Ueberzogen -> inprogress, 2/4, started 60m ago         => time overrun');

cli_writeln('Eva Nichtda      -> no attempt                               => not started');

// Working on a single-page quiz: answers are autosaved but nothing is submitted, so
// quiz_attempts.timemodified never moves. Reproduces the case the invigilators reported.
$attempt = quiz_livemonitor_seed_attempt($quiz, $students['autosaved'], 0, $now - 600);
$quba = question_engine::load_questions_usage_by_activity(
    $DB->get_field('quiz_attempts', 'uniqueid', ['id' => $attempt->get_attemptid()])
);
$uniqueid = $DB->get_field('quiz_attempts', 'uniqueid', ['id' => $attempt->get_attemptid()]);
$postdata = ['slots' => '1,2'];
foreach ([1, 2] as $slot) {
    $qa = $quba->get_question_attempt($slot);
    $prefix = 'q' . $uniqueid . ':' . $slot . '_';
    $postdata[$prefix . ':sequencecheck'] = $qa->get_sequence_check_count();
    foreach ($qa->get_question()->get_correct_response() as $name => $value) {
        $postdata[$prefix . $name] = $value;
    }
}
$quba->process_all_autosaves($now - 5, $postdata);
question_engine::save_questions_usage_by_activity($quba);
// Deliberately not touching quiz_attempts.timemodified -- that is the point.
cli_writeln('Greta Tippt      -> autosaved 2/4, nothing submitted         => active, 2/4');

// Handed in but not yet graded: the state Moodle 5.0 introduced between
// inprogress and finished. Written directly so the fixture also works on 4.x,
// where mod_quiz never produces it but the report must still not misread it.
$attempt = quiz_livemonitor_seed_attempt($quiz, $students['submitted'], 4, $now - 1500);
$DB->update_record('quiz_attempts', (object) [
    'id' => $attempt->get_attemptid(),
    'state' => 'submitted',
    'timefinish' => $now - 240,
    'timemodified' => $now - 240,
]);
cli_writeln('Frank Abgegeben. -> submitted, awaiting grading (Moodle 5.x state) => submitted');

cli_separator();
cli_writeln('Report: ' . (new moodle_url(
    '/mod/quiz/report.php',
    ['id' => $cm->id, 'mode' => 'livemonitor']
))->out(false));
cli_writeln('Note: "active" ages out after the activewindow setting (default 60s). '
    . 'Re-run this script, or bump quiz_attempts.timemodified, to see it again.');
