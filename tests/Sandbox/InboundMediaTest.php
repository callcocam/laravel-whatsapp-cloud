<?php

use Callcocam\WhatsAppCloud\Contracts\AudioTranscriber;
use Callcocam\WhatsAppCloud\Contracts\InboundAudioHandler;
use Callcocam\WhatsAppCloud\Contracts\InboundFileHandler;
use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Listeners\HandleInboundMedia;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;
use Callcocam\WhatsAppCloud\Messages\ReceivedMedia;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxMessage;
use Callcocam\WhatsAppCloud\Sandbox\Sandbox;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->sandbox = app(Sandbox::class);
    $this->conversation = $this->sandbox->participant('5548999999999', 'Maria');
});

function lastAnswer(): ?string
{
    return SandboxMessage::query()
        ->where('direction', SandboxMessage::OUTBOUND)
        ->latest('id')
        ->value('rendered_text');
}

it('is off by default: a received file gets no answer and nothing is queued', function () {
    Queue::fake();

    $this->sandbox->sendMedia($this->conversation, '%PDF-1.4', 'application/pdf', 'boleto.pdf');
    $this->sandbox->sendMedia($this->conversation, 'ogg', 'audio/ogg; codecs=opus', 'voz.ogg', voice: true);

    Queue::assertNothingPushed();
    expect(lastAnswer())->toBeNull();
});

it('is listening, so the switch works at runtime without a reboot', function () {
    Event::fake();

    Event::assertListening(
        WhatsAppMessageReceived::class,
        HandleInboundMedia::class,
    );
});

it('answers a document with its name, kind and size, and nudges the developer', function () {
    config(['whatsapp-cloud.media.handle.document' => true]);

    $this->sandbox->sendMedia($this->conversation, str_repeat('x', 2048), 'application/pdf', 'boleto.pdf', caption: 'Conta de luz');

    expect(lastAnswer())
        ->toContain('Recebi seu documento 📄 *boleto.pdf* (PDF, 2 KB).')
        ->toContain('Legenda: _Conta de luz_')
        ->toContain('implemente InboundFileHandler');
});

it('answers a photo', function () {
    config(['whatsapp-cloud.media.handle.image' => true]);

    $this->sandbox->sendMedia($this->conversation, 'png-bytes', 'image/png', 'foto.png');

    expect(lastAnswer())->toStartWith('Recebi sua imagem 🖼️ (9 B).');
});

it('leaves a kind that is off alone, even with another one on', function () {
    config(['whatsapp-cloud.media.handle.document' => true]);

    $this->sandbox->sendMedia($this->conversation, 'png-bytes', 'image/png', 'foto.png');

    expect(lastAnswer())->toBeNull();
});

it('answers an audio that arrived, without a transcriber', function () {
    config(['whatsapp-cloud.media.handle.audio' => true]);

    $this->sandbox->sendMedia($this->conversation, 'ogg', 'audio/ogg; codecs=opus', 'voz.ogg', voice: true);

    expect(lastAnswer())
        ->toStartWith('Recebi seu áudio 🎤')
        ->not->toContain('Você disse')
        ->toContain('implemente InboundAudioHandler');
});

it('says what the audio said when a transcriber is configured', function () {
    config([
        'whatsapp-cloud.media.handle.audio' => true,
        'whatsapp-cloud.media.transcriber' => 'openai',
        'whatsapp-cloud.media.openai.api_key' => 'sk-test',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['text' => 'Gastei 50 reais no mercado'])]);

    $this->sandbox->sendMedia($this->conversation, 'ogg-bytes', 'audio/ogg; codecs=opus', 'voz.ogg', voice: true);

    expect(lastAnswer())->toContain('Você disse: "Gastei 50 reais no mercado"');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/audio/transcriptions'
        && $request->hasHeader('Authorization', 'Bearer sk-test'));
});

it('transcribes with Cloudflare Workers AI', function () {
    config([
        'whatsapp-cloud.media.handle.audio' => true,
        'whatsapp-cloud.media.transcriber' => 'cloudflare',
        'whatsapp-cloud.media.cloudflare.account_id' => 'acc-1',
        'whatsapp-cloud.media.cloudflare.token' => 'cf-test',
    ]);

    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => true,
        'result' => ['text' => ' Paguei a conta de luz '],
    ])]);

    $this->sandbox->sendMedia($this->conversation, 'ogg-bytes', 'audio/ogg; codecs=opus', 'voz.ogg', voice: true);

    expect(lastAnswer())->toContain('Você disse: "Paguei a conta de luz"');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acc-1/ai/run/@cf/openai/whisper-large-v3-turbo'
        && $request->hasHeader('Authorization', 'Bearer cf-test')
        && $request['audio'] === base64_encode('ogg-bytes')
        && $request['language'] === 'pt');
});

it('still answers when the Cloudflare transcription fails', function () {
    config([
        'whatsapp-cloud.media.handle.audio' => true,
        'whatsapp-cloud.media.transcriber' => 'cloudflare',
        'whatsapp-cloud.media.cloudflare.account_id' => 'acc-1',
        'whatsapp-cloud.media.cloudflare.token' => 'bad',
    ]);

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'Authentication error']]], 401)]);

    $this->sandbox->sendMedia($this->conversation, 'ogg-bytes', 'audio/ogg', 'voz.ogg', voice: true);

    expect(lastAnswer())->toStartWith('Recebi seu áudio 🎤')->not->toContain('Você disse');
});

it('still answers when the transcription fails', function () {
    config([
        'whatsapp-cloud.media.handle.audio' => true,
        'whatsapp-cloud.media.transcriber' => 'openai',
        'whatsapp-cloud.media.openai.api_key' => 'sk-test',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);

    $this->sandbox->sendMedia($this->conversation, 'ogg-bytes', 'audio/ogg', 'voz.ogg', voice: true);

    expect(lastAnswer())->toStartWith('Recebi seu áudio 🎤')->not->toContain('Você disse');
});

it('explains a file over the limit instead of failing', function () {
    config([
        'whatsapp-cloud.media.handle.document' => true,
        'whatsapp-cloud.media.max_download_bytes' => 1024,
    ]);

    $this->sandbox->sendMedia($this->conversation, str_repeat('x', 4096), 'application/pdf', 'grande.pdf');

    expect(lastAnswer())->toBe('Recebi seu arquivo, mas ele passa do limite de 1 KB e não consegui abrir.');
});

it('drops the developer hint when told to', function () {
    config(['whatsapp-cloud.media.handle.image' => true, 'whatsapp-cloud.media.hint' => false]);

    $this->sandbox->sendMedia($this->conversation, 'png-bytes', 'image/png', 'foto.png');

    expect(lastAnswer())->toBe('Recebi sua imagem 🖼️ (9 B).');
});

it('hands the media to the app\'s own handlers instead of the defaults', function () {
    config(['whatsapp-cloud.media.handle' => ['image' => true, 'document' => true, 'audio' => true]]);

    $seen = new ArrayObject;

    app()->bind(InboundFileHandler::class, fn () => new class($seen) implements InboundFileHandler
    {
        public function __construct(private ArrayObject $seen) {}

        public function handle(ReceivedMedia $file): void
        {
            $this->seen[] = [$file->media->type, $file->media->filename, $file->download()->contents, $file->contactName];
        }
    });

    app()->bind(InboundAudioHandler::class, fn () => new class($seen) implements InboundAudioHandler
    {
        public function __construct(private ArrayObject $seen) {}

        public function handle(ReceivedMedia $audio): void
        {
            $this->seen[] = [$audio->media->type, $audio->media->isVoiceNote(), $audio->download()->contents, $audio->from];
        }
    });

    $this->sandbox->sendMedia($this->conversation, '%PDF', 'application/pdf', 'boleto.pdf');
    $this->sandbox->sendMedia($this->conversation, 'ogg', 'audio/ogg', 'voz.ogg', voice: true);

    expect($seen->getArrayCopy())->toBe([
        ['document', 'boleto.pdf', '%PDF', 'Maria'],
        ['audio', true, 'ogg', '5548999999999'],
    ])->and(lastAnswer())->toBeNull();
});

it('lets an app bind its own transcriber', function () {
    config(['whatsapp-cloud.media.handle.audio' => true, 'whatsapp-cloud.media.hint' => false]);

    app()->bind(AudioTranscriber::class, fn () => new class implements AudioTranscriber
    {
        public function transcribe(DownloadedMedia $audio): ?string
        {
            return 'transcrito: '.$audio->contents;
        }
    });

    $this->sandbox->sendMedia($this->conversation, 'oi', 'audio/mpeg', 'musica.mp3');

    expect(lastAnswer())->toBe("Recebi seu áudio 🎤\n\nVocê disse: \"transcrito: oi\"");
});

it('sends a recorded voice note from the screen as audio, even when sniffed as video', function () {
    $file = UploadedFile::fake()->createWithContent('voz.webm', (string) file_get_contents(__DIR__.'/../../resources/sandbox/sample.ogg'));

    $this->actingAs(new GenericUser(['id' => 1]));

    $this->post('whatsapp/cloud/sandbox/media', [
        'conversation' => $this->conversation->id,
        'file' => $file,
        'voice' => '1',
    ])->assertRedirect();

    $message = SandboxMessage::query()->where('direction', SandboxMessage::INBOUND)->sole();
    $audio = $message->inbound_payload['entry'][0]['changes'][0]['value']['messages'][0];

    expect($message->type)->toBe('audio')
        ->and($audio['type'])->toBe('audio')
        ->and($audio['audio']['voice'])->toBeTrue()
        ->and($audio['audio']['mime_type'])->toStartWith('audio/');

    $this->get("whatsapp/cloud/sandbox/messages/{$message->id}/media")
        ->assertOk()
        ->assertHeader('Content-Type', $audio['audio']['mime_type']);
});
