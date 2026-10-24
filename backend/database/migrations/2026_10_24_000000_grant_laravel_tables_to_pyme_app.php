<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Explicit GRANT per migration, never default privileges.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE users_id_seq, jobs_id_seq, failed_jobs_id_seq TO pyme_app');
    }

    public function down(): void
    {
        DB::statement('REVOKE USAGE ON SEQUENCE users_id_seq, jobs_id_seq, failed_jobs_id_seq FROM pyme_app');
        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs FROM pyme_app');
    }
};
