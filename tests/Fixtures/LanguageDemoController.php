<?php

namespace Saola\Admin\Tests\Fixtures;

use Illuminate\Http\Request;
use Saola\Core\Http\Controllers\BaseWebController;
use Saola\Core\Language\ClientCatalogs;

/** Public host page fixture; not registered by the admin package. */
final class LanguageDemoController extends BaseWebController
{
    protected $context = 'web';

    public function languageDemo(Request $request)
    {
        abort_unless(config('sao.i18n.demo_enabled'), 404);
        $locale = $request->query('locale', $request->getPreferredLanguage() ?: config('app.locale'));
        abort_unless(is_string($locale) && preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/D', $locale), 422);
        app()->setLocale($locale);
        $request->attributes->set('saola.i18n', app(ClientCatalogs::class)->clientConfig('language-demo', $locale));
        return $this->response([
            'dictionaryBase' => str_replace('_locale_', '', route('saola.language.dictionary', ['scope' => 'language-demo', 'locale' => '_locale_'])),
        ]);
    }
}
