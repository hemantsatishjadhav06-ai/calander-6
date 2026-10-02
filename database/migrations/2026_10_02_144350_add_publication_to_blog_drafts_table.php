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
        Schema::table('blog_drafts', function (Blueprint $table) {
            $table->string('publication_status')->default('idle');
            $table->uuid('publication_attempt_id')->nullable();
            $table->char('publication_revision', 64)->nullable();
            $table->text('publication_error')->nullable();
            $table->string('publication_deploy_id')->nullable();
            $table->uuid('publication_deploy_attempt_id')->nullable();
            $table->text('publication_url')->nullable();
            $table->string('publication_base_deploy_id')->nullable();
            $table->boolean('publication_base_was_locked')->nullable();
            $table->char('published_revision', 64)->nullable();
            $table->text('published_url')->nullable();
            $table->timestamp('published_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_drafts', function (Blueprint $table) {
            $table->dropColumn([
                'publication_status', 'publication_attempt_id', 'publication_revision', 'publication_error',
                'publication_deploy_id', 'published_revision', 'published_url', 'published_at',
                'publication_deploy_attempt_id', 'publication_url', 'publication_base_deploy_id', 'publication_base_was_locked',
            ]);
        });
    }
};
