<?php

namespace App\Modules\Shop\Pages;

use Illuminate\Http\Request;
use Saola\Admin\Pages\{LinkedPage, ModulePage, NavigablePage, ResourceDocument};

final class ReportPage implements LinkedPage, ModulePage, NavigablePage
{
    public function key(): string { return 'reports'; }
    public function titleKey(): string { return 'shop.reports'; }
    public function ability(): string { return 'shop.reports.view'; }
    public function module(): string { return 'shop-reports'; }
    public function url(): string { return '/control/shop/reports'; }
    public function navigation(): array { return ['order' => 10]; }
    public function document(Request $request): ResourceDocument { return new ResourceDocument($this->titleKey(), ''); }
}
