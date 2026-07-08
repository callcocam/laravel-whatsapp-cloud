<?php

it('runs the install command and prints the checklist', function () {
    $this->artisan('whatsapp:install')
        ->assertExitCode(0)
        ->expectsOutputToContain('WhatsApp Cloud installed');
});
