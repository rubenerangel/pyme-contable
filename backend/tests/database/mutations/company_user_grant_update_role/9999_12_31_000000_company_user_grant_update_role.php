<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE
// pyme_app may update the role of a membership: company_user must have no UPDATE of any kind.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE (role) ON company_user TO pyme_app');
    }
};
