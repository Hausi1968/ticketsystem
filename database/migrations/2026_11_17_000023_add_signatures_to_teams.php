<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // HTML-Signatur je Team (Teams gehören zu einem Mandanten) mit optionalem Logo
        Schema::table('teams', function (Blueprint $table) {
            $table->text('signature_html')->nullable();
            $table->string('signature_logo_path')->nullable();
            $table->unsignedSmallInteger('signature_logo_width')->default(180);
        });

        // Welche Team-Signatur eine Antwort trägt (nur zur Nachvollziehbarkeit)
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('signature_team_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', fn (Blueprint $table) => $table->dropColumn('signature_team_id'));
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn(['signature_html', 'signature_logo_path', 'signature_logo_width']));
    }
};
