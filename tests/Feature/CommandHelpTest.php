<?php

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
