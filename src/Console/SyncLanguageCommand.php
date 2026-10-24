<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Saola\Admin\Language\DatabaseLanguageStore;
use Saola\Core\Language\LanguageSources;
use Saola\Core\Language\TextResolver;

final class SyncLanguageCommand extends Command
{
    protected $signature = 'admin:lang:sync {--dry-run : Validate and roll back all changes}';
    protected $description = 'Sync explicitly registered project/package lang/{locale}/*.php seeds into the database';

    public function handle(LanguageSources $sources, DatabaseLanguageStore $store, TextResolver $resolver): int
    {
        try {
            $changes = $store->sync($sources->catalog(), config('saola-admin.language.source_locale', 'en'), (bool) $this->option('dry-run'));
            $resolver->forget();
            $this->info(json_encode(['dry_run' => (bool) $this->option('dry-run')] + $changes, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
