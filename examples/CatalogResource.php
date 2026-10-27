<?php

// Copy into the host and adapt its models, Policy, language keys and tenant scope.
namespace App\Admin;

use App\Models\{Category, Product};
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Http\Request;
use Saola\Admin\Resources\{Input, Resource};

final class CatalogResource extends Resource
{
    public function key(): string { return 'products'; }
    public function titleKey(): string { return 'catalog.products.title'; }
    public function ability(): string { return 'manage-products'; }
    public function model(): string { return Product::class; }
    public function query(Request $request): Builder { return Product::where('account_id', $request->user()->account_id); }
    public function newRecord(Request $request): Model { return new Product(['account_id' => $request->user()->account_id]); }
    public function searchable(): array { return ['name', 'slug']; }
    public function sortable(): array { return ['name', 'price_cents', 'published_at']; }
    public function formLayout(): string { return 'tabs'; }
    public function sections(): array
    {
        return ['main' => ['labelKey' => 'catalog.sections.main'], 'content' => ['labelKey' => 'catalog.sections.content'], 'seo' => ['labelKey' => 'catalog.sections.seo']];
    }
    public function fields(): array
    {
        return [
            'name' => Input::make('text', 'catalog.fields.name', ['required' => true, 'maxLength' => 150]),
            'slug' => Input::make('text', 'catalog.fields.slug', ['maxLength' => 180, 'helpKey' => 'catalog.hints.slug']),
            'category_id' => Input::make('relation', 'catalog.fields.category', ['nullable' => true]),
            'price_cents' => Input::make('integer', 'catalog.fields.price_cents', ['required' => true, 'min' => 0, 'default' => 0]),
            'enabled' => Input::make('switch', 'catalog.fields.enabled', ['default' => false]),
            'status' => Input::make('radio', 'catalog.fields.status', ['default' => 'draft', 'options' => ['draft' => 'catalog.status.draft', 'published' => 'catalog.status.published']]),
            'published_at' => Input::make('datetime', 'catalog.fields.published_at', ['nullable' => true]),
            'tags' => Input::make('tags', 'catalog.fields.tags', ['maxItems' => 20]), // Product needs an array cast.
            'cover_url' => Input::make('image', 'catalog.fields.cover', ['section' => 'content', 'nullable' => true]),
            'attachment_url' => Input::make('media', 'catalog.fields.attachment', ['section' => 'content', 'nullable' => true]),
            'body' => Input::make('markdown', 'catalog.fields.body', ['section' => 'content', 'rows' => 14, 'maxLength' => 100000]),
            'attributes' => Input::make('json', 'catalog.fields.attributes', ['section' => 'content', 'nullable' => true]), // Product needs an array cast.
            'meta_title' => Input::make('text', 'catalog.fields.meta_title', ['section' => 'seo', 'maxLength' => 200]),
            'meta_description' => Input::make('textarea', 'catalog.fields.meta_description', ['section' => 'seo', 'rows' => 3, 'maxLength' => 500]),
        ];
    }
    public function fieldOptions(string $key, Request $request, ?Model $record): array
    {
        if ($key !== 'category_id') return parent::fieldOptions($key, $request, $record);
        return Category::where('account_id', $request->user()->account_id)->orderBy('name')->limit(1000)
            ->pluck('name', 'id')->map(fn ($name) => ['label' => $name])->all();
    }
    public function columns(): array
    {
        return [
            ['key' => 'cover_url', 'labelKey' => 'catalog.fields.cover', 'renderer' => 'image'],
            ['key' => 'name', 'labelKey' => 'catalog.fields.name'],
            ['key' => 'status', 'labelKey' => 'catalog.fields.status', 'renderer' => 'badge'],
            ['key' => 'enabled', 'labelKey' => 'catalog.fields.enabled', 'renderer' => 'boolean'],
            ['key' => 'published_at', 'labelKey' => 'catalog.fields.published_at', 'renderer' => 'date'],
        ];
    }
}
