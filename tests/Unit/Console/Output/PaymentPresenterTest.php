<?php

use App\Console\Output\PaymentPresenter;

it('builds stable payment detail rows with safe scalar rendering', function (): void {
    $rows = (new PaymentPresenter)->details([
        'id' => 42,
        'order_id' => 'secret-order',
        'accepted' => true,
    ], 'secret');

    expect($rows[0])->toBe(['ID', '42'])
        ->and($rows[1])->toBe(['Order ID', '[redacted]-order'])
        ->and($rows[4])->toBe(['Accepted', 'yes'])
        ->and($rows[5])->toBe(['Balance (minor units)', '-']);
});

it('builds list rows and operation rows without terminal control bytes', function (): void {
    $presenter = new PaymentPresenter;

    expect($presenter->payments([['id' => "4\e[31m2"]], 'api-key')[0][0])->toBe('4\\x1B[31m2')
        ->and($presenter->operations([['id' => 1, 'qp_status_code' => '200', 'qp_status_msg' => 'OK']], 'api-key')[0][4])
        ->toBe('200 OK');
});
