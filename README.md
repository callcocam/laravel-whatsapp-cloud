# Laravel WhatsApp Cloud

Núcleo reutilizável da **WhatsApp Cloud API (Meta)** para Laravel: envio de
templates / texto de sessão / interativo **por-tenant**, webhook com assinatura
e eventos, e gestão de templates via Artisan.

> Extraído do núcleo Meta-only do Coordena. O pacote cuida do **transporte +
> protocolo + ciclo de vida de template**; o app cuida da **regra de negócio +
> gatilhos + textos**.

- **Envio** por intenção: `sendTemplate` / `sendSessionText` / `sendInteractive`.
- **Multi-tenant**: credenciais resolvidas por contrato (`WhatsApp::for($tenant)`),
  com fallback pras credenciais default do config (dev/single-tenant).
- **Webhook** GET (hub-challenge) + POST (assinatura `X-Hub-Signature-256`),
  disparando eventos — sem regra de negócio dentro do pacote.
- **Templates**: registry (config + runtime) e comandos `whatsapp:template:*`.
- **Erros terminais** da Meta mapeados (`isTemporaryRestriction()`) pra você não
  re-tentar o que não adianta (janela de 24h fechada, template pausado, etc.).

## Requisitos

- PHP `^8.3`
- Laravel `^11.0 || ^12.0 || ^13.0`

## Instalação

Repositório Git privado, consumido via Composer:

```jsonc
// composer.json do app
"repositories": [
    { "type": "vcs", "url": "git@github.com:callcocam/laravel-whatsapp-cloud.git" }
]
```

```bash
composer require callcocam/laravel-whatsapp-cloud
php artisan whatsapp:install   # publica config + migration e imprime o checklist
php artisan migrate
```

## Onboarding de um projeto novo (6 passos)

1. `composer require callcocam/laravel-whatsapp-cloud` (com o `repositories: vcs`).
2. `php artisan whatsapp:install` && `php artisan migrate`.
3. `.env`: `WHATSAPP_CLOUD_APP_SECRET`, `WHATSAPP_CLOUD_VERIFY_TOKEN`,
   `WHATSAPP_CLOUD_GRAPH_VERSION`. Cadastre o número — use o model default
   `WhatsAppNumber` **ou** implemente `WhatsAppCredentials` no seu model + binde
   um resolver (veja [Credenciais](#credenciais-multi-tenant)).
4. Declare os templates em `config/whatsapp-cloud.php` e crie-os na Meta com
   `php artisan whatsapp:template:create <nome>`.
5. (Opcional) Ouça `WhatsAppMessageReceived` / `WhatsAppStatusReceived`.
6. Envie: `WhatsApp::for($tenant)->sendTemplate('chave', [...params]);`.

## Uso

### Enviar

```php
use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Messages\InteractiveMessage;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;

// Template aprovado (única forma fora da janela de 24h):
WhatsApp::for($team)->sendTemplate('5548999999999', TemplateMessage::make('assignment', [
    'name' => 'Maria', 'event' => 'Congresso', 'url' => 'https://app.test/x',
]));

// Texto livre (só dentro da janela de 24h):
WhatsApp::for($team)->sendSessionText('5548999999999', 'Recebido, obrigado!');

// Pergunta com opções (lista interativa da Meta):
WhatsApp::for($team)->sendInteractive('5548999999999',
    InteractiveMessage::multiChoice('Quando você pode?', ['Sexta à noite', 'Sábado de manhã']));
```

`WhatsApp::for()` sem argumento usa as credenciais `default` do config (dev /
single-tenant). Prefira injetar `WhatsAppManager` a usar a Facade em código
testável.

### Tratar erros da Meta

```php
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppException;

try {
    WhatsApp::for($team)->sendTemplate(...);
} catch (WhatsAppException $e) {
    if ($e->isTemporaryRestriction()) {
        // Terminal (janela fechada, template pausado…): logue e NÃO re-tente.
    }
    // Senão: deixe a fila re-tentar (rate limit, rede).
}
```

### Templates

Declare cada chave de mensagem do app → template aprovado na Meta:

```php
// config/whatsapp-cloud.php
'templates' => [
    'assignment' => [
        'name' => 'coordena_assignment',
        'language' => 'pt_BR',
        'category' => 'utility',
        'params' => ['name', 'event', 'url'], // ordem de {{1}}, {{2}}, {{3}}
    ],
],
```

Ou em runtime:

```php
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;

WhatsApp::registerTemplate('assignment', new MetaTemplate('coordena_assignment', 'pt_BR', 'utility', ['name', 'event', 'url']));
```

### Painel de templates (Inertia + Vue)

Uma página web para **criar, listar, editar, apagar e enviar teste** de templates —
o mesmo `TemplateManager` do CLI, com preview estilo WhatsApp. Fica em
`/whatsapp/cloud/templates` (configurável).

Opcional e desacoplado do núcleo: só é registrada quando o app tem Inertia
instalado. Para habilitar num app com Inertia + Vue + Vite:

```bash
composer require inertiajs/inertia-laravel
npm install @inertiajs/vue3            # se ainda não tiver
php artisan vendor:publish --tag=whatsapp-cloud-inertia
npm run build
```

O publish copia as páginas Vue para `resources/js/Pages/WhatsAppCloud/` (onde o
resolver padrão do Inertia — `resolvePageComponent('./Pages/**/*.vue')` — as
encontra) para o Vite do app compilá-las.

Configuração em `config/whatsapp-cloud.php`:

```php
'panel' => [
    'enabled'    => env('WHATSAPP_CLOUD_PANEL_ENABLED', true),
    'prefix'     => env('WHATSAPP_CLOUD_PANEL_PREFIX', 'whatsapp/cloud/templates'),
    'name'       => 'whatsapp.cloud.panel',
    'middleware' => ['web', 'auth'],  // adicione sua autorização (ex.: 'can:...')
    'ui_token'   => env('WHATSAPP_CLOUD_PANEL_UI_TOKEN'), // defesa extra opcional
],
```

O painel é poderoso (cria/apaga/envia), então já vem protegido por `['web','auth']`.
Se `WHATSAPP_CLOUD_PANEL_UI_TOKEN` estiver setado, toda requisição precisa mandar
o mesmo valor no header `X-WA-UI-Token`. O protótipo opera no tenant `default`;
o gancho para escolher o número/tenant fica em `TemplatePanelController::tenant()`.

CSRF é automático (Inertia). Criar/editar enviam o template para análise da Meta
(status `PENDING`); editar um aprovado o reseta para nova análise.

### Credenciais (multi-tenant)

Implemente o contrato no seu model (ou use a trait) e binde um resolver:

```php
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Support\HasWhatsAppCredentials;

class TeamWhatsappConnection extends Model implements WhatsAppCredentials
{
    use HasWhatsAppCredentials; // lê phone_number_id, cloud_access_token, waba_id
}
```

```php
// AppServiceProvider::register()
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;

$this->app->bind(WhatsAppCredentialsResolver::class, fn () => new class implements WhatsAppCredentialsResolver {
    public function resolve(mixed $context): ?WhatsAppCredentials
    {
        return $context instanceof \App\Models\Team ? $context->whatsappConnection : null;
    }
});
```

Projeto novo que não quer escrever resolver: guarde os números no model default
`WhatsAppNumber` e binde o `ModelCredentialsResolver` (busca por `key`).

### Webhook

O provider registra `GET|POST {prefix}` (default `webhooks/whatsapp/cloud`, no
grupo `api` — sem CSRF). Aponte o webhook da Meta pra essa URL. Para registrar
você mesmo, ponha `whatsapp-cloud.webhook.enabled = false` e mire o
`WebhookController`.

```php
// Um listener no app:
use Callcocam\WhatsAppCloud\Events\WhatsAppStatusReceived;

Event::listen(function (WhatsAppStatusReceived $event) {
    logger()->info('status', ['id' => $event->status['id'], 'status' => $event->status['status']]);
});
```

Eventos: `WhatsAppMessageReceived`, `WhatsAppStatusReceived`,
`WhatsAppWebhookVerified`.

## Comandos Artisan

```bash
php artisan whatsapp:template:list                 # lista templates da WABA + status
php artisan whatsapp:template:get <nome>           # detalha um template
php artisan whatsapp:template:create <nome>        # cria na Meta a partir de um arquivo de definição
php artisan whatsapp:template:send <nome> <to> ... # envia um template aprovado
```

Todos aceitam `--tenant=` (contexto pro resolver). O `create` lê
`config('whatsapp-cloud.definitions_path')/<nome>.php` (ou `--path=`), um arquivo
que retorna `TemplateBuilder::...->toArray()`.

## Configuração

Veja [`config/whatsapp-cloud.php`](config/whatsapp-cloud.php): `graph_version`,
`app_secret`, `verify_token`, `default` (creds dev), `webhook`, `model`,
`templates`, `definitions_path`.

## Testes

```bash
composer test          # pint --test + phpstan + pest
```

## Segurança

Nunca versione tokens. `cloud_access_token` fica `encrypted` no model; segredos
(`app_secret`, `verify_token`, tokens) só via `.env`.

## Licença

MIT.
