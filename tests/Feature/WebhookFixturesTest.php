<?php

use Callcocam\WhatsAppCloud\Sandbox\FaultCatalog;
use Callcocam\WhatsAppCloud\Sandbox\InboundPayloadFactory;
use Callcocam\WhatsAppCloud\WhatsAppManager;

/**
 * Golden files for every inbound shape the sandbox produces, under
 * tests/fixtures/webhooks/.
 *
 * Be clear about what this proves and what it does not. These are snapshots of
 * OUR OWN output — they catch accidental drift in the factory, not divergence
 * from Meta. Only a payload captured from a real Meta webhook can prove that.
 *
 * So: when you capture a real one, diff it against the fixture of the same name.
 * That diff is the only thing that keeps this sandbox honest.
 *
 * Run with FIXTURES=rewrite to regenerate after an intentional shape change.
 */
function fixturePath(string $name): string
{
    return __DIR__.'/../fixtures/webhooks/'.$name.'.json';
}

/**
 * Blank out the fields that legitimately change every run, so the diff shows
 * shape, not noise.
 */
function stable(array $payload): array
{
    array_walk_recursive($payload, function (&$value, $key) {
        $value = match (true) {
            $key === 'timestamp' => '<timestamp>',
            $key === 'id' && is_string($value) && str_starts_with($value, 'wamid.SANDBOX.') => '<wamid>',
            $key === 'id' && is_string($value) && str_starts_with($value, 'media.SANDBOX.') => '<media-id>',
            $key === 'id' && is_string($value) && str_starts_with($value, 'conv.SANDBOX.') => '<conversation-id>',
            $key === 'sha256' => '<sha256>',
            default => $value,
        };
    });

    return $payload;
}

function assertFixture(string $name, array $payload): void
{
    $actual = json_encode(stable($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

    if (getenv('FIXTURES') === 'rewrite' || ! is_file(fixturePath($name))) {
        file_put_contents(fixturePath($name), $actual);
    }

    expect($actual)->toBe(file_get_contents(fixturePath($name)));
}

beforeEach(function () {
    $this->factory = InboundPayloadFactory::for(
        app(WhatsAppManager::class)->credentials(),
        waId: '5548999999999',
        profileName: 'Maria',
    );
});

it('matches the text fixture', function () {
    assertFixture('text', $this->factory->text('Olá! Confirmo minha presença.'));
});

it('matches the text-reply fixture', function () {
    assertFixture('text-reply', $this->factory->text('Pode ser sábado', replyTo: 'wamid.ORIGINAL'));
});

it('matches the template-button fixture', function () {
    assertFixture('template-button', $this->factory->templateButton('Aceitar', replyTo: 'wamid.ORIGINAL'));
});

it('matches the interactive-button-reply fixture', function () {
    assertFixture('interactive-button-reply', $this->factory->buttonReply('btn_yes', 'Sim', replyTo: 'wamid.ORIGINAL'));
});

it('matches the interactive-list-reply fixture', function () {
    assertFixture('interactive-list-reply', $this->factory->listReply(
        'opt_0', 'Sábado de manhã', 'Sábado de manhã', replyTo: 'wamid.ORIGINAL',
    ));
});

it('matches the image fixture', function () {
    assertFixture('image', $this->factory->image('Comprovante', replyTo: 'wamid.ORIGINAL'));
});

it('matches the status-delivered fixture', function () {
    assertFixture('status-delivered', $this->factory->status('wamid.SENT', 'delivered'));
});

it('matches the status-failed fixture', function () {
    assertFixture('status-failed', $this->factory->status(
        'wamid.SENT', 'failed', FaultCatalog::find('window_closed'),
    ));
});
