<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryBalanceTest.php::it accepts an entry with two balanced lines inserted one per statement
// The line trigger is not deferrable: with one line per statement the first line is checked alone and rejected.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER journal_entry_line_balance ON journal_entry_line');
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER journal_entry_line_balance
                AFTER INSERT ON journal_entry_line
                NOT DEFERRABLE
                FOR EACH ROW EXECUTE FUNCTION journal_entry_line_balance()
            SQL);
    }
};
