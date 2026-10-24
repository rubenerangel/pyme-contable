<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Audit ledger (see docs/decisiones.md, Integridad y auditoría). pyme_app only reads it: the rows are written by two
// SECURITY DEFINER functions owned by pyme_owner, so the application cannot forge, change or delete a row.
return new class extends Migration
{
    public function up(): void
    {
        // A row is either a data change captured by the trigger (table, record, operation and the row before and
        // after) or an explicit event (type and details). The shape CHECK keeps each kind with its own columns. Every
        // branch is false, never NULL, when a required column is missing, because a CHECK that evaluates to NULL
        // passes. old_values is NULL exactly for INSERT and new_values exactly for DELETE. user_id has no default:
        // the functions read it from app.user_id and fail without it.
        DB::statement(<<<'SQL'
            CREATE TABLE audit_log (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL
                    DEFAULT nullif(current_setting('app.company_id', true), '')::bigint
                    REFERENCES companies,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                user_id bigint NOT NULL REFERENCES users,
                kind text NOT NULL CONSTRAINT audit_log_kind_check CHECK (kind IN ('data_change', 'event')),
                table_name text,
                record_id bigint,
                operation text CONSTRAINT audit_log_operation_check CHECK (operation IN ('INSERT', 'UPDATE', 'DELETE')),
                old_values jsonb,
                new_values jsonb,
                event_type text,
                details jsonb,
                CONSTRAINT audit_log_shape_check CHECK (
                    (
                        kind = 'data_change'
                        AND table_name IS NOT NULL
                        AND record_id IS NOT NULL
                        AND operation IS NOT NULL
                        AND event_type IS NULL
                        AND details IS NULL
                        AND (old_values IS NULL) = (operation = 'INSERT')
                        AND (new_values IS NULL) = (operation = 'DELETE')
                    )
                    OR (
                        kind = 'event'
                        AND event_type IS NOT NULL
                        AND event_type ~ '^[a-z][a-z0-9_]*$'
                        AND details IS NOT NULL
                        AND jsonb_typeof(details) = 'object'
                        AND table_name IS NULL
                        AND record_id IS NULL
                        AND operation IS NULL
                        AND old_values IS NULL
                        AND new_values IS NULL
                    )
                )
            )
            SQL);

        DB::statement('CREATE INDEX audit_log_company_id_table_name_record_id_index ON audit_log (company_id, table_name, record_id)');
        DB::statement('CREATE INDEX audit_log_company_id_occurred_at_index ON audit_log (company_id, occurred_at)');

        DB::statement('ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_log FORCE ROW LEVEL SECURITY');

        // pyme_app reads its company. pyme_owner (the owner of the functions) inserts only into the company in
        // context. There is no UPDATE or DELETE policy for anybody: with FORCE not even the owner changes or deletes
        // a row, it just matches none. owner_select exists because INSERT ... RETURNING in audit_log_event also
        // requires the new row to pass a SELECT policy; without it the insert fails with 42501 before the CHECK runs.
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON audit_log
                FOR SELECT TO pyme_app
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY owner_insert ON audit_log
                FOR INSERT TO pyme_owner
                WITH CHECK (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY owner_select ON audit_log
                FOR SELECT TO pyme_owner
                USING (company_id = nullif(current_setting('app.company_id', true), '')::bigint)
            SQL);

        // No USAGE on the sequence: the owner, through the functions, is the only one that inserts.
        DB::statement('GRANT SELECT ON audit_log TO pyme_app');

        // The audit user comes from app.user_id (set with the company by DatabaseCompanyContext). Without it the write
        // fails closed (42501) and the statement that fired the trigger rolls back with it. search_path is pinned
        // because SECURITY DEFINER code must not resolve names through the caller's path. EXECUTE is revoked from
        // PUBLIC (the default for functions) and granted back only where it is needed. migrate:fresh does not drop
        // functions and CREATE OR REPLACE keeps the ACL and the owner of the previous one, so each function is dropped
        // first (no CASCADE: the tables and their triggers are already gone) and the ACL and the definition always
        // come from this migration.
        DB::statement('DROP FUNCTION IF EXISTS audit_log_capture()');
        DB::statement(<<<'SQL'
            CREATE FUNCTION audit_log_capture() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
                v_user_id bigint := nullif(current_setting('app.user_id', true), '')::bigint;
            BEGIN
                IF v_user_id IS NULL THEN
                    RAISE EXCEPTION 'audit_log_capture requires app.user_id in the connection context (table %)', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    INSERT INTO audit_log (user_id, kind, table_name, record_id, operation, new_values)
                    VALUES (v_user_id, 'data_change', TG_TABLE_NAME, NEW.id, TG_OP, to_jsonb(NEW));
                ELSIF TG_OP = 'UPDATE' THEN
                    INSERT INTO audit_log (user_id, kind, table_name, record_id, operation, old_values, new_values)
                    VALUES (v_user_id, 'data_change', TG_TABLE_NAME, NEW.id, TG_OP, to_jsonb(OLD), to_jsonb(NEW));
                ELSE
                    INSERT INTO audit_log (user_id, kind, table_name, record_id, operation, old_values)
                    VALUES (v_user_id, 'data_change', TG_TABLE_NAME, OLD.id, TG_OP, to_jsonb(OLD));
                END IF;

                RETURN NULL;
            END;
            $$
            SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION audit_log_capture() FROM PUBLIC');

        // Not STRICT on purpose: a NULL p_details must reach the shape CHECK (23514), not silently skip the insert.
        DB::statement('DROP FUNCTION IF EXISTS audit_log_event(text, jsonb)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION audit_log_event(p_event_type text, p_details jsonb) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
            DECLARE
                v_user_id bigint := nullif(current_setting('app.user_id', true), '')::bigint;
                v_id bigint;
            BEGIN
                IF v_user_id IS NULL THEN
                    RAISE EXCEPTION 'audit_log_event requires app.user_id in the connection context'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                INSERT INTO audit_log (user_id, kind, event_type, details)
                VALUES (v_user_id, 'event', p_event_type, p_details)
                RETURNING id INTO v_id;

                RETURN v_id;
            END;
            $$
            SQL);

        DB::statement('REVOKE EXECUTE ON FUNCTION audit_log_event(text, jsonb) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION audit_log_event(text, jsonb) TO pyme_app');
    }

    public function down(): void
    {
        // migrate:fresh does not drop functions, so they go away explicitly. The table has no trigger of its own.
        DB::statement('DROP TABLE audit_log');
        DB::statement('DROP FUNCTION audit_log_event(text, jsonb)');
        DB::statement('DROP FUNCTION audit_log_capture()');
    }
};
