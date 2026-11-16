<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zuweisung „reihum“ je Team: wer zuletzt automatisch ein Ticket bekommen hat
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('last_assigned_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('last_assigned_user_id');
        });
    }
};
