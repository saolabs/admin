<?php

declare(strict_types=1);

namespace Saola\Admin\Http;

use Illuminate\Http\Request;
use Saola\Core\Http\Controllers\BaseWebController;

/** Opt-in, read-only developer catalogue, protected by the panel's normal access gate. */
final class UiCatalogController extends BaseWebController
{
    protected $context = 'admin';

    public function __invoke(Request $request)
    {
        abort_unless(config('saola-admin.panel.ui_catalog', false) && config('saola-admin.panel.theme') === 'material', 404);
        $shell = app(PanelData::class)->prepare($request, 'ui-library');
        $asset = static fn (int $n) => asset('static/saola/admin/themes/material/assets/vendor/material/images/small/img-'.$n.'.jpg');
        $close = text('admin.ui.close');
        $items = [
            ['id' => 'overview', 'label' => 'Overview', 'text' => 'A reusable panel driven by data.'],
            ['id' => 'details', 'label' => 'Details', 'text' => 'Views own interaction state; applications own their data and actions.'],
            ['id' => 'disabled', 'label' => 'Disabled', 'text' => '', 'disabled' => true],
        ];
        $samples = [
            'colors' => ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'dark', 'light'],
            'alerts' => array_map(static fn ($color) => ['id' => 'alert-'.$color, 'color' => $color, 'title' => $color, 'text' => 'A simple alert from the template.', 'dismissible' => true, 'closeLabel' => $close], ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'dark', 'light']),
            'variants' => [
                ['id' => 'solid', 'title' => 'Default Buttons', 'rounded' => false],
                ['id' => 'outline', 'title' => 'Outline Buttons', 'rounded' => false],
                ['id' => 'solid-rounded', 'title' => 'Rounded Buttons', 'rounded' => true],
                ['id' => 'soft', 'title' => 'Soft Buttons', 'rounded' => false],
                ['id' => 'ghost', 'title' => 'Ghost Buttons', 'rounded' => false],
            ],
            'cards' => [
                ['id' => 'simple', 'image' => $asset(1), 'title' => 'Web Developer', 'text' => 'A simple card with an image, description and primary action.',
                    'actionsInBody' => true, 'actions' => [['label' => 'Submit', 'action' => 'submit']]],
                ['id' => 'footer', 'image' => $asset(2), 'title' => 'How apps is changing the IT world', 'text' => 'A card with a separate footer for related actions.',
                    'actions' => [['label' => 'Read More', 'action' => 'read', 'color' => 'link', 'icon' => 'ri-arrow-right-s-line'], ['label' => 'Bookmark', 'action' => 'bookmark', 'color' => 'success', 'variant' => 'ghost', 'icon' => 'ri-bookmark-line']]],
                ['id' => 'list', 'image' => $asset(3), 'text' => 'The same component can show a flush list instead of actions.',
                    'items' => [['id' => 'one', 'label' => 'An item'], ['id' => 'two', 'label' => 'A second item'], ['id' => 'three', 'label' => 'A third item']]],
                ['id' => 'middle', 'image' => $asset(4), 'imagePosition' => 'middle', 'title' => 'What planning process needs ?', 'subtitle' => 'Development',
                    'text' => 'Title, image, description and footer are selected from one data contract.', 'actions' => [['label' => 'Read More', 'action' => 'read', 'color' => 'link']]],
            ],
            'stats' => [
                ['label' => 'Total Earnings', 'value' => '$559.25k', 'change' => '+16.24%', 'trend' => 'up', 'color' => 'success', 'icon' => 'bx bx-dollar-circle', 'caption' => 'Sample metric'],
                ['label' => 'Orders', 'value' => '36,894', 'change' => '-3.57%', 'trend' => 'down', 'color' => 'info', 'filled' => true, 'icon' => 'bx bx-shopping-bag', 'caption' => 'Sample metric'],
                ['label' => 'Customers', 'value' => '183.35M', 'change' => '+29.08%', 'trend' => 'up', 'color' => 'warning', 'icon' => 'bx bx-user-circle', 'caption' => 'Sample metric'],
                ['label' => 'My Balance', 'value' => '$165.89k', 'change' => '+0.00%', 'trend' => 'neutral', 'color' => 'primary', 'icon' => 'bx bx-wallet', 'caption' => 'Sample metric'],
            ],
            'tabs' => ['id' => 'demo-tabs', 'label' => 'Default Tabs', 'active' => 'overview', 'items' => $items],
            'accordion' => ['id' => 'demo-accordion', 'expanded' => ['overview'], 'items' => $items],
            'dropdown' => ['id' => 'demo-dropdown', 'label' => 'Dropdown button', 'items' => $items],
            'dialog' => ['id' => 'demo-dialog', 'label' => 'Standard Modal', 'title' => 'Modal Heading', 'text' => 'A standard Velzon modal with Saola lifecycle and keyboard handling.', 'closeLabel' => $close, 'centered' => true],
            'drawer' => ['id' => 'demo-drawer', 'label' => 'Offcanvas', 'title' => 'Offcanvas', 'text' => 'The dialog family also supports an offcanvas presentation.', 'closeLabel' => $close, 'variant' => 'drawer'],
            'toast' => ['id' => 'demo-toast', 'title' => 'Notification', 'caption' => 'Just now', 'text' => 'A toast from the template.', 'closeLabel' => $close],
            'pagination' => ['label' => 'Pagination', 'previousLabel' => 'Previous', 'nextLabel' => 'Next', 'page' => 2, 'totalPages' => 5, 'pages' => [1, 2, 3, 4, 5]],
        ];
        return $this->response([...$shell, 'samples' => $samples], '@RAW:pages.ui-library');
    }
}
