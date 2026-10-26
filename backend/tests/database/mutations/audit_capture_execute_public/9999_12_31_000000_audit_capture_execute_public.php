<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AuditCatalogTest.php::it no SECURITY DEFINER function or procedure owned by pyme_owner or in public has EXECUTE for PUBLIC
// kills: backend/tests/Integration/AuditCatalogTest.php::it pyme_app can execute audit_log_event but not audit_log_capture
// EXECUTE on audit_log_capture for PUBLIC, pyme_app included.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT EXECUTE ON FUNCTION audit_log_capture() TO PUBLIC');
    }
};
