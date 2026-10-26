<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryBalanceTest.php::it rejects an entry without lines when deferred constraints are checked (SQLSTATE 23514, fewer than two lines)
// The balance rule drops the minimum of two lines: an entry without lines sums zero and passes.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_assert_balanced(p_entry_id bigint) RETURNS void
            LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_lines bigint;
                v_debit numeric;
                v_credit numeric;
            BEGIN
                SELECT count(*), coalesce(sum(debit), 0), coalesce(sum(credit), 0) INTO v_lines, v_debit, v_credit
                FROM journal_entry_line
                WHERE journal_entry_id = p_entry_id;

                IF v_debit <> v_credit THEN
                    RAISE EXCEPTION 'journal entry % is unbalanced', p_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$
            SQL);
    }
};
