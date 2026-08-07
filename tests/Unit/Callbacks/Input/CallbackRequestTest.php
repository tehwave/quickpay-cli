<?php

use App\Callbacks\Input\CallbackRequest;

it('parses exactly one payment selector and an explicit target', function (): void {
    $request = CallbackRequest::from('42', null, 'http://localhost:8000/callback');

    expect($request->paymentId)->toBe('42')
        ->and($request->orderId)->toBeNull()
        ->and($request->target->url)->toBe('http://localhost:8000/callback');
});

it('rejects missing or conflicting selectors', function (mixed $payment, mixed $order, string $message): void {
    expect(fn (): CallbackRequest => CallbackRequest::from($payment, $order, 'http://localhost/callback'))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    [null, null, 'Provide a payment ID or --order-id.'],
    ['42', 'order-42', 'Provide exactly one selector'],
]);
