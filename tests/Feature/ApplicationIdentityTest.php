<?php

use Illuminate\Support\Facades\Artisan;

it('reports Quickpay as the application name', function () {
    $this->artisan('list')
        ->expectsOutputToContain('Quickpay')
        ->assertExitCode(0);
});

it('presents only product commands in the public command list', function () {
    expect(Artisan::call('list', ['--raw' => true]))->toBe(0);

    $commands = array_map(
        fn (string $line): string => explode(' ', $line, 2)[0],
        array_values(array_filter(explode(PHP_EOL, trim(Artisan::output())))),
    );

    expect($commands)->toBe([
        'api',
        'auth',
        'login',
        'logout',
        'callbacks:replay',
        'callbacks:watch',
        'payments:cancel',
        'payments:capture',
        'payments:create',
        'payments:get',
        'payments:link',
        'payments:list',
        'payments:refund',
    ]);
});
