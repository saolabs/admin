<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('saola-admin.language.connection'));
        $prefix = config('saola-admin.language.table_prefix', 'saola_');
        $schema->table($prefix.'locales', function (Blueprint $table) {
            $table->string('country', 2)->nullable();
            $table->string('currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('saola-admin.language.connection'));
        $prefix = config('saola-admin.language.table_prefix', 'saola_');
        $schema->table($prefix.'locales', fn (Blueprint $table) => $table->dropColumn(['country', 'currency']));
    }
};
