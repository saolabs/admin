<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('saola-admin.resources.audit_table', 'saola_resource_events');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) throw new LogicException('Invalid Resource audit table.');
        Schema::create($table, function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->string('resource', 64);
            $table->string('record_key', 191)->nullable();
            $table->string('actor_type', 191);
            $table->string('actor_key', 191);
            $table->string('operation', 16);
            $table->json('changed_keys');
            $table->uuid('request_id')->nullable();
            $table->string('input_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->unique(['actor_type', 'actor_key', 'resource', 'request_id'], 'saola_resource_request_unique');
            $table->index(['resource', 'record_key']);
        });
    }
    public function down(): void { Schema::dropIfExists(config('saola-admin.resources.audit_table', 'saola_resource_events')); }
};
