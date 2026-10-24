<?php

declare(strict_types=1);

namespace Saola\Admin\Console;

use Illuminate\Console\Command;
use Saola\Admin\Publishing\SourcePublisher;

class PublishAdminCommand extends Command
{
    protected $signature = 'admin:publish {--dry-run : List source changes without writing files}';
    protected $description = 'Publish or upgrade the owned admin frontend source without overwriting host changes';

    public function handle(): int
    {
        try {
            $publisher = new SourcePublisher(__DIR__.'/../../resources/saola/admin', resource_path('saola/admin'));
            $result = $publisher->publish((bool) $this->option('dry-run'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $result['conflicts'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage()); return self::FAILURE;
        }
    }
}
