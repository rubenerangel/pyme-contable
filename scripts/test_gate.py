"""Tests for gate.py. Run from the repo root:

    python3 -m unittest discover -s scripts -v

Each case builds a throwaway git repo and replaces the suites with a fake runner,
so it needs neither Docker nor the real tests. Every failure asserts part of its
message, so a case cannot pass on another rule.
"""
import os
import shutil
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gate  # noqa: E402

A = "backend/tests/Unit/ATest.php"
B = "backend/tests/Unit/BTest.php"
APP = "frontend/src/App.test.tsx"
PROD = "backend/app/Thing.php"

PEST_XML = """<?xml version="1.0" encoding="UTF-8"?>
<testsuites><testsuite name="Unit">
  <testcase name="it adds" file="tests/Unit/ATest.php::it adds" class="Tests\\Unit\\ATest"/>
  <testcase name="it redacts with data set &quot;('password')&quot;"
            file="tests/Unit/ATest.php::it redactswith data set &quot;('password')&quot;"/>
  <testcase name="it fails" file="tests/Unit/ATest.php::it fails">
    <failure type="Exception">Failed asserting that 1 is 2.
at tests/Unit/ATest.php:9</failure>
  </testcase>
  <testcase name="it probes" file="tests/Unit/ATest.php::it probes">
    <error type="Error">it probesError: Class "App\\GateProbe" not found</error>
  </testcase>
  <testcase name="testLegacy" file="/var/www/backend/tests/Unit/LegacyTest.php"/>
  <testcase name="it waits" file="tests/Unit/ATest.php::it waits"><skipped/></testcase>
</testsuite></testsuites>
"""

VITEST_XML = """<?xml version="1.0" encoding="UTF-8" ?>
<testsuites name="vitest tests" tests="2" failures="1" errors="0">
  <testsuite name="src/App.test.tsx" tests="2">
    <testcase classname="src/App.test.tsx" name="App &gt; renders the title" time="0.09">
    </testcase>
    <testcase classname="src/App.test.tsx" name="App &gt; shows the total" time="0.01">
      <failure message="expected '1' to be '2'" type="AssertionError">AssertionError: expected</failure>
    </testcase>
  </testsuite>
</testsuites>
"""


def git(root, *args):
    subprocess.check_call(["git", "-C", root] + list(args), stdout=subprocess.DEVNULL)


class FakeRunner(object):
    """Stands in for both suites: results is {id: status}."""

    def __init__(self, results):
        self.results = dict(results)
        self.calls = 0

    def __call__(self, root):
        self.calls += 1
        return [gate.Result(i, s, "boom" if s == gate.FAILED else "") for i, s in self.results.items()]


class ParseTest(unittest.TestCase):
    def test_pest_ids_use_the_name_and_strip_the_container_path(self):
        results = {r.id: r for r in gate.parse_junit(PEST_XML, "pest")}
        self.assertEqual(results[A + "::it adds"].status, gate.PASSED)
        self.assertIn(A + "::it redacts with data set \"('password')\"", results)
        self.assertIn("backend/tests/Unit/LegacyTest.php::testLegacy", results)
        self.assertEqual(results[A + "::it waits"].status, gate.SKIPPED)
        failed = results[A + "::it fails"]
        self.assertEqual((failed.status, failed.message), (gate.FAILED, "Failed asserting that 1 is 2."))
        # A missing class is an <error>, not a <failure>: the usual red of the backend.
        probes = results[A + "::it probes"]
        self.assertEqual((probes.status, probes.message), (gate.FAILED, 'Error: Class "App\\GateProbe" not found'))

    def test_vitest_ids_use_classname_and_the_failure_message(self):
        results = {r.id: r for r in gate.parse_junit(VITEST_XML, "vitest")}
        self.assertEqual(results[APP + "::App > renders the title"].status, gate.PASSED)
        failed = results[APP + "::App > shows the total"]
        self.assertEqual((failed.status, failed.message), (gate.FAILED, "expected '1' to be '2'"))

    def test_invalid_xml_fails(self):
        with self.assertRaisesRegex(gate.GateError, "not valid XML"):
            gate.parse_junit("<testsuites>", "pest")

    def test_testcase_without_file_fails(self):
        with self.assertRaisesRegex(gate.GateError, "without name or file"):
            gate.parse_junit('<testsuites><testcase name="x"/></testsuites>', "pest")

    def test_vitest_testcase_without_classname_fails(self):
        with self.assertRaisesRegex(gate.GateError, "without name or classname"):
            gate.parse_junit('<testsuites><testcase name="x"/></testsuites>', "vitest")

    def test_duplicate_ids_fail(self):
        with self.assertRaisesRegex(gate.GateError, "duplicate test id"):
            gate.index([gate.Result("x::a", gate.PASSED), gate.Result("x::a", gate.FAILED)])

    def test_an_id_with_a_newline_fails(self):
        # The baseline holds one id per line.
        with self.assertRaisesRegex(gate.GateError, "test id with a newline"):
            gate.index([gate.Result("x::a\nb", gate.PASSED)])


class NotATestTest(unittest.TestCase):
    def test_vitest_reports_that_are_not_tests(self):
        ids = {
            "frontend/src/Foo.test.tsx::src/Foo.test.tsx",
            "frontend/vitest unhandled errors::Error: boom",
            APP + "::App",
            APP + "::App > renders the title",
            "frontend/src/B.test.tsx::Outer",
            "frontend/src/B.test.tsx::Outer > Inner > works",
            "frontend/src/B.test.tsx::Inner",
        }
        self.assertEqual(gate.not_a_test("frontend/src/Foo.test.tsx::src/Foo.test.tsx", ids), "the file did not load")
        self.assertEqual(gate.not_a_test("frontend/vitest unhandled errors::Error: boom", ids), "an unhandled error")
        self.assertIn("beforeAll", gate.not_a_test(APP + "::App", ids))
        self.assertIn("beforeAll", gate.not_a_test("frontend/src/B.test.tsx::Inner", ids))

    def test_real_tests_are_tests(self):
        ids = {APP + "::App > renders the title", APP + "::renders", A + "::App"}
        for test_id in ids:
            with self.subTest(test_id=test_id):
                self.assertIsNone(gate.not_a_test(test_id, ids))


class DockerRunnerTest(unittest.TestCase):
    def test_pest_ignores_an_untracked_compose_override(self):
        root = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, root)
        commands = []

        def execute(cmd, cwd):
            commands.append(cmd)
            xml, path = (PEST_XML, gate.PEST_XML) if cmd[0:2] == ["docker", "compose"] else (VITEST_XML, gate.VITEST_XML)
            with open(os.path.join(root, path), "w") as fh:
                fh.write(xml)
            return 1

        gate.docker_runner(root, execute)
        self.assertEqual(commands[0][:4], ["docker", "compose", "-f", "compose.yaml"])


class RunSuiteTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.xml = os.path.join(self.tmp, "out", "pest.xml")

    def tearDown(self):
        shutil.rmtree(self.tmp)

    def execute(self, code, xml):
        def run(cmd, cwd):
            if xml is not None:
                with open(self.xml, "w") as fh:
                    fh.write(xml)
            return code
        return run

    def test_reads_the_junit_of_a_failing_run(self):
        results = gate.run_suite([], self.tmp, self.xml, "pest", self.execute(1, PEST_XML))
        self.assertEqual(len(results), 6)

    def test_missing_junit_fails(self):
        with self.assertRaisesRegex(gate.GateError, "wrote no JUnit"):
            gate.run_suite([], self.tmp, self.xml, "pest", self.execute(255, None))

    def test_stale_junit_from_an_earlier_run_is_not_reused(self):
        os.makedirs(os.path.dirname(self.xml))
        with open(self.xml, "w") as fh:
            fh.write(PEST_XML)
        with self.assertRaisesRegex(gate.GateError, "wrote no JUnit"):
            gate.run_suite([], self.tmp, self.xml, "pest", self.execute(0, None))

    def test_nonzero_exit_without_a_failing_test_fails(self):
        ok = '<testsuites><testcase name="a" file="tests/A.php::a"/></testsuites>'
        with self.assertRaisesRegex(gate.GateError, "reported no failing test"):
            gate.run_suite([], self.tmp, self.xml, "pest", self.execute(2, ok))


class GateTest(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp()
        git(self.root, "init", "-q")
        git(self.root, "config", "user.email", "t@example.com")
        git(self.root, "config", "user.name", "t")
        self.put(".gitignore", ".gate/\n")
        self.put(A, "a1")
        self.put(APP, "app1")
        self.put("backend/phpunit.xml", "<phpunit/>")
        self.put("compose.yaml", "services: {}")
        self.put("backend/composer.json", "{}")
        self.put(PROD, "v1")
        self.commit()
        self.runner = FakeRunner({A + "::adds": gate.PASSED, APP + "::renders": gate.PASSED})
        gate.cmd_baseline(self.root, self.runner)
        self.commit()

    def tearDown(self):
        shutil.rmtree(self.root)

    def put(self, path, content):
        full = os.path.join(self.root, path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w") as fh:
            fh.write(content)

    def commit(self):
        git(self.root, "add", "-A")
        git(self.root, "commit", "-q", "--allow-empty", "-m", "x")

    def baseline(self):
        return gate.read_baseline(self.root)

    def assertGate(self, fn, message, *args):
        with self.assertRaisesRegex(gate.GateError, message):
            fn(self.root, self.runner, *args)

    def tester_adds_failing_test(self):
        self.put(A, "a2")
        self.runner.results[A + "::subtracts"] = gate.FAILED

    # baseline

    def test_baseline_lists_every_id_sorted(self):
        with open(os.path.join(self.root, gate.BASELINE)) as fh:
            self.assertEqual(fh.read(), A + "::adds\n" + APP + "::renders\n")

    def test_baseline_refuses_a_protected_file_that_differs_from_head(self):
        self.put(B, "new")
        self.assertGate(gate.cmd_baseline, "differs from HEAD: " + B)

    def test_red_without_a_baseline_says_how_to_create_it(self):
        os.remove(os.path.join(self.root, gate.BASELINE))
        self.assertGate(gate.cmd_red, "no baseline")

    def test_baseline_refuses_a_failing_suite(self):
        self.runner.results[A + "::adds"] = gate.FAILED
        self.assertGate(gate.cmd_baseline, "not passing: " + A + "::adds")

    # red

    def test_red_saves_head_hashes_and_new_ids(self):
        self.tester_adds_failing_test()
        gate.cmd_red(self.root, self.runner)
        state = __import__("json").load(open(gate.state_path(self.root)))
        self.assertEqual(state["new"], [A + "::subtracts"])
        self.assertEqual(state["head"], gate.head_sha(self.root))
        self.assertEqual(set(state["hashes"]),
                         {A, APP, "backend/phpunit.xml", "backend/composer.json", "compose.yaml", gate.BASELINE})
        self.assertEqual(state["failures"], {A + "::subtracts": "boom"})

    def test_red_needs_a_new_test(self):
        self.assertGate(gate.cmd_red, "at least one new or modified failing test")

    def test_red_rejects_a_new_test_that_already_passes(self):
        self.tester_adds_failing_test()
        self.runner.results[A + "::subtracts"] = gate.PASSED
        self.assertGate(gate.cmd_red, "passes before the code exists: " + A + "::subtracts")

    def test_red_rejects_a_skipped_new_test(self):
        self.tester_adds_failing_test()
        self.runner.results[A + "::subtracts"] = gate.SKIPPED
        self.assertGate(gate.cmd_red, "new test skipped")

    def test_red_needs_a_failing_test_besides_the_declared_controls(self):
        self.put(A, "a2")
        self.runner.results[A + "::rolls back"] = gate.PASSED
        self.assertGate(gate.cmd_red, "at least one new or modified failing test", [A + "::rolls back"])

    def test_red_rejects_vitest_reports_that_are_not_tests(self):
        self.put("frontend/src/Foo.test.tsx", "import './Foo'")
        self.runner.results["frontend/src/Foo.test.tsx::src/Foo.test.tsx"] = gate.FAILED
        self.assertGate(gate.cmd_red, r"not a test \(the file did not load\)")

    def test_lock_rejects_vitest_reports_that_are_not_tests(self):
        self.put(APP, "app2")
        self.runner.results["frontend/vitest unhandled errors::Error: boom"] = gate.FAILED
        self.assertGate(gate.cmd_lock, r"not a test \(an unhandled error\)")

    def test_red_refuses_to_overwrite_a_state_without_replace(self):
        self.tester_adds_failing_test()
        gate.cmd_red(self.root, self.runner)
        self.assertGate(gate.cmd_red, "a red state already exists")
        self.assertGate(gate.cmd_lock, "a red state already exists")

    def test_red_replace_says_which_state_it_overwrote(self):
        self.tester_adds_failing_test()
        gate.cmd_red(self.root, self.runner)
        out = gate.cmd_red(self.root, self.runner, [], [], True)
        self.assertIn("replaced a red state", out)

    def test_red_needs_each_removed_test_declared(self):
        self.tester_adds_failing_test()
        del self.runner.results[A + "::adds"]
        self.assertGate(gate.cmd_red, "test removed without --allow-removed: " + A + "::adds")

    def test_red_rejects_allow_removed_on_a_test_that_is_still_there(self):
        self.tester_adds_failing_test()
        self.assertGate(gate.cmd_red, "--allow-removed is not a removed test id: " + A + "::adds",
                        [], [A + "::adds"])

    def test_red_lists_removed_tests_and_changed_files(self):
        self.tester_adds_failing_test()
        del self.runner.results[A + "::adds"]
        out = gate.cmd_red(self.root, self.runner, [], [A + "::adds"])
        self.assertIn("removed " + A + "::adds", out)
        self.assertIn("changed " + A, out)

    def test_red_accepts_a_declared_control_that_passes(self):
        self.tester_adds_failing_test()
        self.runner.results[A + "::rolls back"] = gate.PASSED
        gate.cmd_red(self.root, self.runner, [A + "::rolls back"])

    def test_red_rejects_a_declared_control_that_fails(self):
        self.tester_adds_failing_test()
        self.runner.results[A + "::rolls back"] = gate.FAILED
        self.assertGate(gate.cmd_red, "declared --expect-pass but failed", [A + "::rolls back"])

    def test_red_rejects_expect_pass_on_an_unknown_or_old_id(self):
        self.tester_adds_failing_test()
        self.assertGate(gate.cmd_red, "not a new test id: " + A + "::adds", [A + "::adds"])

    def test_red_rejects_an_existing_test_failing_in_an_unchanged_file(self):
        self.tester_adds_failing_test()
        self.runner.results[APP + "::renders"] = gate.FAILED
        self.assertGate(gate.cmd_red, "existing test not passing: " + APP)

    def test_red_accepts_a_modified_test_that_now_fails(self):
        self.put(A, "a2")
        self.runner.results[A + "::adds"] = gate.FAILED
        gate.cmd_red(self.root, self.runner)

    def test_red_rejects_a_test_that_vanished_without_its_file_changing(self):
        self.tester_adds_failing_test()
        del self.runner.results[APP + "::renders"]
        self.assertGate(gate.cmd_red, "disappeared but its file did not change")

    def test_red_accepts_a_test_removed_from_a_changed_file(self):
        self.tester_adds_failing_test()
        del self.runner.results[A + "::adds"]
        gate.cmd_red(self.root, self.runner, [], [A + "::adds"])

    def test_red_sees_untracked_staged_and_deleted_test_files_as_changed(self):
        self.put(B, "b1")
        git(self.root, "add", B)
        self.runner.results[B + "::new"] = gate.FAILED
        untracked = "backend/tests/Unit/CTest.php"
        self.put(untracked, "c1")
        self.runner.results[untracked + "::new"] = gate.FAILED
        del self.runner.results[A + "::adds"]
        os.remove(os.path.join(self.root, A))
        out = gate.cmd_red(self.root, self.runner, [], [A + "::adds"])
        for path in (A, B, untracked):
            self.assertIn("changed " + path, out)

    # green

    def red_then_code(self, *expect_pass):
        self.tester_adds_failing_test()
        gate.cmd_red(self.root, self.runner, list(expect_pass))
        self.put(PROD, "v2")
        self.runner.results[A + "::subtracts"] = gate.PASSED

    def test_green_updates_the_baseline_and_consumes_the_state(self):
        self.red_then_code()
        gate.cmd_green(self.root, self.runner)
        self.assertIn(A + "::subtracts", self.baseline())
        self.assertFalse(os.path.exists(gate.state_path(self.root)))

    def test_green_reports_the_mode_and_the_red_it_came_from(self):
        self.red_then_code()
        out = gate.cmd_green(self.root, self.runner)
        self.assertIn("green after red", out)
        self.assertIn(A + "::subtracts | red was: boom", out)

    def test_green_respects_a_test_removed_at_red(self):
        self.tester_adds_failing_test()
        del self.runner.results[A + "::adds"]
        gate.cmd_red(self.root, self.runner, [], [A + "::adds"])
        self.runner.results[A + "::subtracts"] = gate.PASSED
        out = gate.cmd_green(self.root, self.runner)
        self.assertNotIn(A + "::adds", self.baseline())
        self.assertIn("removed " + A + "::adds", out)

    def test_green_rejects_an_edited_compose_file(self):
        self.red_then_code()
        self.put("compose.yaml", "services: {php: {volumes: ['./weak:/var/www/backend/tests']}}")
        self.assertGate(gate.cmd_green, "edited after red: compose.yaml")

    def test_green_rejects_an_edited_composer_json(self):
        self.red_then_code()
        self.put("backend/composer.json", '{"autoload-dev": {"files": ["app/FakeSqlAssertions.php"]}}')
        self.assertGate(gate.cmd_green, "edited after red: backend/composer.json")

    def test_green_needs_red_first(self):
        self.assertGate(gate.cmd_green, "run red or lock first")

    def test_green_rejects_an_edited_test(self):
        self.red_then_code()
        self.put(A, "a3")
        self.assertGate(gate.cmd_green, "edited after red: " + A)

    def test_green_rejects_an_added_test_file(self):
        self.red_then_code()
        self.put("frontend/src/Extra.test.ts", "x")
        self.assertGate(gate.cmd_green, "added after red: frontend/src/Extra.test.ts")

    def test_green_rejects_a_deleted_test_file(self):
        self.red_then_code()
        os.remove(os.path.join(self.root, APP))
        self.assertGate(gate.cmd_green, "deleted after red: " + APP)

    def test_green_rejects_an_edited_test_config(self):
        self.red_then_code()
        self.put("backend/phpunit.xml", "<phpunit><testsuites/></phpunit>")
        self.assertGate(gate.cmd_green, "edited after red: backend/phpunit.xml")

    def test_green_rejects_an_edited_baseline(self):
        self.red_then_code()
        self.put(gate.BASELINE, A + "::adds\n")
        self.assertGate(gate.cmd_green, "edited after red: " + gate.BASELINE)

    def test_green_rejects_an_id_that_was_not_there_at_red(self):
        self.red_then_code()
        self.runner.results[A + "::adds with data set 3"] = gate.PASSED
        self.assertGate(gate.cmd_green, "unexpected test id: " + A + "::adds with data set 3")

    def test_green_rejects_an_id_that_vanished_after_red(self):
        self.red_then_code()
        del self.runner.results[A + "::subtracts"]
        self.assertGate(gate.cmd_green, "missing test id: " + A + "::subtracts")

    def test_green_rejects_a_failing_test(self):
        self.red_then_code()
        self.runner.results[A + "::subtracts"] = gate.FAILED
        self.assertGate(gate.cmd_green, "not passing: " + A + "::subtracts")

    def test_green_rejects_a_skipped_test(self):
        self.red_then_code()
        self.runner.results[APP + "::renders"] = gate.SKIPPED
        self.assertGate(gate.cmd_green, "not passing: " + APP)

    def test_green_rejects_a_moved_head(self):
        self.red_then_code()
        self.commit()
        self.assertGate(gate.cmd_green, "HEAD moved")

    def test_green_keeps_the_state_when_it_fails(self):
        self.red_then_code()
        self.runner.results[A + "::subtracts"] = gate.FAILED
        self.assertGate(gate.cmd_green, "not passing")
        self.assertTrue(os.path.exists(gate.state_path(self.root)))

    # lock

    def test_lock_then_green_for_a_test_only_step(self):
        self.put(A, "a2")
        self.runner.results[A + "::guards"] = gate.PASSED
        gate.cmd_lock(self.root, self.runner)
        out = gate.cmd_green(self.root, self.runner)
        self.assertIn(A + "::guards", self.baseline())
        self.assertIn("green after lock", out)
        self.assertIn(A + "::guards | passed at lock", out)

    def test_lock_rejects_a_failing_test(self):
        self.put(A, "a2")
        self.runner.results[A + "::guards"] = gate.FAILED
        self.assertGate(gate.cmd_lock, "lock needs every test green")

    def test_lock_rejects_a_modified_test_that_fails(self):
        self.put(A, "a2")
        self.runner.results[A + "::adds"] = gate.FAILED
        self.assertGate(gate.cmd_lock, "existing test not passing: " + A + "::adds")


if __name__ == "__main__":
    unittest.main()