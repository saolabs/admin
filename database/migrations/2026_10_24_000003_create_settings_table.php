<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('saola-admin.settings.connection'))->create(config('saola-admin.settings.table', 'saola_settings'), function (Blueprint $table) {
            $table->string('group_key', 100)->primary();
            $table->unsignedInteger('schema_version');
            $table->unsignedBigInteger('revision');
            $table->longText('document');
        });
    }

    public function down(): void
    {
        Schema::connection(config('saola-admin.settings.connection'))->dropIfExists(config('saola-admin.settings.table', 'saola_settings'));
    }
};
