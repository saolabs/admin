<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Saola\Admin\Settings\PublicSettings;

/** Làm mới `__site` khi thiết lập đổi ngoài màn admin (seed, migrate, sửa tay DB). */
final class RefreshSiteCommand extends Command
{
    protected $signature = 'saola:site-refresh';
    protected $description = 'Refresh the cached public site settings (__site) on every worker';

    public function handle(PublicSettings $settings): int
    {
        $settings->refresh();
        $this->info('Public site settings refreshed (version '.$settings->version().').');

        return self::SUCCESS;
    }
}
