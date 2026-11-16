<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label', 80);
            $table->string('type', 20)->default('text'); // text | number | date | select | checkbox
            $table->json('options')->nullable();
            $table->boolean('show_in_list')->default(false);
            $table->unsignedInteger('sort')->default(100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->json('fields')->nullable()->after('context');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('fields'));
        Schema::dropIfExists('custom_fields');
    }
};
