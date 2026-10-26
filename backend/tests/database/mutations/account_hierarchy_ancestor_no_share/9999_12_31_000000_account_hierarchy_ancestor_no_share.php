<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AccountHierarchyConcurrencyTest.php::it makes B wait when it moves Z under W while A moves Y under X without commit, and rejects the cycle of depth 2 after A commits (SQLSTATE 23514)
// The walk of the ancestors in account_enforce_hierarchy without FOR SHARE: a cycle of depth 2 passes under concurrency.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION account_enforce_hierarchy() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp AS $$
            DECLARE
                v_parent_is_postable boolean;
                v_ancestor_id bigint;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT is_postable INTO v_parent_is_postable
                    FROM account
                    WHERE id = NEW.parent_id
                    FOR SHARE;

                    IF FOUND AND v_parent_is_postable THEN
                        RAISE EXCEPTION 'account hierarchy: the parent account % is postable and cannot have children', NEW.parent_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    v_ancestor_id := NEW.parent_id;
                    WHILE v_ancestor_id IS NOT NULL LOOP
                        IF v_ancestor_id = NEW.id THEN
                            RAISE EXCEPTION 'account hierarchy: moving account % under account % creates a cycle', NEW.id, NEW.parent_id
                                USING ERRCODE = 'check_violation';
                        END IF;

                        SELECT parent_id INTO v_ancestor_id
                        FROM account
                        WHERE id = v_ancestor_id;
                    END LOOP;
                END IF;

                IF NEW.is_postable AND EXISTS (SELECT 1 FROM account WHERE parent_id = NEW.id) THEN
                    RAISE EXCEPTION 'account hierarchy: account % has children and cannot be postable', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);
    }
};
