<?php

namespace Saola\Admin\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class StringDirectoryUser extends User
{
    protected $table = 'directory_members';
    protected $primaryKey = 'uuid';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
}
