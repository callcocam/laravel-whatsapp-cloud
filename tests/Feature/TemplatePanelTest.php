<?php

use Callcocam\WhatsAppCloud\WhatsAppCloudServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // The panel defaults to the ['web', 'auth'] middleware.
    $this->actingAs(new GenericUser(['id' => 1]));
});

/** The default panel prefix (see config/whatsapp-cloud.php). */
function panelUrl(string $path = ''): string
{
    return 'whatsapp/cloud/templates'.$path;
}

it('renders the panel with the templates and public config', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['name' => 'coordena_x', 'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED', 'id' => '1'],
    ]])]);

    $this->get(panelUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('WhatsAppCloud/Templates/Index')
            ->has('templates', 1)
            ->where('waConfig.waba_id', '999888777')
            ->where('waConfig.phone_number_id', '111222333')
        );
});

it('renders the configured component (host can own a native page)', function () {
    config(['whatsapp-cloud.panel.component' => 'WhatsAppCloud/Custom/Native']);
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    // The native page lives in the host's tree, not the package's — assert the
    // component name without the file-existence check.
    $this->get(panelUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('WhatsAppCloud/Custom/Native', false));
});

it('creates a template from the form payload', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'tpl_1', 'status' => 'PENDING'], 201)]);

    $this->post(panelUrl(), [
        'name' => 'coordena_novo',
        'language' => 'pt_BR',
        'category' => 'UTILITY',
        'body' => 'Olá, {{1}}! Bem-vindo.',
        'bodyExamples' => ['Maria'],
    ])->assertRedirect();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_contains($r->url(), '/v21.0/999888777/message_templates')
        && $r->data()['name'] === 'coordena_novo'
        && $r->data()['components'][0]['type'] === 'BODY');
});

it('edits a template by id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $this->post(panelUrl('/123/edit'), [
        'name' => 'coordena_novo',
        'language' => 'pt_BR',
        'category' => 'MARKETING',
        'body' => 'Oi {{1}}, temos novidades!',
        'bodyExamples' => ['Ana'],
    ])->assertRedirect();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_contains($r->url(), '/v21.0/123')
        && ! str_contains($r->url(), 'message_templates')
        && $r->data()['category'] === 'MARKETING');
});

it('deletes a template by name', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $this->delete(panelUrl('/coordena_x'))->assertRedirect();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && str_contains($r->url(), 'message_templates'));
});

it('sends a test message through the phone number endpoint', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.S']]])]);

    $this->post(panelUrl('/send'), [
        'name' => 'coordena_x',
        'to' => '5548999999999',
        'params' => ['Ana'],
        'language' => 'pt_BR',
    ])->assertRedirect();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v21.0/111222333/messages')
        && $r->data()['template']['name'] === 'coordena_x'
        && $r->data()['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'Ana']);
});

it('surfaces a Meta error as a validation error', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad', 'code' => 100]], 400)]);

    $this->post(panelUrl(), [
        'name' => 'coordena_x',
        'body' => 'Olá, {{1}}! fim.',
        'bodyExamples' => ['Ana'],
    ])->assertRedirect()->assertSessionHasErrors(['meta']);
});

it('rejects an invalid template name with a form error', function () {
    Http::fake();

    $this->post(panelUrl(), [
        'name' => 'Coordena_X', // uppercase is invalid
        'body' => 'Olá, {{1}}! fim.',
        'bodyExamples' => ['Ana'],
    ])->assertRedirect()->assertSessionHasErrors(['form']);

    Http::assertNothingSent();
});

it('enforces the ui token when configured', function () {
    config(['whatsapp-cloud.panel.ui_token' => 'secret']);
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $this->get(panelUrl())->assertStatus(401);

    $this->get(panelUrl(), ['X-WA-UI-Token' => 'secret'])->assertOk();
});

it('normalizes a success flash into the toast shape on store', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'tpl_1', 'status' => 'PENDING'], 201)]);

    $this->post(panelUrl(), [
        'name' => 'coordena_novo',
        'language' => 'pt_BR',
        'category' => 'UTILITY',
        'body' => 'Olá, {{1}}! Bem-vindo.',
        'bodyExamples' => ['Maria'],
    ])->assertRedirect()->assertSessionHas('flash', fn ($flash) => $flash['toast']['type'] === 'success'
        && str_contains($flash['toast']['message'], 'enviado para análise'));
});

it('includes the sent_id alongside the toast on send', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.S']]])]);

    $this->post(panelUrl('/send'), [
        'name' => 'coordena_x',
        'to' => '5548999999999',
        'params' => ['Ana'],
        'language' => 'pt_BR',
    ])->assertRedirect()->assertSessionHas('flash', fn ($flash) => $flash['toast']['type'] === 'success'
        && $flash['sent_id'] === 'wamid.S');
});

it('blocks the panel when the configured gate denies', function () {
    config(['whatsapp-cloud.panel.gate' => 'manage-wa-templates']);
    Gate::define('manage-wa-templates', fn () => false);
    // Re-register so the panel routes pick up the `can:<gate>` middleware.
    $this->app->register(WhatsAppCloudServiceProvider::class, true);
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $this->get(panelUrl())->assertForbidden();
});

it('allows the panel when the configured gate passes', function () {
    config(['whatsapp-cloud.panel.gate' => 'manage-wa-templates']);
    Gate::define('manage-wa-templates', fn () => true);
    $this->app->register(WhatsAppCloudServiceProvider::class, true);
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $this->get(panelUrl())->assertOk();
});
