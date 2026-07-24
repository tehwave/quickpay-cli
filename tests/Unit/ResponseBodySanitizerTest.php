<?php

use App\Support\ResponseBodySanitizer;

it('preserves the original raw json bytes when no semantic credential is present', function () {
    $raw = " {\n  \"url\": \"https:\\/\\/example.test\",\n  \"count\": 42\n}\n";

    expect(ResponseBodySanitizer::json($raw, 'different-secret'))->toBe($raw);
});

it('recursively redacts credentials in decoded object keys and scalar values and returns valid json', function () {
    $escaped = ResponseBodySanitizer::json('{"value":"a\\/b"}', 'a/b');
    $numericAndKey = ResponseBodySanitizer::json('{"key-42":"safe","number":42,"nested":["before-42-after"]}', '42');

    expect(json_validate($escaped))->toBeTrue()
        ->and(json_decode($escaped, true))->toBe(['value' => '[redacted]'])
        ->and(json_validate($numericAndKey))->toBeTrue()
        ->and(json_decode($numericAndKey, true))->toBe([
            'key-[redacted]' => 'safe',
            'number' => '[redacted]',
            'nested' => ['before-[redacted]-after'],
        ]);
});

it('never emits null or numeric credential lexemes from valid json', function (string $raw, string $apiKey, mixed $expected) {
    $safe = ResponseBodySanitizer::json($raw, $apiKey);

    expect(json_validate($safe))->toBeTrue()
        ->and($safe)->not->toContain($apiKey)
        ->and(json_decode($safe))->toBe($expected);
})->with([
    'null scalar' => ['null', 'null', '[redacted]'],
    'float scalar' => ['1.0', '1.0', '[redacted]'],
    'exponent raw lexeme' => ['1e3', '1e3', 1000.0],
]);

it('rejects invalid json instead of writing it in json mode', function () {
    expect(fn () => ResponseBodySanitizer::json('<html>not json</html>', 'secret'))
        ->toThrow(InvalidArgumentException::class, 'valid JSON');
});

it('preserves printable text and newlines while visibly encoding unsafe terminal controls', function () {
    $input = "hello\nworld\r\nsecret\t\e]0;owned\x07\rlone\x9d";

    expect(ResponseBodySanitizer::terminalText($input, 'secret'))
        ->toBe("hello\nworld\r\n[redacted]\\x09\\x1B]0;owned\\x07\\x0Dlone\\x9D");
});
