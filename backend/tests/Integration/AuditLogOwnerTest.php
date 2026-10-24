<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;

// The audit_log ledger is append-only even for its owner: with FORCE ROW LEVEL SECURITY and no UPDATE or DELETE policy
// for anybody, pyme_owner modifies and deletes nothing, even with the company context set on its own connection.
// This needs real commits: pyme_owner is a different connection from the default one, so it does not see the rows of
// a transaction that is still open there, and whether it can read its own rows must not matter for the proof. The row
// is committed by pyme_app through the audit trigger, pyme_owner tries to change it, and pyme_app reads it back.
// DatabaseTruncationAsOwner truncates the tables between tests.
uses(DatabaseTruncationAsOwner::class);

/**
 * Snapshot of every audit_log row as pyme_app sees it in the company in context (jsonb as text, to compare exactly).
 *
 * @return list<array<string, mixed>>
 */
function auditLogOwnerTestSnapshot(): array
{
    return array_map(
        fn (object $row): array => (array) $row,
        DB::select('select id, company_id, user_id, kind, table_name, record_id, operation, old_values::text as old_values, new_values::text as new_values, event_type, details::text as details, occurred_at::text as occurred_at from audit_log order by id'),
    );
}

it('does not let pyme_owner update or delete audit rows, even with the context set: 0 rows affected and the row stays the same', function (string $operation): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $appContext = app(DatabaseCompanyContext::class);
    $owner = DB::connection('pgsql_owner');
    $ownerContext = new DatabaseCompanyContext($owner);

    try {
        // Real commit (no wrapping transaction): the trigger writes the audit row with the context of pyme_app.
        $appContext->enter($company->id, $user->id);
        DB::table('audit_probe')->insert(['label' => 'original']);
        $before = auditLogOwnerTestSnapshot();

        $ownerContext->enter($company->id, $user->id);
        $affected = $operation === 'update'
            ? $owner->update("update audit_log set table_name = 'tampered', user_id = user_id + 1")
            : $owner->delete('delete from audit_log');

        expect(['affected' => $affected, 'rows before' => count($before)])->toBe(['affected' => 0, 'rows before' => 1]);
        expect(auditLogOwnerTestSnapshot())->toBe($before);
    } finally {
        $ownerContext->leave();
        $appContext->leave();
    }
})->with(['update' => ['update'], 'delete' => ['delete']]);

it('does not let pyme_owner update or delete audit rows without the context either', function (string $operation): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $appContext = app(DatabaseCompanyContext::class);
    $owner = DB::connection('pgsql_owner');

    try {
        $appContext->enter($company->id, $user->id);
        DB::table('audit_probe')->insert(['label' => 'original']);
        $before = auditLogOwnerTestSnapshot();

        $affected = $operation === 'update'
            ? $owner->update("update audit_log set table_name = 'tampered'")
            : $owner->delete('delete from audit_log');

        expect($affected)->toBe(0);
        expect(auditLogOwnerTestSnapshot())->toBe($before);
    } finally {
        $appContext->leave();
    }
})->with(['update' => ['update'], 'delete' => ['delete']]);
