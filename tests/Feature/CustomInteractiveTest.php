<?php

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeCustomClient(): CloudApiClient
{
    return new CloudApiClient(graphVersion: 'v21.0', phoneNumberId: '111222333', accessToken: 'the-token', templates: new TemplateRegistry([]));
}

it('sends reply buttons with the app ids under an image header', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.BTN']]])]);

    $result = makeCustomClient()->sendButtons('5548222222222', 'Página 1', [
        'aprovar:01ABC' => 'Aprovar',
        'ajustar:01ABC' => 'Ajustar',
    ], 'https://cdn.test/p1.png', 'gomark');

    expect($result->messageId)->toBe('wamid.BTN');
    Http::assertSent(function (Request $request) {
        $i = $request->data()['interactive'];

        return $request->data()['type'] === 'interactive'
            && $i['type'] === 'button'
            && $i['header'] === ['type' => 'image', 'image' => ['link' => 'https://cdn.test/p1.png']]
            && $i['footer'] === ['text' => 'gomark']
            && $i['action']['buttons'][0]['reply'] === ['id' => 'aprovar:01ABC', 'title' => 'Aprovar']
            && $i['action']['buttons'][1]['reply']['id'] === 'ajustar:01ABC';
    });
});

it('omits the header and footer when not given and caps titles at 20 chars', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.B2']]])]);

    makeCustomClient()->sendButtons('5548222222222', 'Escolha', ['x:1' => str_repeat('a', 30)]);

    Http::assertSent(function (Request $request) {
        $i = $request->data()['interactive'];

        return ! isset($i['header']) && ! isset($i['footer'])
            && mb_strlen($i['action']['buttons'][0]['reply']['title']) === 20;
    });
});

it('refuses more than 3 reply buttons', function () {
    makeCustomClient()->sendButtons('5548222222222', 'Escolha', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);
})->throws(InvalidArgumentException::class);

it('sends a list with the app row ids and descriptions', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.LST']]])]);

    makeCustomClient()->sendList('5548222222222', 'De qual campanha?', 'Escolher campanha', [
        'campanha:1' => ['Pet Bazar 27/10', 'Início 27/10 · 10 produtos'],
        'campanha:2' => 'Feirão',
    ], 'Campanhas');

    Http::assertSent(function (Request $request) {
        $i = $request->data()['interactive'];
        $rows = $i['action']['sections'][0]['rows'];

        return $i['type'] === 'list'
            && $i['action']['button'] === 'Escolher campanha'
            && $i['action']['sections'][0]['title'] === 'Campanhas'
            && $rows[0] === ['id' => 'campanha:1', 'title' => 'Pet Bazar 27/10', 'description' => 'Início 27/10 · 10 produtos']
            && $rows[1] === ['id' => 'campanha:2', 'title' => 'Feirão'];
    });
});

it('refuses an empty list or more than 10 rows', function (array $rows) {
    makeCustomClient()->sendList('5548222222222', 'Escolha', 'Ver', $rows);
})->with([
    'empty' => [[]],
    'eleven' => [array_combine(array_map(fn ($n) => "r{$n}", range(1, 11)), array_map(fn ($n) => "Linha {$n}", range(1, 11)))],
])->throws(InvalidArgumentException::class);

it('sends an image by link with a caption', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.IMG']]])]);

    makeCustomClient()->sendImage('5548222222222', 'https://cdn.test/a.png', 'Arte nova');

    Http::assertSent(fn (Request $request) => $request->data()['type'] === 'image'
        && $request->data()['image'] === ['link' => 'https://cdn.test/a.png', 'caption' => 'Arte nova']);
});
