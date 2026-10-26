"""Tests for mutants.py. Run from the repo root:

    python3 -m unittest discover -s scripts -v

Each case builds a throwaway tree and replaces Pest with a fake, so it needs
neither Docker nor the real tests. Every failure asserts part of its message.
"""
import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gate  # noqa: E402
import mutants  # noqa: E402

ACCOUNT = "backend/tests/Integration/AccountTest.php"
KILL = ACCOUNT + "::it isolates by company"
OTHER = ACCOUNT + "::it rejects a bad code"
ROLES = "backend/tests/Integration/DatabaseRolesTest.php"
FEATURE = "backend/tests/Feature/AccountApiTest.php"


class FakePest(object):
    """statuses is {mutation or None: {id: status}}; calls records (mutation, files)."""

    def __init__(self, statuses):
        self.statuses = statuses
        self.calls = []

    def __call__(self, root, mutation, files):
        self.calls.append((mutation, list(files)))
        found = self.statuses.get(mutation, {mutants.APPLIED_ID: gate.PASSED})
        return [gate.Result(i, s, "boom" if s == gate.FAILED else "") for i, s in found.items()]


class Base(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.root)
        self.write(ACCOUNT, "<?php\nuses(RefreshDatabaseAsOwner::class);\n")
        self.write(ROLES, "<?php\n// Catalogs only: no RefreshDatabase.\n")
        self.write(FEATURE, "<?php\n")
        self.write(mutants.APPLIED_FILE, "<?php\n")

    def write(self, path, text):
        full = os.path.join(self.root, path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w") as fh:
            fh.write(text)

    def mutation(self, name, header, filename="9999_12_31_000000_m.php"):
        self.write(os.path.join(mutants.MUTATIONS, name, filename), "<?php\n" + header + "\nreturn 1;\n")


class LoadTest(Base):
    def test_reads_kills_and_control(self):
        self.mutation("no_force", "// kills: %s\n// kills: %s" % (KILL, OTHER))
        self.mutation("_noop", "// control: survives\n// kills: %s" % KILL)
        m = mutants.load(self.root, "no_force")
        self.assertEqual((m.kills, m.control, m.files()), ([KILL, OTHER], False, [ACCOUNT]))
        self.assertTrue(mutants.load(self.root, "_noop").control)

    def test_feature_tests_get_the_trait_from_pest_php(self):
        self.mutation("m", "// kills: %s::it lists" % FEATURE)
        self.assertEqual(mutants.load(self.root, "m").files(), [FEATURE])

    def assertRejected(self, name, header, message, filename="9999_12_31_000000_m.php"):
        if header is not None:
            self.mutation(name, header, filename)
        with self.assertRaisesRegex(gate.GateError, message):
            mutants.load(self.root, name)

    def test_bad_name(self):
        self.assertRejected("Bad-Name", None, "must match")

    def test_missing_folder(self):
        self.assertRejected("missing", None, "does not exist")

    def test_file_not_dated_last(self):
        self.assertRejected("m", "// kills: %s" % KILL, "exactly one file", "2026_01_01_000000_m.php")

    def test_two_files(self):
        self.mutation("m", "// kills: %s" % KILL)
        self.assertRejected("m", "// kills: %s" % KILL, "exactly one file", "9999_12_31_000001_n.php")

    def test_no_kills(self):
        self.assertRejected("m", "// control: survives", "no '// kills")

    def test_duplicate_kill(self):
        self.assertRejected("m", "// kills: %s\n// kills: %s" % (KILL, KILL), "listed twice")

    def test_not_a_test_id(self):
        self.assertRejected("m", "// kills: %s" % ACCOUNT, "not a Pest test id")

    def test_outside_backend_tests(self):
        self.assertRejected("m", "// kills: frontend/src/App.test.tsx::renders", "not a Pest test id")

    def test_applied_test_cannot_kill(self):
        self.assertRejected("m", "// kills: %s" % mutants.APPLIED_ID, "cannot be a killer")

    def test_missing_test_file(self):
        self.assertRejected("m", "// kills: backend/tests/Integration/GoneTest.php::it x", "does not exist")

    def test_test_without_owner_trait(self):
        self.assertRejected("m", "// kills: %s::it x" % ROLES, "does not use")


class RunTest(Base):
    def setUp(self):
        Base.setUp(self)
        self.mutation("no_force", "// kills: %s" % KILL)
        self.mutation("_noop", "// control: survives\n// kills: %s" % KILL)

    def statuses(self, applied=gate.PASSED, kill=gate.FAILED, control=gate.PASSED):
        return {
            "no_force": {mutants.APPLIED_ID: applied, KILL: kill, OTHER: gate.PASSED},
            "_noop": {mutants.APPLIED_ID: gate.PASSED, KILL: control},
        }

    def test_all_as_expected(self):
        pest = FakePest(self.statuses())
        out = mutants.run(self.root, [], pest)
        self.assertIn("KILLED no_force\n  killed by %s: boom" % KILL, out)
        self.assertIn("CONTROL _noop", out)
        self.assertIn("2 mutations, all as expected", out)
        self.assertEqual(pest.calls, [("_noop", [ACCOUNT]), ("no_force", [ACCOUNT]), (None, [])])

    def test_survivor_fails(self):
        with self.assertRaisesRegex(gate.GateError, "SURVIVED %s \\(passed\\)" % KILL):
            mutants.run(self.root, ["no_force"], FakePest(self.statuses(kill=gate.PASSED)))

    def test_skipped_killer_is_a_survivor(self):
        with self.assertRaisesRegex(gate.GateError, "SURVIVED .*\\(skipped\\)"):
            mutants.run(self.root, ["no_force"], FakePest(self.statuses(kill=gate.SKIPPED)))

    def test_killed_control_fails(self):
        with self.assertRaisesRegex(gate.GateError, "control killed by"):
            mutants.run(self.root, ["_noop"], FakePest(self.statuses(control=gate.FAILED)))

    def test_mutation_that_did_not_apply_fails(self):
        with self.assertRaisesRegex(gate.GateError, "no_force: MutationAppliedTest did not pass"):
            mutants.run(self.root, ["no_force"], FakePest(self.statuses(applied=gate.FAILED)))

    def test_applied_test_missing_fails(self):
        statuses = self.statuses()
        del statuses["no_force"][mutants.APPLIED_ID]
        with self.assertRaisesRegex(gate.GateError, "MutationAppliedTest did not run"):
            mutants.run(self.root, ["no_force"], FakePest(statuses))

    def test_killer_not_run_fails(self):
        statuses = self.statuses()
        del statuses["no_force"][KILL]
        with self.assertRaisesRegex(gate.GateError, "not run: %s" % KILL):
            mutants.run(self.root, ["no_force"], FakePest(statuses))

    def test_restores_after_a_survivor_and_reports_every_failure(self):
        statuses = self.statuses(kill=gate.PASSED, control=gate.FAILED)
        pest = FakePest(statuses)
        with self.assertRaisesRegex(gate.GateError, "(?s)control killed.*SURVIVED"):
            mutants.run(self.root, [], pest)
        self.assertEqual(pest.calls[-1], (None, []))

    def test_failed_restore_fails(self):
        statuses = self.statuses()
        statuses[None] = {mutants.APPLIED_ID: gate.FAILED}
        with self.assertRaisesRegex(gate.GateError, "restore: MutationAppliedTest did not pass"):
            mutants.run(self.root, [], FakePest(statuses))

    def test_invalid_mutation_fails_before_running_anything(self):
        pest = FakePest(self.statuses())
        with self.assertRaisesRegex(gate.GateError, "does not exist"):
            mutants.run(self.root, ["no_force", "missing"], pest)
        self.assertEqual(pest.calls, [])

    def test_no_mutations_fails(self):
        shutil.rmtree(os.path.join(self.root, mutants.MUTATIONS))
        with self.assertRaisesRegex(gate.GateError, "no mutations"):
            mutants.run(self.root, [], FakePest({}))


class CommandTest(unittest.TestCase):
    def test_sets_mutation_and_runs_the_applied_test_first(self):
        cmd = mutants.pest_command("no_force", [ACCOUNT])
        self.assertEqual(cmd[:4], ["docker", "compose", "-f", "compose.yaml"])
        self.assertIn("MUTATION=no_force", cmd)
        start = cmd.index("vendor/bin/pest") + 1
        self.assertEqual(cmd[start:start + 2],
                         ["tests/Integration/MutationAppliedTest.php", "tests/Integration/AccountTest.php"])

    def test_restore_runs_without_mutation(self):
        self.assertFalse(any(part.startswith("MUTATION") for part in mutants.pest_command(None, [])))


if __name__ == "__main__":
    unittest.main()
