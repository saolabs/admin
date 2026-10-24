<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('saola-admin.language.connection'));
        $prefix = config('saola-admin.language.table_prefix', 'saola_');
        $schema->create($prefix.'locales', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->unsignedBigInteger('published_revision')->default(0);
        });
        $schema->create($prefix.'translation_keys', function (Blueprint $table) {
            $table->id();
            $table->string('path', 512)->unique();
            $table->string('owner');
            $table->string('source_file');
            $table->string('source_locale', 40);
            $table->text('source_text');
            $table->string('source_hash', 64);
        });
        $schema->create($prefix.'translation_values', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->unsignedBigInteger('key_id');
            $table->string('locale', 40);
            $table->text('published_value')->nullable();
            $table->string('origin', 20)->default('seed');
            $table->string('seed_hash', 64)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->unique(['key_id', 'locale']);
            $table->foreign('key_id')->references('id')->on($prefix.'translation_keys')->cascadeOnDelete();
            $table->foreign('locale')->references('code')->on($prefix.'locales')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('saola-admin.language.connection'));
        $prefix = config('saola-admin.language.table_prefix', 'saola_');
        $schema->dropIfExists($prefix.'translation_values');
        $schema->dropIfExists($prefix.'translation_keys');
        $schema->dropIfExists($prefix.'locales');
    }
};
