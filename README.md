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
- **Templates**: registry (config + runtime), comandos `whatsapp:template:*` e um
  **painel web** opcional (Inertia + Vue) pra criar/editar/enviar.
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

É **opcional e desacoplado do núcleo**: as rotas só são registradas quando o app
tem Inertia instalado (`inertiajs/inertia-laravel`). Quem só envia mensagens não
carrega nada de frontend.

O **backend** (controller, rotas, `TemplateManager`) é sempre do pacote. A
**página** vem em dois modos — escolha um:

| Modo | Como | Pra quem |
|---|---|---|
| **Fallback autônoma** | `vendor:publish --tag=whatsapp-cloud-inertia` — página self-contained (CSS próprio, sem design system) | Apps sem design system; funciona na hora |
| **Scaffold nativo** | `php artisan whatsapp:panel:scaffold` — gera páginas **shadcn-vue** que você passa a ser dono | Apps com shadcn-vue + `@lucide/vue` + `vue-sonner` + `AppLayout` |

Nos dois casos o pacote continua dono do backend; só o **contrato de props**
(`templates`, `waConfig`, `loadError`, `panelUrl`) atravessa a fronteira — então
atualizar o pacote **não exige recopiar a página**.

#### Habilitar (app com Inertia + Vue + Vite)

1. **Dependências** (se ainda não tiver Inertia no app):

   ```bash
   composer require inertiajs/inertia-laravel
   npm install @inertiajs/vue3
   php artisan inertia:middleware   # cria app/Http/Middleware/HandleInertiaRequests.php
   ```

   Registre o middleware no grupo `web` (Laravel 11+ em `bootstrap/app.php`):

   ```php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class]);
   })
   ```

2. **Publique as páginas Vue** e rebuild o Vite:

   ```bash
   php artisan vendor:publish --tag=whatsapp-cloud-inertia
   npm run build     # ou: npm run dev
   ```

   O publish copia os componentes para `resources/js/Pages/WhatsAppCloud/`, onde o
   resolver padrão do Inertia — `resolvePageComponent('./Pages/**/*.vue')` — os
   encontra. Nada de CDN/assets do pacote: quem compila é o Vite do app.

3. **Compartilhe o `flash`** no `HandleInertiaRequests::share()` para as
   mensagens de sucesso e o id da mensagem enviada aparecerem:

   ```php
   public function share(Request $request): array
   {
       return array_merge(parent::share($request), [
           'flash' => fn () => $request->session()->get('flash'),
       ]);
   }
   ```

4. **Acesse** `/whatsapp/cloud/templates` autenticado. Sem isso, a rota (grupo
   `auth`) redireciona pro login.

#### Configuração

```php
// config/whatsapp-cloud.php
'panel' => [
    'enabled'    => env('WHATSAPP_CLOUD_PANEL_ENABLED', true),
    'prefix'     => env('WHATSAPP_CLOUD_PANEL_PREFIX', 'whatsapp/cloud/templates'),
    'name'       => 'whatsapp.cloud.panel',
    'middleware' => ['web', 'auth'],
    // Componente Inertia renderizado. Aponte para sua página nativa (scaffold)
    // ou deixe no default (fallback autônoma).
    'component'  => env('WHATSAPP_CLOUD_PANEL_COMPONENT', 'WhatsAppCloud/Templates/Index'),
    // Gate de autorização opcional: quando setado, o pacote anexa 'can:<gate>'
    // à middleware do painel (ele mexe na WABA compartilhada).
    'gate'       => env('WHATSAPP_CLOUD_PANEL_GATE'),
    // Moeda (ISO) do card de gasto estimado; null mostra o número puro.
    'currency'   => env('WHATSAPP_CLOUD_PANEL_CURRENCY'),
    'ui_token'   => env('WHATSAPP_CLOUD_PANEL_UI_TOKEN'), // defesa extra opcional
],
```

Env correspondente:

```dotenv
WHATSAPP_CLOUD_PANEL_ENABLED=true
WHATSAPP_CLOUD_PANEL_PREFIX=whatsapp/cloud/templates
# WHATSAPP_CLOUD_PANEL_COMPONENT=WhatsAppCloud/Templates/Index
# WHATSAPP_CLOUD_PANEL_GATE=manage-whatsapp-templates
# WHATSAPP_CLOUD_PANEL_CURRENCY=BRL
# WHATSAPP_CLOUD_PANEL_UI_TOKEN=um-token-secreto
```

O painel é poderoso (cria/apaga/envia numa WABA que costuma ser **compartilhada**
entre times), então proteja-o:

- **Gate** (recomendado): setar `panel.gate` faz o pacote anexar `can:<gate>` à
  middleware. Defina o gate no app (ex.: `Gate::define('manage-whatsapp-templates', …)`).
- **`middleware`**: já vem `['web','auth']` — troque/estenda como quiser.
- **`ui_token`**: se `WHATSAPP_CLOUD_PANEL_UI_TOKEN` estiver setado, toda requisição
  precisa mandar o mesmo valor no header `X-WA-UI-Token` (defesa em profundidade).

Link no seu menu com o nome de rota: `route('whatsapp.cloud.panel.index')`.

#### UI nativa no seu design system (scaffold)

Quer o painel com a **cara do seu app** (shadcn-vue) em vez da página autônoma? Rode:

```bash
php artisan whatsapp:panel:scaffold           # --force pra sobrescrever
```

Copia páginas **shadcn-vue** para `resources/js/pages/WhatsAppCloud/Templates/` (que
você passa a ser dono), removendo o sufixo `.stub`. Depois:

1. Aponte o painel: `WHATSAPP_CLOUD_PANEL_COMPONENT=WhatsAppCloud/Templates/Index`.
2. Garanta os pré-requisitos: shadcn-vue (`@/components/ui/*`), `@lucide/vue`,
   `vue-sonner` e `@/layouts/AppLayout.vue`.
3. `npm run build` (e typecheck com `vue-tsc --noEmit`).

O contrato de props é o mesmo da fallback (`templates`, `waConfig`, `loadError`,
`panelUrl`), e o `flash` de sucesso vem no shape `flash.toast = { type, message }`
(no `send`, com `flash.sent_id`) — o Index nativo já faz a ponte pro `vue-sonner`.

#### Usando a interface

- **Listagem**: tabela com nome, idioma, categoria e status; filtros por status /
  categoria, busca por nome e contadores no topo.
- **Novo / Editar**: formulário completo (cabeçalho, corpo com `{{1}}`, exemplos por
  variável, rodapé e botões `QUICK_REPLY` / `URL` / `PHONE_NUMBER`) com
  **pré-visualização ao vivo** estilo bolha do WhatsApp. Nome e idioma são imutáveis
  ao editar.
- **Enviar teste**: dispara um template aprovado pra um número, preenchendo as
  variáveis do corpo.
- **Apagar**: remove **todos os idiomas** com aquele nome na WABA (há confirmação).
- **Gastos (estimado)**: card com o custo do mês atual e a quebra por categoria,
  lido de `conversation_analytics` da WABA. É **best-effort** — o token precisa da
  permissão `whatsapp_business_management`; sem ela (ou em falha), o card some e o
  resto do painel segue. O valor é **estimado** (a fonte oficial é o WhatsApp
  Manager); ajuste a moeda com `panel.currency`.

Notas: CSRF é automático (Inertia). **Criar e editar são assíncronos** — vão para
análise da Meta (status `PENDING`); editar um aprovado o reseta para nova análise. A
categoria pode ser reclassificada pela Meta. O painel opera no tenant `default`; o
gancho para escolher o número/tenant é o `TemplatePanelController::tenant()`.

#### Integração no app consumidor (Coordena — Parte B)

> **Nota de handoff.** A **Parte A** (este pacote) está pronta na branch
> `feature/template-panel`. A **Parte B** (página nativa no design system) é feita
> no repo do **Coordena** — plano completo em
> [`docs/plans/2026-07-09-painel-templates-nativo-host.md`](docs/plans/2026-07-09-painel-templates-nativo-host.md).

Commits do pacote que o Coordena consome (branch `feature/template-panel`):

- **`2091f0c`** — painel headless: `panel.component` configurável, `panel.gate`,
  flash normalizado (`flash.toast`) e o comando `whatsapp:panel:scaffold` + stubs
  nativos (shadcn-vue).
- **`61e614a`** — card de gasto estimado (`TemplateManager::costs()` + prop `costs`
  + `panel.currency`).

**Contrato de props (congelado — o backend fica no pacote):**

| Prop | Tipo | Descrição |
|---|---|---|
| `templates` | `array<object>` | Templates crus da Meta (`{ id, name, language, category, status, components[], rejected_reason? }`) |
| `waConfig` | `{ waba_id, phone_number_id, api_version }` | Credenciais **públicas** (nunca o token) |
| `loadError` | `string \| null` | Erro ao carregar a lista |
| `costs` | `object \| null` | `{ currency, total, conversations, period:{start,end}, byCategory:[{category,cost,conversations}] }` — `null` quando indisponível |
| `panelUrl` | `string` | Base das rotas de mutação (o host concatena; **sem wayfinder**) |

Config que o Coordena publica: `panel.component = WhatsAppCloud/Templates/Index`,
`panel.gate = manage-whatsapp-templates`, `panel.currency = BRL`. Fluxo de sucesso
via `flash.toast = { type, message }` (`send` inclui `flash.sent_id`); erros via
`errors.meta` / `errors.form`.

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
`app_secret`, `verify_token`, `default` (creds dev), `webhook`, `panel`
([painel de templates](#painel-de-templates-inertia--vue)), `model`, `templates`,
`definitions_path`.

## Testes

```bash
composer test          # pint --test + phpstan + pest
```

## Segurança

Nunca versione tokens. `cloud_access_token` fica `encrypted` no model; segredos
(`app_secret`, `verify_token`, tokens) só via `.env`.

## Licença

MIT.
