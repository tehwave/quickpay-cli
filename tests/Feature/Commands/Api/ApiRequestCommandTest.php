<?php

use App\Commands\Api\ApiRequestCommand;
use App\Console\Terminal\StdinTerminalDetector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->originalApiKey = getenv('QUICKPAY_API_KEY');
    putenv('QUICKPAY_API_KEY=raw-api-secret');
    app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return true;
        }
    });
});

afterEach(function () {
    if ($this->originalApiKey === false) {
        putenv('QUICKPAY_API_KEY');
    } else {
        putenv('QUICKPAY_API_KEY='.$this->originalApiKey);
    }
});

it('allows normalized get requests and merges query options with explicit values winning', function () {
    $raw = '{"items":[{"id":42}],"custom":"unchanged"}';
    Http::fake(['https://api.quickpay.net/payments*' => Http::response($raw, 200, ['Content-Type' => 'application/json'])]);

    $this->artisan('api', [
        'method' => 'get',
        'path' => 'payments?page=1&filter[state]=new',
        '--query' => ['page=2', 'filter[state]=processed', 'filter[accepted]=true'],
        '--header' => ['Idempotency-Key:lookup:42'],
        '--json' => true,
    ])->expectsOutput($raw)->assertExitCode(0);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/payments'
            && $query === [
                'page' => '2',
                'filter' => ['state' => 'processed', 'accepted' => 'true'],
            ]
            && $request->header('Idempotency-Key') === ['lookup:42'];
    });
});

it('confirms raw mutations and keeps json stdout machine readable', function () {
    $raw = '{"id":42,"state":"captured"}';
    Http::fake(['https://api.quickpay.net/payments/42/capture' => Http::response($raw)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['yes']);

    $status = $tester->execute([
        'method' => 'post',
        'path' => '/payments/42/capture',
        '--data' => ['amount=1250', 'metadata[source]=cli'],
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toBe($raw)
        ->and($tester->getErrorOutput())
        ->toContain('Quickpay API request: POST')
        ->not->toContain('/payments/42/capture')
        ->toContain('Continue?');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['amount' => '1250', 'metadata' => ['source' => 'cli']]);
});

it('runs a raw mutation directly with yes in non-interactive input', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(['id' => 42])]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute([
        'method' => 'patch',
        'path' => 'payments/42',
        '--data-json' => '{"description":"updated"}',
        '--yes' => true,
    ], ['interactive' => false]))->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->data() === ['description' => 'updated']);
});

it('performs no raw request when mutation confirmation is declined', function () {
    Http::fake();

    $this->artisan('api', ['method' => 'DELETE', 'path' => '/payments/42'])
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Cancelled')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('requires yes for non-interactive raw mutations', function () {
    Http::fake();
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'PUT',
        'path' => '/payments/42',
        '--json' => true,
    ], ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())
        ->toContain('Quickpay API request: PUT')
        ->not->toContain('/payments/42')
        ->toContain('--yes');
    Http::assertNothingSent();
});

it('does not accept piped confirmation when symfony input is interactive but stdin is not a tty', function () {
    Http::fake();
    app()->instance(StdinTerminalDetector::class, new class implements StdinTerminalDetector
    {
        public function isTty(): bool
        {
            return false;
        }
    });
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs(['yes']);

    $status = $tester->execute([
        'method' => 'POST',
        'path' => '/payments',
    ], ['interactive' => true, 'capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toContain('Quickpay API request: POST')
        ->not->toContain('/payments')
        ->not->toContain('Continue?')
        ->and($tester->getErrorOutput())->toContain('--yes');
    Http::assertNothingSent();
});

it('never includes a raw mutation path or its percent-encoded credential in safety context', function () {
    putenv('QUICKPAY_API_KEY=encoded-secret');
    $encoded = '%65%6E%63%6F%64%65%64%2D%73%65%63%72%65%74';
    Http::fake(['https://api.quickpay.net/resources/*' => Http::response(['ok' => true])]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'DELETE',
        'path' => '/resources/'.$encoded,
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toBe('{"ok":true}')
        ->and($tester->getErrorOutput())->toContain('Quickpay API request: DELETE')
        ->not->toContain('/resources/')
        ->not->toContain('encoded-secret')
        ->not->toContain($encoded);
});

it('validates raw request inputs before making a request', function (array $arguments, string $message) {
    Http::fake();

    $this->artisan('api', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    [['method' => 'OPTIONS', 'path' => '/payments'], 'method'],
    [['method' => 'GET', 'path' => 'https://evil.test/payments'], 'relative'],
    [['method' => 'GET', 'path' => '/payments', '--header' => ['authorization:secret']], 'cannot be overridden'],
    [['method' => 'POST', 'path' => '/payments', '--data' => ['a=b'], '--data-json' => '{}', '--yes' => true], 'mutually exclusive'],
    [['method' => 'POST', 'path' => '/payments', '--data-json' => '{invalid', '--yes' => true], 'valid JSON'],
    [['method' => 'POST', 'path' => '/payments', '--data-json' => '42', '--yes' => true], 'object or array'],
    [['method' => 'GET', 'path' => '/payments', '--query' => ['malformed']], 'key=value'],
]);

it('sends empty json objects when data-json explicitly supplies one', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response(['id' => 42])]);

    $this->artisan('api', [
        'method' => 'PUT',
        'path' => '/payments/42',
        '--data-json' => '{}',
        '--yes' => true,
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->body() === '{}');
});

it('pretty prints arbitrary json and safely writes successful non-json bodies', function () {
    Http::fakeSequence()
        ->push('{"ok":true,"items":[1,2]}', 200, ['Content-Type' => 'application/json'])
        ->push('<not-a-style-tag>', 200, ['Content-Type' => 'text/plain']);

    $this->artisan('api', ['method' => 'GET', 'path' => '/first'])
        ->expectsOutputToContain("{\n    \"ok\": true")
        ->assertExitCode(0);
    $this->artisan('api', ['method' => 'GET', 'path' => '/second'])
        ->expectsOutput('<not-a-style-tag>')
        ->assertExitCode(0);
});

it('sanitizes terminal controls and credentials in successful non-json bodies', function () {
    $body = "line one\nline two\r\nraw-api-secret\t\e]0;owned\x07\rend\x9d";
    Http::fake(['https://api.quickpay.net/text' => Http::response($body, 200, ['Content-Type' => 'text/plain'])]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute(['method' => 'GET', 'path' => '/text']);
    $display = $tester->getDisplay();

    expect($status)->toBe(0)
        ->and($display)->toContain("line one\nline two\r\n[redacted]")
        ->toContain('\\x09\\x1B]0;owned\\x07\\x0Dend\\x9D')
        ->not->toContain("\e")
        ->not->toContain('raw-api-secret');
});

it('fails json mode safely when a successful raw response is not valid json', function () {
    Http::fake(['https://api.quickpay.net/html' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html'])]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'GET',
        'path' => '/html',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain('valid JSON')
        ->not->toContain('<html>');
});

it('writes null in json mode for successful responses without a body', function (int $status) {
    Http::fake(['https://api.quickpay.net/empty' => Http::response('', $status)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'method' => 'GET',
        'path' => '/empty',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($exitCode)->toBe(0)
        ->and($tester->getDisplay())->toBe('null')
        ->and($tester->getErrorOutput())->toBe('');
})->with([200, 204]);

it('still rejects a nonempty malformed 204 body in json mode', function () {
    Http::fake(['https://api.quickpay.net/empty' => Http::response('<html>not empty</html>', 204)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([
        'method' => 'GET',
        'path' => '/empty',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($exitCode)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain('valid JSON')
        ->not->toContain('<html>');
});

it('semantically redacts escaped credentials while keeping json output valid', function () {
    putenv('QUICKPAY_API_KEY=a/b');
    $raw = '{"escaped":"a\\/b","safe":"unchanged"}';
    Http::fake(['https://api.quickpay.net/ping' => Http::response($raw)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'GET',
        'path' => '/ping',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);
    $json = $tester->getDisplay();

    expect($status)->toBe(0)
        ->and(json_validate($json))->toBeTrue()
        ->and(json_decode($json, true))->toBe(['escaped' => '[redacted]', 'safe' => 'unchanged'])
        ->and($json)->not->toContain('a/b')->not->toContain('a\\/b');
});

it('renders structured api errors to stderr with credential redaction', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response([
        'message' => 'Rejected raw-api-secret',
        'errors' => ['amount' => ['Too high']],
        'error_code' => 'invalid_request',
    ], 422)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'POST',
        'path' => '/payments/42',
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())
        ->toContain('Rejected [redacted]')
        ->toContain('amount: Too high')
        ->toContain('error_code:')
        ->toContain('invalid_request')
        ->not->toContain('raw-api-secret');
});

it('renders api error summaries without terminal control bytes', function () {
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response([
        'message' => "Rejected\e]0;owned\x07\rline\nnext\r\nend",
        'errors' => ['amount' => ["Too\tlarge"]],
    ], 422)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'POST',
        'path' => '/payments/42',
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);
    $error = $tester->getErrorOutput();

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($error)->toContain('Rejected\\x1B]0;owned\\x07\\x0Dline')
        ->toContain('\\x0Anext\\x0D\\x0Aend')
        ->toContain('Too\\x09large')
        ->not->toContain("\e")
        ->not->toContain("\x07");
});

it('redacts a reflected basic-auth token from raw api errors', function () {
    $token = base64_encode(':raw-api-secret');
    Http::fake(['https://api.quickpay.net/payments/42' => Http::response([
        'message' => 'Rejected '.$token,
    ], 401)]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'POST',
        'path' => '/payments/42',
        '--yes' => true,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(1)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain('Rejected [redacted]')
        ->not->toContain($token)
        ->not->toContain('raw-api-secret');
});

it('redacts a credential reflected in a successful raw response', function () {
    Http::fake(['https://api.quickpay.net/ping' => Http::response('{"echo":"raw-api-secret"}')]);
    $command = new ApiRequestCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $status = $tester->execute([
        'method' => 'GET',
        'path' => '/ping',
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('[redacted]')->not->toContain('raw-api-secret')
        ->and($tester->getErrorOutput())->not->toContain('raw-api-secret');
});
