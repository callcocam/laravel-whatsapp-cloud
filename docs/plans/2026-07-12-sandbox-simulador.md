# Sandbox / Simulador de WhatsApp

Branch: `feat/sandbox-simulador` · Data: 2026-07-12 · Status: em implementação

---

## Contexto

Hoje o único jeito de testar o pacote contra a realidade é o botão **"Enviar teste"** do painel de templates
([TemplatePanelController::send()](src/Http/Controllers/TemplatePanelController.php#L105-L130)), que dispara uma
mensagem real para um número real. Ele prova que o template foi aprovado — e só. Não prova nada do que importa de
verdade:

- o cliente **respondeu**; o webhook chegou e os listeners do app rodaram?
- a resposta foi roteada pro responsável, ele decidiu, e o sistema voltou a falar com o cliente?
- a janela de 24h fechou no meio do fluxo — o app tratou o `131047` ou entrou em retry infinito?
- o clique num botão de template gera um payload **diferente** do clique numa lista interativa. O app trata os dois?

Nada disso é testável hoje sem um número real, um celular na mão e paciência. O resultado é que os fluxos de
handoff (cliente ↔ sistema ↔ responsável) só são exercitados em produção.

**O objetivo:** um sandbox que substitui o transporte da Meta por um simulador fiel, com uma tela com cara de
WhatsApp onde a equipe encena a conversa inteira — e onde cada envio, cada webhook, cada evento e cada falha ficam
visíveis. O código do app **não muda**: ele continua chamando `WhatsApp::for()->sendTemplate(...)` e continua
recebendo `WhatsAppMessageReceived`. Só o fio que sai pra Meta é trocado.

---

## O que o sandbox é (e o que não é)

**É** um transporte alternativo + um gerador de webhooks byte-fiéis ao formato da Meta, entregues pela rota de
webhook real, com assinatura HMAC real. Os listeners do app rodam idênticos a produção.

**Não é** um mock de conveniência. Se o payload simulado diverge do que a Meta manda, o sandbox perde a razão de
existir. Os payloads são o produto.

**Não simula a tela do host.** No caso "o responsável aprova pelo painel do Coordena", o sandbox não desenha uma
tela de aprovação falsa — você abre o **Coordena de verdade** ao lado, com `driver=sandbox`, aprova lá, e a
mensagem aparece no chat do cliente no sandbox. Isso é um teste end-to-end melhor do que qualquer simulação.

---

## Os quatro fluxos e como o sandbox cobre cada um

| Fluxo | Como se encena no sandbox |
|---|---|
| **Sistema → cliente → resolve** | Dispara o template pelo app (ou pelo botão da UI). A bolha aparece no chat do cliente. Você responde **como o cliente**. O webhook entra, o listener do app roda, a resposta do sistema aparece. |
| **Sistema → cliente → responsável analisa → sistema → cliente** | Idem, mas o listener do app manda pro **participante "operador"** — que é outro chat no sandbox. Você responde como ele. O sistema fecha com o cliente. Os três chats ficam lado a lado. |
| **Responsável inicia → sistema → cliente** | Você abre a rodada pelo chat do operador (ou pelo Coordena real, se a origem for a tela). |
| **Sistema confirma com o responsável → responde ao cliente** | Mesmo motor. O operador é um participante como outro qualquer. |

**Operador pelo WhatsApp** = um participante do sandbox, com chat próprio.
**Operador pelo painel** = o Coordena real rodando ao lado, em `driver=sandbox`.
Um único motor cobre os dois — não precisa de coluna `channel` nem de tela de aprovação falsa.

---

## Arquitetura

### 1. O seam: `MessageTransport`

Os dois caminhos de envio do pacote montam o mesmo envelope e dão POST no mesmo lugar
(`{phone_number_id}/messages`), com `handle()` e `request()` **duplicados** entre as classes:

- [CloudApiClient::send()](src/CloudApiClient.php#L105-L142) — data plane
- [TemplateManager::send()](src/Templates/TemplateManager.php#L113-L140) — control plane, usado pelo painel

Extrair um contrato único:

```php
namespace Callcocam\WhatsAppCloud\Contracts;

interface MessageTransport
{
    /**
     * @param  array<string,mixed>  $envelope  the full /messages body
     * @return array<string,mixed>  Graph-shaped response: {messages:[{id:"wamid..."}]}
     */
    public function postMessage(WhatsAppCredentials $credentials, array $envelope): array;
}
```

Implementações: `Transport/CloudApiTransport` (absorve os dois `handle()`/`request()` duplicados) e
`Sandbox/SandboxTransport`.

**Sem BC break.** As credenciais entram como parâmetro de método, então os construtores só ganham um 5º parâmetro
**opcional no fim** (`?MessageTransport $transport = null`), resolvido tarde (`$this->transport ??= app(...)`).
Nenhum teste existente muda — se algum precisar mudar, o refactor está errado.

O envelope continua sendo montado **nos callers**: `CloudApiClient` manda `recipient_type: individual` e
`TemplateManager` não. Unificar isso quebraria
[TemplateManagerTest.php:57-64](tests/Feature/TemplateManagerTest.php#L57-L64). O transport só posta.

`create/edit/all/delete/costs` do `TemplateManager` **continuam HTTP real** mesmo em sandbox: templates são reais,
só a **entrega** é simulada.

> **Crítico:** `TemplateManager::send()` **tem** que passar pelo transport. É o que o botão "Enviar teste" do painel
> chama. Se ficar de fora, o painel dispara WhatsApp real enquanto o app acha que está em sandbox.

### 2. O driver

```php
// config/whatsapp-cloud.php  (chaves novas de topo — mergeConfigFrom é shallow)
'driver' => env('WHATSAPP_CLOUD_DRIVER', 'cloud'),   // cloud | sandbox
'sandbox' => [
    'display_phone_number' => env('WHATSAPP_CLOUD_SANDBOX_DISPLAY_NUMBER', '+55 11 90000-0000'),
    'prefix' => env('WHATSAPP_CLOUD_SANDBOX_PREFIX', 'whatsapp/cloud/sandbox'),
    'middleware' => ['web', 'auth'],
],
```

`bind` (não `singleton`) em [WhatsAppCloudServiceProvider::register()](src/WhatsAppCloudServiceProvider.php#L20-L47),
resolvendo o driver a cada `make` — senão um `config()->set()` em teste vira no-op contra os singletons já
resolvidos (dor que [WhatsAppManagerTest.php:62-69](tests/Feature/WhatsAppManagerTest.php#L62-L69) já contorna com
`forgetInstance()`).

### 3. O simulador de webhook — **invoca o controller direto, não reentra no Kernel**

```php
// src/Sandbox/WebhookSimulator.php
$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$signature = 'sha256='.hash_hmac('sha256', $body, config('whatsapp-cloud.app_secret'));

$request = Request::create($webhookUrl, 'POST', server: [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_X_HUB_SIGNATURE_256' => $signature,
], content: $body);

try {
    $status = app(WebhookController::class)->store($request)->getStatusCode();
} catch (Throwable $e) {
    $failure = $e;   // ← a exception do listener do app CHEGA aqui. Isso é a feature.
}
```

Mesma técnica de assinatura do helper `postWebhook()` de
[WebhookTest.php:14-27](tests/Feature/WebhookTest.php#L14-L27).

**Por que não `Kernel::handle()`** (era minha ideia inicial, está errada):
- `Kernel::handle()` faz `catch (Throwable)` → **engole a exception do listener** e devolve um 500 opaco. Isso mata
  exatamente a aba de falhas que ele deveria alimentar.
- `sendRequestThroughRouter()` faz `$app->instance('request', $fake)`, que dispara rebindings do core
  (`UrlGenerator::setRequest`, `AuthManager::setRequest`). O `back()` do controller externo, os `share()` do
  `HandleInertiaRequests` e o Ziggy passam a ver o request do **webhook**. Sob Octane, vaza pro request seguinte.
- `Router::dispatch()` também suja `Router::$currentRequest` — e **não tem setter público** pra restaurar.

**O que se perde:** o `webhook.middleware` do host (default `['api']`). O `WebhookController` não depende de nenhum
deles (só lê header, body e `all()`). Documentar: *se você plugou middleware no webhook (ex.: resolver de tenant
pelo PNID), o sandbox não o exercita.*

**Preflight obrigatório:** com `app_secret` vazio, `hasValidSignature()`
([WebhookController.php:101-113](src/Http/Controllers/WebhookController.php#L101-L113)) devolve **403 silencioso** —
o dev clica "responder" e nada acontece, sem erro. O simulador tem que estourar uma exception humana antes disso.

### 4. `Sandbox/InboundPayloadFactory` — o produto de verdade

Payloads byte-fiéis à Meta. Cada um precisa de `messaging_product`, `metadata.phone_number_id` (o **PNID real** das
credenciais — se for fake, o `ModelCredentialsResolver` do host não acha o tenant e o listener morre),
`metadata.display_phone_number`, `contacts[].profile.name`, e — o que eu tinha esquecido — **`context`**:

```json
"context": { "from": "<display_phone_number>", "id": "<wamid da mensagem respondida>" }
```

Sem `context`, o listener do host não consegue correlacionar o clique com o que ele enviou, e o payload é inútil.

Métodos: `text()`, `buttonReply()` (`interactive.button_reply`), `listReply()` (`interactive.list_reply`),
`templateButton()` (`type: button`, com `button.text`/`button.payload` — **shape diferente** do interactive),
`status()` (`sent|delivered|read|failed`, com `errors[]` no caso de `failed`).

> Nota de fidelidade: `button.payload` só é developer-defined quando o envio manda
> `components[{type:button, sub_type:quick_reply, parameters:[{type:payload}]}]` — o que o `CloudApiClient` **nunca
> faz** hoje. Logo, no sandbox, `payload === text`. Codar o host contra outra coisa seria codar contra um payload
> que produção não manda.

### 5. `Sandbox/SandboxTransport`

- Grava a mensagem, devolve `{"messages":[{"id":"wamid.SANDBOX.<ulid>"}]}` (prefixo greppável, pro caso de um
  wamid de sandbox vazar pro banco de produção do host).
- **Janela de 24h de verdade:** se o envelope não é `type: template` e não houve inbound nas últimas 24h, estoura
  `CloudApiException` com `errorCode: 131047` — que já é terminal em
  [TERMINAL_CODES](src/Exceptions/CloudApiException.php#L42-L56). É o erro nº 1 em produção e hoje ninguém consegue
  testá-lo.
- **Injeção de falhas** (coluna `faults` json na conversa — precisa ser legível pelo processo do worker, então não
  pode ser sessão): `131047`, `132015` (template pausado), `131026`, `368`, **`80007` (retryable — pra exercitar o
  backoff da fila, metade do valor do sandbox)**, HTTP 500 e `ConnectionException`.
- Sempre estoura `CloudApiException` (não `WhatsAppException`) com `errorCode` preenchido — é o que `isTerminal()` e
  o `run()` do painel esperam.

### 6. O corpo do template — pré-requisito, não detalhe

[MetaTemplate](src/Templates/MetaTemplate.php) tem `name`, `language`, `category`, `bodyParams`. **Não tem o texto
do corpo nem os botões.** O envelope enviado também não. Logo, com o que existe hoje, **é impossível desenhar a
bolha do template com texto real e botões clicáveis** — e sem botão clicável não existe o fluxo.

Solução: no envio, o `SandboxTransport` busca os componentes via
`WhatsApp::templateApi()->getByName($name)` (control plane, HTTP real), cacheia por `(waba, name, language)` e
**grava um snapshot na linha da mensagem** (`template_components` json) — a bolha renderiza pra sempre a partir da
linha, sem re-fetch.

Consequência aceita: **o sandbox não é offline** — precisa de WABA + token válidos pra renderizar templates. Se o
fetch falhar, degrada pra `name` + params crus, com um aviso no inspector.

---

## Modelo de dados — 2 tabelas

Um telefone tem exatamente uma thread com o número do negócio, então participante e conversa são a mesma linha.

**`whatsapp_sandbox_conversations`**
`id, phone_number_id, wa_id, name, role (customer|operator|other — só rótulo de UI), window_expires_at, faults (json), timestamps`
— unique em `(phone_number_id, wa_id)`

**`whatsapp_sandbox_messages`**
`id, conversation_id, direction (outbound|inbound), wamid, type, envelope (json — o payload EXATO enviado),
inbound_payload (json — o webhook EXATO gerado), template_name, template_components (json), rendered_text,
delivery_status (sent|delivered|read|failed), error_code, meta (json — listeners, exception), timestamps`

Migration + publish com **tag própria** (`whatsapp-cloud-sandbox-migrations`), não a existente. O `SandboxTransport`
faz preflight de `Schema::hasTable()` com erro humano — senão o dev leva um "no such table" cru.

**Cortado de propósito:** tabela de eventos (cabe em `messages.meta`, e ela nunca capturaria um listener
`ShouldQueue`, que roda noutro processo), `channel`, `color` (hash do `wa_id` em JS), `tenant_key` (o painel nem é
multi-tenant — [tenant() retorna null hard-coded](src/Http/Controllers/TemplatePanelController.php#L232)),
`conversations.status` (derivável de `window_expires_at`).

---

## Invariantes de segurança (o que dá muito errado se esquecer)

1. **O driver é env/config, JAMAIS runtime.** Um listener `ShouldQueue` do host envia de **outro processo**, que lê
   o `.env` — um toggle de driver na UI não chega lá, e a mensagem vai **pra Meta de verdade**, pra um número real,
   cobrando. Ligar o sandbox = `WHATSAPP_CLOUD_DRIVER=sandbox` no `.env` + `config:clear` + `queue:restart`.
2. **`SandboxTransport` estoura no construtor se `app()->isProduction()`.** Sem flag de override. O inverso —
   sandbox ligado em produção — transforma mensagens reais em linhas de banco: o cliente nunca recebe nada e o app
   acha que enviou. Silencioso e caríssimo.
3. **As rotas do sandbox derivam do driver**, não de um `enabled` isolado:
   `if (config('...driver') !== 'sandbox' || app()->isProduction() || ! class_exists(Inertia::class)) return;`
   Uma UI de sandbox no ar com `driver=cloud` dispara WhatsApp real.
4. **O source Vue do sandbox NÃO pode morar em `resources/js/pages/WhatsAppCloud/`.** O
   [publishes() de diretório](src/WhatsAppCloudServiceProvider.php#L137-L139) é recursivo — a tag
   `whatsapp-cloud-inertia` arrastaria o sandbox pro bundle de produção do host. Fonte em
   `resources/js/sandbox/`, publicada por tag própria (`whatsapp-cloud-sandbox`), e o path novo entra em
   `inertia.testing.page_paths` ([TestCase.php:43](tests/TestCase.php#L43)).

---

## UI — autônoma, CSS próprio, sem shadcn/Tailwind

Página `WhatsAppCloud/Sandbox/Index.vue`, autocontida. **Copiar, não importar**, de
[Templates/partials/](resources/js/pages/WhatsAppCloud/Templates/partials/): `format.js` e `panel.css` são
**arquivos publicados** — depois do `vendor:publish` eles pertencem ao host e podem ter sido reescritos. Importar
deles é depender de código que não é mais nosso.

O que vale copiar: `formatWa()`/`escapeHtml()` (`*bold*` `_italic_` `~strike~`, format.js:5-20), `parseTemplate()`
(components da Meta → header/body/footer/buttons, :71-110), `toasts.js`, e o bloco de design tokens do
`panel.css:4-98`. As bolhas do `panel.css:798-895` estão escopadas sob **`.wa-panel-dialog`** (não `.wa-panel`) —
copiar e reescopar sob `.wa-sandbox`. `WhatsAppPreview.vue` **não serve**: é preview estático outbound, com
`12:00 ✓✓` hardcoded e botões em `<div>` não-clicáveis. Escrever `Sandbox/partials/Bubble.vue` reaproveitando o
markup e os nomes de classe.

**Três painéis:**
1. **Participantes** — lista de conversas, rótulo do papel (Cliente / Operador), estado da janela de 24h.
2. **Chat** — cara de WhatsApp: wallpaper, bolhas verdes/brancas com rabinho, ticks ✓ / ✓✓ / ✓✓ azul, separadores
   de data, bolha de template com header/footer e **botões clicáveis** (o clique dispara o webhook `type: button`
   real), lista interativa. Composer no rodapé: você digita **como aquele participante**.
3. **Inspector**, duas abas:
   - **Rede** — por mensagem: o envelope exato enviado, o JSON exato do webhook gerado, o header HMAC, o status
     HTTP, os listeners registrados (`Event::getRawListeners()`) e a **exception que estourou**, se estourou.
   - **Falhas** — os toggles de injeção + "fechar a janela de 24h agora".

**Cortado:** a aba "Timeline" com raias. O chat já é a timeline — era o item mais caro e o menos útil.

Atualização por **polling** (`GET /state?after=<id>`, rota JSON pura, ~1.5s). Sem Reverb, sem Echo — o pacote
continua sem dependências de front.

---

## Fases, com marco verificável em cada uma

**Fase 0 — Seam de transporte.** Refatoração pura, zero feature.
→ *Marco: `composer test` passa **sem alterar uma linha de teste existente**. Se precisou mexer em
[CloudApiClientTest](tests/Feature/CloudApiClientTest.php), houve BC break — voltar.* Mais um teste novo que troca
o binding por um spy e prova que `CloudApiClient::sendTemplate()` **e** `TemplateManager::send()` passam pelo
**mesmo** transport.

**Fase 1 — `InboundPayloadFactory`.** Sem DB, sem UI, sem HTTP.
→ *Marco: cada payload do factory entra pelo `postWebhook()` **real** de
[WebhookTest.php](tests/Feature/WebhookTest.php) (HMAC e tudo) e o `WhatsAppMessageReceived` chega com o `context.id`,
o `phoneNumberId` certo e o shape certo — inclusive `type: button` (template) ≠ `type: interactive` (button_reply).*
**Se esse teste passa, a fidelidade está provada antes de existir uma tabela.** Ficam também os primeiros fixtures
de webhook em `tests/fixtures/webhooks/` — o pacote hoje não tem nenhum.

**Fase 2 — `WebhookSimulator`.**
→ *Marco: um listener que estoura → o `SimulatedWebhook` **carrega a exception** (não engoliu). E, depois de chamar
o simulador, `app('request')`, `url()->current()`, `Route::current()` e `back()` continuam íntegros.* Esse segundo
teste é a prova antirregressão da decisão de não reentrar no Kernel.

**Fase 3 — `SandboxTransport` + migrations.** O produto, headless.
→ *Marco, 100% sem UI: com `driver=sandbox`, `WhatsApp::for()->sendSessionText()` estoura `CloudApiException` com
`errorCode 131047` e `isTerminal() === true`; um `InboundPayloadFactory::text()` entregue pelo simulador abre a
janela; o mesmo `sendSessionText` agora passa e grava a linha.* **Aqui o produto já existe** — a UI é só uma janela
pra ele.

**Fase 4 — UI Inertia + polling.**
→ *Marco: `assertInertia()->component('WhatsAppCloud/Sandbox/Index')`; `GET /state` devolve JSON; a rota **não**
registra com `driver=cloud` nem sob `isProduction()`.*

**Fase 5 — Docs.** `docs/SANDBOX.md`, entrada `Sandbox/` no mapa de arquivos do `docs/AGENTS.md`, seção no
`GUIA-DO-USUARIO.md`, `README`, `CHANGELOG`, e os avisos dos invariantes acima.

---

## Verificação end-to-end (o teste de aceitação da equipe)

Num app host (Coordena) com `WHATSAPP_CLOUD_DRIVER=sandbox`, `QUEUE_CONNECTION=sync` e um `app_secret` qualquer:

1. Abrir `/whatsapp/cloud/sandbox`, criar dois participantes: "Maria" (customer) e "Suporte" (operator).
2. Disparar o template real do app pro chat da Maria — a bolha aparece com o corpo e os botões de verdade.
3. Clicar num botão da bolha **como a Maria** → webhook `type: button` assinado entra pela rota real → o listener do
   app roda → a aba Rede mostra o payload, os listeners e o status 200.
4. O listener manda pro Suporte → a bolha aparece no chat dele → responder como o Suporte → o sistema fecha com a
   Maria. **Os três atores, o ciclo inteiro, sem um celular.**
5. Em Falhas: fechar a janela de 24h → o próximo texto livre estoura `131047`, e a aba Rede mostra o app tratando
   (ou não) o erro terminal.
6. Trocar pra `QUEUE_CONNECTION=redis` com um worker rodando → a mensagem aparece com atraso, via polling. Prova
   que o caminho enfileirado (o de produção) funciona.

E `composer test` (pint + phpstan level 6 + pest) verde em todas as fases.

---

## Adiado (de propósito)

- **Roteiros scriptados** (`whatsapp:sandbox:run <cenario>`, com asserções) — a evolução natural, mas só depois que
  o motor estabilizar. É o que transforma o sandbox em suíte de regressão dos fluxos.
- **`WhatsApp::fake()` + `FakeTransport`** — seria a terceira implementação do mesmo seam. `Http::fake()` já resolve
  o teste do host hoje.
- **Multi-tenant no sandbox** — o painel de templates nem é multi-tenant ainda.
- **Stub shadcn-vue** da tela — decidido: uma versão só, autônoma.

## Bug lateral encontrado (issue separada)

`131042` (*business eligibility* — conta sem forma de pagamento) **não está** em
[TERMINAL_CODES](src/Exceptions/CloudApiException.php#L42-L56). É dos erros mais comuns em conta nova, e hoje o
pacote o classifica como retryable → **a fila do host retenta pra sempre numa conta impagável.** Não faz parte
deste plano; abrir issue.
