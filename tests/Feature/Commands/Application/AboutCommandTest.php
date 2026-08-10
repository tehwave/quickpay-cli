<?php

use Illuminate\Support\Facades\Artisan;

it('brands the human command list and shows the quickpay executable in its usage', function () {
    $status = Artisan::call('list', ['--no-ansi' => true]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('   ++++++++++++++++   +++++ ++++  ++++++++++  ++++  ++++++++++++++++++   +++++++++++++++++    +++++')
        ->toMatch('/Quickpay CLI  (?:unreleased|\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?)/')
        ->toContain('USAGE: quickpay <command> [options] [arguments]')
        ->toContain('about')
        ->toContain('Show project, repository, and author information');
});

it('shows the open source project and author credits', function () {
    $status = Artisan::call('about', ['--no-ansi' => true]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('   ++++++++++++++++   +++++ ++++  ++++++++++  ++++  ++++++++++++++++++   +++++++++++++++++    +++++')
        ->toMatch('/Quickpay CLI  (?:unreleased|\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?)/')
        ->toContain('An independent open-source command-line client for the Quickpay API.')
        ->toContain('Repository  https://github.com/tehwave/quickpay-cli')
        ->toContain('Author      Peter 🌊 Jørgensen')
        ->toContain('Website     https://peterchrjoergensen.dk')
        ->toContain('License     MIT')
        ->toContain('Not affiliated with or endorsed by Quickpay.');
});
