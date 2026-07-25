# Testing quiz_livemonitor locally

A verified walkthrough for bringing this plugin up in a throwaway Moodle on your
own machine, on macOS with OrbStack providing the Docker engine. Docker Desktop
works identically — `moodle-docker` only needs a Docker engine and
`docker compose`.

## What has actually been verified

| Axis | Result |
|------|--------|
| Moodle 4.5.12+ (LTS) / PHP 8.3 / PostgreSQL 16 | 41 PHPUnit tests pass; report verified in the browser |
| Moodle 4.5.12+ (LTS) / PHP 8.3 / MariaDB 10.11 | 41 PHPUnit tests pass; report verified in the browser with 205 participants |
| Moodle 5.2.1+ / PHP 8.3 / MariaDB 10.11 | 41 PHPUnit tests pass with no deprecations or notices; report verified in the browser |
| PHP syntax range | `phpcs --standard=PHPCompatibility --runtime-set testVersion 7.4-` reports nothing |
| Moodle coding style | `phpcs --standard=moodle` reports zero errors |
| Snapshot cost, 205 participants, MariaDB | 16.6 ms in 5 queries; `count_answered` 1.7 ms; full page 0.23 s |
| Snapshot cost, 200 participants, PostgreSQL | 4–7 ms in 5 queries |

## Two Moodle 5 changes that matter here

**Moodle 5.1 moved the servable code into `public/`.** So the plugin lives at
`public/mod/quiz/report/livemonitor` from 5.1 onwards, and at
`mod/quiz/report/livemonitor` up to and including 5.0. `$CFG->dirroot` points at
`public/`, while `vendor/` and the `admin/cli/` tools stay at the repository root —
which is why `tests/fixtures/seed_testdata.php` looks for the Composer autoloader
in both places. The dividing line is checkable: `MOODLE_501_STABLE` has no
top-level `version.php`, `MOODLE_500_STABLE` has no `public/version.php`.

**Moodle 5.0 added the attempt state `submitted`,** which sits between
`inprogress` and `finished`:
the student has handed in but automatic grading has not run, possibly because it
was deferred to the `mod_quiz\task\grade_submission` ad-hoc task. The report maps
it to Submitted. Before that was handled it fell through to Idle, so a supervisor
would have been told that someone who had handed in was still sitting there —
`test_submitted_but_ungraded_attempt_counts_as_submitted` pins it.

## 1. Prerequisites

- A Docker engine (Docker Desktop or OrbStack) with ~4–8 GB of RAM available
- Git

## 2. Lay out the workspace

```bash
mkdir moodle-livemonitor-dev && cd moodle-livemonitor-dev
git clone --depth 1 https://github.com/moodlehq/moodle-docker.git
git clone --branch MOODLE_405_STABLE --depth 1 https://github.com/moodle/moodle.git
```

**Clone the plugin to its final location inside the Moodle tree, do not symlink
it from outside.** `moodle-docker` bind-mounts only the Moodle directory into the
container, so a symlink pointing anywhere outside that directory does not resolve
inside the container and the plugin is invisible to Moodle:

```bash
# Moodle 4.x. On 5.x the target is moodle/public/mod/quiz/report/livemonitor.
git clone https://github.com/c-kraus/moodle-quiz_livemonitor.git \
    moodle/mod/quiz/report/livemonitor

# Keep the core checkout from reporting the plugin as untracked.
echo "/mod/quiz/report/livemonitor/" >> moodle/.git/info/exclude
```

Pick the branch that matches the target site once you know the RZ's version
(`MOODLE_500_STABLE` for Moodle 5.0, and so on).

## 3. Configure and start

`moodle-docker` defaults to port 8000. Choose another one if something already
listens there — OrbStack itself binds 8000, which is why this guide uses 8040.

```bash
cat > env.sh <<'EOF'
LM_WORKSPACE="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
export MOODLE_DOCKER_WWWROOT="$LM_WORKSPACE/moodle"
export MOODLE_DOCKER_DB="${1:-pgsql}"
export MOODLE_DOCKER_WEB_PORT=8040
export MOODLE_DOCKER_PHP_VERSION=8.3
EOF

cd moodle-docker
source ../env.sh            # or: source ../env.sh mariadb
cp config.docker-template.php "$MOODLE_DOCKER_WWWROOT/config.php"
bin/moodle-docker-compose up -d
bin/moodle-docker-wait-for-db
```

Install the database from the CLI — quicker and more repeatable than the web
installer. The password is a local throwaway:

```bash
bin/moodle-docker-compose exec webserver php admin/cli/install_database.php \
  --agree-license --fullname="Livemonitor Test" --shortname="LMTEST" \
  --adminuser=admin --adminpass="Dev#Local1234" \
  --adminemail=admin@example.invalid
```

## 4. Composer dependencies (needed for the seed script and PHPUnit)

The `moodlehq/moodle-php-apache` image ships no Composer, and Moodle's `vendor/`
is not part of the git checkout. The test-data generators live behind the PHPUnit
base classes, so they need it:

```bash
bin/moodle-docker-compose exec webserver sh -c \
  'php -r "copy(\"https://getcomposer.org/composer-stable.phar\", \"/usr/local/bin/composer\");" \
   && chmod +x /usr/local/bin/composer'
bin/moodle-docker-compose exec webserver composer install --no-interaction
```

## 5. Seed participants in every status

```bash
bin/moodle-docker-compose exec webserver \
  php mod/quiz/report/livemonitor/tests/fixtures/seed_testdata.php
```

This creates a course, a quiz with a 30 minute limit and four questions (one of
them a two-of-four multi-response question, so the answered-question heuristic is
exercised against a multi-part question), plus:

| User      | Expected status | Progress | Note |
|-----------|-----------------|----------|------|
| `stud1`   | Active          | 3 / 4    | |
| `stud2`   | Idle            | 1 / 4    | |
| `stud3`   | Submitted       | 4 / 4    | submitted and graded |
| `stud4`   | Time overrun    | 2 / 4    | |
| `stud5`   | Not started     | 0 / 4    | |
| `stud6`   | Submitted       | 4 / 4    | state `submitted`, grading not yet run |

Teacher `dozentin`, all users password `Dev#Local1234`. The script prints the
report URL and is repeatable — re-running deletes the previous course and users.

`stud6` is written straight into the `submitted` state so the case is covered on
4.x too, where `mod_quiz` never produces it but the report must still not misread
it.

**"Active" ages out.** It means server-side activity within the *Active window*
setting (default 60s), so `stud1` turns Idle a minute after seeding. Re-run the
script, or bump `timemodified`, to see it again:

```sql
UPDATE m_quiz_attempts SET timemodified = extract(epoch from now())::int
 WHERE userid = (SELECT id FROM m_user WHERE username = 'stud1');
```

(`m_` is the table prefix `config.docker-template.php` sets, not Moodle's usual
`mdl_`.)

## 6. Checklist

Log in as `dozentin` and open the report from *Quiz → Results → Live monitor*.

- [ ] The report appears in the Results navigation and in the report dropdown
- [ ] All six participants are listed, sorted by name, with the statuses above
- [ ] Progress bars and `X / N` labels match the table above
- [ ] Elapsed and remaining time are plausible; `stud4` shows "over by …" and its
      row is highlighted
- [ ] `stud6` reads **Submitted**, not Idle, and shows no remaining time
- [ ] Summary tiles read 1 active / 3 in progress / 2 submitted / 1 overrun /
      1 not started / 6 participants
- [ ] The table refreshes on its own at the configured interval, with no page
      reload and no console errors
- [ ] *Pause auto-refresh* stops it; *Resume* restarts it and refreshes at once
- [ ] *Refresh now* updates immediately
- [ ] No developer debugging notices anywhere on the page
- [ ] A student (`stud1`) opening the report URL directly is refused
- [ ] Adjusting both settings under *Site administration → Plugins → Activity
      modules → Quiz → Live monitor* takes effect

Auto-refresh is deliberately skipped while the tab is in the background
(`document.hidden`), so verify it in a focused window — a headless or background
tab will look as though polling is broken.

## 7. PHPUnit

```bash
bin/moodle-docker-compose exec webserver php admin/tool/phpunit/cli/init.php
bin/moodle-docker-compose exec webserver vendor/bin/phpunit \
  --testsuite quiz_livemonitor_testsuite
```

41 tests, 169 assertions. After adding or moving a test file, re-register the
suite or PHPUnit reports "No tests executed":

```bash
bin/moodle-docker-compose exec webserver php admin/tool/phpunit/cli/util.php --buildconfig
```

`tests/local/progress_provider_test.php` (23) covers the snapshot itself: every
status, the answered-question counter (including a multi-response question and a
blank submission), the summary counters, the latest-attempt and preview rules,
sorting, the time-limit and close-date deadlines, that supervisors are not listed
as participants, that the snapshot writes nothing, and that its query count does
not grow with the cohort.

`tests/external/get_progress_test.php` (18) covers the web service the teacher's
browser polls: that the payload validates against its own declared structure,
that the declaration still carries every field the polled template renders, that
all six status keys survive `PARAM_ALPHA` intact, that the polled payload matches
the first server-side render, and the refusals — student, outsider, prohibited
capability, unknown cmid, a cmid belonging to another module type.

Both files share `tests/fixtures/exam_fixture_trait.php`, which the generated
suite excludes from test discovery.

One trap worth knowing: `clean_returnvalue()` fails loudly when a *declared* key
is missing or mistyped, but silently *strips* anything the declaration does not
mention. So dropping a field from `execute_returns()` does not break that check —
the first render still shows the field and it vanishes on the first poll. That is
why `test_the_declaration_keeps_every_field_the_polled_template_renders` reads the
field list out of `monitor_body.mustache` at run time and asserts against it.

**Do not size a test cohort by what feels realistic.** Under PHPUnit,
`get_enrolled_users()` with a capability filter and `$onlyactive = true` is
pathologically slow — around 54 seconds for 200 participants — because the test
environment resolves capabilities against a null cache store. The same call takes
**1.9 ms** in a normal request, and a full 200-participant snapshot takes **4–7 ms
in 5 queries**. So a slow large-cohort test says something about PHPUnit, not
about this plugin. `test_query_count_does_not_grow_with_participants` therefore
compares query *counts* at 3 and 23 participants rather than timings.

## 8. Running against the other database engine

German university Moodles commonly run MariaDB/MySQL rather than PostgreSQL, and
the answered-question count uses a correlated subquery over
`question_attempt_steps` — historically MySQL's weak spot. Switching engines means
rebuilding the stack, because the database lives in a container volume:

```bash
bin/moodle-docker-compose down -v
source ../env.sh mariadb
cp config.docker-template.php "$MOODLE_DOCKER_WWWROOT/config.php"
bin/moodle-docker-compose up -d && bin/moodle-docker-wait-for-db
bin/moodle-docker-compose exec webserver php admin/tool/phpunit/cli/init.php
bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite quiz_livemonitor_testsuite
```

On MariaDB 10.11 all 41 tests pass and the correlated subquery costs 1.7 ms for
164 attempts, so the concern did not materialise. Re-run `init.php` after any
change to `version.php`, or PHPUnit refuses to start.

## 9. Checking PHP version portability

```bash
bin/moodle-docker-compose exec webserver sh -c \
  'mkdir -p /tmp/compat && cd /tmp/compat \
   && composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
   && composer require --no-interaction phpcompatibility/php-compatibility squizlabs/php_codesniffer \
   && vendor/bin/phpcs --config-set installed_paths /tmp/compat/vendor/phpcompatibility/php-compatibility'

bin/moodle-docker-compose exec webserver \
  /tmp/compat/vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.1- \
  --extensions=php --ignore='*/tests/*' mod/quiz/report/livemonitor
```

Reports nothing down to `testVersion 7.4-`, so the Moodle version is the binding
constraint on where this plugin can run, not PHP.

## 10. Static analysis

```bash
bin/moodle-docker-compose exec webserver sh -c \
  'mkdir -p /tmp/mcs && cd /tmp/mcs \
   && composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
   && composer require moodlehq/moodle-cs --no-interaction'

bin/moodle-docker-compose exec webserver \
  /tmp/mcs/vendor/bin/phpcs --standard=moodle --extensions=php \
  mod/quiz/report/livemonitor
```

The PHP files are expected to report zero errors. The remaining warnings are the
AMOS alphabetical ordering of the language string keys, which is deliberate: they
are grouped by purpose with explanatory comments.

## 11. Rebuilding the AMD module

After editing `amd/src/monitor.js`:

```bash
bin/moodle-docker-compose exec webserver \
  npx grunt amd --root=mod/quiz/report/livemonitor
```

## 12. Tear down

```bash
cd moodle-docker && source ../env.sh
bin/moodle-docker-compose down -v   # -v also drops the database volume
```
