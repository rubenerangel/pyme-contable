<?php

declare(strict_types=1);

// kills: backend/tests/Integration/DatabaseDriversTest.php::it updates an existing session row through the handler (second write is performUpdate)
// kills: backend/tests/Integration/DatabaseDriversTest.php::it updates a session row found by a new handler instance (read() marks it as existing)
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE
// pyme_app loses UPDATE on sessions.payload: the session handler could insert a session but not rewrite it (SQLSTATE 42501).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE UPDATE (payload) ON sessions FROM pyme_app');
    }
};
