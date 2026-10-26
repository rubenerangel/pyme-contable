<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AppPermissionsTest.php::it does not let pyme_app insert an explicit id (no INSERT privilege on the column, SQLSTATE 42501) with data set "dataset "journal_entry_line""
// kills: backend/tests/Integration/AppPermissionsTest.php::it pyme_app cannot insert id or company_id on any public table with a company_id column, except the declared ones
// pyme_app can insert an explicit id into journal_entry_line: a line could take an id below the others of its entry and skip the balance check of the greatest id.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT INSERT (id) ON journal_entry_line TO pyme_app');
    }
};
