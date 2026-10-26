<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has no UPDATE on any primary key column of a public table, except the declared ones
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE
// kills: backend/tests/Integration/AppPermissionsTest.php::it does not let pyme_app update a primary key column (no UPDATE privilege on the column, SQLSTATE 42501) with data set "dataset "companies.id""
// pyme_app could change the primary key of companies through a column privilege. Today every company with data already
// fails with SQLSTATE 23503 when its id is changed, the ledger included, which reaches companies transitively
// (document_type, audit_log). The primary key must not depend on the shape of the foreign key graph, because a pending
// review of the direct foreign keys to companies could change it. Also, a company with no rows could take an id that
// companies_id_seq has not handed out yet, causing a future collision.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE (id) ON companies TO pyme_app');
    }
};
