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
        Schema::table('mcp_grant_workspaces', function (Blueprint $table) {
            $table->string('authorization_code_hash', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_grant_workspaces', function (Blueprint $table) {
            $table->dropUnique(['authorization_code_hash']);
            $table->dropColumn('authorization_code_hash');
        });
    }
};
