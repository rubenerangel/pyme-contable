<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryBalanceTest.php::it rejects a line added after SET CONSTRAINTS ALL IMMEDIATE validated a balanced entry
// The line trigger does not validate: a line added after SET CONSTRAINTS ALL IMMEDIATE unbalances a validated entry.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_line_balance() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            BEGIN
                RETURN NULL;
            END;
            $$
            SQL);
    }
};
