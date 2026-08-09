<?php

namespace App\Console\Output;

use Illuminate\Console\Application;
use NunoMaduro\LaravelConsoleSummary\Contracts\DescriberContract;
use NunoMaduro\LaravelConsoleSummary\Describer;
use Symfony\Component\Console\Output\OutputInterface;

final class QuickpayDescriber extends Describer
{
    protected function describeUsage(OutputInterface $output): DescriberContract
    {
        $output->write("  <fg=yellow;options=bold>USAGE:</> quickpay <command> [options] [arguments]\n");

        return $this;
    }

    protected function describeTitle(Application $application, OutputInterface $output): DescriberContract
    {
        $output->write("\n".QuickpayLogo::styled()."\n");
        $output->write(
            "\n  <fg=white;options=bold>Quickpay CLI</>  <fg=green;options=bold>{$application->getVersion()}</>\n\n"
        );

        return $this;
    }
}
