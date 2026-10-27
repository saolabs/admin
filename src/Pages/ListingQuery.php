<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class ListingQuery
{
    public function __construct(public string $search, public string $sort, public string $direction, public int $page, public int $perPage, public string $searchBy = '') {}

    public static function fromRequest(Request $request, array $sortKeys, array $searchKeys = []): self
    {
        $data = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'search_by' => ['nullable', 'string', Rule::in($searchKeys)],
            'sort' => ['sometimes', 'required', 'string', Rule::in($sortKeys)],
            'direction' => ['sometimes', 'required', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'required', 'integer', Rule::in([10, 25, 50])],
        ])->validate();
        return new self(trim($data['q'] ?? ''), $data['sort'] ?? $sortKeys[0], $data['direction'] ?? 'asc', (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25), $data['search_by'] ?? '');
    }
}
