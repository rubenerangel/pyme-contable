#!/usr/bin/env python3
"""Deterministic TDD gate between the tester and the coder.

    python3 scripts/gate.py baseline   # once, on a clean tree with the suite green
    python3 scripts/gate.py red [--expect-pass ID ...] [--allow-removed ID ...] [--replace]
    python3 scripts/gate.py lock [--allow-removed ID ...] [--replace]   # test-only steps
    python3 scripts/gate.py green

red and lock run both suites, check the result and save the sha256 of every
protected file in .gate/red.json. green requires the same HEAD, the same hashes,
exactly the expected test ids and every one of them passing; then it rewrites
the baseline and removes .gate/red.json.

A test id is "<repo path>::<name>", taken from the JUnit XML of Pest and Vitest.
"New" means not in the baseline (scripts/gate-baseline.txt), the ids of the last
green run.

Limits: the coder runs as the same OS user, so it could rewrite .gate/red.json.
The gate catches a lazy shortcut, not an adversarial agent. Factories are test
inputs the coder still owns: they are not hashed.
Compatible with Python 3.8 (Ubuntu 20.04).
"""
import argparse
import fnmatch
import hashlib
import json
import os
import subprocess
import sys
import xml.etree.ElementTree as ET

BASELINE = "scripts/gate-baseline.txt"
STATE = ".gate/red.json"
PEST_XML = "backend/.gate/pest.xml"
VITEST_XML = "frontend/.gate/vitest.xml"
# Inside the php container the repo's backend/ is mounted here.
CONTAINER_BACKEND = "/var/www/backend/"
# Vitest reports these as testcases too: an unhandled error, a file that failed to
# load (named after the file) and a suite whose beforeAll or afterAll failed.
VITEST_UNHANDLED = "vitest unhandled errors"
VITEST_SEPARATOR = " > "

# Tests and the config that decides which tests run. Editing a suite list, an
# include or a volume that mounts other tests is the cheap way to go green
# without touching a test.
PROTECTED = (
    "backend/tests/*",
    "backend/phpunit.xml",
    # autoload-dev files can load a class before the test helper of the same name.
    "backend/composer.json",
    "compose.yaml",
    "frontend/src/*.test.*",
    "frontend/src/test/*",
    "frontend/vite.config.*",
    "frontend/vitest.config.*",
    BASELINE,
)

PASSED, FAILED, SKIPPED = "passed", "failed", "skipped"


class GateError(Exception):
    """The gate does not pass. The message says why."""


class Result(object):
    def __init__(self, test_id, status, message=""):
        self.id = test_id
        self.status = status
        self.message = message

    def __repr__(self):
        return "Result(%r, %r)" % (self.id, self.status)


# --- JUnit -----------------------------------------------------------------

def _status(case):
    for tag in ("failure", "error"):
        node = case.find(tag)
        if node is not None:
            text = node.get("message") or node.text or tag
            lines = [line.strip() for line in text.strip().splitlines() if line.strip()]
            first = lines[0] if lines else tag
            # Pest starts the text with the test name: "it probes the gateError: ...".
            name = case.get("name") or ""
            if name and first.startswith(name) and len(first) > len(name):
                first = first[len(name):].strip()
            return FAILED, first[:200]
    if case.find("skipped") is not None:
        return SKIPPED, ""
    return PASSED, ""


def _pest_id(case):
    name = case.get("name")
    path = (case.get("file") or "").split("::", 1)[0]
    if path.startswith(CONTAINER_BACKEND):
        path = path[len(CONTAINER_BACKEND):]
    if not name or not path:
        raise GateError("Pest testcase without name or file: %r" % case.attrib)
    # The name, not the rest of file: Pest sometimes drops the space before
    # "with data set" in file, and the name keeps it.
    return "backend/" + path + "::" + name


def _vitest_id(case):
    name = case.get("name")
    path = case.get("classname")
    if not name or not path:
        raise GateError("Vitest testcase without name or classname: %r" % case.attrib)
    return "frontend/" + path + "::" + name


def parse_junit(xml_text, kind):
    make_id = _pest_id if kind == "pest" else _vitest_id
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError as exc:
        raise GateError("%s JUnit is not valid XML: %s" % (kind, exc))
    results = []
    for case in root.iter("testcase"):
        status, message = _status(case)
        results.append(Result(make_id(case), status, message))
    return results


def run_suite(cmd, cwd, xml_path, kind, execute):
    """Run one suite and parse its JUnit. execute(cmd, cwd) returns the exit code."""
    if os.path.exists(xml_path):
        os.remove(xml_path)
    os.makedirs(os.path.dirname(xml_path), exist_ok=True)
    code = execute(cmd, cwd)
    if not os.path.exists(xml_path):
        raise GateError("%s wrote no JUnit (exit %d): the suite did not run" % (kind, code))
    with open(xml_path, encoding="utf-8") as fh:
        results = parse_junit(fh.read(), kind)
    if code != 0 and not any(r.status == FAILED for r in results):
        # A bootstrap error, a file that fails to load or a hook outside any test.
        raise GateError("%s exited with %d but reported no failing test" % (kind, code))
    return results


def _execute(cmd, cwd):
    return subprocess.call(cmd, cwd=cwd)


def docker_runner(root, execute=_execute):
    uidgid = "%d:%d" % (os.getuid(), os.getgid())
    # -f keeps an untracked compose.override.yaml out of the run.
    pest = ["docker", "compose", "-f", "compose.yaml", "run", "--rm", "-T", "-u", "www-data", "php",
            "vendor/bin/pest", "--log-junit", ".gate/pest.xml"]
    vitest = ["docker", "run", "--rm", "-u", uidgid, "-e", "HOME=/tmp",
              "-e", "npm_config_update_notifier=false",
              "-v", os.path.join(root, "frontend") + ":/app", "-w", "/app",
              "node:24-bookworm-slim", "node_modules/.bin/vitest", "run",
              "--reporter=default", "--reporter=junit", "--outputFile.junit=.gate/vitest.xml"]
    results = run_suite(pest, root, os.path.join(root, PEST_XML), "pest", execute)
    results += run_suite(vitest, root, os.path.join(root, VITEST_XML), "vitest", execute)
    return results


def not_a_test(test_id, ids):
    """Why a Vitest testcase is not a test, or None."""
    if not test_id.startswith("frontend/"):
        return None
    path, name = test_id.split("::", 1)
    if path == "frontend/" + VITEST_UNHANDLED:
        return "an unhandled error"
    if "frontend/" + name == path:
        return "the file did not load"
    for other in ids:
        other_path, other_name = other.split("::", 1)
        if other_path == path and other != test_id and (
                other_name.startswith(name + VITEST_SEPARATOR)
                or VITEST_SEPARATOR + name + VITEST_SEPARATOR in other_name):
            return "a suite whose beforeAll or afterAll failed"
    return None


def index(results):
    by_id = {}
    for r in results:
        if r.id in by_id:
            raise GateError("duplicate test id, rename one: %s" % r.id)
        if "\n" in r.id:
            raise GateError("test id with a newline: %r" % r.id)
        by_id[r.id] = r
    return by_id


def file_of(test_id):
    return test_id.split("::", 1)[0]


# --- git and hashes ----------------------------------------------------------

def _git(root, *args):
    return subprocess.check_output(["git", "-C", root] + list(args))


def is_protected(path):
    return any(fnmatch.fnmatchcase(path, pattern) for pattern in PROTECTED)


def _split_z(output):
    return [p for p in output.decode("utf-8").split("\0") if p]


def head_sha(root):
    return _git(root, "rev-parse", "HEAD").decode().strip()


def protected_hashes(root):
    """sha256 of every protected file in the work tree, tracked or untracked."""
    hashes = {}
    for path in _split_z(_git(root, "ls-files", "-z", "-c", "-o", "--exclude-standard")):
        full = os.path.join(root, path)
        if is_protected(path) and os.path.isfile(full):
            with open(full, "rb") as fh:
                hashes[path] = hashlib.sha256(fh.read()).hexdigest()
    return hashes


def changed_protected(root):
    """Protected files that differ from HEAD: staged, unstaged, deleted or untracked."""
    tracked = _split_z(_git(root, "diff", "--no-renames", "--name-only", "-z", "HEAD", "--"))
    untracked = _split_z(_git(root, "ls-files", "-z", "-o", "--exclude-standard"))
    return {p for p in tracked + untracked if is_protected(p)}


def diff_hashes(saved, current):
    problems = []
    for path in sorted(set(saved) | set(current)):
        if path not in current:
            problems.append("deleted after red: " + path)
        elif path not in saved:
            problems.append("added after red: " + path)
        elif saved[path] != current[path]:
            problems.append("edited after red: " + path)
    return problems


# --- baseline and state ------------------------------------------------------

def read_baseline(root):
    path = os.path.join(root, BASELINE)
    if not os.path.exists(path):
        raise GateError("no baseline: run `python3 scripts/gate.py baseline` on a clean tree")
    with open(path, encoding="utf-8") as fh:
        return {line.rstrip("\n") for line in fh if line.strip()}


def write_baseline(root, ids):
    path = os.path.join(root, BASELINE)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        for test_id in sorted(ids):
            fh.write(test_id + "\n")


def state_path(root):
    return os.path.join(root, STATE)


# --- commands ----------------------------------------------------------------

def _fail(problems):
    if problems:
        raise GateError("\n".join(problems))


def cmd_baseline(root, runner):
    changed = changed_protected(root)
    _fail(["protected file differs from HEAD: " + p for p in sorted(changed)])
    by_id = index(runner(root))
    _fail(["not passing: %s (%s)" % (r.id, r.status) for r in by_id.values() if r.status != PASSED])
    write_baseline(root, by_id)
    return "baseline: %d tests" % len(by_id)


def _read_state(root):
    with open(state_path(root), encoding="utf-8") as fh:
        return json.load(fh)


def _snapshot(root, runner, mode, expect_pass, allow_removed, replace):
    replaced = None
    if os.path.exists(state_path(root)):
        replaced = _read_state(root)["mode"]
        if not replace:
            raise GateError("a %s state already exists: run green, or %s --replace" % (replaced, mode))
    baseline = read_baseline(root)
    changed = changed_protected(root)
    by_id = index(runner(root))
    new = sorted(set(by_id) - baseline)
    removed = sorted(baseline - set(by_id))
    problems = []

    for test_id in new:
        reason = not_a_test(test_id, by_id)
        if reason:
            problems.append("not a test (%s), make its tests run: %s" % (reason, test_id))
    for test_id in removed:
        if file_of(test_id) not in changed:
            problems.append("test disappeared but its file did not change: " + test_id)
        elif test_id not in allow_removed:
            problems.append("test removed without --allow-removed: " + test_id)
    for test_id in sorted(allow_removed):
        if test_id not in removed:
            problems.append("--allow-removed is not a removed test id: " + test_id)
    for test_id in sorted(expect_pass):
        if test_id not in by_id or test_id in baseline:
            problems.append("--expect-pass is not a new test id: " + test_id)

    modified_red = []
    for test_id in sorted(baseline & set(by_id)):
        r = by_id[test_id]
        if r.status == PASSED:
            continue
        if mode == "red" and r.status == FAILED and file_of(test_id) in changed:
            modified_red.append(test_id)
        else:
            problems.append("existing test not passing: %s (%s)" % (test_id, r.status))

    for test_id in new:
        r = by_id[test_id]
        if r.status == SKIPPED:
            problems.append("new test skipped: " + test_id)
        elif mode == "lock" and r.status != PASSED:
            problems.append("lock needs every test green, use red: %s" % test_id)
        elif mode == "red" and test_id in expect_pass and r.status != PASSED:
            problems.append("declared --expect-pass but %s: %s" % (r.status, test_id))
        elif mode == "red" and test_id not in expect_pass and r.status != FAILED:
            problems.append("new test passes before the code exists: " + test_id)

    new_red = [t for t in new if t not in expect_pass and by_id[t].status == FAILED]
    if mode == "red" and not new_red and not modified_red:
        problems.append("red needs at least one new or modified failing test")
    _fail(problems)

    state = {
        "mode": mode,
        "head": head_sha(root),
        "hashes": protected_hashes(root),
        "new": new,
        "removed": removed,
        "modified_red": modified_red,
        "failures": {t: by_id[t].message for t in new + modified_red if by_id[t].status == FAILED},
    }
    os.makedirs(os.path.dirname(state_path(root)), exist_ok=True)
    with open(state_path(root), "w", encoding="utf-8") as fh:
        json.dump(state, fh, indent=2, sort_keys=True)
    lines = ["%s: %d new, %d modified red, %d removed, %d protected files hashed"
             % (mode, len(new), len(modified_red), len(removed), len(state["hashes"]))]
    if replaced:
        lines.append("  replaced a %s state" % replaced)
    for test_id in new + modified_red:
        r = by_id[test_id]
        lines.append("  %s %s%s" % (r.status, test_id, (" | " + r.message) if r.message else ""))
    lines += ["  removed " + t for t in removed]
    lines += ["  changed " + p for p in sorted(changed)]
    return "\n".join(lines)


def cmd_red(root, runner, expect_pass=(), allow_removed=(), replace=False):
    return _snapshot(root, runner, "red", set(expect_pass), set(allow_removed), replace)


def cmd_lock(root, runner, allow_removed=(), replace=False):
    return _snapshot(root, runner, "lock", set(), set(allow_removed), replace)


def cmd_green(root, runner):
    if not os.path.exists(state_path(root)):
        raise GateError("no %s: run red or lock first" % STATE)
    state = _read_state(root)
    if head_sha(root) != state["head"]:
        raise GateError("HEAD moved since %s: run it again" % state["mode"])
    _fail(diff_hashes(state["hashes"], protected_hashes(root)))

    baseline = read_baseline(root)
    by_id = index(runner(root))
    expected = (baseline - set(state["removed"])) | set(state["new"])
    problems = ["unexpected test id: " + t for t in sorted(set(by_id) - expected)]
    problems += ["missing test id: " + t for t in sorted(expected - set(by_id))]
    problems += ["not passing: %s (%s)%s" % (r.id, r.status, (" | " + r.message) if r.message else "")
                 for r in sorted(by_id.values(), key=lambda r: r.id) if r.status != PASSED]
    _fail(problems)

    write_baseline(root, by_id)
    os.remove(state_path(root))
    # What the state claimed, so the architect sees a lock where a red was expected.
    lines = ["green after %s at %s: %d tests, baseline updated"
             % (state["mode"], state["head"][:12], len(by_id))]
    for test_id in state["new"] + state["modified_red"]:
        failure = state["failures"].get(test_id)
        lines.append("  %s%s" % (test_id, (" | red was: " + failure) if failure else " | passed at " + state["mode"]))
    lines += ["  removed " + t for t in state["removed"]]
    return "\n".join(lines)


def main(argv=None, runner=docker_runner):
    parser = argparse.ArgumentParser(prog="gate.py", description=__doc__.split("\n\n")[0])
    sub = parser.add_subparsers(dest="command")
    sub.required = True
    sub.add_parser("baseline")
    red = sub.add_parser("red")
    red.add_argument("--expect-pass", action="append", default=[], metavar="ID")
    lock = sub.add_parser("lock")
    for snapshot in (red, lock):
        snapshot.add_argument("--allow-removed", action="append", default=[], metavar="ID")
        snapshot.add_argument("--replace", action="store_true", help="overwrite an existing red or lock state")
    sub.add_parser("green")
    args = parser.parse_args(argv)

    root = _git(os.getcwd(), "rev-parse", "--show-toplevel").decode().strip()
    try:
        if args.command == "baseline":
            out = cmd_baseline(root, runner)
        elif args.command == "red":
            out = cmd_red(root, runner, args.expect_pass, args.allow_removed, args.replace)
        elif args.command == "lock":
            out = cmd_lock(root, runner, args.allow_removed, args.replace)
        else:
            out = cmd_green(root, runner)
    except GateError as exc:
        print("GATE %s FAILED\n%s" % (args.command.upper(), exc), file=sys.stderr)
        return 1
    print(out)
    return 0


if __name__ == "__main__":
    sys.exit(main())