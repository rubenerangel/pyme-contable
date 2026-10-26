<?php

declare(strict_types=1);

// kills: backend/tests/Integration/TenantIsolationTest.php::it stores the active company in company_id when the insert does not pass it
// kills: backend/tests/Integration/TenantIsolationTest.php::it every public table with a company_id column also has it NOT NULL with a DEFAULT read from the context, except the global tables
// kills: backend/tests/Integration/AccountTest.php::it inserts a root account and a child with the company of the context, and applies the defaults
// company_id of account without DEFAULT: an insert without company_id gets NULL and the policy rejects it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE account ALTER COLUMN company_id DROP DEFAULT');
    }
};
