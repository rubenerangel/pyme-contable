<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Audit triggers on period and document_type. document_counter is not audited: it changes on every posting and the
// document itself will be. companies, company_user and users are out of scope for now.
return new class extends Migration
{
    private const AUDITED_TABLES = ['period', 'document_type'];

    public function up(): void
    {
        foreach (self::AUDITED_TABLES as $table) {
            // AFTER, so the row already passed every constraint and BEFORE triggers. The function runs as pyme_owner
            // (SECURITY DEFINER) and Postgres does not check EXECUTE when it fires a trigger, so pyme_app needs no grant.
            DB::statement(<<<SQL
                CREATE TRIGGER audit_log_capture_insert_delete
                    AFTER INSERT OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION audit_log_capture()
                SQL);

            // An UPDATE that changes nothing leaves no audit row.
            DB::statement(<<<SQL
                CREATE TRIGGER audit_log_capture_update
                    AFTER UPDATE ON {$table}
                    FOR EACH ROW WHEN (OLD.* IS DISTINCT FROM NEW.*)
                    EXECUTE FUNCTION audit_log_capture()
                SQL);
        }
    }

    public function down(): void
    {
        foreach (self::AUDITED_TABLES as $table) {
            DB::statement("DROP TRIGGER audit_log_capture_update ON {$table}");
            DB::statement("DROP TRIGGER audit_log_capture_insert_delete ON {$table}");
        }
    }
};
