<?php

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Messages\InteractiveMessage;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeClient(?TemplateRegistry $registry = null): CloudApiClient
{
    return new CloudApiClient(
        graphVersion: 'v21.0',
        phoneNumberId: '111222333',
        accessToken: 'the-token',
        templates: $registry ?? new TemplateRegistry([
            'assignment' => ['name' => 'coordena_assignment', 'language' => 'pt_BR', 'params' => ['name', 'event', 'url']],
        ]),
    );
}

it('sends an approved template with ordered body params', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]]),
    ]);

    $result = makeClient()->sendTemplate('5548999999999', TemplateMessage::make('assignment', [
        'name' => 'Maria',
        'event' => 'Congresso',
        'url' => 'https://coordena.app/x',
    ]));

    expect($result->provider)->toBe('meta_cloud')
        ->and($result->messageId)->toBe('wamid.ABC');

    Http::assertSent(function (Request $request) {
        expect($request->url())->toBe('https://graph.facebook.com/v21.0/111222333/messages')
            ->and($request->hasHeader('Authorization', 'Bearer the-token'))->toBeTrue();

        $body = $request->data();

        expect($body['messaging_product'])->toBe('whatsapp')
            ->and($body['to'])->toBe('5548999999999')
            ->and($body['type'])->toBe('template')
            ->and($body['template']['name'])->toBe('coordena_assignment')
            ->and($body['template']['language'])->toBe(['code' => 'pt_BR'])
            ->and($body['template']['components'][0]['parameters'])->toBe([
                ['type' => 'text', 'text' => 'Maria'],
                ['type' => 'text', 'text' => 'Congresso'],
                ['type' => 'text', 'text' => 'https://coordena.app/x'],
            ]);

        return true;
    });
});

it('omits components when the template has no params', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NP']]])]);

    $registry = new TemplateRegistry;
    $registry->register('ping', new MetaTemplate('coordena_ping', 'pt_BR', 'utility', []));

    makeClient($registry)->sendTemplate('5548000000000', TemplateMessage::make('ping'));

    Http::assertSent(fn (Request $request) => ! isset($request->data()['template']['components']));
});

it('sends free session text', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.T']]])]);

    makeClient()->sendSessionText('5548111111111', 'Olá!');

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $body['type'] === 'text'
            && $body['text'] === ['preview_url' => false, 'body' => 'Olá!'];
    });
});

it('sends an interactive list capping row titles at 24 chars', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.I']]])]);

    makeClient()->sendInteractive('5548222222222', InteractiveMessage::multiChoice('Quando você pode?', [
        'Sexta-feira à noite depois das 19h',
        'Sábado de manhã',
    ]));

    Http::assertSent(function (Request $request) {
        $body = $request->data();
        $rows = $body['interactive']['action']['sections'][0]['rows'];

        return $body['type'] === 'interactive'
            && $body['interactive']['type'] === 'list'
            && $rows[0]['id'] === 'opt_0'
            && mb_strlen($rows[0]['title']) === 24
            && $rows[1]['title'] === 'Sábado de manhã';
    });
});

it('throws a terminal CloudApiException on a closed session window (131047)', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Re-engagement message', 'code' => 131047],
        ], 400),
    ]);

    expect(fn () => makeClient()->sendSessionText('5548333333333', 'oi'))
        ->toThrow(CloudApiException::class);

    try {
        makeClient()->sendSessionText('5548333333333', 'oi');
    } catch (CloudApiException $e) {
        expect($e->errorCode)->toBe(131047)
            ->and($e->isTemporaryRestriction())->toBeTrue();
    }
});

it('keeps unknown error codes retryable (not terminal)', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Rate limited', 'code' => 80007],
        ], 400),
    ]);

    try {
        makeClient()->sendSessionText('5548444444444', 'oi');
        expect(false)->toBeTrue('expected CloudApiException');
    } catch (CloudApiException $e) {
        expect($e->errorCode)->toBe(80007)
            ->and($e->isTemporaryRestriction())->toBeFalse();
    }
});

it('resolving an unregistered template key throws', function () {
    expect(fn () => (new TemplateRegistry)->resolve('nope'))
        ->toThrow(CloudApiException::class);
});
