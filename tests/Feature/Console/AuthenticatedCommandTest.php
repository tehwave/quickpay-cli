<?php

use App\Callbacks\Delivery\CallbackTarget;
use App\Callbacks\Watching\CallbackWatcher;
use App\Callbacks\Watching\CallbackWatcherFactory;
use App\Commands\Api\ApiRequestCommand;
use App\Commands\Callbacks\WatchCallbacksCommand;
use App\Commands\Payments\CancelPaymentCommand;
use App\Commands\Payments\CapturePaymentCommand;
use App\Commands\Payments\RefundPaymentCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKeyResolver;
use App\Credentials\EnvironmentVariables;
use App\Credentials\MerchantStore;
use App\Credentials\ProjectMerchantFile;
use App\Quickpay\QuickpayClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/quickpay-command-selection-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700, true);
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    $this->originalPrivateKey = getenv('QUICKPAY_PRIVATE_KEY');
    putenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_PRIVATE_KEY=fake-signing-key');
    $this->store = new MerchantStore($this->directory.'/config.json');
    $this->store->add('shop-a', 'fake-secret-a');
    $this->store->add('shop-b', 'fake-secret-b');
    $this->project = new ProjectMerchantFile($this->directory);
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
});

afterEach(function (): void {
    foreach (['QUICKPAY_API_KEY' => $this->originalApiKey, 'QUICKPAY_PRIVATE_KEY' => $this->originalPrivateKey] as $name => $value) {
        $value === false ? putenv($name) : putenv($name.'='.$value);
    }
    foreach (glob($this->directory.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->directory);
});

it('identifies the selected merchant in payment confirmation without changing response JSON', function (string $class, string $operation, array $arguments, bool $json, bool $yes): void {
    $raw = '{"id":42,"state":"processed","custom":"unchanged"}';
    Http::fake([
        'https://api.quickpay.net/payments/42' => Http::response(['id' => 42, 'merchant_id' => 123, 'order_id' => 'order-42', 'currency' => 'DKK', 'state' => 'authorized', 'accepted' => true, 'balance' => 2000]),
        'https://api.quickpay.net/payments/42/'.$operation => Http::response($raw),
    ]);
    $command = new $class;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['yes']);
    $exit = $tester->execute([...$arguments, '--merchant' => 'shop-b', '--json' => $json, '--yes' => $yes], ['capture_stderr_separately' => true]);
    $summary = $json ? $tester->getErrorOutput() : $tester->getDisplay();
    expect($exit)->toBe(0)->and($summary)->toContain('Merchant: shop-b')->toContain('Credential source: config file')->toContain('Merchant ID: 123');
    if ($json) {
        expect($tester->getDisplay())->toBe($raw);
    }
    $requests = Http::recorded();
    expect($requests)->toHaveCount(2)->and($requests[0][0]->method())->toBe('GET')->and($requests[1][0]->method())->toBe('POST');
    foreach ($requests as [$request]) {
        expect($request->header('Authorization'))->toBe(['Basic '.base64_encode(':fake-secret-b')]);
    }
})->with([
    'capture JSON yes' => [CapturePaymentCommand::class, 'capture', ['id' => '42', 'amount' => '1250'], true, true],
    'capture JSON confirmation' => [CapturePaymentCommand::class, 'capture', ['id' => '42', 'amount' => '1250'], true, false],
    'capture human confirmation' => [CapturePaymentCommand::class, 'capture', ['id' => '42', 'amount' => '1250'], false, false],
    'refund JSON yes' => [RefundPaymentCommand::class, 'refund', ['id' => '42', 'amount' => '1250'], true, true],
    'cancel JSON yes' => [CancelPaymentCommand::class, 'cancel', ['id' => '42'], true, true],
]);

it('keeps the inspected merchant pinned when its saved credential changes before mutation', function (): void {
    $store = $this->store;
    Http::fake(function (Request $request) use ($store) {
        if ($request->method() === 'GET') {
            $store->select('shop-a');
            $store->remove('shop-b');
        }

        return Http::response(['id' => 42, 'merchant_id' => 123, 'state' => 'processed']);
    });
    $this->artisan('payments:capture', ['id' => '42', 'amount' => '1250', '--merchant' => 'shop-b', '--yes' => true, '--json' => true])->assertExitCode(0);
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->header('Authorization'))->toBe(['Basic '.base64_encode(':fake-secret-b')]);
    }
});

it('never mutates after declining a selected merchant confirmation', function (): void {
    Http::fake(['*' => Http::response(['id' => 42, 'merchant_id' => 123])]);
    $command = new CapturePaymentCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['no']);
    expect($tester->execute(['id' => '42', 'amount' => '1250', '--merchant' => 'shop-b', '--json' => true], ['capture_stderr_separately' => true]))->toBe(0)
        ->and($tester->getDisplay())->toBe('')->and($tester->getErrorOutput())->toContain('Merchant: shop-b')->toContain('Cancelled');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('identifies a selected merchant before confirming a raw mutation', function (): void {
    $raw = '{"id":42,"state":"captured"}';
    Http::fake(['*' => Http::response($raw)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['yes']);
    expect($tester->execute(['method' => 'POST', 'path' => '/payments/42/capture', '--merchant' => 'shop-b', '--json' => true], ['capture_stderr_separately' => true]))->toBe(0)
        ->and($tester->getDisplay())->toBe($raw)->and($tester->getErrorOutput())->toContain('Merchant: shop-b')->toContain('Credential source: config file')->toContain('Continue?');
    expect(strpos($tester->getErrorOutput(), 'Merchant:'))->toBeLessThan(strpos($tester->getErrorOutput(), 'Continue?'));
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Basic '.base64_encode(':fake-secret-b')]);
});

it('selects the same saved key for every other authenticated command family', function (string $command, array $arguments, string $path, mixed $body): void {
    Http::fake([
        'https://api.quickpay.net'.$path.'*' => Http::response($body),
        'http://localhost/callback' => Http::response('', 204),
    ]);
    $this->artisan($command, [...$arguments, '--merchant' => 'shop-b', '--json' => true])->assertExitCode(0);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.quickpay.net'.$path)
        && $request->header('Authorization') === ['Basic '.base64_encode(':fake-secret-b')]);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://localhost/callback' && $request->hasHeader('Authorization'));
})->with([
    'create' => ['payments:create', ['order-id' => 'order-42'], '/payments', ['id' => 42]],
    'get' => ['payments:get', ['id' => '42'], '/payments/42', ['id' => 42]],
    'link' => ['payments:link', ['id' => '42', 'amount' => '1000'], '/payments/42/link', ['url' => 'https://payment.quickpay.net/link/abc']],
    'list' => ['payments:list', [], '/payments', [['id' => 42]]],
    'raw GET' => ['api', ['method' => 'GET', 'path' => '/payments/42'], '/payments/42', ['id' => 42]],
    'replay' => ['callbacks:replay', ['payment-id' => '42', '--to' => 'http://localhost/callback'], '/payments/42', ['id' => 42, 'merchant_id' => 123, 'order_id' => 'order-42', 'operations' => []]],
]);

it('rejects unresolved or conflicting merchant selection before HTTP', function (string $command, array $arguments, bool $environment): void {
    if ($environment) {
        putenv('QUICKPAY_API_KEY=fake-environment');
    }
    Http::fake();
    $this->artisan($command, [...$arguments, '--merchant' => $environment ? 'shop-b' : 'shop-missing'])->assertExitCode(1);
    Http::assertNothingSent();
})->with([
    'capture unknown' => ['payments:capture', ['id' => '42', 'amount' => '1250', '--yes' => true], false],
    'raw conflict' => ['api', ['method' => 'POST', 'path' => '/payments/42/capture', '--yes' => true], true],
    'watch unknown' => ['callbacks:watch', ['--to' => 'http://localhost/callback'], false],
    'replay conflict' => ['callbacks:replay', ['payment-id' => '42', '--to' => 'http://localhost/callback'], true],
]);

it('keeps scoped and account-wide watchers pinned after selection and removal', function (bool $scoped): void {
    Http::fake(['https://api.quickpay.net/ping' => Http::response(['scope' => 'merchant'])]);
    app()->instance(CallbackWatcherFactory::class, new class($this->store) extends CallbackWatcherFactory
    {
        public function __construct(private MerchantStore $store) {}

        public function make(QuickpayClient $quickpay, Factory $http): CallbackWatcher
        {
            return new class($quickpay, $this->store) implements CallbackWatcher
            {
                public function __construct(private QuickpayClient $quickpay, private MerchantStore $store) {}

                public function run(?string $paymentId, ?string $orderId, CallbackTarget $target, string $apiKey, string $privateKey, int $interval, int $deliveryAttempts, Closure $observer): void
                {
                    $this->store->select('shop-a');
                    $this->store->remove('shop-b');
                    $this->quickpay->get('/ping');
                    $this->quickpay->get('/ping');
                    if ($paymentId === null) {
                        $observer('watching-all', ['ready_at' => '2026-09-30T10:00:00+00:00']);
                    }
                }
            };
        }
    });
    $command = new WatchCallbacksCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    expect($tester->execute(['--merchant' => 'shop-b', '--to' => 'http://localhost/callback', ...($scoped ? ['payment-id' => '42'] : [])]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Merchant: shop-b')->toContain('Credential source: config file');
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->header('Authorization'))->toBe(['Basic '.base64_encode(':fake-secret-b')]);
    }
})->with(['scoped' => true, 'account-wide' => false]);
