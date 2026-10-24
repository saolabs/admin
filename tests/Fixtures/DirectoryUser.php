<?php

namespace Saola\Admin\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;

class DirectoryUser extends User
{
    protected $table = 'directory_users';
    public $timestamps = false;
    protected $guarded = [];
    protected $appends = ['credential'];
    protected $with = ['unusedRelation'];

    protected static function booted(): void
    {
        static::addGlobalScope('visible', fn (Builder $query) => $query->where('visible', true)->addSelect('directory_users.*'));
    }

    public function getCredentialAttribute(): string { throw new \RuntimeException('Never serialize or evaluate user accessors in an admin directory.'); }
    public function getEmailAttribute(): string { throw new \RuntimeException('Directory values must not execute presentation accessors.'); }
    public function unusedRelation() { throw new \RuntimeException('Directory must not load host default relations.'); }
}
