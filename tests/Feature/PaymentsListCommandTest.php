<?php

use App\Commands\PaymentsListCommand;
use App\Credentials\CredentialStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY=list-secret');
});

afterEach(function () {
    if ($this->originalApiKey === false) {
        putenv('QUICKPAY_API_KEY');
    } else {
        putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    }
});

it('maps list filters and preserves the original json for one page', function () {
    $raw = '[{"id":12,"order_id":"order-12","custom_value":"unchanged"}]';
    Http::fake(['https://api.quickpay.net/payments*' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);

    $this->artisan('payments:list', [
        '--accepted' => true,
        '--state' => 'processed',
        '--order-id' => 'order-12',
        '--created-after' => '2026-07-01T00:00:00Z',
        '--created-before' => '2026-07-31T23:59:59Z',
        '--page-size' => '50',
        '--json' => true,
    ])->expectsOutput($raw)->assertExitCode(0);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/payments'
            && $query === [
                'accepted' => 'true',
                'state' => 'processed',
                'order_id' => 'order-12',
                'timestamp' => 'created_at',
                'min_time' => '2026-07-01T00:00:00Z',
                'max_time' => '2026-07-31T23:59:59Z',
                'page_size' => '50',
            ];
    });
    Http::assertSentCount(1);
});

it('renders a useful human payment table', function () {
    Http::fake(['https://api.quickpay.net/payments*' => Http::response([[
        'id' => 12,
        'order_id' => 'order-12',
        'accepted' => true,
        'state' => 'processed',
        'currency' => 'DKK',
        'balance' => 2500,
        'created_at' => '2026-07-24T10:00:00Z',
    ]])]);

    $command = new PaymentsListCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('Order ID')
        ->toContain('order-12')
        ->toContain('processed')
        ->toContain('2500');
});

it('aggregates all linked pages in order as one json array', function () {
    Http::fake([
        'https://api.quickpay.net/payments?page_size=2' => Http::response(
            [['id' => 1], ['id' => 2]],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page_key=abc&page_size=2>; rel="next"'],
        ),
        'https://api.quickpay.net/payments?page_key=abc&page_size=2' => Http::response([['id' => 3]]),
    ]);

    $this->artisan('payments:list', ['--page-size' => '2', '--all' => true, '--json' => true])
        ->expectsOutput('[{"id":1},{"id":2},{"id":3}]')
        ->assertExitCode(0);

    Http::assertSentCount(2);
});

it('stops after one page when all is set but no next link exists', function () {
    Http::fake(['https://api.quickpay.net/payments*' => Http::response([['id' => 1]])]);

    $this->artisan('payments:list', ['--all' => true, '--json' => true])
        ->expectsOutput('[{"id":1}]')
        ->assertExitCode(0);

    Http::assertSentCount(1);
});

it('fails before requesting more than max pages', function () {
    Http::fake(['https://api.quickpay.net/payments*' => Http::response(
        [['id' => 1]],
        200,
        ['Link' => '<https://api.quickpay.net/payments?page_key=two>; rel=next'],
    )]);

    $this->artisan('payments:list', ['--all' => true, '--max-pages' => '1'])
        ->expectsOutputToContain('maximum of 1 pages')
        ->assertExitCode(1);

    Http::assertSentCount(1);
});

it('detects pagination cycles before repeating a request', function () {
    Http::fake([
        'https://api.quickpay.net/payments?page_size=20' => Http::response(
            [['id' => 1]],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page_key=loop>; rel=next'],
        ),
        'https://api.quickpay.net/payments?page_key=loop' => Http::response(
            [['id' => 2]],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page_key=loop>; rel=next'],
        ),
    ]);

    $this->artisan('payments:list', ['--all' => true])
        ->expectsOutputToContain('pagination cycle')
        ->assertExitCode(1);

    Http::assertSentCount(2);
});

it('detects a link back to the initial request before requesting it again', function () {
    Http::fake([
        'https://api.quickpay.net/payments?accepted=true&state=processed&page_size=20' => Http::response(
            [['id' => 1]],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page_key=second>; rel=next'],
        ),
        'https://api.quickpay.net/payments?page_key=second' => Http::response(
            [['id' => 2]],
            200,
            ['Link' => '<https://api.quickpay.net/payments?page_size=20&state=processed&accepted=true>; rel=next'],
        ),
        '*' => Http::response([['id' => 99]]),
    ]);

    $this->artisan('payments:list', [
        '--accepted' => true,
        '--state' => 'processed',
        '--all' => true,
    ])->expectsOutputToContain('pagination cycle')
        ->assertExitCode(1);

    $initialRequests = Http::recorded(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return parse_url($request->url(), PHP_URL_PATH) === '/payments'
            && $query === [
                'accepted' => 'true',
                'state' => 'processed',
                'page_size' => '20',
            ];
    });

    expect($initialRequests)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('rejects a hostile next link before contacting its host', function () {
    Http::fake(['*' => Http::response(
        [['id' => 1]],
        200,
        ['Link' => '<https://api.quickpay.net.evil.test/payments?page=2>; rel=next'],
    )]);

    $this->artisan('payments:list', ['--all' => true])
        ->expectsOutputToContain('Quickpay API origin')
        ->assertExitCode(1);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.test'));
});

it('validates list pagination limits before making a request', function (array $arguments, string $message) {
    Http::fake();

    $this->artisan('payments:list', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    [['--page-size' => '0'], 'page-size'],
    [['--page-size' => '101'], 'page-size'],
    [['--page-size' => '1.5'], 'page-size'],
    [['--max-pages' => '0'], 'max-pages'],
    [['--max-pages' => 'nope'], 'max-pages'],
]);

it('fails safely with a login hint when credentials are missing', function () {
    putenv('QUICKPAY_API_KEY');
    app()->instance(CredentialStore::class, new CredentialStore(configPath: sys_get_temp_dir().'/missing-quickpay-'.bin2hex(random_bytes(6)).'.json'));
    Http::fake();

    $this->artisan('payments:list')
        ->expectsOutputToContain('quickpay login')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('routes list failures to stderr', function () {
    Http::fake(['https://api.quickpay.net/payments*' => Http::response(['message' => 'Denied'], 403)]);
    $command = new PaymentsListCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getErrorOutput())->toContain('Denied')
        ->and($tester->getDisplay())->not->toContain('Denied');
});
