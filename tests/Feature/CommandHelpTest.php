<?php

use Illuminate\Support\Facades\Artisan;

it('explains payment mutation safety in command help', function () {
    $this->artisan('help', ['command_name' => 'payments:capture'])
        ->expectsOutputToContain('positive integer in minor units')
        ->expectsOutputToContain('Skip the interactive confirmation')
        ->expectsOutputToContain('machine-readable JSON')
        ->assertExitCode(0);
});

it('explains list pagination in command help', function () {
    $this->artisan('help', ['command_name' => 'payments:list'])
        ->expectsOutputToContain('Payments per page')
        ->expectsOutputToContain('Fetch every available page')
        ->expectsOutputToContain('Maximum pages fetched with --all')
        ->assertExitCode(0);
});

it('explains guarded raw api inputs in command help', function () {
    $this->artisan('help', ['command_name' => 'api'])
        ->expectsOutputToContain('GET, POST, PUT, PATCH, or DELETE')
        ->expectsOutputToContain('Relative Quickpay API path')
        ->expectsOutputToContain('JSON object request body')
        ->assertExitCode(0);
});

it('offers explicit merchant selection for every authenticated command', function (string $command): void {
    expect(Artisan::call('help', ['command_name' => $command]))->toBe(0);
    expect(Artisan::output())->toContain('--merchant')->toContain('Saved merchant to select for this command');
})->with(['api', 'callbacks:replay', 'callbacks:watch', 'merchants:active', 'payments:cancel', 'payments:capture', 'payments:create', 'payments:get', 'payments:link', 'payments:list', 'payments:refund']);
