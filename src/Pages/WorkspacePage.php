<?php

declare(strict_types=1);

namespace Saola\Admin\Pages;

use Illuminate\Http\Request;
use Saola\Admin\Language\DatabaseLanguageStore;

final class WorkspacePage implements Page
{
    public function key(): string { return 'workspace'; }
    public function titleKey(): string { return 'admin.workspace.title'; }
    public function ability(): string { return config('saola-admin.panel.ability', 'access-admin'); }

    public function document(Request $request): ResourceDocument
    {
        $db = app('db')->connection(config('saola-admin.language.connection'));
        $rows = $db->table(config('saola-admin.language.table_prefix', 'saola_').'locales')
            ->where('published_revision', '>', 0)->orderBy('code')->get(['code', 'published_revision'])->map(fn ($row) => [
                'locale' => $row->code, 'revision' => $row->published_revision,
            ])->all();
        return new ResourceDocument($this->titleKey(), 'admin.workspace.description', [
            ['key' => 'website', 'labelKey' => 'admin.workspace.website', 'value' => (string) config('app.name')],
            ['key' => 'locale', 'labelKey' => 'admin.workspace.locale', 'value' => app()->getLocale()],
        ], [
            ['key' => 'locale', 'labelKey' => 'admin.workspace.locale', 'renderer' => 'badge'],
            ['key' => 'revision', 'labelKey' => 'admin.workspace.revision'],
        ], $rows);
    }
}
