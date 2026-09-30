<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_queues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('category', 100)->default('General');
            $table->unsignedSmallInteger('priority')->default(50);
            $table->string('state', 20)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'state', 'priority']);
        });
        Schema::create('recurring_post_series', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->foreignUuid('editorial_queue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('timezone', 100);
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->date('starts_on');
            $table->date('next_date');
            $table->string('local_time', 5);
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->unsignedSmallInteger('lead_hours')->default(168);
            $table->unsignedInteger('generated_count')->default(0);
            $table->string('state', 20)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'state']);
        });
        Schema::create('recurring_post_occurrences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('recurring_post_series_id')->constrained()->cascadeOnDelete();
            $table->string('occurrence_key', 10);
            $table->timestamp('intended_at');
            $table->foreignUuid('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['recurring_post_series_id', 'occurrence_key'], 'recurring_occurrence_unique');
            $table->unique('post_id');
        });
        Schema::create('editorial_queue_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('editorial_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->string('status', 20)->default('waiting');
            $table->text('blocked_reason')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();
            $table->unique('post_id');
            $table->index(['workspace_id', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_queue_entries');
        Schema::dropIfExists('recurring_post_occurrences');
        Schema::dropIfExists('recurring_post_series');
        Schema::dropIfExists('editorial_queues');
    }
};
