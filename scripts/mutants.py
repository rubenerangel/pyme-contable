#!/usr/bin/env python3
"""SQL mutation runner: proves that the database tests catch a broken schema.

    python3 scripts/mutants.py            # every mutation
    python3 scripts/mutants.py NAME ...   # only these

A mutation is one migration in backend/tests/database/mutations/<name>/, named
9999_12_31_*.php so it runs after every real one. RefreshDatabaseAsOwner and
DatabaseTruncationAsOwner load it with --path when MUTATION=<name> is set.
Its header lists the tests that must fail with it, one per line:

    // kills: backend/tests/Integration/AccountTest.php::it rejects ...

A control mutation also carries "// control: survives": its tests must pass.
It proves the runner tells a surviving mutation from a killed one.

For each mutation the runner runs MutationAppliedTest (first, so it migrates
with the mutation before anything else) plus the files of the listed tests,
and requires MutationAppliedTest to pass: a mutation whose SQL fails would
otherwise error every test and count as killed. At the end it migrates again
without MUTATION, so no mutated schema is left in pyme_test.

Mutations that need a superuser (GRANT ON PARAMETER, ALTER ROLE of another
role, ALTER DATABASE) cannot go here: migrations run as pyme_owner.
Compatible with Python 3.8 (Ubuntu 20.04).
"""
import argparse
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gate  # noqa: E402

MUTATIONS = "backend/tests/database/mutations"
MUTANTS_XML = "backend/.gate/mutants.xml"
APPLIED_FILE = "backend/tests/Integration/MutationAppliedTest.php"
APPLIED_ID = APPLIED_FILE + "::it migrates exactly the mutation named in MUTATION and no other"
NAME = re.compile(r"^[a-z0-9_]+$")
KILLS = re.compile(r"^// kills: (\S.*)$")
CONTROL = "// control: survives"
# A test without one of these traits reads whatever schema the last migrate left.
OWNER_TRAITS = ("RefreshDatabaseAsOwner", "DatabaseTruncationAsOwner")


class Mutation(object):
    def __init__(self, name, kills, control):
        self.name = name
        self.kills = kills
        self.control = control

    def files(self):
        seen = []
        for test_id in self.kills:
            path = gate.file_of(test_id)
            if path not in seen:
                seen.append(path)
        return seen


def _uses_owner_trait(root, path):
    if path.startswith("backend/tests/Feature/"):
        return True  # Pest.php applies RefreshDatabaseAsOwner to all of Feature.
    with open(os.path.join(root, path), encoding="utf-8") as fh:
        text = fh.read()
    return any(trait in text for trait in OWNER_TRAITS)


def load(root, name):
    if not NAME.match(name):
        raise gate.GateError("%s: the name must match [a-z0-9_]+" % name)
    folder = os.path.join(root, MUTATIONS, name)
    if not os.path.isdir(folder):
        raise gate.GateError("%s: %s/%s does not exist" % (name, MUTATIONS, name))
    files = sorted(os.listdir(folder))
    if len(files) != 1 or not re.match(r"^9999_12_31_\w+\.php$", files[0]):
        raise gate.GateError("%s: needs exactly one file named 9999_12_31_*.php, found %s" % (name, files))
    kills, control = [], False
    with open(os.path.join(folder, files[0]), encoding="utf-8") as fh:
        for line in fh:
            line = line.rstrip("\n")
            match = KILLS.match(line)
            if match:
                kills.append(match.group(1).rstrip())
            elif line.strip() == CONTROL:
                control = True
    if not kills:
        raise gate.GateError("%s: no '// kills: <test id>' line" % name)
    if len(set(kills)) != len(kills):
        raise gate.GateError("%s: a test id is listed twice" % name)
    for test_id in kills:
        path = gate.file_of(test_id)
        if "::" not in test_id or not path.startswith("backend/tests/"):
            raise gate.GateError("%s: not a Pest test id: %s" % (name, test_id))
        if path == APPLIED_FILE:
            raise gate.GateError("%s: MutationAppliedTest cannot be a killer" % name)
        if not os.path.isfile(os.path.join(root, path)):
            raise gate.GateError("%s: %s does not exist" % (name, path))
        if not _uses_owner_trait(root, path):
            raise gate.GateError("%s: %s does not use %s" % (name, path, " or ".join(OWNER_TRAITS)))
    return Mutation(name, kills, control)


def discover(root):
    folder = os.path.join(root, MUTATIONS)
    if not os.path.isdir(folder):
        return []
    return sorted(n for n in os.listdir(folder) if os.path.isdir(os.path.join(folder, n)))


def pest_command(mutation, files):
    cmd = ["docker", "compose", "-f", "compose.yaml", "run", "--rm", "-T", "-u", "www-data"]
    if mutation is not None:
        cmd += ["-e", "MUTATION=" + mutation]
    paths = [p[len("backend/"):] for p in [APPLIED_FILE] + list(files)]
    return cmd + ["php", "vendor/bin/pest"] + paths + ["--log-junit", ".gate/mutants.xml"]


def docker_pest(root, mutation, files, execute=gate._execute):
    """Run MutationAppliedTest plus files with MUTATION set (None: unset)."""
    return gate.run_suite(pest_command(mutation, files), root, os.path.join(root, MUTANTS_XML), "pest", execute)


def _applied(by_id, label):
    applied = by_id.get(APPLIED_ID)
    if applied is None:
        raise gate.GateError("%s: MutationAppliedTest did not run" % label)
    if applied.status != gate.PASSED:
        raise gate.GateError("%s: MutationAppliedTest did not pass: %s" % (label, applied.message))


def check(mutation, results):
    """Return the report lines of one mutation, or raise if it is not run as expected."""
    by_id = gate.index(results)
    _applied(by_id, mutation.name)
    lines, problems = [], []
    for test_id in mutation.kills:
        r = by_id.get(test_id)
        if r is None:
            problems.append("not run: %s" % test_id)
        elif mutation.control and r.status != gate.PASSED:
            problems.append("control killed by %s: %s" % (test_id, r.message))
        elif not mutation.control and r.status != gate.FAILED:
            problems.append("SURVIVED %s (%s)" % (test_id, r.status))
        elif mutation.control:
            lines.append("  survived %s" % test_id)
        else:
            lines.append("  killed by %s: %s" % (test_id, r.message))
    if problems:
        raise gate.GateError("%s:\n  %s" % (mutation.name, "\n  ".join(problems)))
    return lines


def run(root, names, pest=docker_pest):
    names = list(names) or discover(root)
    if not names:
        raise gate.GateError("no mutations in %s" % MUTATIONS)
    mutations = [load(root, n) for n in names]
    report, failures = [], []
    for m in mutations:
        try:
            lines = check(m, pest(root, m.name, m.files()))
            report.append("%s %s" % ("CONTROL" if m.control else "KILLED", m.name))
            report += lines
        except gate.GateError as exc:
            failures.append(str(exc))
    # Leave pyme_test as a normal run expects it, also after a failed mutation.
    try:
        _applied(gate.index(pest(root, None, [])), "restore")
    except gate.GateError as exc:
        failures.append(str(exc))
    if failures:
        raise gate.GateError("\n".join(failures))
    return "\n".join(report + ["%d mutations, all as expected" % len(mutations)])


def main(argv=None, pest=docker_pest):
    parser = argparse.ArgumentParser(prog="mutants.py", description=__doc__.split("\n\n")[0])
    parser.add_argument("names", nargs="*", metavar="NAME")
    args = parser.parse_args(argv)
    root = gate._git(os.getcwd(), "rev-parse", "--show-toplevel").decode().strip()
    try:
        out = run(root, args.names, pest)
    except gate.GateError as exc:
        print("MUTANTS FAILED\n%s" % exc, file=sys.stderr)
        return 1
    print(out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
