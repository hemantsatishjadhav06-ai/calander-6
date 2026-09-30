<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_assets', function (Blueprint $table): void {
            $table->string('folder', 100)->nullable();
            $table->json('tags')->nullable();
            $table->boolean('starred')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('root_asset_id')->nullable()->constrained('creator_assets')->restrictOnDelete();
            $table->foreignUuid('parent_asset_id')->nullable()->constrained('creator_assets')->restrictOnDelete();
            $table->index(['workspace_id', 'folder', 'archived_at']);
            $table->index(['workspace_id', 'root_asset_id', 'version']);
        });
        Schema::table('content_templates', function (Blueprint $table): void {
            $table->json('destination')->nullable();
            $table->json('media_asset_ids')->nullable();
        });
        Schema::table('content_ideas', function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(0);
            $table->index(['workspace_id', 'status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('content_ideas', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'status', 'position']);
            $table->dropColumn('position');
        });
        Schema::table('content_templates', fn (Blueprint $table) => $table->dropColumn(['destination', 'media_asset_ids']));
        Schema::table('creator_assets', function (Blueprint $table): void {
            $table->dropForeign(['root_asset_id']);
            $table->dropForeign(['parent_asset_id']);
            $table->dropIndex(['workspace_id', 'folder', 'archived_at']);
            $table->dropIndex(['workspace_id', 'root_asset_id', 'version']);
            $table->dropColumn(['folder', 'tags', 'starred', 'archived_at', 'revision', 'version', 'root_asset_id', 'parent_asset_id']);
        });
    }
};
