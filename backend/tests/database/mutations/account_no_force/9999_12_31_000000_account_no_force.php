<?php

declare(strict_types=1);

// kills: backend/tests/Integration/TenantIsolationTest.php::it every public table with a company_id column has RLS enabled, forced and at least one policy, except the global tables
// Without FORCE the owner of account skips RLS. pyme_app is not the owner, so only the catalog test can see it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE account NO FORCE ROW LEVEL SECURITY');
    }
};
