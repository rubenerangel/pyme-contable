<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AuditCatalogTest.php::it every function and procedure owned by pyme_owner or in public, SECURITY DEFINER or not, pins the exact search_path pg_catalog, public, pg_temp
// audit_log_capture (SECURITY DEFINER) without pg_temp last in search_path: a temporary table could shadow a public one.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER FUNCTION audit_log_capture() SET search_path = pg_catalog, public');
    }
};
