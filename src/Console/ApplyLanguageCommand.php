<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Saola\Admin\Language\LanguageExchange;
use Saola\Core\Language\TextResolver;

final class ApplyLanguageCommand extends Command
{
    protected $signature = 'admin:lang:apply {batch} {--publish : Publish instead of keeping the import as a draft}';
    protected $description = 'Save a validated import draft, or explicitly publish it with revision checks';

    public function handle(LanguageExchange $exchange, TextResolver $resolver): int
    {
        try {
            $result = $exchange->apply($this->argument('batch'), 'console', (bool) $this->option('publish'));
            $resolver->forget();
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
