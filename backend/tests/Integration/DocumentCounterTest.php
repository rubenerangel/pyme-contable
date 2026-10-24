<?php

declare(strict_types=1);

use App\Infrastructure\Ledger\DocumentCounters;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// document_type and document_counter, and DocumentCounters::next() on top of them (sequential numbering per company,
// document type and year). Concurrency lives in DocumentCounterConcurrencyTest. The company context is set with
// DatabaseCompanyContext and company_id is never passed. RefreshDatabase wraps the test in a transaction, which is
// what next() requires. A SQL error aborts that transaction in Postgres, so each failing statement lives in its own
// test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Creates a company and returns it without entering its context.
 */
function counterTestCompany(): Company
{
    return Company::factory()->create();
}

/**
 * Inserts a document type for the company (entering and leaving its context) and returns its id.
 */
function counterTestDocumentType(Company $company, string $code = 'INV', bool $resetsYearly = true): int
{
    $context = app(DatabaseCompanyContext::class);

    // No finally on purpose: if the insert fails the transaction is aborted, a leave() in a finally would fail with
    // 25P02 and hide the original error. The RefreshDatabase rollback discards the context anyway.
    $context->enter($company->id);
    $id = (int) DB::table('document_type')->insertGetId([
        'code' => $code,
        'name' => 'Document type '.$code,
        'resets_yearly' => $resetsYearly,
    ]);
    $context->leave();

    return $id;
}

/**
 * Document counters on the default connection (pyme_app), which is inside the RefreshDatabase transaction.
 */
function counterTestCounters(): DocumentCounters
{
    return new DocumentCounters(DB::connection());
}

/**
 * Asserts that the statement fails with a QueryException carrying the given SQLSTATE and, when given, a driver
 * message that contains the text.
 */
function counterTestExpectSqlState(Closure $statement, string $sqlState, ?string $messageContains = null): void
{
    expect($statement)->toThrow(function (QueryException $e) use ($sqlState, $messageContains): void {
        expect($e->errorInfo[0])->toBe($sqlState);

        if ($messageContains !== null) {
            expect($e->errorInfo[2])->toContain($messageContains);
        }
    });
}

/**
 * Enters the context of the company, inserts a counter for the type at last_number 1 (the only value the trigger
 * accepts on insert) and returns its id. Leaves the context entered.
 */
function counterTestInsertCounterAtOne(Company $company, int $documentTypeId, ?int $year = 2026): int
{
    app(DatabaseCompanyContext::class)->enter($company->id);

    return (int) DB::table('document_counter')->insertGetId([
        'document_type_id' => $documentTypeId,
        'year' => $year,
        'last_number' => 1,
    ]);
}

it('next returns 1, 2, 3 in a row for the same document type and year', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    app(DatabaseCompanyContext::class)->enter($company->id);
    $counters = counterTestCounters();

    $numbers = [$counters->next($typeId, 2026), $counters->next($typeId, 2026), $counters->next($typeId, 2026)];

    expect($numbers)->toBe([1, 2, 3]);
});

it('keeps an independent counter per document type', function (): void {
    $company = counterTestCompany();
    $invoiceId = counterTestDocumentType($company, 'INV');
    $paymentId = counterTestDocumentType($company, 'PAY');
    app(DatabaseCompanyContext::class)->enter($company->id);
    $counters = counterTestCounters();

    $numbers = [
        'invoice 1' => $counters->next($invoiceId, 2026),
        'invoice 2' => $counters->next($invoiceId, 2026),
        'payment 1' => $counters->next($paymentId, 2026),
        'invoice 3' => $counters->next($invoiceId, 2026),
    ];

    expect($numbers)->toBe(['invoice 1' => 1, 'invoice 2' => 2, 'payment 1' => 1, 'invoice 3' => 3]);
});

it('keeps an independent counter per year', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    app(DatabaseCompanyContext::class)->enter($company->id);
    $counters = counterTestCounters();

    $numbers = [
        '2025 first' => $counters->next($typeId, 2025),
        '2026 first' => $counters->next($typeId, 2026),
        '2025 second' => $counters->next($typeId, 2025),
    ];

    expect($numbers)->toBe(['2025 first' => 1, '2026 first' => 1, '2025 second' => 2]);
});

it('treats a NULL year as one single counter, apart from the counters of a year', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company, 'LTR', resetsYearly: false);
    app(DatabaseCompanyContext::class)->enter($company->id);
    $counters = counterTestCounters();

    $numbers = [
        'null first' => $counters->next($typeId, null),
        'null second' => $counters->next($typeId, null),
        'year first' => $counters->next($typeId, 2026),
        'null third' => $counters->next($typeId, null),
    ];

    expect($numbers)->toBe(['null first' => 1, 'null second' => 2, 'year first' => 1, 'null third' => 3]);
});

it('starts company B at 1 with its own document type while A is at 3, and neither sees the counter of the other', function (): void {
    $context = app(DatabaseCompanyContext::class);
    $companyA = counterTestCompany();
    $companyB = counterTestCompany();
    // The same code in both companies is allowed: UNIQUE (company_id, code).
    $typeA = counterTestDocumentType($companyA, 'INV');
    $typeB = counterTestDocumentType($companyB, 'INV');
    $counters = counterTestCounters();

    $context->enter($companyA->id);
    $counters->next($typeA, 2026);
    $counters->next($typeA, 2026);
    $lastOfA = $counters->next($typeA, 2026);

    $context->enter($companyB->id);
    $firstOfB = $counters->next($typeB, 2026);
    $seenByB = DB::table('document_counter')->pluck('last_number', 'document_type_id')->all();

    $context->enter($companyA->id);
    $seenByA = DB::table('document_counter')->pluck('last_number', 'document_type_id')->all();

    expect(['a' => $lastOfA, 'b' => $firstOfB, 'seenByA' => $seenByA, 'seenByB' => $seenByB])->toBe([
        'a' => 3,
        'b' => 1,
        'seenByA' => [$typeA => 3],
        'seenByB' => [$typeB => 1],
    ]);
});

it('rejects a direct insert of a counter with last_number other than 1 (trigger, SQLSTATE 23514)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    app(DatabaseCompanyContext::class)->enter($company->id);

    counterTestExpectSqlState(fn () => DB::table('document_counter')->insert([
        'document_type_id' => $typeId,
        'year' => 2026,
        'last_number' => 2,
    ]), '23514', 'document_counter');
});

it('rejects an update that skips a number (+2, trigger, SQLSTATE 23514)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    $counterId = counterTestInsertCounterAtOne($company, $typeId);

    counterTestExpectSqlState(
        fn () => DB::table('document_counter')->where('id', $counterId)->update(['last_number' => 3]),
        '23514',
        'document_counter',
    );
});

it('rejects an update that goes backwards (trigger, SQLSTATE 23514)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    $counterId = counterTestInsertCounterAtOne($company, $typeId);
    DB::table('document_counter')->where('id', $counterId)->update(['last_number' => 2]);

    counterTestExpectSqlState(
        fn () => DB::table('document_counter')->where('id', $counterId)->update(['last_number' => 1]),
        '23514',
        'document_counter',
    );
});

it('rejects an update that leaves the number unchanged (it must be exactly +1, trigger, SQLSTATE 23514)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    $counterId = counterTestInsertCounterAtOne($company, $typeId);

    counterTestExpectSqlState(
        fn () => DB::table('document_counter')->where('id', $counterId)->update(['last_number' => 1]),
        '23514',
        'document_counter',
    );
});

it('accepts an update of exactly +1 on the counter', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    $counterId = counterTestInsertCounterAtOne($company, $typeId);

    $affected = DB::table('document_counter')->where('id', $counterId)->update(['last_number' => 2]);

    expect([
        'affected' => $affected,
        'last_number' => (int) DB::table('document_counter')->where('id', $counterId)->value('last_number'),
    ])->toBe(['affected' => 1, 'last_number' => 2]);
});

it('rejects a second document type with the same code in the same company (UNIQUE, SQLSTATE 23505)', function (): void {
    $company = counterTestCompany();
    counterTestDocumentType($company, 'INV');
    app(DatabaseCompanyContext::class)->enter($company->id);

    counterTestExpectSqlState(fn () => DB::table('document_type')->insert([
        'code' => 'INV',
        'name' => 'Another invoice',
        'resets_yearly' => true,
    ]), '23505');
});

it('rejects a document type with a NULL resets_yearly (NOT NULL, SQLSTATE 23502)', function (): void {
    $company = counterTestCompany();
    app(DatabaseCompanyContext::class)->enter($company->id);

    counterTestExpectSqlState(fn () => DB::table('document_type')->insert([
        'code' => 'INV',
        'name' => 'Invoice',
        'resets_yearly' => null,
    ]), '23502');
});

it('rejects a document type that omits resets_yearly: the column has no DEFAULT (SQLSTATE 23502)', function (): void {
    $company = counterTestCompany();
    app(DatabaseCompanyContext::class)->enter($company->id);

    counterTestExpectSqlState(
        fn () => DB::table('document_type')->insert(['code' => 'INV', 'name' => 'Invoice']),
        '23502',
    );
});

it('lets pyme_app update the name of a document type', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    app(DatabaseCompanyContext::class)->enter($company->id);

    $affected = DB::table('document_type')->where('id', $typeId)->update(['name' => 'Renamed']);

    expect(['affected' => $affected, 'name' => DB::table('document_type')->where('id', $typeId)->value('name')])->toBe([
        'affected' => 1,
        'name' => 'Renamed',
    ]);
});

it('does not let pyme_app update the code of a document type (no UPDATE privilege on the column, SQLSTATE 42501)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    app(DatabaseCompanyContext::class)->enter($company->id);

    counterTestExpectSqlState(
        fn () => DB::table('document_type')->where('id', $typeId)->update(['code' => 'OTHER']),
        '42501',
    );
});

it('does not let pyme_app update resets_yearly of a document type (no UPDATE privilege on the column, SQLSTATE 42501)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    app(DatabaseCompanyContext::class)->enter($company->id);

    counterTestExpectSqlState(
        fn () => DB::table('document_type')->where('id', $typeId)->update(['resets_yearly' => false]),
        '42501',
    );
});

it('does not let pyme_app delete a document type (no DELETE privilege, SQLSTATE 42501)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    app(DatabaseCompanyContext::class)->enter($company->id);

    counterTestExpectSqlState(fn () => DB::table('document_type')->where('id', $typeId)->delete(), '42501');
});

it('does not let pyme_app delete a document counter (no DELETE privilege, SQLSTATE 42501)', function (): void {
    $company = counterTestCompany();
    $typeId = counterTestDocumentType($company);
    $counterId = counterTestInsertCounterAtOne($company, $typeId);

    counterTestExpectSqlState(fn () => DB::table('document_counter')->where('id', $counterId)->delete(), '42501');
});

it('rejects a counter that points at a document type of another company (composite FK, SQLSTATE 23503)', function (): void {
    $companyA = counterTestCompany();
    $companyB = counterTestCompany();
    $typeOfB = counterTestDocumentType($companyB);

    // Context A with the id of a type of B, as pyme_app: as the owner the case could not be built because of FORCE.
    app(DatabaseCompanyContext::class)->enter($companyA->id);

    counterTestExpectSqlState(fn () => DB::table('document_counter')->insert([
        'document_type_id' => $typeOfB,
        'year' => 2026,
        'last_number' => 1,
    ]), '23503');
});
