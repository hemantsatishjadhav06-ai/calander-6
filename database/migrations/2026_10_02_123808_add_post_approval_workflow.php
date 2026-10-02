<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->boolean('requires_post_approval')->default(false);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->timestamp('planned_schedule_at')->nullable();
            $table->string('review_requested_revision', 64)->nullable();
            $table->timestamp('review_requested_at')->nullable();
            $table->string('approved_revision', 64)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_revision', 64)->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['planned_schedule_at', 'review_requested_revision', 'review_requested_at', 'approved_revision', 'approved_at', 'rejected_revision', 'rejected_at', 'rejection_reason']);
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('requires_post_approval');
        });
    }
};
