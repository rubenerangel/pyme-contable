<?php

declare(strict_types=1);

// kills: backend/tests/Integration/AccountHierarchyConcurrencyTest.php::it keeps B waiting while A marks the parent postable and rejects the child when A commits (SQLSTATE 23514)
// The read of the parent in account_enforce_hierarchy without FOR SHARE: the loop still locks the parent, but after reading is_postable.

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
                    WHERE id = NEW.parent_id;

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
                        WHERE id = v_ancestor_id
                        FOR SHARE;
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
