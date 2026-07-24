<?php

use App\Credentials\CredentialRedactor;

it('keeps the readable placeholder when it cannot expose a normal credential', function () {
    $apiKey = 'ordinary-secret';
    $token = base64_encode(':'.$apiKey);

    expect(CredentialRedactor::redact("raw={$apiKey}; basic={$token}", $apiKey))
        ->toBe('raw=[redacted]; basic=[redacted]');
});

it('uses a collision-safe replacement for every sensitive credential representation', function (string $apiKey) {
    $token = base64_encode(':'.$apiKey);
    $safe = CredentialRedactor::redact("prefix {$apiKey} {$token} suffix", $apiKey);

    expect(CredentialRedactor::containsSensitiveValue("{$apiKey} {$token}", $apiKey))->toBeTrue()
        ->and(CredentialRedactor::containsSensitiveValue($safe, $apiKey))->toBeFalse()
        ->and($safe)->not->toContain($apiKey)->not->toContain($token);
})->with([
    'primary placeholder' => '[redacted]',
    'secondary placeholder' => '[credential removed]',
    'one-character key' => 'e',
]);

it('never treats an empty credential as sensitive', function () {
    expect(CredentialRedactor::containsSensitiveValue('anything', ''))->toBeFalse()
        ->and(CredentialRedactor::redact('anything', ''))->toBe('anything');
});

it('falls back to an empty replacement when every readable placeholder collides', function () {
    expect(CredentialRedactor::redact('e', 'e'))->toBe('');
});

it('removes a sensitive value recreated across an overlapping match boundary', function () {
    $safe = CredentialRedactor::redact('rree', 're');

    expect(CredentialRedactor::containsSensitiveValue($safe, 're'))->toBeFalse()
        ->and($safe)->not->toContain('re');
});

it('removes a sensitive value recreated beside a readable replacement', function () {
    $safe = CredentialRedactor::redact('aa[', 'a[');

    expect(CredentialRedactor::containsSensitiveValue($safe, 'a['))->toBeFalse()
        ->and($safe)->not->toContain('a[');
});

it('redacts percent and form encoded credential representations', function () {
    $apiKey = 'secret /?&+=%';
    $rawEncoded = rawurlencode($apiKey);
    $formEncoded = urlencode($apiKey);
    $lowercaseEscapes = preg_replace_callback(
        '/%[0-9A-F]{2}/',
        fn (array $match): string => strtolower($match[0]),
        $rawEncoded,
    );

    $safe = CredentialRedactor::redact(
        "raw={$rawEncoded}; form={$formEncoded}; lowercase={$lowercaseEscapes}",
        $apiKey,
    );

    expect(CredentialRedactor::containsSensitiveValue($rawEncoded, $apiKey))->toBeTrue()
        ->and(CredentialRedactor::containsSensitiveValue($formEncoded, $apiKey))->toBeTrue()
        ->and(CredentialRedactor::containsSensitiveValue($lowercaseEscapes, $apiKey))->toBeTrue()
        ->and(CredentialRedactor::containsSensitiveValue($safe, $apiKey))->toBeFalse()
        ->and($safe)->not->toContain($rawEncoded)
        ->not->toContain($formEncoded)
        ->not->toContain($lowercaseEscapes);
});

it('redacts a credential whose every byte is percent encoded', function () {
    $apiKey = 'ordinary-secret';
    $encoded = implode('', array_map(
        fn (string $byte): string => sprintf('%%%02X', ord($byte)),
        str_split($apiKey),
    ));
    $lowercase = strtolower($encoded);
    $safe = CredentialRedactor::redact("upper={$encoded}; lower={$lowercase}", $apiKey);

    expect(CredentialRedactor::containsSensitiveValue($encoded, $apiKey))->toBeTrue()
        ->and(CredentialRedactor::containsSensitiveValue($lowercase, $apiKey))->toBeTrue()
        ->and($safe)->not->toContain($encoded)->not->toContain($lowercase);
});
