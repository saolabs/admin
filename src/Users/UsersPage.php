<?php

declare(strict_types=1);

namespace Saola\Admin\Users;

use Illuminate\Http\Request;
use Saola\Admin\Pages\{AuthorizesPage, ListingQuery, Page, ResourceDocument};

final class UsersPage implements Page, AuthorizesPage
{
    public function key(): string { return 'users'; }
    public function titleKey(): string { return 'admin.users.title'; }
    public function ability(): string { return config('saola-admin.users.read_ability', 'view-admin-users'); }
    public function allows(Request $request): bool { return app(UserDirectory::class)->allows($request); }
    public function document(Request $request): ResourceDocument
    {
        return app(UserDirectory::class)->document($request, ListingQuery::fromRequest($request, ['name', 'email', 'id']));
    }
}
