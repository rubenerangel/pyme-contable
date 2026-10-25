<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\DivipolaCsv;

// The municipality table is a global catalog (no company_id, no RLS, no audit) seeded by the migration that creates it
// from the official DIVIPOLA file of DANE (backend/database/data/divipola.csv). Only the owner writes it. Every expected
// value comes from the file through DivipolaCsv: no DANE code or name is written by hand in this file. A SQL error
// aborts the RefreshDatabase transaction in Postgres, so each failing statement lives in its own test. The constraint
// tests run as pgsql_owner, which RefreshDatabaseAsOwner does not wrap: each case opens its own transaction and rolls
// it back in a finally. The company context is never set: the catalog does not depend on it.
uses(RefreshDatabaseAsOwner::class);

/**
 * Runs the statements as pyme_owner in an explicit transaction that is always rolled back. Each valid control row goes
 * first and must pass, then the rejected statement must fail with a QueryException of the given SQLSTATE whose Postgres
 * message contains the needle (the constraint or column name), so that the test fails if the rule under test is dropped
 * and another one happens to reject the row.
 *
 * @param  list<array<string, string|null>>  $controls  valid rows inserted first
 * @param  array<string, string|null>  $rejected
 */
function municipalityTestExpectOwnerRejected(array $controls, array $rejected, string $sqlState, string $needle): void
{
    $owner = DB::connection('pgsql_owner');

    $owner->beginTransaction();
    try {
        foreach ($controls as $control) {
            $owner->table('municipality')->insert($control);
        }

        expect(fn () => $owner->table('municipality')->insert($rejected))->toThrow(function (QueryException $e) use ($sqlState, $needle): void {
            expect($e->errorInfo[0])->toBe($sqlState);
            expect($e->errorInfo[2])->toContain($needle);
        });
    } finally {
        $owner->rollBack();
    }
}

/**
 * A valid row that is not in the seed.
 *
 * @return array<string, string>
 */
function municipalityTestValidRow(string $code): array
{
    return ['code' => $code, 'name' => 'Test Municipality', 'department_name' => 'Test Department'];
}

// Seed.

it('holds exactly the rows of the DIVIPOLA file, with the department code derived from the municipality code', function (): void {
    $expected = DivipolaCsv::rows();

    // Guard against passing on an empty result.
    expect(count($expected))->toBeGreaterThan(1000);

    // Read as pyme_app with no company context: the catalog is global.
    $actual = array_map(
        fn (object $row): array => [
            'code' => $row->code,
            'name' => $row->name,
            'department_code' => $row->department_code,
            'department_name' => $row->department_name,
        ],
        DB::select('select code, name, department_code, department_name from municipality order by code collate "C"'),
    );

    $this->assertSame($expected, $actual, 'municipality rows (code, name, department_code, department_name) versus the DIVIPOLA file, both sorted by code.');
});

// Privileges of pyme_app: SELECT only. One statement per test.

it('does not let pyme_app insert into municipality (SQLSTATE 42501)', function (): void {
    [$code] = DivipolaCsv::unusedCodes(1);

    expect(fn () => DB::table('municipality')->insert(municipalityTestValidRow($code)))->toThrow(function (QueryException $e): void {
        expect($e->errorInfo[0])->toBe('42501');
        expect($e->errorInfo[2])->toContain('permission denied for table municipality');
    });
});

it('does not let pyme_app update municipality (SQLSTATE 42501)', function (): void {
    $code = DivipolaCsv::rows()[0]['code'];

    expect(fn () => DB::table('municipality')->where('code', $code)->update(['name' => 'Tampered']))->toThrow(function (QueryException $e): void {
        expect($e->errorInfo[0])->toBe('42501');
        expect($e->errorInfo[2])->toContain('permission denied for table municipality');
    });
});

it('does not let pyme_app delete from municipality (SQLSTATE 42501)', function (): void {
    $code = DivipolaCsv::rows()[0]['code'];

    expect(fn () => DB::table('municipality')->where('code', $code)->delete())->toThrow(function (QueryException $e): void {
        expect($e->errorInfo[0])->toBe('42501');
        expect($e->errorInfo[2])->toContain('permission denied for table municipality');
    });
});

// Constraints, as pyme_owner.

it('accepts a valid row from the owner and fills department_code with the first two digits of the code', function (): void {
    $owner = DB::connection('pgsql_owner');
    [$code] = DivipolaCsv::unusedCodes(1);

    $owner->beginTransaction();
    try {
        $owner->table('municipality')->insert(municipalityTestValidRow($code));

        $row = $owner->selectOne('select department_code from municipality where code = ?', [$code]);

        expect($row->department_code)->toBe(substr($code, 0, 2));
    } finally {
        $owner->rollBack();
    }
});

it('rejects a code that is not five digits with municipality_code_check (SQLSTATE 23514)', function (string $code): void {
    [$controlCode] = DivipolaCsv::unusedCodes(1);

    municipalityTestExpectOwnerRejected(
        [municipalityTestValidRow($controlCode)],
        municipalityTestValidRow($code),
        '23514',
        'violates check constraint "municipality_code_check"',
    );
})->with([
    'four digits' => ['1234'],
    'six digits' => ['123456'],
    'letters' => ['ABCDE'],
]);

it('rejects a blank name with municipality_name_check (SQLSTATE 23514)', function (string $name): void {
    [$controlCode, $rejectedCode] = DivipolaCsv::unusedCodes(2);

    municipalityTestExpectOwnerRejected(
        [municipalityTestValidRow($controlCode)],
        [...municipalityTestValidRow($rejectedCode), 'name' => $name],
        '23514',
        'violates check constraint "municipality_name_check"',
    );
})->with([
    'empty' => [''],
    'spaces' => ['   '],
]);

it('rejects a blank department_name with municipality_department_name_check (SQLSTATE 23514)', function (string $departmentName): void {
    [$controlCode, $rejectedCode] = DivipolaCsv::unusedCodes(2);

    municipalityTestExpectOwnerRejected(
        [municipalityTestValidRow($controlCode)],
        [...municipalityTestValidRow($rejectedCode), 'department_name' => $departmentName],
        '23514',
        'violates check constraint "municipality_department_name_check"',
    );
})->with([
    'empty' => [''],
    'spaces' => ['   '],
]);

it('rejects a NULL in a required column (NOT NULL, SQLSTATE 23502)', function (string $column): void {
    [$controlCode, $rejectedCode] = DivipolaCsv::unusedCodes(2);

    municipalityTestExpectOwnerRejected(
        [municipalityTestValidRow($controlCode)],
        [...municipalityTestValidRow($rejectedCode), $column => null],
        '23502',
        'column "'.$column.'" of relation "municipality"',
    );
})->with(['code', 'name', 'department_name']);

it('rejects a duplicate code taken from the seed with municipality_code_key (UNIQUE, SQLSTATE 23505)', function (): void {
    $seeded = DivipolaCsv::rows()[0]['code'];

    municipalityTestExpectOwnerRejected(
        [],
        municipalityTestValidRow($seeded),
        '23505',
        'violates unique constraint "municipality_code_key"',
    );
});

it('rejects an explicit department_code because the column is generated (SQLSTATE 428C9)', function (): void {
    [$code] = DivipolaCsv::unusedCodes(1);

    municipalityTestExpectOwnerRejected(
        [],
        [...municipalityTestValidRow($code), 'department_code' => substr($code, 0, 2)],
        '428C9',
        'column "department_code"',
    );
});
