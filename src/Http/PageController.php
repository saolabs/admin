<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Saola\Admin\Pages\PageRegistry;
use Saola\Core\Http\Controllers\BaseWebController;

final class PageController extends BaseWebController
{
    protected $context = 'admin';

    public function __invoke(Request $request, PageRegistry $pages, ?string $page = null)
    {
        $page ??= config('saola-admin.panel.home_page', 'workspace');
        $definition = $pages->get($page);
        abort_unless($pages->allows($definition, $request), 403);
        if ($definition instanceof \Saola\Admin\Pages\LinkedPage) return redirect($definition->url());
        if ($definition instanceof \Saola\Admin\Resources\Resource) return app(ResourceController::class)->page($request, $page);
        return $this->response(array_replace(app(PanelData::class)->prepare($request, $page), [
            'page' => $definition->document($request)->toArray(),
        ]), '@RAW:pages.resource');
    }
}
