<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_brand_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('tagline', 300)->nullable();
            $table->text('voice')->nullable();
            $table->text('audience')->nullable();
            $table->text('guidelines')->nullable();
            $table->json('palette');
            $table->foreignUuid('logo_asset_id')->nullable()->constrained('creator_assets')->nullOnDelete();
            $table->json('default_hashtags');
            $table->boolean('first_comment_enabled')->default(false);
            $table->text('first_comment')->nullable();
            $table->timestamps();
        });
        Schema::create('content_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->text('brief');
            $table->text('caption')->nullable();
            $table->json('hashtags');
            $table->text('first_comment')->nullable();
            $table->json('document')->nullable();
            $table->foreignUuid('source_project_id')->nullable()->constrained('creator_projects')->nullOnDelete();
            $table->unsignedInteger('source_project_revision')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'archived_at']);
        });
        Schema::create('content_ideas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('brief')->nullable();
            $table->text('caption')->nullable();
            $table->string('category', 100)->nullable();
            $table->json('tags');
            $table->string('status', 20)->default('inbox');
            $table->date('due_on')->nullable();
            $table->foreignUuid('template_id')->nullable()->constrained('content_templates')->nullOnDelete();
            $table->foreignUuid('draft_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignUuid('creator_project_id')->nullable()->constrained('creator_projects')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_ideas');
        Schema::dropIfExists('content_templates');
        Schema::dropIfExists('workspace_brand_profiles');
    }
};
