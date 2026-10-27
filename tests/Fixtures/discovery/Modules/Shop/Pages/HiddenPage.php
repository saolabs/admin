<?php

namespace App\Modules\Shop\Pages;

use Illuminate\Http\Request;
use Saola\Admin\Pages\{NavigablePage, ResourceDocument};

final class HiddenPage implements NavigablePage
{
    public function key(): string { return 'hidden'; }
    public function titleKey(): string { return 'shop.hidden'; }
    public function ability(): string { return 'shop.reports.view'; }
    public function navigation(): array { return ['hidden' => true]; }
    public function document(Request $request): ResourceDocument { return new ResourceDocument($this->titleKey(), ''); }
}
