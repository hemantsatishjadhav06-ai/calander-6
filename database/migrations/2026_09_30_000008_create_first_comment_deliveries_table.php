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
            $table->boolean('first_comment_enabled')->default(false);
            $table->text('first_comment')->nullable();
        });
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->boolean('first_comment_enabled')->nullable();
            $table->text('first_comment')->nullable();
        });
        Schema::create('first_comment_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_target_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('revision', 64);
            $table->text('text');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->string('remote_id', 2048)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_comment_deliveries');
        Schema::table('post_targets', fn (Blueprint $table) => $table->dropColumn(['first_comment_enabled', 'first_comment']));
        Schema::table('posts', fn (Blueprint $table) => $table->dropColumn(['first_comment_enabled', 'first_comment']));
    }
};
