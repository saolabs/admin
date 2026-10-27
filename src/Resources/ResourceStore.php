<?php

declare(strict_types=1);

namespace Saola\Admin\Resources;

use Illuminate\Http\Request;

interface ResourceStore
{
    public function listing(Resource $resource, Request $request): array;
    public function editor(Resource $resource, Request $request, ?string $id = null): array;
    public function create(Resource $resource, Request $request): array;
    public function update(Resource $resource, Request $request, string $id): array;
    public function transition(Resource $resource, Request $request, string $operation, array $items): array;
}
