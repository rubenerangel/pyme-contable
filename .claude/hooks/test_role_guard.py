"""Tests for role_guard.py. Run from the repo root:

    python3 -m unittest discover -s .claude/hooks -v

To see a rule fail on purpose, delete it from role_guard.py and run again.
Every block asserts part of its reason, so a case cannot pass on another rule.
"""
import json
import os
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import role_guard  # noqa: E402

HOOK = os.path.join(os.path.dirname(os.path.abspath(__file__)), "role_guard.py")
PEST = "docker compose run --rm -u www-data php vendor/bin/pest"
PSQL = "docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U pyme_app -d pyme_test"
PSQL_DEV = PSQL.replace("-d pyme_test", "-d pyme")
COMPOSER_AUDIT = "docker compose run --rm -u www-data php composer audit"
NPM_AUDIT = (
    'docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e npm_config_update_notifier=false '
    '-v "$PWD/frontend":/app -w /app node:24-bookworm-slim npm audit'
)


def payload(role, tool, **tool_input):
    data = {"tool_name": tool, "tool_input": tool_input}
    if role is not None:
        data["agent_id"] = "agent-1"
        data["agent_type"] = role
    return data


def sql(statements, psql=PSQL):
    return psql + ' -c "' + statements + '"'


def heredoc(body, psql=PSQL):
    return psql + " <<'SQL'\n" + body + "\nSQL"


class RoleGuardTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = self.tmp.name

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, role, path):
        return role_guard.decide(payload(role, "Write", file_path=os.path.join(self.root, path)), self.root)

    def bash(self, role, command):
        return role_guard.decide(payload(role, "Bash", command=command), self.root)

    def assertAllowed(self, reason):
        self.assertIsNone(reason)

    def assertBlocked(self, reason, why):
        self.assertIsNotNone(reason, "expected a block for: " + why)
        self.assertIn(why, reason)

    # Main thread (architect)

    def test_main_thread_is_not_restricted_by_the_hook(self):
        self.assertAllowed(self.write(None, "backend/tests/Integration/AccountTest.php"))
        self.assertAllowed(self.bash(None, "git push origin paso/4-4"))

    # tester

    def test_tester_writes_only_test_files(self):
        self.assertAllowed(self.write("tester", "backend/tests/Integration/AccountTest.php"))
        self.assertAllowed(self.write("tester", "frontend/src/components/Ledger.test.tsx"))
        for path in (
            "backend/app/Models/Account.php",
            "backend/database/migrations/2026_01_01_x.php",
            "frontend/src/components/Ledger.tsx",
        ):
            with self.subTest(path=path):
                self.assertBlocked(self.write("tester", path), "tester only writes test files")

    def test_tester_cannot_write_production_code_through_bash(self):
        self.assertAllowed(self.bash("tester", "sed -i 's/a/b/' backend/tests/Integration/AccountTest.php"))
        self.assertAllowed(self.bash("tester", "cp /tmp/x.tsx frontend/src/components/Ledger.test.tsx"))
        for command in (
            "sed -i 's/a/b/' backend/app/Models/Account.php",
            "echo x > backend/database/migrations/2026_01_01_x.php",
            "cp /tmp/x.tsx ./frontend/src/components/Ledger.tsx",
            "tee docs/estado.md < /tmp/x",
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("tester", command), "not even through Bash")

    def test_tester_writes_the_vitest_setup_but_not_the_gate_state(self):
        self.assertAllowed(self.write("tester", "frontend/src/test/setup.ts"))
        for path in (".gate/red.json", "backend/.gate/pest.xml", "scripts/gate-baseline.txt",
                     "frontend/vite.config.ts", "backend/phpunit.xml"):
            with self.subTest(path=path):
                self.assertBlocked(self.write("tester", path), "tester only writes test files")
        for command in ("echo '{}' > .gate/red.json", "cp /tmp/x .gate/red.json",
                        "tee scripts/gate-baseline.txt < /tmp/x"):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("tester", command), "not even through Bash")

    def test_writers_never_run_the_gate(self):
        for role in ("tester", "coder"):
            for command in ("python3 scripts/gate.py green", "cd scripts && python3 gate.py red",
                            "python3 ./scripts/gate.py lock 2>&1 | tail -5", "scripts/gate.py baseline",
                            "python3 -m scripts.gate lock", "python3 scripts/gat?.py lock",
                            "python3 scripts/gate.p[y] lock", "python3 scripts/gat''e.py green",
                            "python3 scripts/{gate,x}.py lock"):
                with self.subTest(role=role, command=command):
                    self.assertBlocked(self.bash(role, command), "scripts/gate.py belongs to the architect")
        self.assertAllowed(self.bash("coder", "python3 -m unittest scripts/test_gate.py"))

    def test_reviewers_never_run_the_gate(self):
        self.assertBlocked(self.bash("code-reviewer", "python3 scripts/gate.py green"), "not in the allowlist")

    def test_nobody_writes_outside_the_project(self):
        self.assertBlocked(self.write("tester", "../backend/tests/X.php"), "outside the project")
        self.assertBlocked(self.write("coder", "/etc/passwd"), "outside the project")

    def test_tester_runs_tests_but_not_git_or_dependencies(self):
        self.assertAllowed(self.bash("tester", PEST + " tests/Integration/AccountTest.php 2>&1 | tail -40"))
        self.assertBlocked(self.bash("tester", "git commit -m wip"), "git write operations")
        self.assertBlocked(self.bash("tester", "git stash"), "git write operations")
        self.assertBlocked(self.bash("tester", "docker compose run --rm -u www-data php composer require foo/bar"),
                           "dependency changes")

    # coder

    def test_coder_writes_production_code_and_config(self):
        self.assertAllowed(self.write("coder", "backend/app/Models/Account.php"))
        self.assertAllowed(self.write("coder", "backend/database/migrations/2026_01_01_x.php"))
        self.assertAllowed(self.write("coder", "backend/.env.example"))
        self.assertAllowed(self.write("coder", "db/init/01-roles.sql"))

    def test_coder_never_writes_tests_docs_agents_ci_or_env(self):
        for path in (
            "backend/tests/Integration/AccountTest.php",
            "frontend/src/App.test.tsx",
            "docs/estado.md",
            ".claude/hooks/role_guard.py",
            ".claude/settings.json",
            "CLAUDE.md",
            "backend/.env",
            ".github/workflows/ci.yml",
            "backend/phpunit.xml",
            "scripts/gate.py",
            "scripts/gate-baseline.txt",
            "frontend/src/test/setup.ts",
            "frontend/vite.config.ts",
            "frontend/vitest.config.ts",
            ".gate/red.json",
            "backend/.gate/pest.xml",
            "frontend/.gate/vitest.xml",
            # Compose decides what the gate runs: it belongs to the architect.
            "compose.yaml",
            "compose.override.yaml",
            "docker-compose.override.yml",
            # autoload-dev files could load a fake test helper before tests/ does.
            "backend/composer.json",
        ):
            with self.subTest(path=path):
                self.assertBlocked(self.write("coder", path), "coder never edits")
        self.assertAllowed(self.write("coder", "docker/php/php.ini"))

    def test_coder_cannot_write_protected_paths_through_bash(self):
        for command in (
            "sed -i 's/a/b/' backend/tests/Integration/AccountTest.php",
            "echo x > backend/tests/Integration/AccountTest.php",
            "cp /tmp/x.php backend/tests/Integration/AccountTest.php",
            "cd backend && sed -i 's/a/b/' tests/Integration/AccountTest.php",
            "sed -i 's/guards//' .github/workflows/ci.yml",
            "cp /tmp/x.xml backend/phpunit.xml",
            "touch scripts/gate",
            "echo '{}' > .gate/red.json",
            "cp /tmp/x .gate/red.json",
            "cd backend && cp /tmp/x.xml .gate/pest.xml",
            "sed -i 's/a/b/' scripts/gate-baseline.txt",
            "sed -i 's/a/b/' frontend/vite.config.ts",
            "cp /tmp/x.yaml compose.override.yaml",
            "sed -i 's/a/b/' compose.yaml",
            "sed -i 's/a/b/' backend/composer.json",
            "cd backend && cp /tmp/x.json composer.json",
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("coder", command), "not even through Bash")

    def test_writers_never_touch_git_internals(self):
        # Not a railing: a hook or core.fsmonitor in .git/ runs in the unrestricted main session.
        for path in (".git/config", ".git/hooks/pre-commit", ".git/info/attributes"):
            with self.subTest(path=path):
                self.assertBlocked(self.write("coder", path), "coder never edits")
                self.assertBlocked(self.write("tester", path), "tester only writes test files")
        for role in ("coder", "tester"):
            for command in (
                "echo x > .git/hooks/pre-commit",
                "cd .git && echo x > hooks/pre-commit",
                "cp /tmp/x " + os.path.join(self.root, ".git/config"),
                "cd backend && cp /tmp/x ../.git/hooks/post-checkout",
                "echo x > .gi?/hooks/pre-commit",
                "echo x > .g{i,x}t/config",
                "echo x > .gi''t/config",
                "ln -s /tmp/x .git/hooks/pre-push",
            ):
                with self.subTest(role=role, command=command):
                    self.assertBlocked(self.bash(role, command), "not even through Bash")
            for command in ("git config core.fsmonitor ./x", "git -C . config core.hooksPath /tmp/h"):
                with self.subTest(role=role, command=command):
                    self.assertBlocked(self.bash(role, command), "git config")
        self.assertAllowed(self.bash("coder", "cp /tmp/x .gitignore.bak"))
        self.assertAllowed(self.bash("coder", "sed -i 's/a/b/' backend/app/Models/Account.php"))

    def test_coder_can_run_a_single_test(self):
        self.assertAllowed(self.bash("coder", PEST + " tests/Integration/AccountTest.php 2>&1 | tail -40"))
        self.assertAllowed(self.bash("coder", PEST + " --filter=hierarchy > /dev/null"))

    def test_dangerous_commands_are_blocked_for_writers(self):
        for command, why in (
            ("docker compose down -v", "docker compose down"),
            ("docker volume rm pyme-contable_pgdata", "removing Docker resources"),
            ("docker compose run --rm -u www-data php php artisan migrate --database=pgsql_owner", "migrations"),
            ("docker run --rm node:24-bookworm-slim npm install lodash", "dependency changes"),
            ("git push", "git write operations"),
            ("git checkout -- backend/tests", "git write operations"),
            ("rm -rf backend/vendor", "recursive rm"),
            ("cat backend/.env", ".env holds secrets"),
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("coder", command), why)

    def test_global_options_and_aliases_do_not_dodge_the_denylist(self):
        for command, why in (
            ("git -C . push", "git write operations"),
            ("git -c user.name=x commit -m x", "git write operations"),
            ("git --git-dir=.git push", "git write operations"),
            ("git --no-pager -C . reset --hard", "git write operations"),
            ("docker compose -f compose.yaml down -v", "docker compose down"),
            ("docker compose --project-name pyme down", "docker compose down"),
            ("docker-compose down -v", "docker compose down"),
            ("docker --context default compose down", "docker compose down"),
            ("docker volume prune -f", "removing Docker resources"),
            ("docker system prune --volumes", "removing Docker resources"),
            ("rm -f -r backend/vendor", "recursive rm"),
            ("rm --recursive backend/vendor", "recursive rm"),
            ("docker compose run --rm -u www-data php composer req foo/bar", "dependency changes"),
            ("docker compose run --rm -u www-data php composer --no-interaction update", "dependency changes"),
            ("docker run --rm node:24-bookworm-slim npm i lodash", "dependency changes"),
            ("docker run --rm node:24-bookworm-slim npm isntall lodash", "dependency changes"),
            ("docker run --rm node:24-bookworm-slim npm rm react", "dependency changes"),
            ("docker run --rm node:24-bookworm-slim npx cowsay", "npx"),
            ("docker compose run --rm -u www-data php php artisan --env=testing migrate:fresh", "migrations"),
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("coder", command), why)

    # Secrets, every subagent

    def test_env_file_is_out_of_reach_even_through_globs(self):
        for command in (
            "cat .en?",
            "cat backend/.e*",
            "ls .{env,x}",
            "cat .*",
            "grep -r x [.]env",
            'cat backend/.en""v',
            "cat backend/.e''nv",
            "head .''env",
            "cat backend/.e\\nv",
            "grep x --file=.e''nv",
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("code-reviewer", command), ".env holds secrets")
                self.assertBlocked(self.bash("coder", command), ".env holds secrets")
        # Unbalanced quotes stop the tokenizer; the raw words still catch the glob.
        self.assertBlocked(self.bash("coder", "cat .e* 'unterminated"), ".env holds secrets")
        self.assertAllowed(self.bash("code-reviewer", "ls *.md"))
        self.assertAllowed(self.bash("code-reviewer", "cat *"))
        self.assertAllowed(self.bash("code-reviewer", "cat backend/.env.example"))

    # Database access, every subagent

    def test_psql_only_as_pyme_app(self):
        self.assertBlocked(self.bash("coder", "docker compose exec postgres psql -U postgres -d pyme"),
                           "psql must connect as pyme_app")
        self.assertBlocked(self.bash("coder", "docker compose exec postgres psql -d pyme"),
                           "psql must connect as pyme_app")
        self.assertBlocked(self.bash("security-reviewer", PSQL + " -U postgres -c 'select 1'"),
                           "psql must connect as pyme_app")

    def test_pguser_cannot_override_the_user(self):
        # A -U pyme_app in the SQL satisfies the user check; only the PGUSER rule stops it.
        self.assertBlocked(self.bash("coder", "PGUSER=postgres psql -c 'select 1 -- -U pyme_app'"), "PGUSER")
        self.assertBlocked(self.bash("security-reviewer", "PGUSER=postgres " + PSQL + " -c 'select 1'"), "PGUSER")

    # code-reviewer

    def test_code_reviewer_reads_git_only(self):
        self.assertAllowed(self.bash("code-reviewer", "git diff HEAD"))
        self.assertAllowed(self.bash("code-reviewer", "git status --porcelain"))
        self.assertAllowed(self.bash("code-reviewer", "git log --oneline -20 | head -5"))
        self.assertAllowed(self.bash("code-reviewer", "git diff 2>&1|head -5"))
        self.assertAllowed(self.bash("code-reviewer", "cat backend/app/Models/Account.php"))

    def test_read_only_git_accepts_only_the_project_root_as_global_option(self):
        self.assertAllowed(self.bash("code-reviewer", "git -C " + self.root + " status"))
        self.assertAllowed(self.bash("code-reviewer", "git -C . diff HEAD"))
        self.assertAllowed(self.bash("code-reviewer", "git --no-pager log -5"))
        for command in (
            "git -C /etc status",
            "git -c core.pager=sh log",
            "git --git-dir=/tmp/x log",
            "git -C . push",
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("code-reviewer", command), "only read-only git commands")

    def test_code_reviewer_cannot_write_or_run(self):
        self.assertBlocked(self.write("code-reviewer", "backend/app/Models/Account.php"), "read-only")
        self.assertBlocked(self.bash("code-reviewer", "git push"), "only read-only git commands")
        self.assertBlocked(self.bash("code-reviewer", "git diff --output=/tmp/x"), "only read-only git commands")
        self.assertBlocked(self.bash("code-reviewer", PEST), "allowlist")
        self.assertBlocked(self.bash("code-reviewer", PSQL + " -c 'select 1'"), "allowlist")

    def test_read_only_roles_cannot_run_programs_through_search_tools(self):
        for role in ("code-reviewer", "security-reviewer", "Explore"):
            with self.subTest(role=role):
                self.assertBlocked(self.bash(role, "rg --pre sh x ."), "allowlist")

    def test_shell_tricks_are_blocked(self):
        for command, why in (
            ("git diff; rm -rf backend", "shell operator"),
            ("git diff && touch x", "shell operator"),
            ("git log $(rm x)", "command substitution"),
            ("git log `rm x`", "command substitution"),
            ("git diff > /tmp/x", "shell operator"),
            ("cat CLAUDE.md >&2x", "shell operator"),
            ("cat CLAUDE.md > /dev/nullx", "shell operator"),
            ("git diff | sh", "only head, tail"),
            ("git diff &", "shell operator"),
            ("git diff\nrm x", "multi-line"),
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("code-reviewer", command), why)

    # security-reviewer and performance-reviewer

    def test_db_reviewers_run_audits_but_not_the_suite(self):
        self.assertAllowed(self.bash("security-reviewer", COMPOSER_AUDIT))
        self.assertAllowed(self.bash("security-reviewer", NPM_AUDIT))
        self.assertBlocked(self.bash("security-reviewer", NPM_AUDIT + " fix"), "npm audit without fix")
        for command in (
            PEST + " --testsuite=Integration 2>&1 | tail -30",
            PEST + " --testdox-text=app/Models/Account.php",
            PEST + " --bootstrap=x.php",
            "docker compose run --rm -u www-data php php artisan migrate",
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("performance-reviewer", command), "allowlist")

    def test_db_reviewers_probe_as_pyme_app_with_rollback(self):
        self.assertAllowed(self.bash("security-reviewer", sql("BEGIN; select relname from pg_class; ROLLBACK;")))
        self.assertAllowed(self.bash("security-reviewer",
                                     sql("BEGIN; select has_table_privilege('pyme_app', 'account', 'UPDATE'); ROLLBACK;")))
        self.assertAllowed(self.bash("security-reviewer", sql("BEGIN; INSERT INTO account (code) VALUES ('1'); ROLLBACK;")))
        self.assertAllowed(self.bash("security-reviewer", sql("START TRANSACTION; select 1; ROLLBACK")))
        self.assertAllowed(self.bash("performance-reviewer",
                                     heredoc("BEGIN;\nEXPLAIN ANALYZE UPDATE period SET status = 'closed';\nROLLBACK;")))

    def test_psql_stops_on_the_first_error(self):
        without = PSQL.replace("-v ON_ERROR_STOP=1 ", "")
        self.assertBlocked(self.bash("security-reviewer", sql("BEGIN; select 1; ROLLBACK;", without)),
                           "needs -v ON_ERROR_STOP=1")
        self.assertBlocked(self.bash("performance-reviewer", heredoc("BEGIN;\nselect 1;\nROLLBACK;", without)),
                           "needs -v ON_ERROR_STOP=1")
        self.assertBlocked(self.bash("security-reviewer",
                                     sql("BEGIN; select 1; ROLLBACK;", PSQL.replace("ON_ERROR_STOP=1", "ON_ERROR_STOP=0"))),
                           "-v only as ON_ERROR_STOP=1")

    def test_development_database_is_read_only(self):
        self.assertAllowed(self.bash("security-reviewer", sql("BEGIN; select relname from pg_class; ROLLBACK;", PSQL_DEV)))
        self.assertAllowed(self.bash("security-reviewer",
                                     sql("BEGIN; select has_table_privilege('pyme_app', 'account', 'UPDATE'); ROLLBACK;",
                                         PSQL_DEV)))
        self.assertAllowed(self.bash("performance-reviewer",
                                     heredoc("BEGIN;\nSELECT set_config('app.company_id', '1', true);\n"
                                             "EXPLAIN ANALYZE SELECT * FROM account;\nROLLBACK;", PSQL_DEV)))
        for command in (
            sql("BEGIN; INSERT INTO account (code) VALUES ('1'); ROLLBACK;", PSQL_DEV),
            sql("BEGIN; select nextval('account_id_seq'); ROLLBACK;", PSQL_DEV),
            heredoc("BEGIN;\nEXPLAIN ANALYZE UPDATE period SET status = 'closed';\nROLLBACK;", PSQL_DEV),
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("performance-reviewer", command), "pyme is read-only")
        self.assertBlocked(self.bash("security-reviewer",
                                     sql("BEGIN; select 1; ROLLBACK;", PSQL.replace(" -d pyme_test", ""))),
                           "needs -d pyme or -d pyme_test")
        self.assertBlocked(self.bash("security-reviewer", sql("BEGIN; select 1; ROLLBACK;", PSQL + " -d pyme")),
                           "-d only once")

    def test_db_reviewers_use_describe_meta_commands_only(self):
        self.assertAllowed(self.bash("security-reviewer", PSQL + " -c '\\d account'"))
        self.assertAllowed(self.bash("security-reviewer", PSQL + " -c '\\dp+ account'"))
        self.assertAllowed(self.bash("performance-reviewer", heredoc("\\x\nBEGIN;\nselect 1;\nROLLBACK;")))
        for command in (
            PSQL + " -c '\\c - postgres'",
            PSQL + " -c '\\connect pyme postgres'",
            PSQL + " -c '\\getenv p POSTGRES_PASSWORD'",
            PSQL + " -c '\\setenv EDITOR sh'",
            PSQL + " -c '\\lo_export 1 /tmp/x'",
            PSQL + " -c '\\! rm -rf /var/lib/postgresql/data'",
            PSQL + " -c '\\o /tmp/x'",
            heredoc("BEGIN;\n\\c - postgres\nROLLBACK;"),
            heredoc("BEGIN;\nselect 'x' \\gexec\nROLLBACK;"),
            heredoc("\\d account \\\\ \\! sh"),
        ):
            with self.subTest(command=command):
                self.assertBlocked(self.bash("security-reviewer", command), "meta-command not allowed")
        self.assertBlocked(self.bash("security-reviewer", heredoc("\\echo `id`")), "backquotes")

    def test_db_reviewers_never_commit_or_write_outside_a_rollback(self):
        for statements, why in (
            ("select 1", "inside BEGIN ... ROLLBACK"),
            ("INSERT INTO account (code) VALUES (1)", "inside BEGIN ... ROLLBACK"),
            ("select audit_log_event('x', '{}')", "inside BEGIN ... ROLLBACK"),
            ("DELETE FROM account; ROLLBACK;", "inside BEGIN ... ROLLBACK"),
            ("BEGIN; ROLLBACK; DELETE FROM account; BEGIN; ROLLBACK;", "inside BEGIN ... ROLLBACK"),
            ("BEGIN; DELETE FROM account; COMMIT; ROLLBACK;", "COMMIT"),
            ("BEGIN; DELETE FROM account; COMMIT AND CHAIN; ROLLBACK;", "COMMIT"),
            ("BEGIN; DELETE FROM account; END WORK; BEGIN; ROLLBACK;", "COMMIT"),
            ("BEGIN; DELETE FROM account; ABORT; DELETE FROM account; BEGIN; ROLLBACK;", "COMMIT"),
            ("BEGIN; DELETE FROM account; COMMIT/**/; ROLLBACK;", "COMMIT"),
            ("BEGIN; PREPARE TRANSACTION 'x'; ROLLBACK;", "COMMIT"),
        ):
            with self.subTest(statements=statements):
                self.assertBlocked(self.bash("security-reviewer", sql(statements)), why)
        self.assertBlocked(self.bash("security-reviewer", PSQL + " -c \"SELECT E'\\\\''; DELETE FROM account; --'\""),
                           "meta-command not allowed")
        self.assertBlocked(self.bash("performance-reviewer",
                                     heredoc("SELECT $$'$$;\nLOCK TABLE account;\nSELECT $$'$$;")), "dollar quotes")

    def test_a_failing_begin_cannot_leave_the_rest_in_autocommit(self):
        for body in (
            "BEGIN ISOLATION LEVEL nope;\nDELETE FROM account;\nROLLBACK;",
            "BEGIN TRANSCTION;\nDELETE FROM account;\nROLLBACK;",
            "BEGIN\nDELETE FROM account;\nROLLBACK;",
        ):
            with self.subTest(body=body):
                self.assertBlocked(self.bash("performance-reviewer", heredoc(body)), "inside BEGIN ... ROLLBACK")

    def test_db_reviewers_never_escape_psql(self):
        self.assertBlocked(self.bash("security-reviewer", PSQL + " -f /tmp/x.sql"), "option '-f' not allowed")
        self.assertBlocked(self.bash("security-reviewer", PSQL + " -v q=x -c 'select 1'"), "-v only as ON_ERROR_STOP=1")
        self.assertBlocked(self.bash("security-reviewer", PSQL.replace("pyme_test", "postgres") + " -c 'select 1'"),
                           "only on pyme or pyme_test")
        self.assertBlocked(self.bash("performance-reviewer", PSQL + " <<SQL\nselect $(id);\nSQL"), "quoted tag")
        self.assertBlocked(self.bash("performance-reviewer", PSQL + " <<'SQL'\nselect 1;\nSQL\nrm -rf backend"),
                           "heredoc must end with its tag")

    # Any other subagent (Explore, Plan, general-purpose)

    def test_unknown_subagents_are_read_only(self):
        self.assertBlocked(self.write("general-purpose", "backend/app/Models/Account.php"), "read-only")
        self.assertAllowed(self.bash("Explore", "ls backend/app"))
        self.assertBlocked(self.bash("Explore", "rm backend/app/Models/Account.php"), "allowlist")
        self.assertBlocked(self.bash("general-purpose", PEST), "allowlist")


class HookProcessTest(unittest.TestCase):
    def run_hook(self, stdin):
        env = dict(os.environ, CLAUDE_PROJECT_DIR=os.getcwd())
        return subprocess.run([sys.executable, HOOK], input=stdin, env=env,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)

    def test_block_exits_2_with_reason_on_stderr(self):
        result = self.run_hook(json.dumps(payload("coder", "Bash", command="git push")))
        self.assertEqual(2, result.returncode)
        self.assertIn("role_guard", result.stderr)

    def test_allow_exits_0(self):
        result = self.run_hook(json.dumps(payload("code-reviewer", "Bash", command="git status")))
        self.assertEqual(0, result.returncode)

    def test_invalid_input_fails_closed(self):
        self.assertEqual(2, self.run_hook("not json").returncode)


if __name__ == "__main__":
    unittest.main()