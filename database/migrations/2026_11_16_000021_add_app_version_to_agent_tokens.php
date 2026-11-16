<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Welche App-Version das Gerät zuletzt benutzt hat (Seite „Versionen“)
        Schema::table('agent_tokens', function (Blueprint $table) {
            $table->string('app_version', 30)->nullable();
            $table->string('app_platform', 20)->nullable();
            $table->string('app_update', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_tokens', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'app_platform', 'app_update']);
        });
    }
};
