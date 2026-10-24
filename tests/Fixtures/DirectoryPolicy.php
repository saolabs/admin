<?php

namespace Saola\Admin\Tests\Fixtures;

final class DirectoryPolicy
{
    public function viewAny($actor): bool { return (bool) $actor->directory_policy; }
}
