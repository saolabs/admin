<?php

namespace App\Modules\Shop\Resources;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Http\Request;
use Saola\Admin\Resources\Resource;

class ShopItem extends Model { protected $table = 'shop_items'; protected $guarded = []; }

/** Discovered by convention; authorised by ability prefix, no Policy. */
class ItemResource extends Resource
{
    public function key(): string { return 'items'; }
    public function titleKey(): string { return 'shop.items'; }
    public function abilityPrefix(): string { return 'shop.items'; }
    public function navigation(): array { return ['icon' => 'ri-store-line', 'order' => 20]; }
    public function model(): string { return ShopItem::class; }
    public function query(Request $request): Builder { return ShopItem::query(); }
    public function fields(): array { return ['name' => ['labelKey' => 'shop.name', 'type' => 'text', 'required' => true, 'maxLength' => 50]]; }
    public function rules(Request $request, ?Model $record): array { return ['name' => ['required', 'string', 'max:50']]; }
}
