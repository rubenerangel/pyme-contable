<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Global table, no RLS: memberships are read before the active company exists. Foreign keys without cascade
// (NO ACTION): a company or user with memberships is not deleted silently.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->text('role');
            $table->timestamps();

            $table->primary(['company_id', 'user_id']);
            // The primary key covers company_id first; membership lookups go by user.
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE company_user ADD CONSTRAINT company_user_role_check CHECK (role in ('admin', 'accountant'))");

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON company_user TO pyme_app');
    }

    public function down(): void
    {
        // Grants go away with the table.
        Schema::dropIfExists('company_user');
    }
};
