<?php

namespace Saola\Admin\Http;

use Saola\Core\Http\Controllers\BaseWebController;

/**
 * Base class for host screens that render inside the admin shell. A host extends it, declares module() on the screen's Page
 * and keeps its views under the namespace configured as saola-admin.panel.host_modules.
 *
 * GET pages (HTML and SPA JSON) receive the shell data of PanelData for the module's registered Page; mutations answer with result().
 */
abstract class HostScreenController extends BaseWebController
{
    protected $context = 'admin';

    /**
     * Optional route module (second segment of the route name, e.g. "admin.providers.index" -> "providers") => Page key.
     * Without an entry the registered ModulePage whose module() matches is used.
     */
    protected function pageKeys(): array { return []; }

    /** Outcome of a write (POST/PUT/DELETE), delivered to the view's App.Http. */
    protected function result(array $data, int $status = 200)
    {
        return $this->response($data, null, ['forceJson' => true, 'status' => $status]);
    }

    public function response(array $data = [], ?string $bladePath = null, array $options = [])
    {
        $request = request();
        [, $module, $action] = [...explode('.', (string) $request->route()?->getName()), '', '', ''];
        if ($module) $this->module = $module;
        if ($request->isMethod('GET') && !($options['forceJson'] ?? false)) {
            $data = [...app(PanelData::class)->prepare($request, $this->pageKeys()[$module] ?? app(\Saola\Admin\Pages\PageRegistry::class)->keyForModule($module)), ...$data];
            // Core's own derivation (response() without a view) names the view '@RAW:modules.x', which the theme base then prefixes,
            // and routeToViewPathConfig()'s '@MODULE:module.blade' doubles the module. So derive module/action from the route name here.
            // "@module:" resolves through the "modules" directory (the host namespace), not the theme base.
            $bladePath ??= $module && $action ? "@module:{$action}" : null;
        }

        return parent::response($data, $bladePath, $options);
    }
}
