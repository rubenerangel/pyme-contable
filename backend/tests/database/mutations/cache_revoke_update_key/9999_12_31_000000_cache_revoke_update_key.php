<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has no UPDATE on any primary key column of a public table, except the declared ones
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected privileges on every public table except migrations
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE
// kills: backend/tests/Integration/DatabaseDriversTest.php::it overwrites a cache key through the upsert (ON CONFLICT DO UPDATE) with the second value
// pyme_app loses UPDATE on cache.key (only value and expiration remain): the declared exception is no longer needed, but the Laravel upsert of the cache would fail with 42501.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE UPDATE ON cache FROM pyme_app');
        DB::statement('GRANT UPDATE (value, expiration) ON cache TO pyme_app');
    }
};
