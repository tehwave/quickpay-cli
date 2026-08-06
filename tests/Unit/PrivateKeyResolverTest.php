<?php

use App\Callbacks\PrivateKeyResolver;

afterEach(function () {
    putenv('QUICKPAY_PRIVATE_KEY');
});

it('prefers a non-empty environment private key without calling Quickpay', function () {
    putenv('QUICKPAY_PRIVATE_KEY=environment-private-key');
    $calls = 0;
    $resolver = new PrivateKeyResolver(function () use (&$calls): string {
        $calls++;

        return 'remote-private-key';
    });

    expect($resolver->resolve())->toBe('environment-private-key')
        ->and($calls)->toBe(0);
});

it('fetches the account private key once when the environment value is empty', function () {
    putenv('QUICKPAY_PRIVATE_KEY=');
    $calls = 0;
    $resolver = new PrivateKeyResolver(function () use (&$calls): string {
        $calls++;

        return 'remote-private-key';
    });

    expect($resolver->resolve())->toBe('remote-private-key')
        ->and($resolver->resolve())->toBe('remote-private-key')
        ->and($calls)->toBe(1);
});

it('rejects a missing or malformed private key without exposing its value', function (mixed $value) {
    putenv('QUICKPAY_PRIVATE_KEY');
    $resolver = new PrivateKeyResolver(fn (): mixed => $value);

    expect(fn () => $resolver->resolve())
        ->toThrow(UnexpectedValueException::class, 'private key');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => [" \n\t"],
    'array' => [['private_key' => 'secret']],
]);
