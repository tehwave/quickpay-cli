<?php

use App\Callbacks\Delivery\CallbackTarget;
use App\Callbacks\Watching\CallbackWatcher;
use App\Callbacks\Watching\CallbackWatcherFactory;
use App\Commands\Api\ApiRequestCommand;
use App\Commands\Callbacks\ReplayCallbackCommand;
use App\Commands\Callbacks\WatchCallbacksCommand;
use App\Commands\Payments\CancelPaymentCommand;
use App\Commands\Payments\CapturePaymentCommand;
use App\Commands\Payments\CreatePaymentCommand;
use App\Commands\Payments\CreatePaymentLinkCommand;
use App\Commands\Payments\RefundPaymentCommand;
use App\Console\Terminal\StdinTerminalDetector;
use App\Credentials\ApiKeyResolver;
use App\Credentials\EnvironmentVariables;
use App\Credentials\HomeDirectory;
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

it('identifies the selected merchant before creating remote payment state', function (string $class, array $arguments, string $path, string $raw): void {
    Http::fake(['https://api.quickpay.net'.$path => Http::response($raw)]);
    $command = new $class;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    expect($tester->execute([...$arguments, '--merchant' => 'shop-b', '--json' => true], ['capture_stderr_separately' => true]))->toBe(0)
        ->and($tester->getDisplay())->toBe($raw)
        ->and($tester->getErrorOutput())->toContain('Merchant: shop-b')->toContain('Credential source: config file');
})->with([
    'create' => [CreatePaymentCommand::class, ['order-id' => 'order-42'], '/payments', '{"id":42}'],
    'link' => [CreatePaymentLinkCommand::class, ['id' => '42', 'amount' => '1000'], '/payments/42/link', '{"url":"https://payment.quickpay.net/link/abc"}'],
]);

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

it('blocks writes for a read-only merchant before any request or confirmation', function (string $class, array $arguments, string $method): void {
    $this->store->add('shop-r', 'fake-secret-r', readOnly: true);
    Http::fake();
    $command = new $class;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $exit = $tester->execute([...$arguments, '--merchant' => 'shop-r'], ['capture_stderr_separately' => true]);
    expect($exit)->toBe(1)
        ->and(preg_replace('/\s+/', ' ', $tester->getErrorOutput()))->toContain("is read-only, so the {$method} request was not sent")
        ->and($tester->getDisplay().$tester->getErrorOutput())->not->toContain('Continue?')->not->toContain('fake-secret-r');
    Http::assertNothingSent();
})->with([
    'capture' => [CapturePaymentCommand::class, ['id' => '42', 'amount' => '1250'], 'POST'],
    'refund' => [RefundPaymentCommand::class, ['id' => '42', 'amount' => '1250'], 'POST'],
    'cancel' => [CancelPaymentCommand::class, ['id' => '42'], 'POST'],
    'create' => [CreatePaymentCommand::class, ['order-id' => 'order-42'], 'POST'],
    'link' => [CreatePaymentLinkCommand::class, ['id' => '42', 'amount' => '1000'], 'PUT'],
    'raw POST' => [ApiRequestCommand::class, ['method' => 'POST', 'path' => '/payments/42/capture'], 'POST'],
    'raw PUT' => [ApiRequestCommand::class, ['method' => 'put', 'path' => '/payments/42/link'], 'PUT'],
    'raw PATCH' => [ApiRequestCommand::class, ['method' => 'PATCH', 'path' => '/payments/42'], 'PATCH'],
    'raw DELETE' => [ApiRequestCommand::class, ['method' => 'DELETE', 'path' => '/payments/42/link'], 'DELETE'],
]);

it('still reads through a read-only merchant', function (string $command, array $arguments, string $path, mixed $body): void {
    $this->store->add('shop-r', 'fake-secret-r', readOnly: true);
    Http::fake([
        'https://api.quickpay.net'.$path.'*' => Http::response($body),
        'http://localhost/callback' => Http::response('', 204),
    ]);
    $this->artisan($command, [...$arguments, '--merchant' => 'shop-r', '--json' => true])->assertExitCode(0);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.quickpay.net'.$path)
        && $request->method() === 'GET');
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.quickpay.net') && $request->method() !== 'GET');
})->with([
    'get' => ['payments:get', ['id' => '42'], '/payments/42', ['id' => 42]],
    'list' => ['payments:list', [], '/payments', [['id' => 42]]],
    'raw GET' => ['api', ['method' => 'GET', 'path' => '/payments/42'], '/payments/42', ['id' => 42]],
    'replay to a local handler' => ['callbacks:replay', ['payment-id' => '42', '--to' => 'http://localhost/callback'], '/payments/42', ['id' => 42, 'merchant_id' => 123, 'order_id' => 'order-42', 'operations' => []]],
]);

it('warns when QUICKPAY_PRIVATE_KEY signs replayed callbacks for a saved merchant', function (bool $json): void {
    Http::fake([
        'https://api.quickpay.net/payments/42*' => Http::response(['id' => 42, 'merchant_id' => 123, 'order_id' => 'order-42', 'operations' => []]),
        'http://localhost/callback' => Http::response('', 204),
    ]);
    $command = new ReplayCallbackCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $exit = $tester->execute(['payment-id' => '42', '--to' => 'http://localhost/callback', '--merchant' => 'shop-b', '--json' => $json], ['capture_stderr_separately' => true]);
    $warnings = $json ? $tester->getErrorOutput() : $tester->getDisplay();
    expect($exit)->toBe(0)
        ->and($warnings)->toContain('Warning: QUICKPAY_PRIVATE_KEY is set, so callbacks are signed with it instead of the account private key of shop-b. Unset it if it belongs to another merchant.')
        ->and($tester->getDisplay().$tester->getErrorOutput())->not->toContain('fake-signing-key');
    if ($json) {
        expect(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['success'])->toBeTrue();
    }
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/account/private-key'));
})->with(['human' => false, 'JSON' => true]);

it('warns before watching when QUICKPAY_PRIVATE_KEY signs callbacks for a saved merchant', function (): void {
    app()->instance(CallbackWatcherFactory::class, new class extends CallbackWatcherFactory
    {
        public function __construct() {}

        public function make(QuickpayClient $quickpay, Factory $http): CallbackWatcher
        {
            return new class implements CallbackWatcher
            {
                public function run(?string $paymentId, ?string $orderId, CallbackTarget $target, string $apiKey, string $privateKey, int $interval, int $deliveryAttempts, Closure $observer): void {}
            };
        }
    });
    $this->artisan('callbacks:watch', ['payment-id' => '42', '--to' => 'http://localhost/callback'])
        ->expectsOutputToContain('Merchant: shop-a')
        ->expectsOutputToContain('Warning: QUICKPAY_PRIVATE_KEY is set, so callbacks are signed with it instead of the account private key of shop-a.')
        ->assertExitCode(0);
    Http::assertNothingSent();
});

it('does not warn about the private key when it cannot belong to another saved merchant', function (bool $environmentApiKey): void {
    if ($environmentApiKey) {
        putenv('QUICKPAY_API_KEY=fake-environment');
    } else {
        putenv('QUICKPAY_PRIVATE_KEY');
    }
    Http::fake([
        'https://api.quickpay.net/payments/42*' => Http::response(['id' => 42, 'merchant_id' => 123, 'order_id' => 'order-42', 'operations' => []]),
        'https://api.quickpay.net/account/private-key' => Http::response(['private_key' => 'fake-account-signing-key']),
        'http://localhost/callback' => Http::response('', 204),
    ]);
    $command = new ReplayCallbackCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    expect($tester->execute(['payment-id' => '42', '--to' => 'http://localhost/callback'], ['capture_stderr_separately' => true]))->toBe(0)
        ->and($tester->getDisplay().$tester->getErrorOutput())->not->toContain('QUICKPAY_PRIVATE_KEY');
})->with(['environment API key' => true, 'saved merchant fetching its own key' => false]);

it('uses QUICKPAY_API_KEY and reports a clean error when no home folder is set', function (): void {
    $saved = ['HOME' => getenv('HOME'), 'USERPROFILE' => getenv('USERPROFILE')];
    putenv('HOME');
    putenv('USERPROFILE');
    putenv('QUICKPAY_API_KEY=fake-environment');
    try {
        $store = MerchantStore::inHome(new HomeDirectory(new EnvironmentVariables));
        app()->instance(MerchantStore::class, $store);
        app()->instance(ApiKeyResolver::class, new ApiKeyResolver(new EnvironmentVariables, $store, $this->project));
        Http::fake(['https://api.quickpay.net/ping' => Http::response(['msg' => 'pong'])]);
        $this->artisan('api', ['method' => 'GET', 'path' => '/ping', '--json' => true])->assertExitCode(0);
        Http::assertSentCount(1);
        $this->artisan('merchants:list')->expectsOutputToContain('Unable to locate the home directory')->assertExitCode(1);
    } finally {
        foreach ($saved as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
    }
});
