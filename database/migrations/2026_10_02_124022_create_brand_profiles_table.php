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
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('website_url', 2048);
            $table->string('instagram_username', 30)->nullable();
            $table->string('facebook_page_id', 30)->nullable();
            $table->string('facebook_page_url', 2048)->nullable();
            $table->string('x_username', 15)->nullable();
            $table->uuid('netlify_site_id')->nullable();
            $table->string('repository_url', 2048)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
    }
};
