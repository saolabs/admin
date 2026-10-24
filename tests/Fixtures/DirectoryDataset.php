<?php

namespace Saola\Admin\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Disposable test/workbench records, never used by the package installer. */
final class DirectoryDataset
{
    public static function create(): void
    {
        Schema::create('directory_users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email'); $table->integer('tenant_id');
            $table->boolean('visible')->default(true); $table->string('password'); $table->string('remember_token')->nullable();
        });
        for ($i = 1; $i <= 27; $i++) DB::table('directory_users')->insert([
            'id' => $i, 'name' => sprintf('Member %02d', $i), 'email' => 'member'.$i.'@example.test', 'tenant_id' => 7, 'password' => 'never-send-hash',
        ]);
        DB::table('directory_users')->insert([
            ['id' => 28, 'name' => 'Member hidden', 'email' => 'outside@example.test', 'tenant_id' => 8, 'password' => 'outside-secret', 'visible' => true],
            ['id' => 29, 'name' => 'Deleted', 'email' => 'hidden@example.test', 'tenant_id' => 7, 'password' => 'hidden-secret', 'visible' => false],
        ]);
    }
}
