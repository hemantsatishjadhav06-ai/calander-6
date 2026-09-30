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
            $table->string('review_mode')->default('off');
            $table->unsignedInteger('review_policy_version')->default(0);
        });

        Schema::create('post_review_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode');
            $table->unsignedInteger('policy_version')->default(0);
            $table->string('revision', 64)->nullable();
            $table->json('target_states');
            $table->boolean('on_hold')->default(false);
            $table->timestamps();
            $table->index(['workspace_id', 'client_user_id']);
        });

        Schema::table('post_workflow_events', function (Blueprint $table): void {
            $table->string('stage')->nullable();
            $table->string('audience')->default('internal');
            $table->foreignUuid('review_target_id')->nullable()->constrained('post_targets')->nullOnDelete();
            $table->index(['workspace_id', 'post_id', 'audience']);
        });
    }

    public function down(): void
    {
        Schema::table('post_workflow_events', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'post_id', 'audience']);
            $table->dropConstrainedForeignId('review_target_id');
            $table->dropColumn(['stage', 'audience']);
        });
        Schema::dropIfExists('post_review_states');
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn(['review_mode', 'review_policy_version']);
        });
    }
};
