# Live monitor (quiz_livemonitor)

A Moodle **quiz report** subplugin that gives the teacher a compact, self-refreshing
overview while students work on a **Quiz** (Test) activity — designed for supervising
online exams taken in the **Safe Exam Browser (SEB)**.

It appears in the quiz's **Results** navigation, next to *Grades*, *Responses* and
*Statistics*, and shows — per participant, refreshing every few seconds without a page
reload:

- **Status**: active / idle / submitted / time overrun / not started
- **Progress**: *X of N questions answered* (+ progress bar)
- **Last activity**: e.g. "8 secs ago"
- **Elapsed** working time and **remaining** time

Plus summary tiles (active now / in progress / submitted / time overrun / not started / total).

## What it is *not*

- It shows **no answer content** — only the working/presence status (a supervision view).
- "Active" means **server-side activity within the last few seconds**. SEB locks the
  student's device but does **not** report window focus or keystrokes to Moodle, so genuine
  client-side "is the exam window focused" telemetry is not available.

## Requirements / compatibility

- Primary target: **Moodle 4.4+ and 5.x**.
- The quiz report base class was namespaced in Moodle 4.2; `report.php` detects and aliases
  whichever base class exists, so it also runs on **4.1**.
- One caveat for **Moodle 4.1**: `classes/external/get_progress.php` uses the `core_external\*`
  namespaced base classes (introduced in 4.2). On 4.1 replace them with the legacy global
  classes (`external_api`, `external_function_parameters`, `external_single_structure`,
  `external_multiple_structure`, `external_value`) and remove the `use core_external\...;`
  lines. Everything else is unchanged.

## Installation (local development)

This plugin is installed at `mod/quiz/report/livemonitor/` inside a Moodle codebase.
The repository root **is** the plugin (files like `version.php` live at the top level).

Clone this repository straight to its final location inside the Moodle tree:

```bash
git clone https://github.com/c-kraus/moodle-quiz_livemonitor.git \
    /path/to/moodle/mod/quiz/report/livemonitor
```

Then visit **Site administration → Notifications** to complete installation.

Do **not** symlink the repository in from outside the Moodle directory when using
[`moodle-docker`](https://github.com/moodlehq/moodle-docker): only the Moodle directory is
bind-mounted into the container, so such a symlink does not resolve there and the plugin
stays invisible to Moodle.

[`TESTING.md`](TESTING.md) is a verified end-to-end walkthrough — container setup, a seed
script that creates participants in every status the report shows, and a test checklist.

Regenerate the AMD build after editing `amd/src/monitor.js`:

```bash
npx grunt amd --root=mod/quiz/report/livemonitor
```

(The committed `amd/build/monitor.min.js` is a hand-authored equivalent so the plugin also
works before the first Grunt build.)

## Central / RZ-managed Moodle

On a centrally administered university Moodle you cannot self-install plugins. Develop and
test locally, then hand this repository (or a packaged ZIP) to the computing centre for
review and deployment. The plugin is deliberately review-friendly: a standard subplugin,
**read-only** (writes nothing to the database), no core modifications, capability-gated, and
a Privacy API **null provider** (it stores no personal data of its own).

## Capabilities

- `quiz/livemonitor:view` — granted to `teacher`, `editingteacher`, `manager` by default;
  clones from `mod/quiz:viewreports`.

## Settings

Site administration → Plugins → Activity modules → Quiz → Live monitor:

- **Auto-refresh interval** (seconds, default 20) — how often the teacher's browser polls.
- **Active window** (seconds, default 60) — how recent activity must be to count as "active".

## Data source

All data is derived from tables `mod_quiz` already populates (`quiz_attempts`, `quiz_slots`,
`question_attempts`, `question_attempt_steps`). "Answered" is approximated as: the latest
step of a question attempt is in a state other than `todo` (never touched) or `gaveup` (left
blank on a submitted attempt).

Verified on Moodle 4.5 against single-answer and two-of-four multi-response questions,
including that a blank submission counts as 0 answered rather than as complete. The proxy is
still worth re-checking against your own question types, especially cloze and other
multi-part questions, on a realistic quiz.

## License

GNU GPL v3 or later.
