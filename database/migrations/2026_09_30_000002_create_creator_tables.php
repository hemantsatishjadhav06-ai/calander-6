<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 200);
            $table->unsignedInteger('revision')->default(1);
            $table->string('document_hash', 64);
            $table->json('document');
            $table->timestamps();
            $table->index(['workspace_id', 'updated_at']);
        });
        Schema::create('creator_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 200);
            $table->string('kind', 20)->default('image');
            $table->string('disk');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
        Schema::create('creator_export_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('creator_projects')->nullOnDelete();
            $table->unsignedInteger('project_revision');
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->json('document_snapshot');
            $table->json('media_ids');
            $table->timestamps();
            $table->unique(['post_id', 'idempotency_key']);
        });
        Schema::create('creator_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained('creator_projects')->nullOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('creator_export_batches')->nullOnDelete();
            $table->foreignUuid('post_media_id')->unique()->constrained('post_media')->cascadeOnDelete();
            $table->unsignedInteger('project_revision');
            $table->string('slide_id', 100);
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_exports');
        Schema::dropIfExists('creator_export_batches');
        Schema::dropIfExists('creator_assets');
        Schema::dropIfExists('creator_projects');
    }
};
