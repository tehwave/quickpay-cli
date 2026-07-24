<?php

it('reports Quickpay as the application name', function () {
    $this->artisan('list')
        ->expectsOutputToContain('Quickpay')
        ->assertExitCode(0);
});
