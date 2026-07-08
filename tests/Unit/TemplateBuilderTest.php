<?php

use Callcocam\WhatsAppCloud\Templates\TemplateBuilder;
use Callcocam\WhatsAppCloud\Templates\TemplateInput;

it('builds a full template payload', function () {
    $payload = TemplateBuilder::make('coordena_lembrete', 'pt_BR', 'utility')
        ->body('Olá, {{1}}! Lembrete: {{2}}.', ['Maria', 'Reunião'])
        ->footer('Coordena')
        ->quickReply('Confirmar')
        ->toArray();

    expect($payload['name'])->toBe('coordena_lembrete')
        ->and($payload['language'])->toBe('pt_BR')
        ->and($payload['category'])->toBe('UTILITY')
        ->and($payload['components'][0])->toBe([
            'type' => 'BODY',
            'text' => 'Olá, {{1}}! Lembrete: {{2}}.',
            'example' => ['body_text' => [['Maria', 'Reunião']]],
        ])
        ->and($payload['components'][1])->toBe(['type' => 'FOOTER', 'text' => 'Coordena'])
        ->and($payload['components'][2])->toBe(['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Confirmar']]]);
});

it('rejects a body starting with a variable', function () {
    expect(fn () => TemplateBuilder::make('x')->body('{{1}} olá', ['a']))
        ->toThrow(LogicException::class);
});

it('rejects a body ending with a variable', function () {
    expect(fn () => TemplateBuilder::make('x')->body('Olá {{1}}', ['a']))
        ->toThrow(LogicException::class);
});

it('rejects a variable/example count mismatch', function () {
    expect(fn () => TemplateBuilder::make('x')->body('Olá {{1}} e {{2}}!', ['a']))
        ->toThrow(LogicException::class);
});

it('rejects an empty template', function () {
    expect(fn () => TemplateBuilder::make('x')->toArray())
        ->toThrow(LogicException::class);
});

it('turns a flat array into a payload via TemplateInput', function () {
    $payload = TemplateInput::toPayload([
        'name' => 'coordena_teste',
        'body' => 'Olá, {{1}}! Tudo certo.',
        'bodyExamples' => ['Maria'],
        'footer' => 'Coordena',
        'buttons' => [['type' => 'URL', 'text' => 'Abrir', 'url' => 'https://x.test']],
    ]);

    expect($payload['name'])->toBe('coordena_teste')
        ->and($payload['components'][2]['buttons'][0])->toBe([
            'type' => 'URL', 'text' => 'Abrir', 'url' => 'https://x.test',
        ]);
});

it('rejects an invalid template name', function () {
    expect(fn () => TemplateInput::toPayload(['name' => 'Bad Name', 'body' => 'x {{1}} y', 'bodyExamples' => ['a']]))
        ->toThrow(InvalidArgumentException::class);
});
