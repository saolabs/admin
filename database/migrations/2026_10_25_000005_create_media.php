<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('saola-admin.media.connection'))->create(config('saola-admin.media.table', 'saola_media'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope_key', 128)->index();
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->string('original_name', 200);
            $table->string('title', 200);
            $table->string('alt', 200)->default('');
            $table->text('caption');
            $table->string('mime', 100);
            $table->string('kind', 20);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['scope_key', 'deleted_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('saola-admin.media.connection'))->dropIfExists(config('saola-admin.media.table', 'saola_media'));
    }
};
