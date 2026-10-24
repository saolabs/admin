<?php

namespace Saola\Admin\Console;

final class InstallAdminCommand extends PublishAdminCommand
{
    protected $signature = 'admin:install {--dry-run : Review frontend installation without writing files}';
    protected $description = 'Install admin frontend source; leaves authentication, database and toolchain configuration to the host';
}
