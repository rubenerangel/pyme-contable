<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// The period table: columns generated from (year, number), CHECK constraints, uniqueness per company and the
// privileges of pyme_app (it can only change status). The company context is set with DatabaseCompanyContext and
// company_id is never passed. A SQL error aborts the RefreshDatabase transaction in Postgres, so each failing
// statement lives in its own test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Creates a company, enters its context on the default connection (pyme_app) and returns its id.
 */
function periodTestEnterNewCompany(): int
{
    $company = Company::factory()->create();
    app(DatabaseCompanyContext::class)->enter($company->id, User::factory()->create()->id);

    return $company->id;
}

/**
 * Inserts a period of the company in context, passing only year and number, and returns its id.
 */
function periodTestInsert(int $year, int $number): int
{
    return (int) DB::table('period')->insertGetId(['year' => $year, 'number' => $number]);
}

/**
 * Asserts that the statement fails with a QueryException carrying the given SQLSTATE.
 */
function periodTestExpectSqlState(Closure $statement, string $sqlState): void
{
    expect($statement)->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe($sqlState));
}

it('generates type, start_date and end_date from year and number', function (
    int $year,
    int $number,
    string $type,
    string $startDate,
    string $endDate,
): void {
    periodTestEnterNewCompany();
    periodTestInsert($year, $number);

    $row = DB::selectOne(
        'select type, start_date::text as start_date, end_date::text as end_date from period where year = ? and number = ?',
        [$year, $number],
    );

    expect(['type' => $row->type, 'start_date' => $row->start_date, 'end_date' => $row->end_date])->toBe([
        'type' => $type,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
})->with([
    'opening period 0' => [2026, 0, 'opening', '2026-01-01', '2026-01-01'],
    'january' => [2026, 1, 'regular', '2026-01-01', '2026-01-31'],
    'february of a leap year' => [2024, 2, 'regular', '2024-02-01', '2024-02-29'],
    'february of a non leap year' => [2025, 2, 'regular', '2025-02-01', '2025-02-28'],
    'april (30 days)' => [2026, 4, 'regular', '2026-04-01', '2026-04-30'],
    'december' => [2026, 12, 'regular', '2026-12-01', '2026-12-31'],
    'adjustment period 13 shares the date of december' => [2026, 13, 'adjustment', '2026-12-31', '2026-12-31'],
]);

it('defaults the status to open', function (): void {
    periodTestEnterNewCompany();
    periodTestInsert(2026, 1);

    expect(DB::table('period')->where('year', 2026)->where('number', 1)->value('status'))->toBe('open');
});

it('stores the active company in company_id when the insert does not pass it', function (): void {
    $companyId = periodTestEnterNewCompany();
    periodTestInsert(2026, 1);

    expect((int) DB::table('period')->value('company_id'))->toBe($companyId);
});

it('rejects a number outside 0 to 13 with the CHECK constraint (SQLSTATE 23514), not with a date error from the generated columns', function (int $number): void {
    periodTestEnterNewCompany();

    // 22008 (datetime_field_overflow from make_date) would mean the generated expressions run before the CHECK.
    periodTestExpectSqlState(fn () => periodTestInsert(2026, $number), '23514');
})->with([
    'below the range' => [-1],
    'above the range' => [14],
]);

it('rejects a status other than open or closed (CHECK, SQLSTATE 23514)', function (): void {
    periodTestEnterNewCompany();

    periodTestExpectSqlState(
        fn () => DB::table('period')->insert(['year' => 2026, 'number' => 1, 'status' => 'locked']),
        '23514',
    );
});

it('rejects a second period with the same company, year and number (UNIQUE, SQLSTATE 23505)', function (): void {
    periodTestEnterNewCompany();
    periodTestInsert(2026, 1);

    periodTestExpectSqlState(fn () => periodTestInsert(2026, 1), '23505');
});

it('accepts the same year and number in another company, and each company sees only its own period', function (): void {
    $context = app(DatabaseCompanyContext::class);

    $companyA = periodTestEnterNewCompany();
    periodTestInsert(2026, 1);

    $companyB = periodTestEnterNewCompany();
    periodTestInsert(2026, 1);

    $seenByB = DB::table('period')->pluck('company_id')->map(fn ($id) => (int) $id)->all();
    $context->enter($companyA, User::factory()->create()->id);
    $seenByA = DB::table('period')->pluck('company_id')->map(fn ($id) => (int) $id)->all();

    expect(['a' => $seenByA, 'b' => $seenByB])->toBe(['a' => [$companyA], 'b' => [$companyB]]);
});

it('lets pyme_app update the status to closed', function (): void {
    periodTestEnterNewCompany();
    $id = periodTestInsert(2026, 1);

    $affected = DB::table('period')->where('id', $id)->update(['status' => 'closed']);

    expect(['affected' => $affected, 'status' => DB::table('period')->where('id', $id)->value('status')])->toBe([
        'affected' => 1,
        'status' => 'closed',
    ]);
});

it('does not let pyme_app update the year (no UPDATE privilege on the column, SQLSTATE 42501)', function (): void {
    periodTestEnterNewCompany();
    $id = periodTestInsert(2026, 1);

    periodTestExpectSqlState(fn () => DB::table('period')->where('id', $id)->update(['year' => 2027]), '42501');
});

it('does not let pyme_app update the number (no UPDATE privilege on the column, SQLSTATE 42501)', function (): void {
    periodTestEnterNewCompany();
    $id = periodTestInsert(2026, 1);

    periodTestExpectSqlState(fn () => DB::table('period')->where('id', $id)->update(['number' => 2]), '42501');
});

it('does not let pyme_app delete a period (no DELETE privilege, SQLSTATE 42501)', function (): void {
    periodTestEnterNewCompany();
    $id = periodTestInsert(2026, 1);

    periodTestExpectSqlState(fn () => DB::table('period')->where('id', $id)->delete(), '42501');
});
