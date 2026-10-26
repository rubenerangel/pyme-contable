<?php

declare(strict_types=1);

// kills: backend/tests/Integration/DatabaseDriversTest.php::it takes over an expired lock of another owner with an UPDATE of owner and expiration, and refreshes it
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE
// pyme_app loses UPDATE on cache_locks.owner: an expired lock could not be taken over by another owner (SQLSTATE 42501).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE UPDATE (owner) ON cache_locks FROM pyme_app');
    }
};
