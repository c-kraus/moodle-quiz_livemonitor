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

        // Eligible participants: enrolled users who can actually attempt the quiz.
        $users = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.*', null, 0, 0, true);

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
        $answeredbyusage = self::count_answered(array_map(static function ($a) {
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
                $answeredbyusage,
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
     * Count, per question usage, how many slots the student has answered.
     *
     * "Answered" is approximated as: the latest step of the question attempt is in a
     * state other than "todo" (untouched) or "gaveup" (left blank on a finished attempt).
     * The latest step is found with a correlated subquery, which uses the
     * (questionattemptid, sequencenumber) index and only touches steps of the relevant
     * usages, rather than aggregating the whole steps table.
     *
     * @param int[] $usageids question usage ids (quiz_attempts.uniqueid).
     * @return array map of usageid => answered count.
     */
    protected static function count_answered(array $usageids): array {
        global $DB;

        $usageids = array_values(array_filter($usageids));
        if (empty($usageids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($usageids, SQL_PARAMS_NAMED, 'uid');
        [$notinsql, $notinparams] = $DB->get_in_or_equal(['todo', 'gaveup'], SQL_PARAMS_NAMED, 'st', false);
        $params += $notinparams;

        $sql = "SELECT qa.questionusageid AS usageid, COUNT(1) AS answered
                  FROM {question_attempts} qa
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                 WHERE qa.questionusageid $insql
                   AND qas.sequencenumber = (
                           SELECT MAX(qas2.sequencenumber)
                             FROM {question_attempt_steps} qas2
                            WHERE qas2.questionattemptid = qa.id)
                   AND qas.state $notinsql
              GROUP BY qa.questionusageid";

        return $DB->get_records_sql_menu($sql, $params);
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
     * @param array $answeredbyusage map of usageid => answered count.
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
        array $answeredbyusage,
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

        $answered = (int) ($answeredbyusage[$attempt->uniqueid] ?? 0);
        $answered = min($answered, $total);
        $percent = $total > 0 ? (int) round($answered / $total * 100) : 0;

        $sincemodified = $now - (int) $attempt->timemodified;
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

        if ($state === 'finished') {
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
