#!/usr/bin/env python3
"""PreToolUse guard for subagents: write paths and Bash rules per role.

The main thread (architect) is not restricted here; its risky commands go through
permissions.ask in .claude/settings.json. Exit code 2 blocks the tool call and
sends stderr back to the agent. settings.json wraps the call with `|| exit 2`,
so a crash or a missing python3 also blocks: the guard fails closed.

Any agent with docker access is root in the containers, so this is not a sandbox.
For tester and coder the guard is a railing against mistakes: a denylist that
python -c or docker exec get around. For the reviewers it is a barrier, by
allowlist: a command that is not listed does not run. The barrier for tests
is scripts/gate.py (G2), which hashes them; here tester and coder lose every
Bash command that names the gate (running it, and also reading it: the Read
tool still works) and its state, so a mistake cannot rewrite a red run.
Compose decides what the gate runs, so it belongs to the architect.
Compatible with Python 3.8 (Ubuntu 20.04).
"""
import fnmatch
import json
import os
import re
import shlex
import sys

WRITE_TOOLS = {"Edit", "Write", "MultiEdit", "NotebookEdit"}
WRITERS = {"tester", "coder"}
DB_REVIEWERS = {"security-reviewer", "performance-reviewer"}

# frontend/src/test/ holds the Vitest setup; scripts/gate.py protects it like the tests.
TEST_FILE = re.compile(r"^(backend/tests/.+|frontend/src/test/.+|frontend/src/.+\.test\.[cm]?[jt]sx?)$")
CODER_FORBIDDEN = re.compile(
    r"^(backend/tests/|tests/|docs/|\.claude/|\.github/|scripts/|frontend/src/test/|CLAUDE\.md$)"
    r"|^frontend/vite(st)?\.config\.|\.test\.[cm]?[jt]sx?$|(^|/)phpunit\.xml$|(^|/)\.env$|(^|/)\.git(/|$)"
    r"|(^|/)\.gate(/|$)|^(docker-)?compose\.(override\.)?[^/]*$|(^|/)composer\.json$"
)
TESTER_PROTECTED = re.compile(
    r"^(backend|frontend|db|docker|docs|scripts|\.claude|\.github|\.gate)/|^(compose\.yaml|CLAUDE\.md|\.gitignore)$"
)

ENV_FILE = re.compile(r"(^|[\s/'\"=])\.env($|[\s'\"])")
GLOB_WORD_SPLIT = re.compile(r"[\s'\"=;&|()<>]+")
BRACES = re.compile(r"\{([^{}]*)\}")
PSQL = re.compile(r"\bpsql\b")
PSQL_USER = re.compile(r"(?:-U\s*|--username[= ])([\w-]+)")

GATE_REASON = "scripts/gate.py belongs to the architect"

DANGEROUS = [
    (re.compile(r"\bgit\s+(commit|push|merge|rebase|reset|checkout|switch|restore|stash|tag|cherry-pick|revert|clean)\b"),
     "git write operations belong to the architect"),
    # Not a railing: core.fsmonitor, core.hooksPath or a hook in .git/ runs in the
    # unrestricted main session the next time it uses git.
    (re.compile(r"\bgit\s+config\b"), "git config can make git run programs in the main session"),
    (re.compile(r"\bdocker\s+compose\s+down\b"), "docker compose down needs Rubén's OK"),
    (re.compile(r"\bdocker\s+(volume|system|image|container)\s+(rm|prune)\b"), "removing Docker resources"),
    # Composer accepts any unambiguous prefix of a command name (composer req).
    (re.compile(r"\bcomposer\s+(-\S+\s+)*(re\w*|u\w*|i\w*|bump|global)\b"),
     "dependency changes belong to the architect"),
    (re.compile(r"\bnpm\s+(-\S+\s+)*(i|in\w*|is\w*|add|ci|clean-install|ic|it|un\w*|r|rm|remove|up\w*|udpate"
                r"|exec|x|link|ln|dedupe|ddp|prune)\b"),
     "dependency changes belong to the architect"),
    (re.compile(r"\bnpx\b"), "npx installs and runs packages"),
    # gate.py runs red, lock and green; test_gate.py does not match (no word boundary after _).
    # Globs and quotes are caught by names_gate.
    (re.compile(r"\bgate\.py\b|\bscripts\.gate\b"), GATE_REASON),
    (re.compile(r"\bartisan\s+(-\S+\s+)*(migrate|db:)"), "migrations and db commands belong to the architect"),
    (re.compile(r"(^|[\s;&|(])rm\s+(-\S+\s+)*(-[a-zA-Z]*[rR]|--recursive)"), "recursive rm"),
]

# Global options that take a value, so `git -C . push` normalizes to `git push`.
GIT_VALUE_OPTIONS = {"-C", "-c", "--git-dir", "--work-tree", "--namespace", "--config-env", "--super-prefix"}
DOCKER_VALUE_OPTIONS = {"-c", "--context", "-H", "--host", "--config", "-l", "--log-level",
                        "--tlscacert", "--tlscert", "--tlskey"}
COMPOSE_VALUE_OPTIONS = {"-f", "--file", "-p", "--project-name", "--env-file", "--profile",
                         "--project-directory", "--ansi", "--progress", "--parallel"}

HARMLESS_REDIRECT = re.compile(r"\s\d?>&\d(?=[\s|]|$)|\s\d?>\s*/dev/null(?=[\s|]|$)")
WRITE_OP = re.compile(
    r">"
    r"|(^|[\s;&|(])(tee|mv|cp|rm|truncate|touch|ln|dd|install|patch)(\s|$)"
    r"|(^|[\s;&|(])(sed|perl)\s[^|;&]*(-[a-zA-Z]*i\b|--in-place)"
    r"|\bgit\s+(apply|checkout|restore)\b"
)

UIDGID_RAW = '"$(id -u):$(id -g)"'
# rg is out on purpose: --pre runs a program for every file it reads.
READ_ONLY = {"cat", "head", "tail", "wc", "grep", "ls"}
SAFE_FILTERS = {"head", "tail", "grep", "wc", "cut"}
GIT_READ = {"diff", "status", "log", "show", "blame", "ls-files", "rev-parse"}
COMPOSER_AUDIT = ["docker", "compose", "run", "--rm", "-u", "www-data", "php", "composer", "audit"]
NPM_AUDIT = [
    "docker", "run", "--rm", "-u", "UIDGID", "-e", "HOME=/tmp",
    "-e", "npm_config_update_notifier=false", "-v", "$PWD/frontend:/app",
    "-w", "/app", "node:24-bookworm-slim", "npm", "audit",
]
PSQL_PREFIXES = (
    ["docker", "compose", "exec", "-T", "postgres", "psql"],
    ["docker", "compose", "exec", "postgres", "psql"],
)
PSQL_DATABASES = {"pyme", "pyme_test"}
# pyme holds development data: reads only, even inside BEGIN ... ROLLBACK.
WRITABLE_DATABASE = "pyme_test"
PSQL_FLAGS = re.compile(r"^-[XAtqeEx]+$|^--no-psqlrc$")
# Only describe and display meta-commands, each on its own line. Any other
# backslash (\c, \getenv, \gexec, \!, \o, \copy, \i ...) reaches another role,
# the environment, files or the shell.
PSQL_META_LINE = re.compile(r"^\s*\\(d[a-zA-Z+]*|x|echo)(\s[^\\]*)?$")
# A bare BEGIN only: psql reading a heredoc goes on after an error, so a BEGIN
# that fails (bad options, a typo) would leave the rest in autocommit.
SQL_BEGIN = re.compile(r"^\s*(begin|start\s+transaction)\s*;", re.IGNORECASE)
SQL_ROLLBACK = re.compile(r"\brollback\b", re.IGNORECASE)
SQL_ROLLBACK_END = re.compile(r"\brollback\s*;?\s*$", re.IGNORECASE)
# Statements that end the transaction early, and syntax that hides text from these checks.
SQL_ESCAPES = re.compile(r"\b(commit|abort|prepare\s+transaction)\b|(^|;)\s*end\b|\$|--|/\*", re.IGNORECASE)
# Sequences ignore ROLLBACK, so nextval and setval count as writes.
SQL_WRITE = re.compile(
    r"\b(insert|update|delete|merge|alter|create|drop|grant|revoke|truncate|copy|comment|reindex|"
    r"vacuum|cluster|lock|call|do|refresh|nextval|setval)\b",
    re.IGNORECASE,
)
# Without dollar quotes, backslashes or comments, a quoted literal is only '...' with '' inside.
SQL_LITERAL = re.compile(r"'(?:[^']|'')*'")
HEREDOC = re.compile(r"^(.*?)\s*<<-?\s*'(\w+)'\s*$")


def is_operator(token):
    return token != "" and all(ch in "();<>|&" for ch in token)


def tokenize(command):
    lexer = shlex.shlex(command, posix=True, punctuation_chars=True)
    lexer.whitespace_split = True
    return list(lexer)


def relative_to_project(path, project_dir):
    root = os.path.realpath(project_dir)
    full = os.path.realpath(path if os.path.isabs(path) else os.path.join(root, path))
    rel = os.path.relpath(full, root)
    if rel == ".." or rel.startswith(".." + os.sep):
        return None
    return rel.replace(os.sep, "/")


def project_path(word, project_dir):
    if os.path.isabs(word):
        return relative_to_project(word, project_dir)
    while word.startswith("./"):
        word = word[2:]
    return word


def check_write(role, path, project_dir):
    if not path:
        return "write without a path"
    rel = relative_to_project(path, project_dir)
    if rel is None:
        return "writes outside the project are not allowed"
    if role == "tester":
        return None if TEST_FILE.match(rel) else "tester only writes test files"
    if role == "coder":
        if CODER_FORBIDDEN.search(rel):
            return ("coder never edits tests, test config, compose, composer.json, docs, .claude, .github, .git, .gate, "
                    "scripts, CLAUDE.md or .env")
        return None
    return "{} is read-only".format(role or "this subagent")


def expand_braces(word):
    match = BRACES.search(word)
    if not match:
        return [word]
    return [
        expanded
        for part in match.group(1).split(",")
        for expanded in expand_braces(word[:match.start()] + part + word[match.end():])
    ]


def names_env(word):
    # A wildcard never matches a leading dot, so only patterns with a literal dot count.
    for pattern in expand_braces(word):
        name = pattern.rsplit("/", 1)[-1]
        if "." in name and fnmatch.fnmatchcase(".env", name):
            return True
    return False


def names_git(word):
    """True when any path component of the word could be .git (globs and braces included)."""
    return any(
        "." in part and fnmatch.fnmatchcase(".git", part)
        for pattern in expand_braces(word)
        for part in pattern.split("/")
    )


def shell_words(command):
    """Raw words for globs and braces, and words after quote removal, where .en''v becomes .env."""
    words = GLOB_WORD_SPLIT.split(command)
    try:
        words += [part for token in tokenize(command) for part in token.split("=")]
    except ValueError:
        pass
    return words


def reaches_env(command):
    return bool(ENV_FILE.search(command)) or any(names_env(word) for word in shell_words(command))


def names_gate(word):
    for pattern in expand_braces(word):
        name = pattern.rsplit("/", 1)[-1]
        if "." in name and fnmatch.fnmatchcase("gate.py", name):
            return True
    return False


def check_sql(sql, writable):
    # psql passes backquoted text in meta-command arguments to the shell.
    if "`" in sql:
        return "backquotes are not allowed in psql input"
    statements = "\n".join(line for line in sql.split("\n") if not PSQL_META_LINE.match(line))
    if "\\" in statements:
        return "psql meta-command not allowed; only \\d*, \\x and \\echo on their own line"
    if not statements.strip():
        return None
    if SQL_ESCAPES.search(statements):
        return "COMMIT, END, ABORT, PREPARE TRANSACTION, dollar quotes and comments are not allowed"
    if not (SQL_BEGIN.search(statements) and SQL_ROLLBACK_END.search(statements)) \
            or len(SQL_ROLLBACK.findall(statements)) != 1:
        return "SQL must run inside BEGIN ... ROLLBACK"
    if not writable and SQL_WRITE.search(SQL_LITERAL.sub("''", statements)):
        return "SQL that writes only on pyme_test; pyme is read-only"
    return None


def check_psql(tokens, stdin_sql=None):
    args = None
    for prefix in PSQL_PREFIXES:
        if tokens[:len(prefix)] == prefix:
            args = tokens[len(prefix):]
            break
    if args is None:
        return "psql only through docker compose exec postgres psql"
    database, stops_on_error, statements = None, False, []
    i = 0
    while i < len(args):
        arg = args[i]
        if arg in ("-U", "-d", "-c", "-v"):
            if i + 1 >= len(args):
                return "{} needs a value".format(arg)
            value = args[i + 1]
            if arg == "-d":
                if value not in PSQL_DATABASES:
                    return "psql only on pyme or pyme_test"
                if database is not None:
                    return "-d only once"
                database = value
            elif arg == "-c":
                statements.append(value)
            elif arg == "-v":
                if value != "ON_ERROR_STOP=1":
                    return "-v only as ON_ERROR_STOP=1"
                stops_on_error = True
            i += 2
            continue
        if PSQL_FLAGS.match(arg):
            i += 1
            continue
        return "psql option {!r} not allowed".format(arg)
    # Without it psql goes on after an error, and a failed BEGIN leaves the rest in autocommit.
    if not stops_on_error:
        return "psql needs -v ON_ERROR_STOP=1"
    if database is None:
        return "psql needs -d pyme or -d pyme_test"
    if stdin_sql is not None:
        statements.append(stdin_sql)
    for sql in statements:
        reason = check_sql(sql, writable=database == WRITABLE_DATABASE)
        if reason:
            return reason
    # The user itself is checked once for every role in check_bash.
    return None


def read_only_git(segment, project_dir):
    # -c and the other global options are out: config can run programs (core.pager, aliases).
    i = 1
    while i < len(segment) and segment[i].startswith("-"):
        if segment[i] == "--no-pager":
            i += 1
        elif segment[i] == "-C" and i + 1 < len(segment) and relative_to_project(segment[i + 1], project_dir) == ".":
            i += 2
        else:
            return False
    rest = segment[i:]
    return bool(rest) and rest[0] in GIT_READ and not any(t.startswith("--output") for t in rest)


def check_first_segment(role, segment, project_dir):
    head = segment[0]
    if head in READ_ONLY:
        return None
    if head == "git":
        return None if read_only_git(segment, project_dir) else "only read-only git commands"
    if role not in DB_REVIEWERS:
        return "command not in the allowlist of {}".format(role or "this subagent")
    if segment[:len(COMPOSER_AUDIT)] == COMPOSER_AUDIT:
        if all(t.startswith("--") for t in segment[len(COMPOSER_AUDIT):]):
            return None
        return "composer audit takes only -- options"
    if segment[:len(NPM_AUDIT)] == NPM_AUDIT:
        if all(t.startswith("--") and "fix" not in t for t in segment[len(NPM_AUDIT):]):
            return None
        return "npm audit without fix"
    if "psql" in segment:
        return check_psql(segment)
    return "command not in the allowlist of {}".format(role)


def check_allowlisted_bash(role, command, project_dir):
    first, _, rest = command.partition("\n")
    if rest.strip():
        if role not in DB_REVIEWERS:
            return "multi-line commands are not allowed"
        match = HEREDOC.match(first)
        if not match:
            return "multi-line commands only as a psql heredoc with a quoted tag (<<'SQL')"
        head, tag = match.group(1), match.group(2)
        lines = rest.rstrip("\n").split("\n")
        if lines[-1].strip() != tag or any(line.strip() == tag for line in lines[:-1]):
            return "heredoc must end with its tag and contain nothing after it"
        try:
            tokens = tokenize(head)
        except ValueError:
            return "unparseable command"
        if any(is_operator(t) for t in tokens):
            return "shell operators are not allowed before a heredoc"
        return check_psql(tokens, "\n".join(lines[:-1]))

    raw = command.replace(UIDGID_RAW, '"UIDGID"')
    if "$(" in raw or "`" in raw:
        return "command substitution is not allowed"
    raw = HARMLESS_REDIRECT.sub(" ", " " + raw)
    try:
        tokens = tokenize(raw)
    except ValueError:
        return "unparseable command"
    segments = [[]]
    for token in tokens:
        if token == "|":
            segments.append([])
        elif is_operator(token):
            return "shell operator {!r} is not allowed".format(token)
        else:
            segments[-1].append(token)
    if any(not segment for segment in segments):
        return "empty pipeline segment"
    for segment in segments[1:]:
        if segment[0] not in SAFE_FILTERS:
            return "only head, tail, grep, wc or cut after a pipe"
    return check_first_segment(role, segments[0], project_dir)


def skip_options(tokens, i, value_options):
    while i < len(tokens) and tokens[i].startswith("-") and not is_operator(tokens[i]):
        i += 2 if tokens[i] in value_options else 1
    return i


def without_global_options(tokens):
    """`git -C . push` becomes `git push`, `docker compose -f x down` becomes `docker compose down`."""
    out, i = [], 0
    while i < len(tokens):
        token = tokens[i]
        if token == "git":
            out.append(token)
            i = skip_options(tokens, i + 1, GIT_VALUE_OPTIONS)
        elif token == "docker":
            out.append(token)
            i = skip_options(tokens, i + 1, DOCKER_VALUE_OPTIONS)
        elif token == "docker-compose" or (token == "compose" and out[-1:] == ["docker"]):
            out.extend(["docker", "compose"] if token == "docker-compose" else ["compose"])
            i = skip_options(tokens, i + 1, COMPOSE_VALUE_OPTIONS)
        else:
            out.append(token)
            i += 1
    return " ".join(out)


def writes_protected_path(role, command, project_dir):
    cleaned = HARMLESS_REDIRECT.sub(" ", " " + command)
    if not WRITE_OP.search(cleaned):
        return False
    try:
        words = tokenize(cleaned)
    except ValueError:
        words = cleaned.split()
    for word in words:
        # A write that names .git anywhere, even through a glob, counts: the main session runs its hooks.
        if any(names_git(part) for part in word.split("=")):
            return True
        rel = project_path(word, project_dir)
        if rel is None:
            continue
        if role == "coder" and CODER_FORBIDDEN.search(rel):
            return True
        if role == "tester" and TESTER_PROTECTED.search(rel) and not TEST_FILE.match(rel):
            return True
    return False


def check_bash(role, command, project_dir):
    if reaches_env(command):
        return ".env holds secrets"
    if "PGUSER" in command:
        return "PGUSER is not allowed; use -U pyme_app"
    if PSQL.search(command):
        users = PSQL_USER.findall(command)
        if not users or any(user != "pyme_app" for user in users):
            return "psql must connect as pyme_app"
    if role in WRITERS:
        try:
            normalized = without_global_options(tokenize(command))
        except ValueError:
            normalized = command
        for pattern, reason in DANGEROUS:
            if pattern.search(command) or pattern.search(normalized):
                return reason
        if any(names_gate(word) for word in shell_words(command)):
            return GATE_REASON
        if writes_protected_path(role, command, project_dir):
            if role == "coder":
                return ("coder never writes tests, test config, compose, composer.json, docs, .claude, .github, .git, "
                        ".gate or scripts, not even through Bash")
            return "tester only writes test files, not even through Bash"
        return None
    return check_allowlisted_bash(role, command, project_dir)


def decide(payload, project_dir):
    """Return the reason to block, or None to allow."""
    if not payload.get("agent_id"):
        return None
    role = payload.get("agent_type") or ""
    tool = payload.get("tool_name") or ""
    tool_input = payload.get("tool_input") or {}
    if tool in WRITE_TOOLS:
        path = tool_input.get("file_path") or tool_input.get("notebook_path") or ""
        return check_write(role, path, project_dir)
    if tool == "Bash":
        return check_bash(role, tool_input.get("command") or "", project_dir)
    return None


def main():
    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    try:
        payload = json.load(sys.stdin)
    except ValueError:
        print("role_guard: invalid hook input", file=sys.stderr)
        return 2
    reason = decide(payload, project_dir)
    if reason:
        print(
            "Blocked by .claude/hooks/role_guard.py: {}. If you need it, stop and ask the architect.".format(reason),
            file=sys.stderr,
        )
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())