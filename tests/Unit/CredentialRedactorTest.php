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
