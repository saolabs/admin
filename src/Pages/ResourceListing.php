<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

/** Server-built links retain only validated list parameters; never echo an arbitrary request URL. */
final readonly class ResourceListing
{
    public function __construct(private ListingQuery $query, private int $total, private string $path, private string $locale, private array $sortOptions) {
        if ($total < 0 || !preg_match('~^/[A-Za-z0-9_/-]+$~D', $path) || str_contains($path, '//')) throw new \InvalidArgumentException('Invalid listing projection.');
    }

    public function toArray(): array
    {
        $q = $this->query;
        $url = fn (int $page) => $this->path.'?'.http_build_query(['locale' => $this->locale, 'q' => $q->search, 'sort' => $q->sort,
            'direction' => $q->direction, 'per_page' => $q->perPage, 'page' => $page], '', '&', PHP_QUERY_RFC3986);
        $last = max(1, (int) ceil($this->total / $q->perPage));
        return ['action' => $this->path, 'locale' => $this->locale, 'search' => $q->search, 'sort' => $q->sort, 'direction' => $q->direction,
            'sortOptions' => $this->sortOptions, 'perPage' => $q->perPage, 'pageSizes' => [10, 25, 50], 'page' => $q->page,
            'lastPage' => $last, 'total' => $this->total,
            'previous' => $q->page > 1 ? $url(min($q->page - 1, $last)) : null, 'next' => $q->page < $last ? $url($q->page + 1) : null];
    }
}
