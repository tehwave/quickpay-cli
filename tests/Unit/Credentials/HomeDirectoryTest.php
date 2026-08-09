<?php

use App\Credentials\EnvironmentVariables;
use App\Credentials\HomeDirectory;

it('uses an explicit home directory', function (): void {
    expect((new HomeDirectory(new EnvironmentVariables, '/tmp/quickpay-home'))->path())
        ->toBe('/tmp/quickpay-home');
});

it('falls back from HOME to USERPROFILE', function (): void {
    $originalHome = getenv('HOME');
    $originalProfile = getenv('USERPROFILE');

    try {
        putenv('HOME=');
        putenv('USERPROFILE=/tmp/quickpay-profile');

        expect((new HomeDirectory(new EnvironmentVariables))->path())
            ->toBe('/tmp/quickpay-profile');
    } finally {
        $originalHome === false ? putenv('HOME') : putenv('HOME='.$originalHome);
        $originalProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE='.$originalProfile);
    }
});
