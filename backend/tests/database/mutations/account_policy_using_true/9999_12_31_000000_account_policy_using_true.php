<?php

declare(strict_types=1);

// kills: backend/tests/Integration/TenantIsolationTest.php::it shows only the rows of the active company
// kills: backend/tests/Integration/TenantIsolationTest.php::it shows no rows when the context is empty, even if there is data (fail closed)
// kills: backend/tests/Integration/TenantIsolationTest.php::it shows no rows when the context was never set in the session (fail closed)
// kills: backend/tests/Integration/TenantIsolationTest.php::it deletes nothing of another company and its rows survive
// kills: backend/tests/Integration/TenantIsolationTest.php::it every public table with a company_id column has exactly the expected policies, except the global tables
// USING (true) in the account policy: every company sees and deletes the rows of the others.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP POLICY tenant_isolation ON account');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON account
                FOR ALL TO pyme_app
                USING (true)
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);
    }
};
