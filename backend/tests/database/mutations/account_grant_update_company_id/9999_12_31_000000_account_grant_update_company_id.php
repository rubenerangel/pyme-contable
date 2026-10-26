<?php

declare(strict_types=1);

// kills: backend/tests/Integration/TenantIsolationTest.php::it rejects moving a row to another company with an update (no UPDATE privilege on company_id, SQLSTATE 42501)
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE
// kills: backend/tests/Integration/AccountTest.php::it does not let pyme_app update company_id or id (no UPDATE privilege on the column, SQLSTATE 42501) with data set "dataset "company_id""
// pyme_app may update company_id of account: the WITH CHECK still rejects a move, but with an RLS message instead of permission denied.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE (company_id) ON account TO pyme_app');
    }
};
