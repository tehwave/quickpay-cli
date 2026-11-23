<?php

use App\Console\BaseCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKeyResolver;
use App\Credentials\EnvironmentVariables;
use App\Credentials\MerchantStore;
use App\Credentials\ProjectMerchantFile;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Integration');

pest()->beforeEach(function (): void {
    $this->tempDirectory = sys_get_temp_dir().'/quickpay-merchant-command-'.bin2hex(random_bytes(8));
    mkdir($this->tempDirectory.'/project/nested', 0700, true);
    $this->originalDirectory = getcwd();
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY');
    chdir($this->tempDirectory.'/project');
    $this->store = new MerchantStore($this->tempDirectory.'/config.json');
    $this->project = new ProjectMerchantFile;
    app()->instance(MerchantStore::class, $this->store);
    app()->instance(ProjectMerchantFile::class, $this->project);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver(new EnvironmentVariables, $this->store, $this->project));
    app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return true;
        }
    });
    Http::preventStrayRequests();
})->afterEach(function (): void {
    chdir($this->originalDirectory);
    $this->originalApiKey === false ? putenv('QUICKPAY_API_KEY') : putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    app('files')->deleteDirectory($this->tempDirectory);
})->in('Feature/Commands/Merchants');

/** @param class-string<BaseCommand> $class */
function merchantCommandResult(string $class, array $input = []): array
{
    $command = new $class;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $exit = $tester->execute($input, ['capture_stderr_separately' => true]);

    return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
}
