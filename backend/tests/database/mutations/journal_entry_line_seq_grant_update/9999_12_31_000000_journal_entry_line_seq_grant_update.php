<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AppPermissionsTest.php::it does not let pyme_app move journal_entry_line_id_seq backwards (only USAGE, no UPDATE: setval gives SQLSTATE 42501)
// pyme_app can setval journal_entry_line_id_seq backwards: a later line could take an id below the others of its entry and skip the balance check of the greatest id.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE ON SEQUENCE journal_entry_line_id_seq TO pyme_app');
    }
};
