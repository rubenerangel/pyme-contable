<?php

declare(strict_types=1);

// control: survives
// kills: backend/tests/Integration/TenantIsolationTest.php::it every public table with a company_id column has RLS enabled, forced and at least one policy, except the global tables
// Control of the runner (scripts/mutants.py): it changes nothing, so its killer must pass. If it gets killed, the
// runner or MutationAppliedTest is miscounting.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('select 1');
    }
};
