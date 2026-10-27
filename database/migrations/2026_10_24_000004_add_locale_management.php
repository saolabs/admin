<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('saola-admin.language.connection'));
        $prefix = config('saola-admin.language.table_prefix', 'saola_');
        $schema->table($prefix.'locales', fn (Blueprint $table) => $table->string('name', 100)->nullable());
        $schema->create($prefix.'language_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision');
            $table->longText('document');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('saola-admin.language.connection'));
        $prefix = config('saola-admin.language.table_prefix', 'saola_');
        $schema->dropIfExists($prefix.'language_settings');
        $schema->table($prefix.'locales', fn (Blueprint $table) => $table->dropColumn('name'));
    }
};
