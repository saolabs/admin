<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Saola\Admin\Language\LanguageExchange;

final class ExportLanguageCommand extends Command
{
    protected $signature = 'admin:lang:export {locale} {--ticket : Write the server-side baseline batch id to stderr}';
    protected $description = 'Write one complete nested JSON translation tree to stdout (metadata stays outside the file)';

    public function handle(LanguageExchange $exchange): int
    {
        try {
            $export = $exchange->export($this->argument('locale'), 'console');
            $this->line(json_encode($export['tree'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if ($this->option('ticket')) fwrite(STDERR, 'Export batch: '.$export['batch'].PHP_EOL);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
