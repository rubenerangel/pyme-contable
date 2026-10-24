<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Global table, no RLS: it is the tenant itself. Explicit GRANT, never default privileges. No DELETE for the application.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->text('name');
            $table->timestamps();
        });

        DB::statement('GRANT SELECT, INSERT, UPDATE ON companies TO pyme_app');
        DB::statement('GRANT USAGE ON SEQUENCE companies_id_seq TO pyme_app');
    }

    public function down(): void
    {
        // Grants go away with the table.
        Schema::dropIfExists('companies');
    }
};
