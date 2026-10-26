<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// pyme_app cannot update any primary key column. For companies.id this is defense in depth: today any company with data
// already fails with 23503 when its id changes, the ledger included, since it reaches companies transitively
// (document_type, audit_log). But the primary key must not depend on the shape of the FK graph, which a pending review of
// the direct FKs to companies could change. A company without rows could also take an id that companies_id_seq has not
// handed out yet, causing a future collision.
// company_user gets no UPDATE at all: the app only inserts and deletes memberships. This prevents accidental UPDATEs
// (updateExistingPivot, sync, pivot touch) and primary key changes. It does not prevent role escalation, since DELETE
// plus INSERT changes the role anyway: that is controlled by the authorization of the membership action. cache keeps
// its table-level UPDATE: DatabaseStore upserts with SET key = excluded.key, so it needs UPDATE on key. The Laravel
// tables keep every column but the primary key, so the framework behaves as before.
return new class extends Migration
{
    private const COLUMN_UPDATES = [
        'companies' => ['name', 'updated_at'],
        'users' => ['created_at', 'email', 'email_verified_at', 'name', 'password', 'remember_token', 'updated_at'],
        'sessions' => ['ip_address', 'last_activity', 'payload', 'user_agent', 'user_id'],
        'jobs' => ['attempts', 'available_at', 'created_at', 'payload', 'queue', 'reserved_at'],
        'job_batches' => [
            'cancelled_at',
            'created_at',
            'failed_job_ids',
            'failed_jobs',
            'finished_at',
            'name',
            'options',
            'pending_jobs',
            'total_jobs',
        ],
        'failed_jobs' => ['connection', 'exception', 'failed_at', 'payload', 'queue', 'uuid'],
        'password_reset_tokens' => ['created_at', 'token'],
        'cache_locks' => ['expiration', 'owner'],
    ];

    private const TABLES = [
        'companies',
        'company_user',
        'users',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'cache_locks',
    ];

    public function up(): void
    {
        // Table-level first: in Postgres, revoking a table privilege also revokes the column privileges.
        DB::statement('REVOKE UPDATE ON '.implode(', ', self::TABLES).' FROM pyme_app');

        foreach (self::COLUMN_UPDATES as $table => $columns) {
            DB::statement('GRANT UPDATE ('.implode(', ', $columns).") ON $table TO pyme_app");
        }
    }

    public function down(): void
    {
        foreach (self::COLUMN_UPDATES as $table => $columns) {
            DB::statement('REVOKE UPDATE ('.implode(', ', $columns).") ON $table FROM pyme_app");
        }

        DB::statement('GRANT UPDATE ON '.implode(', ', self::TABLES).' TO pyme_app');
    }
};
