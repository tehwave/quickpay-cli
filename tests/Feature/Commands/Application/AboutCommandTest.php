<?php

use Illuminate\Support\Facades\Artisan;

it('brands the human command list and shows the quickpay executable in its usage', function () {
    $status = Artisan::call('list', ['--no-ansi' => true]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('   ++++++++++++++++   +++++ ++++  ++++++++++  ++++  ++++++++++++++++++   +++++++++++++++++    +++++')
        ->toContain('Quickpay CLI  unreleased')
        ->toContain('USAGE: quickpay <command> [options] [arguments]')
        ->toContain('about')
        ->toContain('Show project, repository, and author information');
});

it('shows the open source project and author credits', function () {
    $this->artisan('about', ['--no-ansi' => true])
        ->expectsOutputToContain('   ++++++++++++++++   +++++ ++++  ++++++++++  ++++  ++++++++++++++++++   +++++++++++++++++    +++++')
        ->expectsOutputToContain('Quickpay CLI  unreleased')
        ->expectsOutputToContain('An independent open-source command-line client for the Quickpay API.')
        ->expectsOutputToContain('Repository  https://github.com/tehwave/quickpay-cli')
        ->expectsOutputToContain('Author      Peter 🌊 Jørgensen')
        ->expectsOutputToContain('Website     https://peterchrjoergensen.dk')
        ->expectsOutputToContain('License     MIT')
        ->expectsOutputToContain('Not affiliated with or endorsed by Quickpay.')
        ->assertExitCode(0);
});
