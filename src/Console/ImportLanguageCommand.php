<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Saola\Admin\Language\LanguageExchange;

final class ImportLanguageCommand extends Command
{
    protected $signature = 'admin:lang:import {locale} {file} {--export= : Server-side export baseline id} {--confirm-source=* : Missing keys intentionally translated identically to source}';
    protected $description = 'Validate JSON and create an import preview; published translations are not changed';

    public function handle(LanguageExchange $exchange): int
    {
        try {
            $file = $this->argument('file');
            if (!is_file($file) || !is_readable($file)) throw new \InvalidArgumentException('Cannot read language JSON file.');
            $json = file_get_contents($file, false, null, 0, (int) config('saola-admin.language.exchange.max_bytes', 2097152) + 1);
            if ($json === false) throw new \InvalidArgumentException('Cannot read language JSON file.');
            $result = $exchange->preview($this->argument('locale'), $json, 'console',
                $this->option('export'), $this->option('confirm-source'));
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
