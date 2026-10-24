<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('saola-admin.language.connection'))->create(
            config('saola-admin.language.table_prefix', 'saola_').'language_batches',
            function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('kind', 20);
                $table->string('locale', 40);
                $table->string('actor', 255);
                $table->string('status', 20);
                $table->unsignedBigInteger('base_revision');
                $table->unsignedBigInteger('result_revision')->nullable();
                $table->string('identity_hash', 64)->unique();
                $table->longText('payload');
                $table->unsignedBigInteger('expires_at');
                $table->timestamp('created_at');
                $table->timestamp('published_at')->nullable();
            },
        );
    }

    public function down(): void
    {
        Schema::connection(config('saola-admin.language.connection'))->dropIfExists(
            config('saola-admin.language.table_prefix', 'saola_').'language_batches',
        );
    }
};
