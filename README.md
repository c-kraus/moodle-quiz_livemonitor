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

- **Requires Moodle 4.2 or later** (`$plugin->requires = 2023042400`). Primary target
  **4.4+ / 5.x**.
- Verified end to end on **Moodle 4.5.12+ (LTS)** and **Moodle 5.2.1+**, with PHP 8.3,
  against both **PostgreSQL 16** and **MariaDB 10.11**: 41 PHPUnit tests pass on every
  combination, and the report was checked in the browser on each. See
  [`TESTING.md`](TESTING.md).
- **From Moodle 5.1 the plugin belongs under `public/`**: 5.1 moved the servable code there,
  so the path is `public/mod/quiz/report/livemonitor`. Up to and including **5.0** it is
  `mod/quiz/report/livemonitor`, as on 4.x. (`MOODLE_501_STABLE` has no top-level
  `version.php`, `MOODLE_500_STABLE` has no `public/version.php` — that is where the split is.)
- Moodle 5.0 added the attempt state **`submitted`** (handed in, automatic grading not yet
  run) between `inprogress` and `finished`. The report treats it as submitted; see
  *Attempt states* below.
- The PHP itself is portable across **7.4 – 8.4** (`phpcs --standard=PHPCompatibility
  --runtime-set testVersion 7.4-` reports nothing), so the Moodle version is the binding
  constraint, not the PHP version.
- **4.1 is not supported**, and `$plugin->requires` blocks installation there rather than
  letting it fail during an exam. Two 4.2 changes are relied on directly: the quiz report base
  class `mod_quiz\local\reports\report_base`, which `report.php` extends, and the
  `core_external\*` classes, which `classes/external/get_progress.php` extends. Neither exists
  on 4.1, so the report class and the polling web service both fail to load. Supporting 4.1
  would mean reinstating a base-class shim and rewriting the web service against the legacy
  global `external_*` classes.

## Installation

The repository root **is** the plugin — `version.php` and friends live at the top level — and
its place in a Moodle codebase is:

| Moodle | Path |
|--------|------|
| 4.2 – 5.0 | `mod/quiz/report/livemonitor/` |
| 5.1 and later | `public/mod/quiz/report/livemonitor/` |

### From a ZIP (how a computing centre will usually do it)

**Site administration → Plugins → Install plugins**, upload the ZIP, and pick the plugin type
**Quiz → Report** — internally `quiz`, since `mod/quiz/db/subplugins.json` maps the type name
`quiz` to `mod/quiz/report`. Do not pick `quizaccess`, and note the type is not called
"quizreport": Moodle's ZIP validator derives the expected type from the component name
`quiz_livemonitor` and rejects the upload if the selected type disagrees.

Moodle then unpacks the archive to the right place and runs the upgrade. The ZIP contains a
single top-level `livemonitor/` directory, which is what the installer expects.

To build one from a checkout:

```bash
git archive --format=zip --prefix=livemonitor/ -o moodle-quiz_livemonitor.zip HEAD
```

The result passes Moodle's own `core\update\validator` — the check the upload form applies —
with no errors, reporting `componentmatch quiz_livemonitor`. Its two warnings are expected:
`maturity MATURITY_ALPHA`, and `targetexists` if the plugin is already present.

### From git (development)

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

Points a reviewer usually asks about:

- **No database tables of its own.** `db/` contains only `access.php` (one capability) and
  `services.php` (one read-only web service). There is no `install.xml` and no `upgrade.php`.
- **The web service is declared `'type' => 'read'`** and gated on `quiz/livemonitor:view`. It
  returns working status only, never answer content. A test asserts that declaration, and
  another asserts that generating a snapshot writes nothing to any table.
- **`tests/fixtures/seed_testdata.php`** is a development helper that creates a test course
  and users with a known password. It is CLI-only and refuses to run unless developer
  debugging is enabled, so it cannot do anything on a production site. Delete it before
  deployment if that is your policy.
- **Tests:** 41 PHPUnit tests, run with
  `vendor/bin/phpunit --testsuite quiz_livemonitor_testsuite`. See [`TESTING.md`](TESTING.md)
  for what is covered and on which Moodle, PHP and database versions it has been verified.
- **`maturity` is `MATURITY_ALPHA`** deliberately: the plugin has been tested thoroughly but
  has not yet run in a real exam.

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

Verified on Moodle 4.5 and 5.2 against single-answer and two-of-four multi-response
questions, including that a blank submission counts as 0 answered rather than as complete.
The proxy is still worth re-checking against your own question types, especially cloze and
other multi-part questions, on a realistic quiz.

## Attempt states

The report maps `mod_quiz` attempt states onto the statuses a supervisor cares about:

| Attempt state | Shown as | Note |
|---------------|----------|------|
| *no attempt*  | Not started | |
| `inprogress`, recent server activity | Active | within the *Active window* setting |
| `inprogress`, no recent activity | Idle | |
| `inprogress` past its deadline, or `overdue` | Time overrun | |
| `submitted` | Submitted | **Moodle 5.0+**: handed in, grading not yet run |
| `finished` | Submitted | |
| `abandoned` | Abandoned | see the caveat below |

`submitted` matters: Moodle 5.0 split submitting from grading, and grading can be deferred to
the `mod_quiz\task\grade_submission` ad-hoc task — which is also how the 5.0 upgrade grades
pre-existing attempts. An attempt can therefore genuinely sit in `submitted` while an
invigilator is watching, and treating it as anything other than submitted would tell them a
student who has handed in is still answering questions.

Known gap: an `abandoned` attempt gets its own row and badge but is counted in no summary
tile except the participant total, so the tiles do not add up to the number of participants.
Abandoned attempts are rare during a supervised exam. This is pinned by
`test_abandoned_attempt_is_absent_from_every_summary_tile`, so changing it is a deliberate
decision rather than an accident.

## Version

`v0.2.0` (`$plugin->version = 2026072500`), maturity **alpha**. Requires Moodle 4.2 or later.

## License

GNU GPL v3 or later.
