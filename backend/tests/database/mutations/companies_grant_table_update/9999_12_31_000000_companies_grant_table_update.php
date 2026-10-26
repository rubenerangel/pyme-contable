<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has no UPDATE on any primary key column of a public table, except the declared ones
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected privileges on every public table except migrations
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE
// pyme_app gets table-level UPDATE on companies again, which includes the primary key id.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE ON companies TO pyme_app');
    }
};
