<?php

use App\Commands\PaymentsCreateCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY=create-secret');
});

afterEach(function () {
    if ($this->originalApiKey === false) {
        putenv('QUICKPAY_API_KEY');
    } else {
        putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    }
});

it('creates a payment with normalized currency and nested fields', function () {
    $raw = '{"id":81,"order_id":"order-81","currency":"DKK","custom":"unchanged"}';
    Http::fake(['https://api.quickpay.net/payments' => Http::response($raw, 201, ['Content-Type' => 'application/json'])]);

    $this->artisan('payments:create', [
        'order-id' => 'order-81',
        'currency' => 'dkk',
        '--field' => [
            'invoice_address[email]=a@example.com',
            'basket[0][qty]=2',
            'description=reference=a=b',
        ],
        '--json' => true,
    ])->expectsOutput($raw)->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.quickpay.net/payments'
        && $request->data() === [
            'invoice_address' => ['email' => 'a@example.com'],
            'basket' => [['qty' => '2']],
            'description' => 'reference=a=b',
            'order_id' => 'order-81',
            'currency' => 'DKK',
        ]);
});

it('keeps reserved create arguments authoritative over fields', function () {
    Http::fake(['https://api.quickpay.net/payments' => Http::response(['id' => 81], 201)]);

    $this->artisan('payments:create', [
        'order-id' => 'order-81',
        'currency' => 'dkk',
        '--field' => ['order_id=attacker', 'currency=USD'],
        '--json' => true,
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->data()['order_id'] === 'order-81'
        && $request->data()['currency'] === 'DKK');
});

it('uses DKK as the default currency and renders created details', function () {
    Http::fake(['https://api.quickpay.net/payments' => Http::response([
        'id' => 81,
        'order_id' => 'order-81',
        'currency' => 'DKK',
        'state' => 'new',
    ], 201)]);
    $command = new PaymentsCreateCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute(['order-id' => 'order-81']))->toBe(0)
        ->and($tester->getDisplay())
        ->toContain('order-81')
        ->toContain('DKK')
        ->toContain('new');

    Http::assertSent(fn (Request $request): bool => $request->data()['currency'] === 'DKK');
});

it('validates create arguments and fields before making a request', function (array $arguments, string $message) {
    Http::fake();

    $this->artisan('payments:create', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    [['order-id' => 'abc'], '4 through 20'],
    [['order-id' => str_repeat('x', 21)], '4 through 20'],
    [['order-id' => 'order-81', 'currency' => 'DK'], 'three'],
    [['order-id' => 'order-81', 'currency' => 'D1K'], 'three'],
    [['order-id' => 'order-81', '--field' => ['malformed']], 'key=value'],
]);

it('never exposes the credential through a reflected validation error', function () {
    Http::fake();
    $command = new PaymentsCreateCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'order-id' => 'order-81',
        '--field' => ['create-secret[=value'],
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getErrorOutput())->toContain('Malformed field key')
        ->not->toContain('create-secret');

    Http::assertNothingSent();
});
