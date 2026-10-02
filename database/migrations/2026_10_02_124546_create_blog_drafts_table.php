<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blog_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->string('slug', 190);
            $table->longText('body');
            $table->text('excerpt')->nullable();
            $table->text('featured_image_url')->nullable();
            $table->string('featured_image_alt', 300)->nullable();
            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->text('canonical_url')->nullable();
            $table->unsignedInteger('content_revision')->default(1);
            $table->char('review_requested_revision', 64)->nullable();
            $table->timestamp('review_requested_at')->nullable();
            $table->char('approved_revision', 64)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('rejected_revision', 64)->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'updated_at']);
            $table->unique(['workspace_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_drafts');
    }
};
