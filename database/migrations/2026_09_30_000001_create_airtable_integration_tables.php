<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('review_required')->default(false);
            $table->string('review_status')->default('not_required');
            $table->string('review_revision', 64)->nullable();
            $table->text('review_note')->nullable();
        });

        Schema::create('airtable_integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('configured_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('base_id');
            $table->string('table_id');
            $table->string('post_name_field', 100)->nullable();
            $table->unique(['base_id', 'table_id']);
            $table->string('interface_url', 2048)->nullable();
            $table->unsignedInteger('sync_interval_minutes')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_sync_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->string('lease_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('quota_month', 7)->nullable();
            $table->unsignedInteger('api_calls')->default(0);
            $table->timestamps();
        });

        Schema::create('airtable_post_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_id')->constrained('airtable_integrations')->cascadeOnDelete();
            $table->foreignUuid('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('app_post_id');
            $table->string('record_id')->nullable();
            $table->string('export_hash', 64)->nullable();
            $table->string('proposal_hash', 64)->nullable();
            $table->json('conflict')->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['integration_id', 'app_post_id']);
            $table->unique(['integration_id', 'record_id']);
        });

        Schema::create('post_workflow_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source');
            $table->string('action');
            $table->string('revision', 64);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_workflow_events');
        Schema::dropIfExists('airtable_post_links');
        Schema::dropIfExists('airtable_integrations');
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn(['review_required', 'review_status', 'review_revision', 'review_note']);
        });
    }
};
