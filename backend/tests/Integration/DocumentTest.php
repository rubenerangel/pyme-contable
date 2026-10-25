<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\LedgerFixtures;
use Tests\Support\SqlAssertions;

// The document table: a draft business document that is born without a journal entry (a document is posted when it has
// one; there is no status column). Covers the company default, the CHECK constraints, the reversal link (one reversal
// per original, same company) and the composite foreign keys. The privileges of pyme_app, RLS and the audit triggers are
// covered by AppPermissionsTest and TenantIsolationTest, which read them from the catalog. Immutability once posted
// arrives with step 5c. The company context is set with DatabaseCompanyContext and company_id is never passed. A SQL
// error aborts the RefreshDatabase transaction in Postgres, so each failing statement lives in its own test.
uses(RefreshDatabaseAsOwner::class);

it('inserts a draft with the company of the context and the reversal link empty', function (): void {
    $companyId = AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();

    $id = LedgerFixtures::insertDocument($typeId, ['description' => 'Test draft']);

    $row = DB::selectOne('select company_id, document_type_id, document_date::text as document_date, description, reverses_document_id from document where id = ?', [$id]);

    expect([
        (int) $row->company_id,
        (int) $row->document_type_id,
        $row->document_date,
        $row->description,
        $row->reverses_document_id,
    ])->toBe([$companyId, $typeId, '2026-01-15', 'Test draft', null]);
});

it('accepts a document without description', function (): void {
    AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();

    $id = LedgerFixtures::insertDocument($typeId);

    expect(DB::table('document')->where('id', $id)->value('description'))->toBeNull();
});

// CHECK constraints.

it('rejects a blank description with document_description_check (SQLSTATE 23514)', function (string $description): void {
    AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();
    LedgerFixtures::insertDocument($typeId, ['description' => 'Test control']);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertDocument($typeId, ['description' => $description]),
        '23514',
        'document_description_check',
    );
})->with([
    'empty' => [''],
    'spaces' => ['   '],
]);

it('rejects a document that reverses itself with document_reverses_document_id_check (SQLSTATE 23514)', function (): void {
    AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();
    $originalId = LedgerFixtures::insertDocument($typeId);
    $controlId = LedgerFixtures::insertDocument($typeId);
    $draftId = LedgerFixtures::insertDocument($typeId);

    // Control, before the failing statement (an error aborts the transaction): the same update pointing at another
    // document of the company passes.
    DB::table('document')->where('id', $controlId)->update(['reverses_document_id' => $originalId]);

    // The id comes from the sequence, so a document can only point at itself by an update.
    SqlAssertions::expectRejected(
        fn () => DB::table('document')->where('id', $draftId)->update(['reverses_document_id' => $draftId]),
        '23514',
        'document_reverses_document_id_check',
    );
});

// Reversal link: one reversal per original.

it('rejects a second document that reverses the same original with document_company_id_reverses_document_id_key (SQLSTATE 23505)', function (): void {
    AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();
    $originalId = LedgerFixtures::insertDocument($typeId);
    LedgerFixtures::insertDocument($typeId, ['reverses_document_id' => $originalId]);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertDocument($typeId, ['reverses_document_id' => $originalId]),
        '23505',
        'document_company_id_reverses_document_id_key',
    );
});

it('accepts many documents that reverse nothing, and one reversal for each different original', function (): void {
    AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();
    $first = LedgerFixtures::insertDocument($typeId);
    $second = LedgerFixtures::insertDocument($typeId);

    LedgerFixtures::insertDocument($typeId);
    LedgerFixtures::insertDocument($typeId);
    LedgerFixtures::insertDocument($typeId, ['reverses_document_id' => $first]);
    LedgerFixtures::insertDocument($typeId, ['reverses_document_id' => $second]);

    expect(DB::table('document')->count())->toBe(6);
});

// Composite foreign keys: a document points only at rows of its own company.

it('rejects a document type of another company with document_document_type_fkey (SQLSTATE 23503)', function (): void {
    AccountFixtures::enterNewCompany();
    $typeOfOther = LedgerFixtures::insertDocumentType();

    AccountFixtures::enterNewCompany();
    $ownType = LedgerFixtures::insertDocumentType();
    LedgerFixtures::insertDocument($ownType);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertDocument($typeOfOther),
        '23503',
        'document_document_type_fkey',
    );
});

it('rejects a reversal of a document of another company with document_reverses_document_fkey (SQLSTATE 23503)', function (): void {
    AccountFixtures::enterNewCompany();
    $typeOfOther = LedgerFixtures::insertDocumentType();
    $documentOfOther = LedgerFixtures::insertDocument($typeOfOther);

    AccountFixtures::enterNewCompany();
    $ownType = LedgerFixtures::insertDocumentType();
    $ownDocument = LedgerFixtures::insertDocument($ownType);
    LedgerFixtures::insertDocument($ownType, ['reverses_document_id' => $ownDocument]);

    SqlAssertions::expectRejected(
        fn () => LedgerFixtures::insertDocument($ownType, ['reverses_document_id' => $documentOfOther]),
        '23503',
        'document_reverses_document_fkey',
    );
});

// A draft can still change and disappear.

it('updates a draft', function (): void {
    AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();
    $otherTypeId = LedgerFixtures::insertDocumentType('TS2');
    $id = LedgerFixtures::insertDocument($typeId, ['description' => 'Test before']);

    $affected = DB::table('document')->where('id', $id)->update([
        'document_type_id' => $otherTypeId,
        'document_date' => '2026-02-20',
        'description' => 'Test after',
    ]);

    $row = DB::selectOne('select document_type_id, document_date::text as document_date, description from document where id = ?', [$id]);

    expect([$affected, (int) $row->document_type_id, $row->document_date, $row->description])
        ->toBe([1, $otherTypeId, '2026-02-20', 'Test after']);
});

it('deletes a draft', function (): void {
    AccountFixtures::enterNewCompany();
    $typeId = LedgerFixtures::insertDocumentType();
    $id = LedgerFixtures::insertDocument($typeId);

    $affected = DB::table('document')->where('id', $id)->delete();

    expect([$affected, DB::table('document')->count()])->toBe([1, 0]);
});

it('does not delete a posted document, the one that has a journal entry (SQLSTATE 23503, journal_entry_document_fkey)', function (): void {
    $ledger = LedgerFixtures::enterCompanyWithLedger();
    $draft = LedgerFixtures::insertDocument($ledger['type']);

    // Control, before the failing statement (an error aborts the transaction): a draft without entry is deleted.
    $deleted = DB::table('document')->where('id', $draft)->delete();
    expect($deleted)->toBe(1);

    SqlAssertions::expectRejected(
        fn () => DB::table('document')->where('id', $ledger['document'])->delete(),
        '23503',
        'journal_entry_document_fkey',
    );
});
