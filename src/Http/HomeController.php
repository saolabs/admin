<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Saola\Admin\Pages\ResourceDocument;
use Saola\Admin\Registry\AdminRegistry;
use Saola\Core\Http\Controllers\AdminController as CoreAdminController;

/**
 * /{prefix}: opens the menu named by panel.home_page when the user may open it; otherwise the package's overview
 * (site name, UI locale, published translation revisions) inside the shell.
 */
final class HomeController extends CoreAdminController
{
    public function __invoke(Request $request)
    {
        $registry = app(AdminRegistry::class);
        $home = $registry->menuOf((string) config('saola-admin.panel.home_page', ''));
        if ($home && \Illuminate\Support\Facades\Gate::forUser($request->user())->allows($home['ability'])) return redirect($registry->url($home));

        return $this->response([...app(PanelData::class)->prepare($request, 'workspace'), 'page' => $this->overview()->toArray()], '@RAW:pages.resource');
    }

    private function overview(): ResourceDocument
    {
        $db = app('db')->connection(config('saola-admin.language.connection'));
        $rows = $db->table(config('saola-admin.language.table_prefix', 'saola_').'locales')
            ->where('published_revision', '>', 0)->orderBy('code')->get(['code', 'published_revision'])
            ->map(fn ($row) => ['locale' => $row->code, 'revision' => $row->published_revision])->all();
        return new ResourceDocument('admin.workspace.title', 'admin.workspace.description', [
            ['key' => 'website', 'labelKey' => 'admin.workspace.website', 'value' => (string) config('app.name')],
            ['key' => 'locale', 'labelKey' => 'admin.workspace.locale', 'value' => app()->getLocale()],
        ], [
            ['key' => 'locale', 'labelKey' => 'admin.workspace.locale', 'renderer' => 'badge'],
            ['key' => 'revision', 'labelKey' => 'admin.workspace.revision'],
        ], $rows);
    }
}
