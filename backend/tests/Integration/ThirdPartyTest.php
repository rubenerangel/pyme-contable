<?php

declare(strict_types=1);

use App\Domain\ThirdParties\PersonType;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\ThirdParty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\MasterDataFixtures;

// The third_party table (third parties of a company): CHECK and NOT NULL constraints, defaults, uniqueness per company
// and the ThirdParty model casts. The privileges of pyme_app, RLS and the audit triggers are covered by
// AppPermissionsTest and TenantIsolationTest, which read them from the catalog. Every value is made up on purpose
// ('Test', identification numbers in the 9000000xx series) and resembles no real person. The company context is set with DatabaseCompanyContext and
// company_id is never passed. A SQL error aborts the RefreshDatabase transaction in Postgres, so each failing
// statement lives in its own test. The rejection datasets pair the rejected columns with a neighbouring valid row
// (control) that is inserted first in the same test, so the only difference between both is the rule under test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Asserts that the statement fails with a QueryException carrying the given SQLSTATE. For a CHECK (23514) or a UNIQUE
 * (23505) violation the constraint name is mandatory and must appear in the Postgres message, so that the test fails
 * if the constraint under test is dropped and another one happens to reject the row.
 */
function thirdPartyTestExpectSqlState(Closure $statement, string $sqlState, ?string $constraint = null): void
{
    $kind = match ($sqlState) {
        '23514' => 'check',
        '23505' => 'unique',
        default => null,
    };
    if ($kind !== null && $constraint === null) {
        throw new InvalidArgumentException("SQLSTATE {$sqlState} requires the name of the expected constraint.");
    }

    expect($statement)->toThrow(function (QueryException $e) use ($sqlState, $kind, $constraint): void {
        expect($e->errorInfo[0])->toBe($sqlState);

        if ($kind !== null) {
            expect($e->errorInfo[2])->toMatch('/violates '.$kind.' constraint "'.preg_quote((string) $constraint, '/').'"/');
        }
    });
}

/**
 * Inserts the control row, which must pass, and then the rejected row, which must fail with a CHECK violation of the
 * given constraint. The rejected row gets its own id_number unless its overrides set one, so the two never collide on
 * the unique key. Postgres reports only the first CHECK that fails, in alphabetical order by constraint name, so every
 * rejected row violates exactly one constraint and the test does not depend on how the constraints are named.
 *
 * @param  array<string, mixed>  $rejected
 * @param  array<string, mixed>  $control
 */
function thirdPartyTestExpectRejected(array $rejected, array $control, string $constraint, string $sqlState = '23514'): void
{
    AccountFixtures::enterNewCompany();
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow($control));

    thirdPartyTestExpectSqlState(
        fn () => DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['id_number' => '900000002', ...$rejected])),
        $sqlState,
        $constraint,
    );
}

// Insert and defaults.

it('inserts a natural and a legal third party with the company of the context, and applies the defaults', function (): void {
    $companyId = AccountFixtures::enterNewCompany();

    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow());
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow([
        'id_type' => '31',
        'id_number' => '900000002',
        ...MasterDataFixtures::legalNames(),
    ]));

    $rows = [];
    foreach (DB::select('select * from third_party order by id') as $row) {
        $rows[] = [
            'company_id' => (int) $row->company_id,
            'id_type' => $row->id_type,
            'id_number' => $row->id_number,
            'country' => $row->country,
            'person_type' => $row->person_type,
            'first_name' => $row->first_name,
            'business_name' => $row->business_name,
            'is_active' => (bool) $row->is_active,
        ];
    }

    expect($rows)->toBe([
        [
            'company_id' => $companyId,
            'id_type' => '13',
            'id_number' => '900000001',
            'country' => 'CO',
            'person_type' => 'natural',
            'first_name' => 'Test',
            'business_name' => null,
            'is_active' => true,
        ],
        [
            'company_id' => $companyId,
            'id_type' => '31',
            'id_number' => '900000002',
            'country' => 'CO',
            'person_type' => 'legal',
            'first_name' => null,
            'business_name' => 'Test Company',
            'is_active' => true,
        ],
    ]);
});

it('accepts a natural person with all four name columns', function (): void {
    AccountFixtures::enterNewCompany();

    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow([
        'middle_names' => 'Test Middle',
        'second_surname' => 'Other',
    ]));

    $row = DB::selectOne('select middle_names, second_surname from third_party');

    expect([$row->middle_names, $row->second_surname])->toBe(['Test Middle', 'Other']);
});

// CHECK constraints: identification.

it('rejects an invalid id_type with third_party_id_type_check (SQLSTATE 23514)', function (string $idType): void {
    thirdPartyTestExpectRejected(['id_type' => $idType], ['id_type' => '13'], 'third_party_id_type_check');
})->with([
    'one digit' => ['3'],
    'three digits' => ['311'],
    'letters' => ['AB'],
    'empty' => [''],
]);

// Each row violates one CHECK only. For a NIT (id_type 31) an empty or space-padded id_number also fails the digits-only
// rule, so third_party_id_number_check cannot be isolated with a NIT: the empty and padded cases use other id_types.
it('rejects an invalid id_number with the CHECK constraint of its rule (SQLSTATE 23514)', function (string $idType, string $idNumber, string $controlNumber, string $constraint): void {
    thirdPartyTestExpectRejected(
        ['id_type' => $idType, 'id_number' => $idNumber],
        ['id_type' => $idType, 'id_number' => $controlNumber],
        $constraint,
    );
})->with([
    'empty' => ['13', '', '900000003', 'third_party_id_number_check'],
    'blank' => ['13', '   ', '900000003', 'third_party_id_number_check'],
    'leading space' => ['13', ' 900000004', '900000004', 'third_party_id_number_check'],
    'trailing space' => ['13', '900000004 ', '900000004', 'third_party_id_number_check'],
    'alphanumeric with a leading space' => ['41', ' TEST9000004', 'TEST9000004', 'third_party_id_number_check'],
    'NIT with a check digit' => ['31', '900000002-7', '900000002', 'third_party_nit_digits_check'],
    'NIT with dots' => ['31', '900.000.002', '900000002', 'third_party_nit_digits_check'],
    'NIT with letters' => ['31', 'TEST000002', '900000002', 'third_party_nit_digits_check'],
]);

it('accepts the id_number formats of each id_type: only digits for a NIT, alphanumeric for the others', function (string $idType, string $idNumber): void {
    AccountFixtures::enterNewCompany();

    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['id_type' => $idType, 'id_number' => $idNumber]));

    $stored = DB::selectOne('select id_type, id_number from third_party');

    expect([$stored->id_type, $stored->id_number])->toBe([$idType, $idNumber]);
})->with([
    'NIT with digits only' => ['31', '900000002'],
    'other type with digits' => ['13', '900000003'],
    'other type with letters and digits' => ['41', 'TEST9000004'],
    'cédula type with letters and digits' => ['13', 'TEST9000005'],
]);

it('rejects an invalid country with third_party_country_check (SQLSTATE 23514)', function (string $country): void {
    thirdPartyTestExpectRejected(['country' => $country], ['country' => 'CO'], 'third_party_country_check');
})->with([
    'lowercase' => ['co'],
    'three letters' => ['COL'],
    'one letter' => ['C'],
    'letter and digit' => ['C1'],
    'empty' => [''],
]);

it('accepts any ISO 3166-1 alpha-2 shaped country', function (string $country): void {
    AccountFixtures::enterNewCompany();

    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['country' => $country]));

    expect(DB::table('third_party')->value('country'))->toBe($country);
})->with(['CO', 'US']);

it('rejects an invalid person_type with third_party_person_type_check (SQLSTATE 23514)', function (string $personType): void {
    thirdPartyTestExpectRejected(['person_type' => $personType], ['person_type' => 'natural'], 'third_party_person_type_check');
})->with([
    'unknown value' => ['other'],
    'wrong case' => ['Natural'],
    'empty' => [''],
]);

// CHECK constraints: the shape of the name columns per person type.

/**
 * Rejected and control overrides, and the expected constraint, for the shape rules of each person type. The control of
 * each case is the same person type with the offending column fixed. No column is blank, so only the shape rule fails.
 *
 * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
 */
function thirdPartyTestShapeCases(): array
{
    $natural = MasterDataFixtures::naturalNames();
    $legal = MasterDataFixtures::legalNames();

    return [
        'natural without first_name' => [[...$natural, 'first_name' => null], $natural, 'third_party_names_check'],
        'natural without first_surname' => [[...$natural, 'first_surname' => null], $natural, 'third_party_names_check'],
        'natural with business_name' => [[...$natural, 'business_name' => 'Test Company'], $natural, 'third_party_names_check'],
        'legal without business_name' => [[...$legal, 'business_name' => null], $legal, 'third_party_names_check'],
        'legal with first_name' => [[...$legal, 'first_name' => 'Test'], $legal, 'third_party_names_check'],
        'legal with middle_names' => [[...$legal, 'middle_names' => 'Test'], $legal, 'third_party_names_check'],
        'legal with first_surname' => [[...$legal, 'first_surname' => 'Sample'], $legal, 'third_party_names_check'],
        'legal with second_surname' => [[...$legal, 'second_surname' => 'Other'], $legal, 'third_party_names_check'],
    ];
}

it('rejects name columns that do not fit the person type with third_party_names_check (SQLSTATE 23514)', function (array $rejected, array $control, string $constraint): void {
    thirdPartyTestExpectRejected($rejected, $control, $constraint);
})->with(thirdPartyTestShapeCases());

/**
 * Rejected and control overrides, and the expected constraint, for a present but blank text column. A blank value is
 * not NULL, so the shape rule of the person type still holds and the blank rule is the only one that rejects the row.
 *
 * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
 */
function thirdPartyTestBlankCases(): array
{
    $natural = MasterDataFixtures::naturalNames();
    $legal = MasterDataFixtures::legalNames();

    return [
        'natural with an empty first_name' => [[...$natural, 'first_name' => ''], $natural, 'third_party_names_not_blank_check'],
        'natural with a blank first_name' => [[...$natural, 'first_name' => '   '], $natural, 'third_party_names_not_blank_check'],
        'natural with a blank first_surname' => [[...$natural, 'first_surname' => '   '], $natural, 'third_party_names_not_blank_check'],
        'natural with an empty middle_names' => [[...$natural, 'middle_names' => ''], [...$natural, 'middle_names' => 'Test'], 'third_party_names_not_blank_check'],
        'natural with a blank middle_names' => [[...$natural, 'middle_names' => '   '], [...$natural, 'middle_names' => 'Test'], 'third_party_names_not_blank_check'],
        'natural with an empty second_surname' => [[...$natural, 'second_surname' => ''], [...$natural, 'second_surname' => 'Other'], 'third_party_names_not_blank_check'],
        'natural with a blank second_surname' => [[...$natural, 'second_surname' => '   '], [...$natural, 'second_surname' => 'Other'], 'third_party_names_not_blank_check'],
        'legal with an empty business_name' => [[...$legal, 'business_name' => ''], $legal, 'third_party_names_not_blank_check'],
        'legal with a blank business_name' => [[...$legal, 'business_name' => '   '], $legal, 'third_party_names_not_blank_check'],
    ];
}

it('rejects a blank text in any of the name columns with third_party_names_not_blank_check (SQLSTATE 23514)', function (array $rejected, array $control, string $constraint): void {
    thirdPartyTestExpectRejected($rejected, $control, $constraint);
})->with(thirdPartyTestBlankCases());

// NOT NULL.

it('rejects an insert that leaves a required column out or NULL (NOT NULL, SQLSTATE 23502)', function (string $column, bool $explicitNull): void {
    AccountFixtures::enterNewCompany();
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['id_number' => '900000003']));

    $row = MasterDataFixtures::thirdPartyRow();
    if ($explicitNull) {
        $row[$column] = null;
    } else {
        unset($row[$column]);
    }

    thirdPartyTestExpectSqlState(fn () => DB::table('third_party')->insert($row), '23502');
})->with([
    'country omitted (no default)' => ['country', false],
    'person_type omitted (no default)' => ['person_type', false],
    'id_type omitted' => ['id_type', false],
    'id_number omitted' => ['id_number', false],
    'country NULL' => ['country', true],
    'person_type NULL' => ['person_type', true],
    'id_type NULL' => ['id_type', true],
    'id_number NULL' => ['id_number', true],
    'is_active NULL' => ['is_active', true],
]);

it('keeps is_active as given when it is false', function (): void {
    AccountFixtures::enterNewCompany();

    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['is_active' => false]));

    expect((bool) DB::table('third_party')->value('is_active'))->toBeFalse();
});

// Uniqueness per company: (company_id, id_type, id_number, country).

it('rejects a second third party with the same id_type, id_number and country in the company (UNIQUE, SQLSTATE 23505)', function (): void {
    AccountFixtures::enterNewCompany();
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow());

    // Other names and person type: only the identification is the key.
    thirdPartyTestExpectSqlState(
        fn () => DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(MasterDataFixtures::legalNames())),
        '23505',
        'third_party_company_id_id_type_id_number_country_key',
    );
});

it('accepts the same id_number with another id_type or another country in the same company', function (): void {
    AccountFixtures::enterNewCompany();
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow());

    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['id_type' => '41']));
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['country' => 'US']));

    expect(DB::table('third_party')->count())->toBe(3);
});

it('accepts the same identification in another company, and each company sees only its own third parties', function (): void {
    $context = app(DatabaseCompanyContext::class);

    $companyA = AccountFixtures::enterNewCompany();
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow());

    $companyB = AccountFixtures::enterNewCompany();
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow());

    $seenByB = DB::table('third_party')->pluck('company_id')->map(fn ($id) => (int) $id)->all();
    $context->enter($companyA, User::factory()->create()->id);
    $seenByA = DB::table('third_party')->pluck('company_id')->map(fn ($id) => (int) $id)->all();

    expect(['a' => $seenByA, 'b' => $seenByB])->toBe(['a' => [$companyA], 'b' => [$companyB]]);
});

// Model.

it('casts person_type to PersonType when read back from the database through the model', function (): void {
    AccountFixtures::enterNewCompany();
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow());
    DB::table('third_party')->insert(MasterDataFixtures::thirdPartyRow(['id_number' => '900000002', ...MasterDataFixtures::legalNames()]));

    $read = ThirdParty::query()->orderBy('id_number')->get()->map(fn (ThirdParty $thirdParty) => $thirdParty->person_type)->all();

    expect($read)->toBe([PersonType::Natural, PersonType::Legal]);
});

it('has the PersonType cases natural and legal', function (): void {
    expect([PersonType::Natural->value, PersonType::Legal->value])->toBe(['natural', 'legal']);
});

it('stores a third party created through the model in the company of the context, with the enum as person_type', function (): void {
    $companyId = AccountFixtures::enterNewCompany();

    ThirdParty::create(MasterDataFixtures::thirdPartyRow(['person_type' => PersonType::Natural]));

    $row = DB::selectOne('select company_id, person_type, is_active from third_party');

    expect([(int) $row->company_id, $row->person_type, (bool) $row->is_active])->toBe([$companyId, 'natural', true]);
});

it('casts is_active to a strict bool in memory and has no timestamps (without a cast the integers would stay as they are)', function (): void {
    // No database: pdo_pgsql already returns bool, so only integers handed to the model can prove the cast exists.
    $on = new ThirdParty(['is_active' => 1]);
    $off = new ThirdParty(['is_active' => 0]);

    expect([
        'from 1' => $on->is_active,
        'from 0' => $off->is_active,
        'timestamps' => $on->usesTimestamps(),
        'table' => $on->getTable(),
    ])->toBe(['from 1' => true, 'from 0' => false, 'timestamps' => false, 'table' => 'third_party']);
});
