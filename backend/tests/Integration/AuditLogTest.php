<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// The audit_log ledger: written only by SECURITY DEFINER functions (the audit_log_capture trigger function and
// audit_log_event), read by pyme_app under RLS, never modified by anybody. The tests-only table audit_probe carries
// the audit triggers and admits DELETE for pyme_app, which no real table does. The company context is set with
// DatabaseCompanyContext (real users from the factory) except in the fail closed tests, which set it with raw SQL on
// purpose. A SQL error aborts the RefreshDatabase transaction in Postgres, so each failing statement lives in its own
// test; where a test must keep reading after a failure, the statement runs in a savepoint (nested DB::transaction).
// What pyme_owner can do to audit_log with a real commit lives in AuditLogOwnerTest.
uses(RefreshDatabaseAsOwner::class);

/**
 * Creates a company and a user and enters their context on the default connection (pyme_app).
 *
 * @return array{company: int, user: int}
 */
function auditLogTestEnter(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create();
    app(DatabaseCompanyContext::class)->enter($company->id, $user->id);

    return ['company' => $company->id, 'user' => $user->id];
}

/**
 * Sets only the company, with raw SQL, and leaves the user setting empty ('empty') or undefined ('reset').
 */
function auditLogTestEnterWithoutUser(string $how): int
{
    $company = Company::factory()->create();
    DB::select("select set_config('app.company_id', ?, false)", [(string) $company->id]);

    if ($how === 'empty') {
        DB::select("select set_config('app.user_id', '', false)");
    } else {
        DB::statement('reset app.user_id');
    }

    return $company->id;
}

/**
 * Decodes a jsonb value read as text, with the keys sorted so that the comparison does not depend on jsonb order.
 *
 * @return array<string, mixed>|null
 */
function auditLogTestDecode(?string $json): ?array
{
    if ($json === null) {
        return null;
    }

    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    ksort($decoded);

    return $decoded;
}

/**
 * Audit rows of the company in context for one table (kind data_change), oldest first.
 *
 * @return list<array<string, mixed>>
 */
function auditLogTestRowsOf(string $tableName): array
{
    return array_map(fn (object $row): array => [
        'id' => (int) $row->id,
        'company_id' => (int) $row->company_id,
        'user_id' => (int) $row->user_id,
        'kind' => $row->kind,
        'table_name' => $row->table_name,
        'record_id' => $row->record_id === null ? null : (int) $row->record_id,
        'operation' => $row->operation,
        'old_values' => auditLogTestDecode($row->old_values),
        'new_values' => auditLogTestDecode($row->new_values),
        'event_type' => $row->event_type,
        'details' => auditLogTestDecode($row->details),
        'has_occurred_at' => (bool) $row->has_occurred_at,
    ], DB::select(<<<'SQL'
        select id, company_id, user_id, kind, table_name, record_id, operation,
               old_values::text as old_values, new_values::text as new_values,
               event_type, details::text as details, (occurred_at is not null) as has_occurred_at
        from audit_log
        where table_name = ?
        order by id
        SQL, [$tableName]));
}

/**
 * Audit rows of kind event of the company in context, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function auditLogTestEventRows(): array
{
    return array_map(fn (object $row): array => [
        'id' => (int) $row->id,
        'company_id' => (int) $row->company_id,
        'user_id' => (int) $row->user_id,
        'kind' => $row->kind,
        'table_name' => $row->table_name,
        'record_id' => $row->record_id,
        'operation' => $row->operation,
        'old_values' => $row->old_values,
        'new_values' => $row->new_values,
        'event_type' => $row->event_type,
        'details' => auditLogTestDecode($row->details),
    ], DB::select(<<<'SQL'
        select id, company_id, user_id, kind, table_name, record_id, operation,
               old_values::text as old_values, new_values::text as new_values,
               event_type, details::text as details
        from audit_log
        where kind = 'event'
        order by id
        SQL));
}

/**
 * Number of audit rows of the company in context for the table.
 */
function auditLogTestCountOf(string $tableName): int
{
    return (int) DB::selectOne('select count(*) as total from audit_log where table_name = ?', [$tableName])->total;
}

/**
 * Asserts that the statement fails with a QueryException carrying the given SQLSTATE and, when given, a driver
 * message that contains the text.
 */
function auditLogTestExpectSqlState(Closure $statement, string $sqlState, ?string $messageContains = null): void
{
    expect($statement)->toThrow(function (QueryException $e) use ($sqlState, $messageContains): void {
        expect($e->errorInfo[0])->toBe($sqlState);

        if ($messageContains !== null) {
            expect($e->getMessage())->toContain($messageContains);
        }
    });
}

/**
 * Runs the statement in a savepoint and returns whether it failed. The savepoint is rolled back on failure, so the
 * RefreshDatabase transaction stays usable and the test can read what was left behind.
 */
function auditLogTestFailsInSavepoint(Closure $statement): bool
{
    try {
        DB::transaction($statement);
    } catch (QueryException) {
        return true;
    }

    return false;
}

/**
 * Runs $test with a pgsql_owner transaction that has a company and a user created by the owner itself (the rows of
 * the default connection are uncommitted and invisible to it) and their context set on the owner connection.
 * RefreshDatabaseAsOwner does not wrap pgsql_owner, so the rollback and the context cleanup are mandatory.
 *
 * $test receives: the owner connection, the company id and the user id.
 */
function auditLogTestWithOwner(Closure $test): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();

    try {
        $now = now();
        $companyId = (int) $owner->table('companies')->insertGetId([
            'name' => 'Audit owner company SAS',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) $owner->table('users')->insertGetId([
            'name' => 'Audit owner user',
            'email' => 'audit-owner-'.uniqid().'@example.test',
            'password' => 'not-a-real-hash',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $owner->select(
            "select set_config('app.company_id', ?, false), set_config('app.user_id', ?, false)",
            [(string) $companyId, (string) $userId],
        );

        $test($owner, $companyId, $userId);
    } finally {
        while ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        $owner->select("select set_config('app.company_id', '', false), set_config('app.user_id', '', false)");
    }
}

/**
 * Inserts one audit_log row as pyme_owner (the owner_insert policy) with the given column values over a valid
 * data_change UPDATE row, and returns whether the insert succeeded.
 *
 * @param  array<string, mixed>  $columns
 */
function auditLogTestOwnerInsert(Connection $owner, int $userId, array $columns): bool
{
    $row = array_merge([
        'kind' => 'data_change',
        'table_name' => 'some_table',
        'record_id' => 1,
        'operation' => 'UPDATE',
        'old_values' => '{"a":1}',
        'new_values' => '{"a":2}',
        'event_type' => null,
        'details' => null,
    ], $columns);

    return $owner->insert(<<<'SQL'
        insert into audit_log (user_id, kind, table_name, record_id, operation, old_values, new_values, event_type, details)
        values (?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?::jsonb)
        SQL, [
        $userId,
        $row['kind'],
        $row['table_name'],
        $row['record_id'],
        $row['operation'],
        $row['old_values'],
        $row['new_values'],
        $row['event_type'],
        $row['details'],
    ]);
}

// Data changes captured by the trigger on the tests-only table audit_probe.

it('records an INSERT in audit_probe as a data_change row with the new values and the context', function (): void {
    $context = auditLogTestEnter();

    $id = (int) DB::table('audit_probe')->insertGetId(['label' => 'first']);

    $rows = auditLogTestRowsOf('audit_probe');

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBe([
        'id' => $rows[0]['id'],
        'company_id' => $context['company'],
        'user_id' => $context['user'],
        'kind' => 'data_change',
        'table_name' => 'audit_probe',
        'record_id' => $id,
        'operation' => 'INSERT',
        'old_values' => null,
        'new_values' => ['company_id' => $context['company'], 'id' => $id, 'label' => 'first'],
        'event_type' => null,
        'details' => null,
        'has_occurred_at' => true,
    ]);
});

it('records an UPDATE that changes the label with the old and the new values', function (): void {
    $context = auditLogTestEnter();
    $id = (int) DB::table('audit_probe')->insertGetId(['label' => 'before']);

    DB::table('audit_probe')->where('id', $id)->update(['label' => 'after']);

    $rows = auditLogTestRowsOf('audit_probe');
    $update = $rows[1] ?? null;

    expect(['count' => count($rows), 'operations' => array_column($rows, 'operation')])->toBe([
        'count' => 2,
        'operations' => ['INSERT', 'UPDATE'],
    ]);
    expect($update)->toMatchArray([
        'company_id' => $context['company'],
        'user_id' => $context['user'],
        'kind' => 'data_change',
        'table_name' => 'audit_probe',
        'record_id' => $id,
        'operation' => 'UPDATE',
        'old_values' => ['company_id' => $context['company'], 'id' => $id, 'label' => 'before'],
        'new_values' => ['company_id' => $context['company'], 'id' => $id, 'label' => 'after'],
        'has_occurred_at' => true,
    ]);
});

it('records nothing for an UPDATE that does not change any value', function (): void {
    auditLogTestEnter();
    $id = (int) DB::table('audit_probe')->insertGetId(['label' => 'same']);

    $affected = DB::table('audit_probe')->where('id', $id)->update(['label' => 'same']);

    expect(['affected' => $affected, 'audit rows' => auditLogTestCountOf('audit_probe')])->toBe([
        'affected' => 1,
        'audit rows' => 1,
    ]);
});

it('records a DELETE with the old values and no new values', function (): void {
    $context = auditLogTestEnter();
    $id = (int) DB::table('audit_probe')->insertGetId(['label' => 'doomed']);

    $affected = DB::table('audit_probe')->where('id', $id)->delete();

    $rows = auditLogTestRowsOf('audit_probe');
    $delete = $rows[1] ?? null;

    expect(['affected' => $affected, 'operations' => array_column($rows, 'operation')])->toBe([
        'affected' => 1,
        'operations' => ['INSERT', 'DELETE'],
    ]);
    expect($delete)->toMatchArray([
        'company_id' => $context['company'],
        'user_id' => $context['user'],
        'record_id' => $id,
        'operation' => 'DELETE',
        'old_values' => ['company_id' => $context['company'], 'id' => $id, 'label' => 'doomed'],
        'new_values' => null,
    ]);
});

it('takes the user of each change from the context at that moment', function (): void {
    $first = auditLogTestEnter();
    $id = (int) DB::table('audit_probe')->insertGetId(['label' => 'before']);

    $second = User::factory()->create();
    app(DatabaseCompanyContext::class)->enter($first['company'], $second->id);
    DB::table('audit_probe')->where('id', $id)->update(['label' => 'after']);

    expect(array_column(auditLogTestRowsOf('audit_probe'), 'user_id'))->toBe([$first['user'], $second->id]);
});

it('rolls the audit row back together with the change it records', function (): void {
    auditLogTestEnter();

    $failed = false;
    try {
        DB::transaction(function (): void {
            DB::table('audit_probe')->insert(['label' => 'rolled back']);

            throw new RuntimeException('Abort the savepoint after the insert.');
        });
    } catch (RuntimeException) {
        $failed = true;
    }

    expect([
        'failed' => $failed,
        'probe rows' => DB::table('audit_probe')->count(),
        'audit rows' => auditLogTestCountOf('audit_probe'),
    ])->toBe(['failed' => true, 'probe rows' => 0, 'audit rows' => 0]);
});

// Data changes captured on the real audited tables.

it('records the INSERT of a document_type with its new values', function (): void {
    $context = auditLogTestEnter();

    $id = (int) DB::table('document_type')->insertGetId(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]);

    $rows = auditLogTestRowsOf('document_type');

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'company_id' => $context['company'],
        'user_id' => $context['user'],
        'kind' => 'data_change',
        'record_id' => $id,
        'operation' => 'INSERT',
        'old_values' => null,
        'new_values' => [
            'code' => 'INV',
            'company_id' => $context['company'],
            'id' => $id,
            'name' => 'Invoice',
            'resets_yearly' => true,
        ],
    ]);
});

it('records the UPDATE of the name of a document_type with the old and the new name', function (): void {
    $context = auditLogTestEnter();
    $id = (int) DB::table('document_type')->insertGetId(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]);

    DB::table('document_type')->where('id', $id)->update(['name' => 'Sales invoice']);

    $update = auditLogTestRowsOf('document_type')[1] ?? null;

    expect($update)->toMatchArray([
        'user_id' => $context['user'],
        'record_id' => $id,
        'operation' => 'UPDATE',
        'old_values' => [
            'code' => 'INV',
            'company_id' => $context['company'],
            'id' => $id,
            'name' => 'Invoice',
            'resets_yearly' => true,
        ],
        'new_values' => [
            'code' => 'INV',
            'company_id' => $context['company'],
            'id' => $id,
            'name' => 'Sales invoice',
            'resets_yearly' => true,
        ],
    ]);
});

it('records nothing for an UPDATE of a document_type that keeps the same name', function (): void {
    auditLogTestEnter();
    $id = (int) DB::table('document_type')->insertGetId(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]);

    DB::table('document_type')->where('id', $id)->update(['name' => 'Invoice']);

    expect(array_column(auditLogTestRowsOf('document_type'), 'operation'))->toBe(['INSERT']);
});

it('records the UPDATE of the status of a period with the old and the new status', function (): void {
    $context = auditLogTestEnter();
    $id = (int) DB::table('period')->insertGetId(['year' => 2026, 'number' => 1]);

    DB::table('period')->where('id', $id)->update(['status' => 'closed']);

    $rows = auditLogTestRowsOf('period');
    $update = $rows[1] ?? null;

    expect(array_column($rows, 'operation'))->toBe(['INSERT', 'UPDATE']);
    expect($update)->toMatchArray([
        'company_id' => $context['company'],
        'user_id' => $context['user'],
        'kind' => 'data_change',
        'record_id' => $id,
        'operation' => 'UPDATE',
    ]);
    expect([$update['old_values']['status'] ?? null, $update['new_values']['status'] ?? null])->toBe(['open', 'closed']);
});

// Fail closed: without a user in the context a write to an audited table fails and is rolled back.

it('rejects an INSERT in an audited table without a user in the context (SQLSTATE 42501, names app.user_id)', function (string $how): void {
    auditLogTestEnterWithoutUser($how);

    auditLogTestExpectSqlState(fn () => DB::table('audit_probe')->insert(['label' => 'orphan']), '42501', 'app.user_id');
})->with(['empty user setting' => ['empty'], 'user setting never defined' => ['reset']]);

it('leaves no row in audit_probe nor in audit_log when the INSERT without a user fails', function (string $how): void {
    auditLogTestEnterWithoutUser($how);

    $failed = auditLogTestFailsInSavepoint(fn () => DB::table('audit_probe')->insert(['label' => 'orphan']));

    expect([
        'failed' => $failed,
        'probe rows' => DB::table('audit_probe')->count(),
        'audit rows' => auditLogTestCountOf('audit_probe'),
    ])->toBe(['failed' => true, 'probe rows' => 0, 'audit rows' => 0]);
})->with(['empty user setting' => ['empty'], 'user setting never defined' => ['reset']]);

it('rolls back an UPDATE or DELETE without a user in the context and keeps the row as it was', function (string $operation): void {
    auditLogTestEnter();
    $id = (int) DB::table('audit_probe')->insertGetId(['label' => 'keep']);
    DB::select("select set_config('app.user_id', '', false)");

    $failed = auditLogTestFailsInSavepoint(fn () => $operation === 'update'
        ? DB::table('audit_probe')->where('id', $id)->update(['label' => 'tampered'])
        : DB::table('audit_probe')->where('id', $id)->delete());

    expect([
        'failed' => $failed,
        'labels' => DB::table('audit_probe')->pluck('label')->all(),
        'audit operations' => array_column(auditLogTestRowsOf('audit_probe'), 'operation'),
    ])->toBe([
        'failed' => true,
        'labels' => ['keep'],
        'audit operations' => ['INSERT'],
    ]);
})->with(['update' => ['update'], 'delete' => ['delete']]);

it('rejects a write to a real audited table without a user in the context (SQLSTATE 42501, names app.user_id)', function (): void {
    auditLogTestEnterWithoutUser('empty');

    auditLogTestExpectSqlState(
        fn () => DB::table('document_type')->insert(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]),
        '42501',
        'app.user_id',
    );
});

// pyme_app only reads audit_log: it has no INSERT, UPDATE nor DELETE privilege on it.

it('does not let pyme_app insert directly into audit_log (SQLSTATE 42501, permission denied)', function (): void {
    $context = auditLogTestEnter();

    auditLogTestExpectSqlState(
        fn () => DB::statement(
            "insert into audit_log (user_id, kind, event_type, details) values (?, 'event', 'forged_event', '{}'::jsonb)",
            [$context['user']],
        ),
        '42501',
        'permission denied for table audit_log',
    );
});

it('does not let pyme_app update audit_log (SQLSTATE 42501, permission denied)', function (): void {
    auditLogTestEnter();
    DB::table('audit_probe')->insert(['label' => 'seed']);

    auditLogTestExpectSqlState(
        fn () => DB::update("update audit_log set table_name = 'tampered'"),
        '42501',
        'permission denied for table audit_log',
    );
});

it('does not let pyme_app delete from audit_log (SQLSTATE 42501, permission denied)', function (): void {
    auditLogTestEnter();
    DB::table('audit_probe')->insert(['label' => 'seed']);

    auditLogTestExpectSqlState(
        fn () => DB::delete('delete from audit_log'),
        '42501',
        'permission denied for table audit_log',
    );
});

// Isolation by company on read.

it('shows each company only its own audit rows', function (): void {
    $context = app(DatabaseCompanyContext::class);

    $a = auditLogTestEnter();
    DB::table('audit_probe')->insert(['label' => 'a-1']);
    DB::selectOne("select audit_log_event('period_closed', '{\"scope\":\"a\"}'::jsonb) as id");

    $b = auditLogTestEnter();
    DB::table('audit_probe')->insert(['label' => 'b-1']);
    $seenByB = array_map(fn (object $row): int => (int) $row->company_id, DB::select('select company_id from audit_log order by id'));

    $context->enter($a['company'], $a['user']);
    $seenByA = array_map(fn (object $row): int => (int) $row->company_id, DB::select('select company_id from audit_log order by id'));

    expect(['a' => $seenByA, 'b' => $seenByB])->toBe([
        'a' => [$a['company'], $a['company']],
        'b' => [$b['company']],
    ]);
});

it('shows no audit rows when the context is empty (fail closed)', function (): void {
    auditLogTestEnter();
    DB::table('audit_probe')->insert(['label' => 'seed']);

    app(DatabaseCompanyContext::class)->leave();

    expect(DB::table('audit_log')->count())->toBe(0);
});

// Explicit events through audit_log_event().

it('audit_log_event returns the id of the event row it writes', function (): void {
    $context = auditLogTestEnter();

    $id = (int) DB::selectOne(
        'select audit_log_event(?, ?::jsonb) as id',
        ['period_closed', json_encode(['year' => 2026, 'number' => 1], JSON_THROW_ON_ERROR)],
    )->id;

    $rows = auditLogTestEventRows();

    expect($rows)->toBe([[
        'id' => $id,
        'company_id' => $context['company'],
        'user_id' => $context['user'],
        'kind' => 'event',
        'table_name' => null,
        'record_id' => null,
        'operation' => null,
        'old_values' => null,
        'new_values' => null,
        'event_type' => 'period_closed',
        'details' => ['number' => 1, 'year' => 2026],
    ]]);
});

it('audit_log_event accepts event types in lower snake case', function (string $eventType): void {
    auditLogTestEnter();

    DB::selectOne("select audit_log_event(?, '{}'::jsonb) as id", [$eventType]);

    expect(array_column(auditLogTestEventRows(), 'event_type'))->toBe([$eventType]);
})->with(['letters' => ['approved'], 'with underscore and digits' => ['period_13_reopened']]);

it('audit_log_event rejects an event type with an invalid format (CHECK, SQLSTATE 23514)', function (string $eventType): void {
    auditLogTestEnter();

    auditLogTestExpectSqlState(
        fn () => DB::selectOne("select audit_log_event(?, '{}'::jsonb) as id", [$eventType]),
        '23514',
    );
})->with([
    'uppercase and space' => ['Period Closed'],
    'hyphen' => ['period-closed'],
    'starts with a digit' => ['1st_event'],
    'empty' => [''],
]);

it('audit_log_event rejects NULL details (CHECK, SQLSTATE 23514)', function (): void {
    auditLogTestEnter();

    auditLogTestExpectSqlState(
        fn () => DB::selectOne('select audit_log_event(?, ?::jsonb) as id', ['period_closed', null]),
        '23514',
    );
});

it('audit_log_event rejects details that are not a JSON object (CHECK, SQLSTATE 23514)', function (string $details): void {
    auditLogTestEnter();

    auditLogTestExpectSqlState(
        fn () => DB::selectOne('select audit_log_event(?, ?::jsonb) as id', ['period_closed', $details]),
        '23514',
    );
})->with(['array' => ['[]'], 'string' => ['"closed"'], 'number' => ['1']]);

it('audit_log_event fails without a user in the context (SQLSTATE 42501, names app.user_id)', function (string $how): void {
    auditLogTestEnterWithoutUser($how);

    auditLogTestExpectSqlState(
        fn () => DB::selectOne("select audit_log_event('period_closed', '{}'::jsonb) as id"),
        '42501',
        'app.user_id',
    );
})->with(['empty user setting' => ['empty'], 'user setting never defined' => ['reset']]);

// Shape CHECK of audit_log, proven as pyme_owner (the only role that can insert directly, under the owner_insert
// policy and with the context set on its own connection).

it('accepts the valid shapes of an audit row inserted by pyme_owner', function (array $columns): void {
    auditLogTestWithOwner(function (Connection $owner, int $companyId, int $userId) use ($columns): void {
        expect(auditLogTestOwnerInsert($owner, $userId, $columns))->toBeTrue();
    });
})->with([
    'data_change INSERT' => [['operation' => 'INSERT', 'old_values' => null]],
    'data_change UPDATE' => [[]],
    'data_change DELETE' => [['operation' => 'DELETE', 'new_values' => null]],
    'event' => [[
        'kind' => 'event',
        'table_name' => null,
        'record_id' => null,
        'operation' => null,
        'old_values' => null,
        'new_values' => null,
        'event_type' => 'period_closed',
        'details' => '{"year":2026}',
    ]],
]);

it('rejects an audit row with an invalid shape (CHECK, SQLSTATE 23514)', function (array $columns): void {
    auditLogTestWithOwner(function (Connection $owner, int $companyId, int $userId) use ($columns): void {
        auditLogTestExpectSqlState(fn () => auditLogTestOwnerInsert($owner, $userId, $columns), '23514');
    });
})->with([
    'data_change without table_name' => [['table_name' => null]],
    'data_change without record_id' => [['record_id' => null]],
    'data_change without operation' => [['operation' => null]],
    'data_change with event_type' => [['event_type' => 'period_closed']],
    'data_change with details' => [['details' => '{}']],
    'INSERT with old_values' => [['operation' => 'INSERT']],
    'INSERT without new_values' => [['operation' => 'INSERT', 'old_values' => null, 'new_values' => null]],
    'UPDATE without old_values' => [['old_values' => null]],
    'UPDATE without new_values' => [['new_values' => null]],
    'DELETE with new_values' => [['operation' => 'DELETE']],
    'DELETE without old_values' => [['operation' => 'DELETE', 'old_values' => null, 'new_values' => null]],
    'operation outside INSERT, UPDATE and DELETE' => [['operation' => 'TRUNCATE']],
    'kind outside data_change and event' => [['kind' => 'other']],
    'event with table_name' => [[
        'kind' => 'event', 'record_id' => null, 'operation' => null, 'old_values' => null, 'new_values' => null,
        'event_type' => 'period_closed', 'details' => '{}',
    ]],
    'event with record_id' => [[
        'kind' => 'event', 'table_name' => null, 'operation' => null, 'old_values' => null, 'new_values' => null,
        'event_type' => 'period_closed', 'details' => '{}', 'record_id' => 1,
    ]],
    'event with old_values' => [[
        'kind' => 'event', 'table_name' => null, 'record_id' => null, 'operation' => null, 'new_values' => null,
        'event_type' => 'period_closed', 'details' => '{}',
    ]],
    'event without event_type' => [[
        'kind' => 'event', 'table_name' => null, 'record_id' => null, 'operation' => null, 'old_values' => null,
        'new_values' => null, 'details' => '{}',
    ]],
    'event without details' => [[
        'kind' => 'event', 'table_name' => null, 'record_id' => null, 'operation' => null, 'old_values' => null,
        'new_values' => null, 'event_type' => 'period_closed', 'details' => null,
    ]],
    'event with details that are an array' => [[
        'kind' => 'event', 'table_name' => null, 'record_id' => null, 'operation' => null, 'old_values' => null,
        'new_values' => null, 'event_type' => 'period_closed', 'details' => '[]',
    ]],
    'event with an invalid event_type' => [[
        'kind' => 'event', 'table_name' => null, 'record_id' => null, 'operation' => null, 'old_values' => null,
        'new_values' => null, 'event_type' => 'Period Closed', 'details' => '{}',
    ]],
]);
