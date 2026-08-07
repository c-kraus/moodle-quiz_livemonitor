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

/**
 * Builds a read-only progress snapshot of a quiz's attempts.
 *
 * All data is derived from tables that mod_quiz already populates; this class
 * never writes anything. The returned structure is shared by the server-side
 * renderer and the polling web service, so both views are always identical.
 *
 * @package    quiz_livemonitor
 * @copyright  2026 Christian Kraus
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_provider {
    /** @var int sentinel: separate groups apply and the viewer belongs to none of them. */
    protected const NO_GROUP_ACCESS = -1;

    /**
     * Build the full snapshot (summary counters + one row per eligible participant).
     *
     * @param \stdClass $quiz the quiz record.
     * @param \stdClass|\cm_info $cm the course module.
     * @param \context $context the module context.
     * @param int $activewindow seconds within which server activity counts as "active".
     * @return array template- and web-service-ready structure.
     */
    public static function get_data($quiz, $cm, \context $context, int $activewindow): array {
        global $DB;

        $now = time();
        $viewfullnames = has_capability('moodle/site:viewfullnames', $context);

        // Separate groups must be honoured here, not left to the caller: an invigilator
        // restricted to their own group may not see who else is sitting the exam, and both
        // the page and the polling web service reach this method.
        $groupid = self::resolve_group($cm, $context);
        if ($groupid === self::NO_GROUP_ACCESS) {
            return self::empty_snapshot($cm, $now);
        }

        // Eligible participants: enrolled users who can actually attempt the quiz.
        $users = get_enrolled_users($context, 'mod/quiz:attempt', $groupid, 'u.*', null, 0, 0, true);

        // Total number of question slots in the quiz.
        $total = (int) $DB->count_records('quiz_slots', ['quizid' => $quiz->id]);

        // Latest non-preview attempt per user.
        $attemptrecords = $DB->get_records_select(
            'quiz_attempts',
            'quiz = :quizid AND preview = 0',
            ['quizid' => $quiz->id],
            'userid ASC, attempt ASC'
        );
        $latest = [];
        foreach ($attemptrecords as $attempt) {
            // Ordered by attempt ASC, so the last assignment keeps the highest attempt.
            $latest[$attempt->userid] = $attempt;
        }

        // Answered-question counts, resolved for every attempt in a single aggregate query.
        $progressbyusage = self::load_usage_progress(array_map(static function ($a) {
            return $a->uniqueid;
        }, $latest));

        $rows = [];
        $summary = [
            'total'      => 0,
            'active'     => 0,
            'inprogress' => 0,
            'finished'   => 0,
            'overrun'    => 0,
            'notstarted' => 0,
        ];

        foreach ($users as $user) {
            $summary['total']++;
            $attempt = $latest[$user->id] ?? null;
            $row = self::build_row(
                $quiz,
                $user,
                $attempt,
                $total,
                $now,
                $activewindow,
                $progressbyusage,
                $viewfullnames
            );

            if ($row['statuskey'] === 'notstarted') {
                $summary['notstarted']++;
            } else if ($row['statuskey'] === 'finished') {
                $summary['finished']++;
            }
            if (in_array($row['statuskey'], ['active', 'idle', 'overdue'], true)) {
                $summary['inprogress']++;
            }
            if ($row['isactive']) {
                $summary['active']++;
            }
            if ($row['isoverrun']) {
                $summary['overrun']++;
            }

            $rows[] = $row;
        }

        // Sort by displayed name for a stable, scannable list.
        usort($rows, static function ($a, $b) {
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        return [
            'cmid'           => (int) $cm->id,
            'generatedat'    => $now,
            'generatedatstr' => userdate($now, get_string('strftimetime', 'langconfig')),
            'hasrows'        => !empty($rows),
            'summary'        => array_map('intval', $summary),
            'rows'           => array_values($rows),
        ];
    }

    /**
     * Work out which group the snapshot may cover.
     *
     * Mirrors what mod_quiz\local\reports\report_base::get_current_group() decides, but is
     * applied here so every entry point is covered by construction.
     *
     * @param \stdClass|\cm_info $cm the course module.
     * @param \context $context the module context.
     * @return int 0 for "all participants", a group id to restrict to, or NO_GROUP_ACCESS.
     */
    protected static function resolve_group($cm, \context $context): int {
        if (groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
            return 0;
        }
        if (has_capability('moodle/site:accessallgroups', $context)) {
            return 0;
        }
        // Restricted to separate groups: only the viewer's own current group is visible.
        $groupid = (int) groups_get_activity_group($cm, true);
        return $groupid > 0 ? $groupid : self::NO_GROUP_ACCESS;
    }

    /**
     * A well-formed snapshot with no participants.
     *
     * Used when separate groups apply and the viewer belongs to no group, so the payload
     * still satisfies the web service's declared structure.
     *
     * @param \stdClass|\cm_info $cm the course module.
     * @param int $now current unix time.
     * @return array
     */
    protected static function empty_snapshot($cm, int $now): array {
        return [
            'cmid'           => (int) $cm->id,
            'generatedat'    => $now,
            'generatedatstr' => userdate($now, get_string('strftimetime', 'langconfig')),
            'hasrows'        => false,
            'summary'        => [
                'total'      => 0,
                'active'     => 0,
                'inprogress' => 0,
                'finished'   => 0,
                'overrun'    => 0,
                'notstarted' => 0,
            ],
            'rows'           => [],
        ];
    }

    /**
     * Per question usage: how many slots are answered, and when the student was last seen.
     *
     * "Answered" means the current step of the question attempt is in a state other than
     * "todo" (untouched) or "gaveup" (left blank on a submitted attempt). Half-finished work
     * ends up in "invalid" and counts as started, which is what a progress bar should show.
     *
     * The subtlety is which step counts as current. On a quiz that puts every question on one
     * page nothing is submitted until the end, and the only trace is the browser's autosave.
     * The engine stores an autosaved step with a *negative* sequencenumber
     * (question/engine/questionattempt.php:978-983), so the MAX(sequencenumber) that every core
     * report uses (question/engine/datalib.php:1261-1267) can never select it.
     *
     * An autosave is live only while real steps have not overtaken it. Core expresses that as
     * `-sequencenumber >= number of real steps` in its load loop
     * (question/engine/questionattempt.php:1676); the arithmetic below is the portable
     * equivalent. That rule appears in no public API -- without this reference the SQL is
     * unreadable a year from now. Both branches are safe: step 0 always exists, so MAX over all
     * rows is always the last real step, and MIN is only negative when an autosave exists.
     *
     * Stays portable across PostgreSQL and MariaDB: no FILTER clause, no GREATEST, no window
     * functions, the derived table is aliased, and GROUP BY names every non-aggregated column
     * for ONLY_FULL_GROUP_BY.
     *
     * Not filtered by user: a teacher's manual grading writes steps too and would push
     * lastactivity forward. Harmless, because "active" additionally requires the attempt to be
     * in progress, and grading only happens on attempts that are not.
     *
     * @param int[] $usageids question usage ids (quiz_attempts.uniqueid).
     * @return array map of usageid => object with answered and lastactivity.
     */
    protected static function load_usage_progress(array $usageids): array {
        global $DB;

        $usageids = array_values(array_filter($usageids));
        if (empty($usageids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($usageids, SQL_PARAMS_NAMED, 'uid');
        [$notinsql, $notinparams] = $DB->get_in_or_equal(['todo', 'gaveup'], SQL_PARAMS_NAMED, 'st', false);
        $params += $notinparams;

        // The inner query picks, per question attempt, the step that is actually current --
        // which is the autosaved one when a live autosave exists, and the last submitted step
        // otherwise -- and the newest timestamp of any of its steps.
        //
        // Counting with SUM(CASE ...) rather than filtering in the WHERE clause is deliberate:
        // a WHERE filter would drop question attempts with nothing answered out of the result
        // entirely, and with them their timestamps. Those rows are exactly the ones this fix is
        // about -- a student who has started typing but submitted nothing.
        //
        // MAX(timecreated) covers every step, stale autosaves included. Each one was written by
        // a real request from the student, so it is a truthful lower bound on "last seen"; only
        // the answer count needs the staleness rule.
        $sql = "SELECT eff.usageid AS usageid,
                       SUM(CASE WHEN cur.state $notinsql THEN 1 ELSE 0 END) AS answered,
                       MAX(eff.lastactivity) AS lastactivity
                  FROM (
                        SELECT qa.id AS questionattemptid,
                               qa.questionusageid AS usageid,
                               MAX(qas.timecreated) AS lastactivity,
                               CASE WHEN MIN(qas.sequencenumber)
                                         + SUM(CASE WHEN qas.sequencenumber >= 0 THEN 1 ELSE 0 END) <= 0
                                    THEN MIN(qas.sequencenumber)
                                    ELSE MAX(qas.sequencenumber)
                               END AS effectiveseq
                          FROM {question_attempts} qa
                          JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                         WHERE qa.questionusageid $insql
                      GROUP BY qa.id, qa.questionusageid
                       ) eff
                  JOIN {question_attempt_steps} cur
                    ON cur.questionattemptid = eff.questionattemptid
                   AND cur.sequencenumber = eff.effectiveseq
              GROUP BY eff.usageid";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Build a single participant row.
     *
     * @param \stdClass $quiz the quiz record.
     * @param \stdClass $user the user record.
     * @param \stdClass|null $attempt the latest attempt, or null if none.
     * @param int $total total number of question slots.
     * @param int $now current unix time.
     * @param int $activewindow "active" threshold in seconds.
     * @param array $progressbyusage map of usageid => object with answered and lastactivity.
     * @param bool $viewfullnames whether full names may be shown.
     * @return array a single template-ready row.
     */
    protected static function build_row(
        $quiz,
        $user,
        $attempt,
        int $total,
        int $now,
        int $activewindow,
        array $progressbyusage,
        bool $viewfullnames
    ): array {

        $fullname = fullname($user, $viewfullnames);

        if ($attempt === null) {
            return self::decorate_status([
                'userid'              => (int) $user->id,
                'fullname'            => $fullname,
                'statuskey'           => 'notstarted',
                'answered'            => 0,
                'total'               => $total,
                'progresspercent'     => 0,
                'progresslabel'       => '0 / ' . $total,
                'lastactivitylabel'   => '—',
                'lastactivityseconds' => -1,
                'elapsedlabel'        => '—',
                'hastimelimit'        => false,
                'timeleftlabel'       => '',
                'timeleftseconds'     => 0,
                'isoverrun'           => false,
                'isactive'            => false,
            ]);
        }

        $progress = $progressbyusage[$attempt->uniqueid] ?? null;

        // PostgreSQL returns SUM() as a string, hence the explicit casts.
        $answered = min((int) ($progress->answered ?? 0), $total);
        $percent = $total > 0 ? (int) round($answered / $total * 100) : 0;

        // The attempt row only moves when a page is submitted; the question engine's steps also
        // move on autosave. Whichever is newer is when the student was last seen.
        $lastactivity = max((int) $attempt->timemodified, (int) ($progress->lastactivity ?? 0));

        $sincemodified = $now - $lastactivity;
        $state = $attempt->state;
        $isactive = ($state === 'inprogress') && ($sincemodified <= $activewindow);

        // Duration: elapsed working time (finished attempts use their own finish time).
        if (!empty($attempt->timefinish) && (int) $attempt->timefinish > 0) {
            $elapsed = (int) $attempt->timefinish - (int) $attempt->timestart;
        } else {
            $elapsed = $now - (int) $attempt->timestart;
        }

        // Remaining time only matters while an attempt is still open.
        $hastimelimit = false;
        $timeleftseconds = 0;
        if (in_array($state, ['inprogress', 'overdue'], true)) {
            $deadlines = [];
            if (!empty($quiz->timelimit)) {
                $deadlines[] = (int) $attempt->timestart + (int) $quiz->timelimit;
            }
            if (!empty($quiz->timeclose)) {
                $deadlines[] = (int) $quiz->timeclose;
            }
            if (!empty($deadlines)) {
                $hastimelimit = true;
                $timeleftseconds = min($deadlines) - $now;
            }
        }

        $isoverrun = ($state === 'overdue') || ($state === 'inprogress' && $hastimelimit && $timeleftseconds < 0);

        // 'submitted' is the state Moodle 5.0 introduced between 'inprogress' and
        // 'finished': the student has handed in, but automatic grading has not run
        // yet (it may be deferred to the mod_quiz\task\grade_submission ad-hoc
        // task). For supervision the distinction is handed in versus still
        // working, so it must read as submitted -- otherwise it falls through to
        // active/idle and the invigilator is told that someone who has handed in
        // is still answering questions.
        if (in_array($state, ['finished', 'submitted'], true)) {
            $statuskey = 'finished';
        } else if ($state === 'abandoned') {
            $statuskey = 'abandoned';
        } else if ($isoverrun) {
            $statuskey = 'overdue';
        } else {
            $statuskey = $isactive ? 'active' : 'idle';
        }

        if ($hastimelimit) {
            if ($timeleftseconds >= 0) {
                $timeleftlabel = format_time($timeleftseconds);
            } else {
                $timeleftlabel = get_string('overrunby', 'quiz_livemonitor', format_time(abs($timeleftseconds)));
            }
        } else {
            $timeleftlabel = '';
        }

        return self::decorate_status([
            'userid'              => (int) $user->id,
            'fullname'            => $fullname,
            'statuskey'           => $statuskey,
            'answered'            => $answered,
            'total'               => $total,
            'progresspercent'     => $percent,
            'progresslabel'       => $answered . ' / ' . $total,
            'lastactivitylabel'   => get_string('ago', 'quiz_livemonitor', format_time(max(0, $sincemodified))),
            'lastactivityseconds' => max(0, $sincemodified),
            'elapsedlabel'        => format_time(max(0, $elapsed)),
            'hastimelimit'        => $hastimelimit,
            'timeleftlabel'       => $timeleftlabel,
            'timeleftseconds'     => $timeleftseconds,
            'isoverrun'           => $isoverrun,
            'isactive'            => $isactive,
        ]);
    }

    /**
     * Attach the localized status label and badge CSS to a row.
     *
     * Both Bootstrap 4 and Bootstrap 5 badge classes are emitted so the badge
     * renders correctly regardless of the target Moodle's theme version.
     *
     * @param array $row a partially built row containing 'statuskey'.
     * @return array the row with 'statuslabel' and 'statusbadgeclass' added.
     */
    protected static function decorate_status(array $row): array {
        $badges = [
            'active'     => 'badge badge-success bg-success text-white',
            'idle'       => 'badge badge-warning bg-warning text-dark',
            'finished'   => 'badge badge-primary bg-primary text-white',
            'overdue'    => 'badge badge-danger bg-danger text-white',
            'abandoned'  => 'badge badge-dark bg-dark text-white',
            'notstarted' => 'badge badge-secondary bg-secondary text-white',
        ];
        $key = $row['statuskey'];
        $row['statuslabel'] = get_string('status_' . $key, 'quiz_livemonitor');
        $row['statusbadgeclass'] = $badges[$key] ?? $badges['notstarted'];
        return $row;
    }
}
