<?php

namespace App\Commands\Application;

use App\Console\BaseCommand;
use App\Console\Output\QuickpayLogo;

class AboutCommand extends BaseCommand
{
    protected $signature = 'about';

    protected $description = 'Show project, repository, and author information';

    public function handle(): int
    {
        $version = $this->getApplication()?->getVersion() ?? 'unknown';

        $this->newLine();
        $this->line(QuickpayLogo::styled());
        $this->newLine();
        $this->line("  <fg=white;options=bold>Quickpay CLI</>  <fg=green;options=bold>{$version}</>");
        $this->newLine();
        $this->line('  An independent open-source command-line client for the Quickpay API.');
        $this->newLine();
        $this->line('  <fg=gray>Repository</>  https://github.com/tehwave/quickpay-cli');
        $this->line('  <fg=gray>Author</>      Peter 🌊 Jørgensen');
        $this->line('  <fg=gray>Website</>     https://peterchrjoergensen.dk');
        $this->line('  <fg=gray>License</>     MIT');
        $this->newLine();
        $this->line('  Not affiliated with or endorsed by Quickpay.');
        $this->newLine();

        return self::SUCCESS;
    }
}
