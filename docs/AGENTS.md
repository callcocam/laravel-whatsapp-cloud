# Guia de implementação para agentes de IA

Documento de referência para um agente (Claude Code, Copilot, etc.) **integrar
`callcocam/laravel-whatsapp-cloud` num app Laravel** sem ler o código-fonte do
pacote. Tudo aqui foi extraído do código real — assinaturas, invariantes e
armadilhas.

Para humanos operando o painel/CLI, use o [Guia do usuário](GUIA-DO-USUARIO.md).

---

## 1. Modelo mental (leia antes de escrever código)

O pacote é **transporte + protocolo Meta + ciclo de vida de template**. Ele
**não** tem regra de negócio, **não** persiste mensagens e **não** decide quando
enviar. O app faz isso.

Duas superfícies independentes, que nunca se cruzam:

| Superfície | Classe | Endpoint Meta | Para quê |
|---|---|---|---|
| **Data plane** (envio em runtime) | `CloudApiClient` | `{phone_number_id}/messages` | Mandar mensagem pro usuário final |
| **Control plane** (gestão de template) | `TemplateManager` | `{waba_id}/message_templates` | Criar/listar/editar/apagar template na WABA |

Ambas nascem do mesmo lugar: `WhatsAppManager` (a Facade `WhatsApp`).

```php
WhatsApp::for($tenant)          // → CloudApiClient   (envia mensagem)
WhatsApp::templateApi($tenant)  // → TemplateManager  (gerencia template)
WhatsApp::credentials($tenant)  // → WhatsAppCredentials (creds resolvidas)
```

**Regra da Meta que dita todo o desenho:** fora da janela de 24h (depois que o
usuário te respondeu), você **só** pode enviar um **template aprovado**. Texto
livre e interativo só valem **dentro** da janela.

---

## 2. Mapa de arquivos

```
src/
  WhatsAppManager.php            entrypoint — for() / templateApi() / credentials()
  CloudApiFactory.php            monta CloudApiClient a partir de credenciais
  CloudApiClient.php             DATA PLANE: sendTemplate / sendSessionText / sendInteractive
  Facades/WhatsApp.php           facade → WhatsAppManager
  Contracts/
    MessageGateway.php           interface do data plane (CloudApiClient implementa)
    MessageTransport.php         O FIO. Todo envio passa por aqui — o driver escolhe qual
    WhatsAppCredentials.php      phoneNumberId() accessToken() wabaId() graphVersion()
    WhatsAppCredentialsResolver.php   resolve(mixed $context): ?WhatsAppCredentials
  Transport/
    CloudApiTransport.php        o fio real (HTTP → Graph API). Default.
  Support/
    ArrayCredentials.php         credenciais de array (usado pelo config `default`)
    ConfigCredentialsResolver.php     resolver default (ignora o contexto)
    ModelCredentialsResolver.php      resolver por coluna-chave num model
    HasWhatsAppCredentials.php   trait p/ implementar o contrato num Eloquent
  Models/WhatsAppNumber.php      model default opt-in (tabela whatsapp_numbers)
  Templates/
    TemplateRegistry.php         chave do app → template Meta (config + runtime)
    MetaTemplate.php             name / language / category / bodyParams (ORDENADO)
    TemplateManager.php          CONTROL PLANE: create/edit/all/getByName/delete/send/costs
    TemplateBuilder.php          builder fluente do payload + guard-rails anti-rejeição
    TemplateInput.php            array plano (form/JSON) → TemplateBuilder/payload
  Messages/
    TemplateMessage.php          key + params (map nome => valor)
    InteractiveMessage.php       body + options
    SendResult.php               provider / messageId (wamid) / status
  Events/                        WhatsAppMessageReceived, WhatsAppStatusReceived, WhatsAppWebhookVerified
  Exceptions/                    WhatsAppException (base), CloudApiException, WhatsAppNotConfiguredException, SandboxException
  Http/Controllers/              WebhookController, TemplatePanelController, SandboxController
  Sandbox/                       SÓ com driver=sandbox. Ver docs/SANDBOX.md
    SandboxTransport.php         o fio que não vai a lugar nenhum: guarda a mensagem
    InboundPayloadFactory.php    monta os webhooks no formato da Meta (o produto)
    WebhookSimulator.php         assina com HMAC real e entrega pela rota REAL
    TemplateDefinitions.php      lê os arquivos de definição locais
    TemplateResolver.php         corpo do template: definição local → Meta → degrada
    ResolvedTemplate.php         components → header/body/footer/buttons + render({{n}})
    Sandbox.php                  a API: participant() / reply() / tapTemplateButton() / …
    Fault.php, FaultCatalog.php  as falhas da Meta que dá pra injetar
    Models/                      SandboxConversation, SandboxMessage
  Console/                       install, panel:scaffold, template:{list,get,create,send}
config/whatsapp-cloud.php        toda a configuração
routes/webhook.php               GET|POST {webhook.prefix}
routes/panel.php                 CRUD do painel sob {panel.prefix}
routes/sandbox.php               a tela do sandbox (só com driver=sandbox, nunca em produção)
database/migrations/             cria whatsapp_numbers + as tabelas do sandbox (tags separadas)
resources/js/pages/...           página Vue fallback do painel (publicável)
resources/js/sandbox/...         página Vue do sandbox — FORA de js/pages de propósito
resources/stubs/inertia-native/  stubs shadcn-vue (scaffold)
```

Namespace raiz: `Callcocam\WhatsAppCloud\`. Auto-discovery registra o provider e
o alias `WhatsApp`.

---

## 3. Envio (data plane)

`CloudApiClient` — sempre obtido via `WhatsApp::for($context)`.

```php
public function sendTemplate(string $to, TemplateMessage $template): SendResult;
public function sendSessionText(string $to, string $text): SendResult;
public function sendInteractive(string $to, InteractiveMessage $message): SendResult;
```

⚠️ **A ordem é `($to, $mensagem)`.** `sendTemplate()` recebe um `TemplateMessage`,
**não** uma string de chave.

```php
use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Messages\InteractiveMessage;

// Template aprovado — única forma fora da janela de 24h.
$result = WhatsApp::for($team)->sendTemplate('5548999999999', TemplateMessage::make('assignment', [
    'name'  => 'Maria',
    'event' => 'Congresso',
    'url'   => 'https://app.test/x',
]));
$result->messageId; // wamid — guarde-o se quiser casar com o webhook de status

// Texto livre — SÓ dentro da janela de 24h (Meta devolve 131047 se fechada).
WhatsApp::for($team)->sendSessionText('5548999999999', 'Recebido, obrigado!');

// Lista interativa.
WhatsApp::for($team)->sendInteractive('5548999999999',
    InteractiveMessage::multiChoice('Quando você pode?', ['Sexta à noite', 'Sábado de manhã']));
```

**Invariantes:**

- `$to` = só dígitos, com DDI (`5548999999999`). O pacote não normaliza — quem
  normaliza é o app (o painel faz `preg_replace('/\D+/', '', ...)`).
- Os `params` do `TemplateMessage` são um **map nome → valor**; o `CloudApiClient`
  os ordena pelo `bodyParams` do registry (o que vira `{{1}}`, `{{2}}`…).
  **Param faltando vira string vazia — não estoura.** Valide antes se importa.
- `sendInteractive` corta o título de cada opção em **24 chars** (o label completo
  vai na `description`, cortada em 72) e o rótulo do botão em 20.
- Timeout HTTP: **10s** no data plane, **30s** no control plane.
- Em runtime só se envia **chave registrada** no `TemplateRegistry`; chave
  desconhecida → `CloudApiException` (guard deliberado, antes de bater na Meta).

**Injete `WhatsAppManager` em vez de usar a Facade** em código que você vai testar.

---

## 4. Registry de templates

Mapeia a **chave do app** → **template aprovado na Meta** + a ordem dos params.

```php
// config/whatsapp-cloud.php
'templates' => [
    'assignment' => [
        'name'     => 'coordena_assignment', // nome real na Meta
        'language' => 'pt_BR',
        'category' => 'utility',
        'params'   => ['name', 'event', 'url'], // ORDEM = {{1}}, {{2}}, {{3}}
    ],
],
```

Em runtime (ganha do config na mesma chave):

```php
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;

WhatsApp::registerTemplate('assignment',
    new MetaTemplate('coordena_assignment', 'pt_BR', 'utility', ['name', 'event', 'url']));
```

O registry é **singleton** — registre no `boot()` de um provider se precisar de
templates dinâmicos.

---

## 5. Credenciais e multi-tenant

Contrato:

```php
interface WhatsAppCredentials {
    public function phoneNumberId(): string;
    public function accessToken(): string;
    public function wabaId(): ?string;      // obrigatório p/ templateApi()
    public function graphVersion(): ?string; // null = usa o default do config
}

interface WhatsAppCredentialsResolver {
    public function resolve(mixed $context): ?WhatsAppCredentials;
}
```

### ⚠️ Armadilha nº 1 — `for()` sem argumento NÃO passa pelo resolver

```php
WhatsApp::for()          // → ArrayCredentials::fromArray(config('whatsapp-cloud.default'))
WhatsApp::for($tenant)   // → resolver->resolve($tenant)
```

`for(null)` **curto-circuita** o resolver e vai direto no config `default`. Se as
credenciais `default` estiverem vazias, estoura `WhatsAppNotConfiguredException`.
Num app multi-tenant, **sempre passe o contexto**.

### Três formas de fornecer credenciais

**(a) Config `default`** — dev / single-tenant. Só preencher o `.env`.

**(b) Model do app** — implemente o contrato (a trait faz o trabalho, desde que as
colunas sejam `phone_number_id`, `cloud_access_token`, `waba_id`, `graph_version`):

```php
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Support\HasWhatsAppCredentials;

class TeamWhatsappConnection extends Model implements WhatsAppCredentials
{
    use HasWhatsAppCredentials;
}
```

E binde o resolver **no `register()`** de um provider:

```php
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;

$this->app->bind(WhatsAppCredentialsResolver::class, fn () => new class implements WhatsAppCredentialsResolver {
    public function resolve(mixed $context): ?WhatsAppCredentials
    {
        return $context instanceof \App\Models\Team ? $context->whatsappConnection : null;
    }
});
```

**(c) Model default do pacote** — sem escrever resolver:

```php
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Support\ModelCredentialsResolver;

$this->app->bind(WhatsAppCredentialsResolver::class,
    fn () => new ModelCredentialsResolver(WhatsAppNumber::class, 'key'));

// Depois: WhatsApp::for('slug-do-time')
```

`WhatsAppNumber` (tabela `whatsapp_numbers`, publicada pela migration) já tem
`cloud_access_token` **encrypted** e `$hidden`.

---

## 6. Erros — a armadilha do nome

```php
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppException;      // base (pegue esta)
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;      // erro da Graph API (tem ->errorCode)
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppNotConfiguredException; // sem credenciais
```

### ⚠️ Armadilha nº 2 — `isTemporaryRestriction()` é um alias deprecado

O método correto é **`isTerminal()`**: `true` = **não adianta re-tentar**.

`isTemporaryRestriction()` ainda existe e devolve exatamente o mesmo valor, mas
está **deprecado** — o nome dizia o oposto do comportamento. **Em código novo use
`isTerminal()`**; se encontrar o nome antigo num app existente, ele não está
quebrado, só desatualizado.

```php
try {
    WhatsApp::for($team)->sendTemplate($to, TemplateMessage::make('assignment', $params));
} catch (WhatsAppException $e) {
    if ($e->isTerminal()) {
        Log::warning('WhatsApp: erro terminal', ['e' => $e->getMessage()]);
        return; // NÃO re-tente — a fila não vai resolver
    }
    throw $e;  // rate limit / rede → deixe a fila re-tentar com backoff
}
```

Códigos tratados como terminais: `131047` (janela de 24h fechada), `131026`
(indeliverable), `131051`, `131048`, `131031`, `368`, `132000`, `132001`,
`132005`, `132007`, `132012`, `132015` (template pausado), `132016` (desabilitado).
Qualquer outro código continua **retentável**.

Padrão recomendado: envio dentro de um **Job na fila**, capturando
`WhatsAppException` como acima.

---

## 7. Webhook

O provider registra sozinho, sob `whatsapp-cloud.webhook.prefix` (default
`webhooks/whatsapp/cloud`, grupo `api` — sem CSRF):

| Verbo | Ação |
|---|---|
| `GET` | Handshake `hub.challenge` da Meta. Compara `hub_verify_token` com `config('whatsapp-cloud.verify_token')` (`hash_equals`) e ecoa o challenge. Dispara `WhatsAppWebhookVerified`. |
| `POST` | Valida `X-Hub-Signature-256` (HMAC-SHA256 do corpo cru com o `app_secret`) e dispara um evento por item. Responde `{"handled":true}`. |

**Sem `app_secret` configurado, todo POST é rejeitado com 403.** Isso é
proposital: nunca processa payload não autenticado.

O pacote **não persiste nada**. O app escuta:

```php
use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Events\WhatsAppStatusReceived;

// Mensagem recebida do usuário (abre a janela de 24h!)
Event::listen(function (WhatsAppMessageReceived $e) {
    $e->message;        // 1 item de value.messages[] (cru, da Meta)
    $e->value;          // o changes[].value inteiro (metadata, contacts…)
    $e->phoneNumberId;  // qual número recebeu → resolva o tenant por aqui
});

// Status de entrega (sent/delivered/read/failed)
Event::listen(function (WhatsAppStatusReceived $e) {
    $e->status['id'];     // o wamid — casa com SendResult::$messageId
    $e->status['status'];
});
```

Para registrar as rotas você mesmo: `whatsapp-cloud.webhook.enabled = false` e
aponte para `WebhookController::verify` / `::store`.

---

## 7b. Sandbox — testar o fluxo inteiro sem a Meta

Guia completo: **[docs/SANDBOX.md](SANDBOX.md)**. O que um agente precisa saber:

`whatsapp-cloud.driver` escolhe o fio por onde toda mensagem sai:

| driver | |
|---|---|
| `cloud` (default) | `CloudApiTransport` → a Graph API de verdade |
| `sandbox` | `SandboxTransport` → guarda a mensagem; **nada chega à Meta** |

**O código do app não muda.** Continua `WhatsApp::for()->sendTemplate(...)`, continua
recebendo `WhatsAppMessageReceived`. As respostas do sandbox entram pela **rota de
webhook real**, assinadas com o **HMAC real** — os listeners rodam como em produção.

O corpo do template vem do **arquivo de definição local**, não da Meta. Logo, dá pra
ensaiar um template **antes de submetê-lo** — e `create` é one-way.

```php
$sandbox = app(Callcocam\WhatsAppCloud\Sandbox\Sandbox::class);

$maria = $sandbox->participant('5548999999999', 'Maria', 'customer');
$sandbox->reply($maria, 'Confirmo!');                     // abre a janela de 24h
$sandbox->tapTemplateButton($maria, 'Aceitar', $wamid);   // webhook type:button
$maria->closeWindow();                                    // → o próximo texto livre estoura 131047
$maria->arm('rate_limited');                              // → o próximo envio estoura 80007 (retentável)
```

O operador/responsável **não tem maquinaria própria**: é outro participante. Dois
chats, um motor.

### ⚠️ O driver vem do `.env`. NUNCA de um toggle em runtime

Se um listener enfileira, o envio sai num **worker** — outro processo, que lê o
próprio `.env`. Um driver trocado em runtime **não chega lá**, e a mensagem vai pra
Meta de verdade, pra um número real, cobrando.

Ligar: `WHATSAPP_CLOUD_DRIVER=sandbox` no `.env`, depois `config:clear` **e**
`queue:restart`.

O `SandboxTransport` se recusa a bootar em produção (sem override), e a tela não se
registra com o driver `cloud`.

---

## 8. Gestão de templates (control plane)

```php
$api = WhatsApp::templateApi($tenant); // exige wabaId nas credenciais, senão WhatsAppNotConfiguredException

$api->create(array $payload): array;                    // envia p/ análise da Meta
$api->edit(string $id, array $components, ?string $category = null): array;
$api->all(?string $name = null, int $limit = 100): array;   // ['data' => [...]]
$api->getByName(string $name): ?array;
$api->delete(string $name, ?string $id = null): array;  // apaga TODOS os idiomas do nome
$api->send(string $templateName, string $to, array $bodyParams = [], string $language = 'pt_BR'): array;
$api->costs(int $start, int $end, string $granularity = 'MONTHLY'): array;
```

Regras da Meta embutidas:

- `create` e `edit` são **assíncronos** → o template fica `PENDING` até a Meta
  analisar.
- `edit` só funciona em template `APPROVED` / `REJECTED` / `PAUSED` (nunca
  `PENDING`), **reseta para `PENDING`**, e **não** edita `name` nem `language`.
- `delete(name)` remove **todos os idiomas** com aquele nome.
- `send()` é posicional (`{{1}}`, `{{2}}`…), diferente do `sendTemplate()` do data
  plane, que é por nome de param.
- `costs()` lê `conversation_analytics` da WABA e exige o escopo
  `whatsapp_business_management` no token; o valor é **estimativa**.

### Montar o payload: `TemplateBuilder`

```php
use Callcocam\WhatsAppCloud\Templates\TemplateBuilder;

$payload = TemplateBuilder::make('coordena_lembrete', 'pt_BR', 'UTILITY')
    ->headerText('Lembrete')                                  // opcional, no máx. 1 variável
    ->body('Olá, {{1}}! Lembrete: {{2}} em {{3}}.', ['Maria', 'Reunião', '10/07'])
    ->footer('Coordena')                                      // opcional, SEM variáveis
    ->quickReply('Confirmar')                                 // ou ->urlButton($t,$url) / ->phoneButton($t,$fone)
    ->toArray();

WhatsApp::templateApi()->create($payload);
```

Guard-rails que estouram `LogicException` **antes** de chamar a Meta (evitam o
erro 100 dela):

- corpo **não pode começar** com variável;
- corpo **não pode terminar** com variável;
- nº de exemplos deve bater **1:1** com o maior `{{n}}` do corpo;
- exemplo não pode ter `\n`, `\t` nem 4+ espaços seguidos;
- `toArray()` sem nenhum componente estoura.

### Vindo de um form/JSON: `TemplateInput`

```php
use Callcocam\WhatsAppCloud\Templates\TemplateInput;

$payload = TemplateInput::toPayload([
    'name' => 'coordena_lembrete', 'language' => 'pt_BR', 'category' => 'UTILITY',
    'header' => 'Lembrete', 'headerExamples' => ['Maria'],
    'body' => 'Olá, {{1}}! ...', 'bodyExamples' => ['Maria'],
    'footer' => 'Coordena',
    'buttons' => [
        ['type' => 'QUICK_REPLY',  'text' => 'Confirmar'],
        ['type' => 'URL',          'text' => 'Abrir', 'url' => 'https://...'],
        ['type' => 'PHONE_NUMBER', 'text' => 'Ligar', 'phone_number' => '+55...'],
    ],
]);
```

Valida `name` (`^[a-z0-9_]+$`, ≤512), exige `body`, rejeita tipo de botão
desconhecido → `InvalidArgumentException`.

---

## 9. A pasta de definições e a manutenção dos templates

### ⚠️ Armadilha nº 3 — um template vive em DOIS lugares

Não confunda os dois; eles servem a fases diferentes e **precisam ser mantidos em
sincronia à mão**:

| | Arquivo de definição | Registry (`config.templates`) |
|---|---|---|
| **Onde** | `{definitions_path}/<nome>.php` | `config('whatsapp-cloud.templates')` |
| **Quando age** | Só no `whatsapp:template:create` (nasce na Meta) | Em **todo** `sendTemplate()` em runtime |
| **Contém** | O conteúdo: texto, `{{n}}`, exemplos, botões | O mapeamento: chave do app → nome/idioma/**ordem dos params** |
| **Se faltar** | Não dá pra criar o template pelo CLI | `CloudApiException: template not registered` |

O invariante que quebra silenciosamente: **a ordem de `params` no registry tem que
bater com a ordem dos `{{1}}, {{2}}…` no corpo da definição.** Se você trocar
`{{1}}` e `{{2}}` no arquivo e esquecer o config, o pacote **não reclama** — manda
o nome no lugar da data. Sempre revise os dois juntos.

```php
// 1) database/whatsapp-templates/coordena_lembrete.php  — o CONTEÚDO (vai pra Meta)
return TemplateBuilder::make('coordena_lembrete', 'pt_BR', 'UTILITY')
    ->body('Olá, {{1}}! Lembrete: {{2}} em {{3}}.', ['Maria', 'Reunião', '10/07'])
    ->footer('Coordena')
    ->toArray();

// 2) config/whatsapp-cloud.php — o MAPEAMENTO (usado no envio)
'templates' => [
    'lembrete' => [                              // a chave que o app usa
        'name'     => 'coordena_lembrete',       // == o name da definição
        'language' => 'pt_BR',                   // == o language da definição
        'category' => 'utility',
        'params'   => ['nome', 'evento', 'data'], // MESMA ordem de {{1}},{{2}},{{3}}
    ],
],

// 3) no app:
WhatsApp::for($t)->sendTemplate($to, TemplateMessage::make('lembrete', [
    'nome' => 'Maria', 'evento' => 'Reunião', 'data' => '10/07',
]));
```

### Configurar a pasta (não vem pronta)

`definitions_path` **é `null` por padrão** — sem configurar, `template:create`
aborta com *"No definitions directory"*. O pacote não cria a pasta nem versiona os
arquivos: o conteúdo é **do app**.

```bash
mkdir -p database/whatsapp-templates
```

```dotenv
WHATSAPP_CLOUD_DEFINITIONS_PATH="${PWD}/database/whatsapp-templates"
```

Como o config lê um caminho cru, prefira resolvê-lo no PHP para não depender do
diretório de trabalho:

```php
// config/whatsapp-cloud.php
'definitions_path' => env('WHATSAPP_CLOUD_DEFINITIONS_PATH', database_path('whatsapp-templates')),
```

Um arquivo por template, nomeado **igual ao argumento do comando** (`<nome>.php` →
`whatsapp:template:create <nome>`); por convenção use o mesmo nome do template na
Meta. O arquivo **retorna um array** (via `TemplateBuilder::…->toArray()`) e deve
ser **versionado no git** — é o histórico do que você submeteu.

### Ciclo de vida (o que o CLI faz e o que NÃO faz)

| Operação | CLI | Painel | API |
|---|---|---|---|
| Criar | `whatsapp:template:create` | ✅ | `TemplateManager::create()` |
| Listar / ver | `whatsapp:template:list` / `:get` | ✅ | `all()` / `getByName()` |
| Enviar | `whatsapp:template:send` | ✅ (teste) | `send()` |
| **Editar** | ❌ **não existe comando** | ✅ | `TemplateManager::edit()` |
| **Apagar** | ❌ **não existe comando** | ✅ | `TemplateManager::delete()` |

**Para alterar um template já criado, o CLI não serve** — use o painel ou chame
`TemplateManager::edit($id, $components, $category)` (o `$id` vem do
`getByName()`). Re-rodar `template:create` com o mesmo nome+idioma **não** atualiza:
a Meta rejeita, porque já existe.

Fluxo de manutenção recomendado:

1. Edite o arquivo de definição (é a fonte versionada).
2. Aplique na Meta pelo painel, **ou** por um script/tinker:
   ```php
   $api  = WhatsApp::templateApi();
   $id   = $api->getByName('coordena_lembrete')['id'];
   $body = require database_path('whatsapp-templates/coordena_lembrete.php');
   $api->edit($id, $body['components'], $body['category']);
   ```
3. Se mudou a **quantidade ou a ordem** das variáveis, atualize `params` no
   registry **no mesmo commit**.
4. Espere sair de `PENDING` (o `edit` reseta o template para nova análise — ele
   **para de poder ser enviado** nesse meio-tempo).

`name` e `language` **não são editáveis**. Mudar qualquer um dos dois = criar um
template novo (e, se for o caso, apagar o antigo pelo painel).

---

## 10. Painel web (Inertia + Vue) — opcional

As rotas **só existem** se `inertiajs/inertia-laravel` estiver instalado **e**
`panel.enabled = true`. Quem só envia mensagem não carrega nada de frontend.

Backend (controller + rotas + `TemplateManager`) é **sempre do pacote**. A
**página** tem dois modos — escolha **um**:

| Modo | Comando | Destino |
|---|---|---|
| **Fallback autônoma** (CSS próprio, sem design system) | `vendor:publish --tag=whatsapp-cloud-inertia` | `resources/js/pages/WhatsAppCloud/` |
| **Scaffold nativo** (shadcn-vue, o host vira dono) | `php artisan whatsapp:panel:scaffold` | `resources/js/pages/WhatsAppCloud/` |

Os dois gravam no **mesmo destino** (`resources/js/pages/`, minúsculo — o padrão
dos starter kits do Laravel, cujo resolver é
`resolvePageComponent('./pages/**/*.vue')`). Escolha **um** modo: rodar os dois
sobrescreve o mesmo arquivo.

### Rotas (`whatsapp.cloud.panel.*`, sob `panel.prefix`)

| Verbo | Caminho | Ação |
|---|---|---|
| GET | `/` | `index` |
| POST | `/` | `store` — cria (vai p/ análise) |
| POST | `/{id}/edit` | `update` — id numérico; reseta p/ PENDING |
| DELETE | `/{name}` | `destroy` — `[a-z0-9_]+`; apaga todos os idiomas |
| POST | `/send` | `send` — `{ name, to, params[], language }` |

### Contrato de props (**congelado** — atualizar o pacote não exige recopiar a página)

| Prop | Tipo |
|---|---|
| `templates` | `array<{ id, name, language, category, status, components[], rejected_reason? }>` (cru da Meta) |
| `waConfig` | `{ waba_id, phone_number_id, api_version }` — **nunca** o token |
| `loadError` | `string \| null` |
| `costs` | `{ currency, total, conversations, period:{start,end}, byCategory:[{category,cost,conversations}] } \| null` |
| `panelUrl` | `string` — base das rotas de mutação (concatene; **sem wayfinder**) |

**Sucesso:** `flash.toast = { type: 'success', message }` (no `send`, também
`flash.sent_id`). Requer compartilhar `flash` no `HandleInertiaRequests::share()`.
**Erro:** `errors.meta` (falha da Meta/API) e `errors.form` (guard-rail local).

### Segurança do painel

Ele **cria / apaga / envia** numa WABA que costuma ser compartilhada. Camadas:

1. `panel.middleware` — default `['web','auth']`.
2. `panel.gate` — quando setado, o provider anexa `can:<gate>` à middleware.
   **Recomendado.** Defina o gate no app.
3. `panel.ui_token` — se setado, **toda** requisição precisa do header
   `X-WA-UI-Token` com o mesmo valor, senão 401.

### Multi-tenant no painel

`TemplatePanelController::tenant()` **retorna `null` fixo** hoje (opera sempre no
tenant `default`). Esse método é o gancho único para um seletor de tenant.

---

## 11. Comandos Artisan

```bash
php artisan whatsapp:install [--force]         # publica config + migration (+ páginas Vue se houver Inertia) e imprime checklist
php artisan whatsapp:panel:scaffold [--force]  # copia as páginas shadcn-vue pro host
php artisan whatsapp:template:list  [--tenant=]
php artisan whatsapp:template:get   <nome> [--tenant=]
php artisan whatsapp:template:create <nome> [--tenant=] [--path=]
php artisan whatsapp:template:send  <nome> <to> [params...] [--tenant=] [--lang=pt_BR]
```

`--tenant=` passa uma **string** ao resolver bindado — com o
`ConfigCredentialsResolver` default ela é **ignorada** (sempre volta o `default`
do config). Só tem efeito com um resolver que entenda a chave (ex.:
`ModelCredentialsResolver`).

**Não há comando de `edit` nem de `delete`** — para alterar/apagar um template já
criado, use o painel ou o `TemplateManager` (veja a
[§9](#9-a-pasta-de-definições-e-a-manutenção-dos-templates)).

`template:create` lê `config('whatsapp-cloud.definitions_path')/<nome>.php` (ou
`--path=`), um arquivo que **retorna um array**:

```php
// database/whatsapp-templates/coordena_lembrete.php
use Callcocam\WhatsAppCloud\Templates\TemplateBuilder;

return TemplateBuilder::make('coordena_lembrete', 'pt_BR', 'UTILITY')
    ->body('Olá, {{1}}! Lembrete: {{2}}.', ['Maria', 'Reunião'])
    ->footer('Coordena')
    ->toArray();
```

Tags de publish: `whatsapp-cloud-config`, `whatsapp-cloud-migrations`,
`whatsapp-cloud-inertia`.

---

## 12. Config e `.env`

| Chave | Env | Default |
|---|---|---|
| `graph_version` | `WHATSAPP_CLOUD_GRAPH_VERSION` | `v21.0` |
| `app_id` | `WHATSAPP_CLOUD_APP_ID` | — |
| `app_secret` | `WHATSAPP_CLOUD_APP_SECRET` | — (**sem ele o webhook rejeita tudo**) |
| `verify_token` | `WHATSAPP_CLOUD_VERIFY_TOKEN` | — |
| `default.phone_number_id` | `WHATSAPP_CLOUD_PHONE_NUMBER_ID` | — |
| `default.access_token` | `WHATSAPP_CLOUD_ACCESS_TOKEN` | — |
| `default.waba_id` | `WHATSAPP_CLOUD_WABA_ID` | — |
| `webhook.enabled` / `.prefix` | `WHATSAPP_CLOUD_WEBHOOK_ENABLED` / `_PREFIX` | `true` / `webhooks/whatsapp/cloud` |
| `panel.enabled` / `.prefix` | `WHATSAPP_CLOUD_PANEL_ENABLED` / `_PREFIX` | `true` / `whatsapp/cloud/templates` |
| `panel.component` | `WHATSAPP_CLOUD_PANEL_COMPONENT` | `WhatsAppCloud/Templates/Index` |
| `panel.gate` | `WHATSAPP_CLOUD_PANEL_GATE` | — |
| `panel.currency` | `WHATSAPP_CLOUD_PANEL_CURRENCY` | — (null = número puro) |
| `panel.ui_token` | `WHATSAPP_CLOUD_PANEL_UI_TOKEN` | — |
| `model` | — | `WhatsAppNumber::class` |
| `templates` | — | `[]` |
| `definitions_path` | `WHATSAPP_CLOUD_DEFINITIONS_PATH` | — |

---

## 13. Receita de integração (ordem exata)

1. `composer require callcocam/laravel-whatsapp-cloud` (repo VCS privado — veja o
   README).
2. `php artisan whatsapp:install && php artisan migrate`.
3. `.env`: `WHATSAPP_CLOUD_APP_SECRET`, `WHATSAPP_CLOUD_VERIFY_TOKEN` e as
   credenciais (`default.*` **ou** via model/resolver).
4. Se multi-tenant: model + `WhatsAppCredentialsResolver` bindado no `register()`.
5. Declare as chaves em `config('whatsapp-cloud.templates')` e crie os templates na
   Meta (`whatsapp:template:create` ou o painel). Espere ficar `APPROVED`.
6. Aponte o webhook da Meta para `https://seu-app/{webhook.prefix}` e escute os
   eventos, se precisar.
7. Envie **dentro de um Job**, capturando `WhatsAppException` e respeitando
   `isTerminal()`.

## 14. Testes do próprio pacote

```bash
composer test        # pint --test + phpstan (larastan) + pest
composer lint        # pint --parallel
```

Testes usam `orchestra/testbench` + `Http::fake()`. Ao mexer no pacote, siga o
padrão de `tests/Feature/` (nunca bate na Meta de verdade).

---

## 15. Checklist anti-erro (revise antes de dar o trabalho por pronto)

- [ ] `sendTemplate($to, TemplateMessage::make($key, $params))` — nunca
      `sendTemplate($key, $params)`.
- [ ] Multi-tenant: `for($tenant)` **sempre com argumento** (`for()` pula o resolver).
- [ ] `isTerminal() === true` ⇒ **não re-tentar** (`isTemporaryRestriction()` é
      alias deprecado do mesmo valor).
- [ ] Chave enviada existe no `templates` do config (senão `CloudApiException`).
- [ ] Mexeu no corpo de uma definição? A ordem de `params` no registry ainda bate
      com os `{{1}}, {{2}}…` do arquivo (dessincronizar **não gera erro** — só
      manda o valor errado).
- [ ] `definitions_path` configurado antes de usar `whatsapp:template:create`
      (o default é `null`).
- [ ] Editar template = painel ou `TemplateManager::edit()`. Re-rodar
      `template:create` com nome+idioma existente **falha** na Meta.
- [ ] `templateApi()` exige `waba_id` nas credenciais.
- [ ] `WHATSAPP_CLOUD_APP_SECRET` setado, senão o webhook devolve 403 em tudo.
- [ ] Painel: um só modo (publish **ou** scaffold) — ambos gravam em
      `resources/js/pages/WhatsAppCloud/`.
- [ ] Painel: `flash` compartilhado no `HandleInertiaRequests::share()`, e
      `panel.gate` definido.
- [ ] Token nunca em código/commit/prop — só `.env` ou coluna encrypted.
