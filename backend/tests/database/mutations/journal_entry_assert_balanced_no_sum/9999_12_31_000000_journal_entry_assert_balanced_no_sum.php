<?php

declare(strict_types=1);

// kills: backend/tests/Integration/JournalEntryBalanceTest.php::it rejects an entry with two unbalanced lines (SQLSTATE 23514, unbalanced)
// kills: backend/tests/Integration/JournalEntryCommitTest.php::it rejects an unbalanced entry at COMMIT and persists neither the entry nor its lines
// The balance rule drops the comparison of the sums: an unbalanced entry commits.

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

                IF v_lines < 2 THEN
                    RAISE EXCEPTION 'journal entry % has fewer than two lines', p_entry_id
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$
            SQL);
    }
};
