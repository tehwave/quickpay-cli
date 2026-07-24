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

it('sanitizes credentials and terminal controls in create json and human output', function () {
    $token = base64_encode(':create-secret');
    $raw = json_encode([
        'id' => 81,
        'order_id' => "create-secret\e]0;owned\x07",
        'state' => $token,
    ], JSON_THROW_ON_ERROR);
    Http::fake(['https://api.quickpay.net/payments' => Http::response($raw, 201)]);
    $jsonCommand = new PaymentsCreateCommand;
    $jsonCommand->setLaravel(app());
    $jsonTester = new CommandTester($jsonCommand);

    $jsonStatus = $jsonTester->execute(
        ['order-id' => 'order-81', '--json' => true],
        ['capture_stderr_separately' => true],
    );
    $json = $jsonTester->getDisplay();

    expect($jsonStatus)->toBe(0)
        ->and(json_validate($json))->toBeTrue()
        ->and($json)->not->toContain('create-secret')->not->toContain($token);

    $humanCommand = new PaymentsCreateCommand;
    $humanCommand->setLaravel(app());
    $humanTester = new CommandTester($humanCommand);
    $humanStatus = $humanTester->execute(['order-id' => 'order-81']);
    $display = $humanTester->getDisplay();

    expect($humanStatus)->toBe(0)
        ->and($display)->toContain('[redacted]\\x1B]0;owned\\x07')
        ->not->toContain('create-secret')
        ->not->toContain($token)
        ->not->toContain("\e");
});

it('rejects invalid successful create bodies before writing json', function (string $body) {
    Http::fake(['https://api.quickpay.net/payments' => Http::response($body, 201)]);
    $command = new PaymentsCreateCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute(
        ['order-id' => 'order-81', '--json' => true],
        ['capture_stderr_separately' => true],
    );

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain('invalid created payment')
        ->not->toContain($body);
})->with([
    'invalid json' => '<html>not json</html>',
    'scalar json' => '42',
    'list json' => '[{"id":81}]',
]);

it('accepts an empty json object as an object-shaped create response', function () {
    Http::fake(['https://api.quickpay.net/payments' => Http::response('{}', 201)]);

    $this->artisan('payments:create', ['order-id' => 'order-81', '--json' => true])
        ->expectsOutput('{}')
        ->assertExitCode(0);
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
