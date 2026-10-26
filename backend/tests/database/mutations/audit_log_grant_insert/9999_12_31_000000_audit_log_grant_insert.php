<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AuditLogTest.php::it does not let pyme_app insert directly into audit_log (SQLSTATE 42501, permission denied)
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected privileges on every public table except migrations
// pyme_app gets INSERT on audit_log: no policy lets it insert, so RLS still rejects it, but the privilege is there.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT INSERT ON audit_log TO pyme_app');
    }
};
