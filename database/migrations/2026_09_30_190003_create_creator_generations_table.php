<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('creator_projects')->nullOnDelete();
            $table->foreignUuid('asset_id')->nullable()->constrained('creator_assets')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('input_hash', 64);
            $table->string('operation', 40);
            $table->string('endpoint_id');
            $table->text('prompt');
            $table->json('options');
            $table->string('status', 40);
            $table->json('quote');
            $table->string('provider_request_id')->nullable();
            $table->text('status_url')->nullable();
            $table->text('response_url')->nullable();
            $table->text('cancel_url')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->json('outputs')->nullable();
            $table->json('error')->nullable();
            $table->unsignedInteger('queue_position')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_generations');
    }
};
