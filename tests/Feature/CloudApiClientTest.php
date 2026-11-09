<?php

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\MediaDownloadException;
use Callcocam\WhatsAppCloud\Messages\InboundMedia;
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

it('sends a row description only when the label does not fit the title', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.I']]])]);

    $short = 'Registrar donativos'; // 19 chars: fits the title
    $medium = 'Registrar donativos da semana'; // 29 chars: 25..72
    $long = str_repeat('Doação recebida no culto de domingo ', 3); // > 72 chars

    makeClient()->sendInteractive('5548222222222', InteractiveMessage::multiChoice('O que deseja fazer?', [
        $short, 'Confirmar', $medium, $long, str_repeat('x', 24), str_repeat('y', 25),
    ]));

    Http::assertSent(function (Request $request) use ($short, $medium, $long) {
        $rows = $request->data()['interactive']['action']['sections'][0]['rows'];

        // Short labels: title only — a description equal to the title is what
        // WhatsApp printed twice ("Confirmar / Confirmar").
        expect($rows[0])->toBe(['id' => 'opt_0', 'title' => $short])
            ->and($rows[1])->toBe(['id' => 'opt_1', 'title' => 'Confirmar'])
            // 25..72: title cut at 24, full label in the description.
            ->and($rows[2])->toBe(['id' => 'opt_2', 'title' => mb_substr($medium, 0, 24), 'description' => $medium])
            // > 72: description cut at 72.
            ->and($rows[3]['title'])->toBe(mb_substr($long, 0, 24))
            ->and($rows[3]['description'])->toBe(mb_substr($long, 0, 72))
            ->and(mb_strlen($rows[3]['description']))->toBe(72)
            // The boundary: exactly 24 fits, 25 does not.
            ->and($rows[4])->not->toHaveKey('description')
            ->and($rows[5]['description'])->toBe(str_repeat('y', 25))
            ->and(array_column($rows, 'id'))->toBe(['opt_0', 'opt_1', 'opt_2', 'opt_3', 'opt_4', 'opt_5']);

        return true;
    });
});

it('sends up to 3 short options as reply buttons with the same opt ids', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.B']]])]);

    makeClient()->sendInteractive('5548222222222', InteractiveMessage::multiChoice('Confirma?', ['Sim', 'Não']));

    Http::assertSent(function (Request $request) {
        $interactive = $request->data()['interactive'];

        expect($interactive['type'])->toBe('button')
            ->and($interactive['body'])->toBe(['text' => 'Confirma?'])
            ->and($interactive['action']['buttons'])->toBe([
                ['type' => 'reply', 'reply' => ['id' => 'opt_0', 'title' => 'Sim']],
                ['type' => 'reply', 'reply' => ['id' => 'opt_1', 'title' => 'Não']],
            ]);

        return true;
    });
});

it('falls back to a list for more than 3 options, a label over 20 chars or repeated labels', function (array $options) {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.L']]])]);

    makeClient()->sendInteractive('5548222222222', InteractiveMessage::multiChoice('Escolha', $options));

    Http::assertSent(fn (Request $request) => $request->data()['interactive']['type'] === 'list');
})->with([
    'four options' => [['A', 'B', 'C', 'D']],
    'a long label' => [['Sim', str_repeat('x', 21)]],
    'repeated' => [['Sim', 'Sim']],
]);

it('takes a label of exactly 20 chars as a button', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.E']]])]);

    makeClient()->sendInteractive('5548222222222', InteractiveMessage::multiChoice('Escolha', [str_repeat('x', 20), 'Outro']));

    Http::assertSent(fn (Request $request) => $request->data()['interactive']['type'] === 'button');
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
            ->and($e->isTerminal())->toBeTrue()
            // The deprecated alias must keep answering the same thing.
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
            ->and($e->isTerminal())->toBeFalse()
            ->and($e->isTemporaryRestriction())->toBeFalse();
    }
});

it('resolving an unregistered template key throws', function () {
    expect(fn () => (new TemplateRegistry)->resolve('nope'))
        ->toThrow(CloudApiException::class);
});

it('sends a template with a document in its header before the body params', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.DOC']]])]);

    makeClient()->sendTemplate('5548999999999', TemplateMessage::make('assignment', [
        'name' => 'Maria', 'event' => 'Congresso', 'url' => 'https://coordena.app/x',
    ])->withDocument('media-123', 'recibo.pdf'));

    Http::assertSent(function (Request $request) {
        $components = $request->data()['template']['components'];

        expect($components[0])->toBe([
            'type' => 'header',
            'parameters' => [['type' => 'document', 'document' => ['id' => 'media-123', 'filename' => 'recibo.pdf']]],
        ])->and($components[1]['type'])->toBe('body')
            ->and($components[1]['parameters'][0]['text'])->toBe('Maria');

        return true;
    });
});

it('uploads a file to the number media endpoint and returns its id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'media-987'])]);

    $id = makeClient()->uploadMedia('%PDF-1.4 fake', 'application/pdf', 'recibo.pdf');

    expect($id)->toBe('media-987');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v21.0/111222333/media'
        && $request->hasHeader('Authorization', 'Bearer the-token')
        && $request->isMultipart());
});

it('fails the upload with Meta error code', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad file', 'code' => 131053]], 400)]);

    expect(fn () => makeClient()->uploadMedia('x', 'application/pdf', 'r.pdf'))
        ->toThrow(CloudApiException::class);
});

it('downloads a received file through the temporary url, with the token', function () {
    Http::fake([
        'graph.facebook.com/v21.0/media-555*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/file-555', 'mime_type' => 'image/jpeg', 'file_size' => 9, 'id' => 'media-555']),
        'lookaside.fbsbx.com/*' => Http::response('jpeg-data'),
    ]);

    $media = makeClient()->downloadMedia('media-555', 1024, ['image/jpeg', 'application/pdf']);

    expect($media->contents)->toBe('jpeg-data')
        ->and($media->mimeType)->toBe('image/jpeg')
        ->and($media->size)->toBe(9)
        ->and($media->sha256)->toBe(hash('sha256', 'jpeg-data'));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/media-555?phone_number_id=111222333')
        && $request->hasHeader('Authorization', 'Bearer the-token'));
    Http::assertSent(fn (Request $request) => $request->url() === 'https://lookaside.fbsbx.com/whatsapp/file-555'
        && $request->hasHeader('Authorization', 'Bearer the-token'));
});

it('refuses a received file of a type not allowed without downloading it', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'audio/ogg', 'file_size' => 10])]);

    expect(fn () => makeClient()->downloadMedia('media-1', 1024, ['image/jpeg']))
        ->toThrow(fn (MediaDownloadException $e) => expect($e->reason)->toBe(MediaDownloadException::TYPE_NOT_ALLOWED)->and($e->isTerminal())->toBeTrue());

    Http::assertSentCount(1);
});

it('refuses a received file larger than allowed, by the size Meta announces and by what arrives', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'image/jpeg', 'file_size' => 2048])]);

    expect(fn () => makeClient()->downloadMedia('media-1', 1024))
        ->toThrow(fn (MediaDownloadException $e) => expect($e->reason)->toBe(MediaDownloadException::TOO_LARGE));

    Http::fake([
        'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/y', 'mime_type' => 'image/jpeg']),
        'lookaside.fbsbx.com/*' => Http::response(str_repeat('a', 2048)),
    ]);

    expect(fn () => makeClient()->downloadMedia('media-2', 1024))
        ->toThrow(MediaDownloadException::class);
});

it('fails the download with Meta error code', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Media not found', 'code' => 100]], 404)]);

    expect(fn () => makeClient()->downloadMedia('media-gone'))->toThrow(CloudApiException::class);
});

it('reads the media part of an inbound image or document, and nothing else', function () {
    $document = InboundMedia::fromMessage(['type' => 'document', 'document' => [
        'id' => 'm-1', 'mime_type' => 'application/pdf', 'filename' => 'comprovante.pdf', 'caption' => 'depósito', 'sha256' => 'abc',
    ]]);

    expect($document->type)->toBe('document')
        ->and($document->id)->toBe('m-1')
        ->and($document->mimeType)->toBe('application/pdf')
        ->and($document->filename)->toBe('comprovante.pdf')
        ->and($document->caption)->toBe('depósito')
        ->and(InboundMedia::fromMessage(['type' => 'image', 'image' => ['id' => 'm-2', 'mime_type' => 'image/jpeg']])->type)->toBe('image')
        ->and(InboundMedia::fromMessage(['type' => 'audio', 'audio' => ['id' => 'm-3']]))->toBeNull()
        ->and(InboundMedia::fromMessage(['type' => 'text', 'text' => ['body' => 'oi']]))->toBeNull();
});
