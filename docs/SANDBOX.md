# Sandbox — ensaiar a conversa inteira sem um celular

O sandbox troca o fio que vai pra Meta por um simulador. O código do seu app **não muda**: ele continua
chamando `WhatsApp::for()->sendTemplate(...)` e continua recebendo `WhatsAppMessageReceived`. Só o
transporte é outro.

Do outro lado, quando você responde na tela, o simulador monta o payload no formato da Meta, **assina com o
HMAC de verdade** e entrega **pela rota de webhook real**. Seus listeners rodam exatamente como em produção.

**Não é um mock de conveniência.** Ele diz "não" nos mesmos lugares em que a Meta diz não — a janela de 24
horas acima de tudo. Um app que passa aqui de fato tratou os modos de falha, em vez de apenas nunca tê-los
encontrado.

---

## O que ele resolve

Hoje o único teste real é o botão "Enviar teste" do painel: ele dispara uma mensagem para um número real e
prova que o template foi aprovado. Só isso. Não prova que o cliente respondeu, que o webhook chegou, que a
resposta foi roteada pro responsável, nem que o app sobrevive quando a janela de 24h fecha no meio do fluxo.

E, principalmente: **você consegue ensaiar um template que ainda não existe na Meta.**

O corpo do template vem do **arquivo de definição local** (`{definitions_path}/<nome>.php`), não da Meta.
Isso importa porque `whatsapp:template:create` é **one-way** — re-submeter o mesmo nome+idioma falha, e não
existe comando de `edit` nem de `delete`. O sandbox é a bancada onde você fecha o texto, os botões e o fluxo
inteiro **antes** de queimar o nome.

---

## Ligar

```bash
php artisan vendor:publish --tag=whatsapp-cloud-sandbox-migrations
php artisan vendor:publish --tag=whatsapp-cloud-sandbox   # a página Vue
php artisan migrate
npm run build
```

```dotenv
WHATSAPP_CLOUD_DRIVER=sandbox
WHATSAPP_CLOUD_APP_SECRET=qualquer-coisa   # no sandbox pode ser qualquer string
QUEUE_CONNECTION=sync                      # ou deixe um worker rodando (veja abaixo)
```

```bash
php artisan config:clear && php artisan queue:restart
```

A tela fica em **`/whatsapp/cloud/sandbox`** (middleware `['web', 'auth']`).

> **O driver tem que vir do `.env`. Nunca de um toggle em runtime.**
> Se um listener seu enfileira, o envio sai num **worker**, que é outro processo e lê o próprio `.env`. Um
> driver trocado em runtime não chega lá — e a mensagem vai **pra Meta de verdade**, pra um número real,
> cobrando. Por isso o driver é config, e por isso `config:clear` + `queue:restart` fazem parte do ritual.

O `SandboxTransport` **se recusa a bootar em produção**, sem flag de override. E a tela **não se registra**
com o driver `cloud` — um simulador em cima do fio real seria pior do que simulador nenhum.

---

## Os quatro fluxos

O operador **não tem maquinaria própria**: ele é outro participante. Dois chats, um motor.

| Fluxo | Como se encena |
|---|---|
| Sistema → cliente → resolve | Dispare o template (pelo app ou pelo seletor da tela). Responda como o cliente. |
| Sistema → cliente → **responsável analisa** → sistema → cliente | Idem, mas o listener do app manda pro participante "operador" — que é outro chat. Responda como ele. |
| Responsável inicia → sistema → cliente | Abra a rodada pelo chat do operador. |
| Sistema confirma com o responsável → responde ao cliente | Mesmo motor. |

**Operador pelo painel do seu app?** O sandbox **não** desenha uma tela de aprovação falsa. Você abre o seu
app de verdade ao lado, com `driver=sandbox`, aprova lá, e a mensagem aparece no chat do cliente no sandbox.
É um teste melhor do que qualquer simulação.

---

## As três formas que todo mundo confunde

Um toque num botão **não** produz sempre o mesmo webhook. Um app que trata só uma delas ignora as outras em
silêncio — e o sandbox é onde isso aparece:

| Onde o botão estava | O que chega no webhook |
|---|---|
| Num **template** (quick reply) | `type: button` → `button.text` / `button.payload` |
| Num **interactive** (botões) | `type: interactive` → `interactive.button_reply` |
| Numa **lista** (`sendInteractive`) | `type: interactive` → `interactive.list_reply` |

Todos carregam `context.id` — o wamid da mensagem respondida. **Sem ele não há como saber o que foi
respondido**, e é isso que amarra o handoff.

Na tela, cada opção da bolha já sabe qual dos três webhooks ela dispara: clicar num botão interativo
manda `button_reply`, numa linha de lista manda `list_reply`. Nada disso depende de o app usar o
`sendInteractive()` do pacote — ele só monta LISTA, e um app que monta o próprio envelope de botões
(porque precisa escolher os ids das opções) é atendido igual.

> `button.payload === button.text`. A Meta só deixa escolher o payload quando o envio manda
> `components[{type: button, sub_type: quick_reply, parameters: [{type: payload}]}]` — coisa que este pacote
> nunca faz. Codar contra um payload diferente seria codar contra uma mensagem que produção não manda.

Os formatos exatos estão em [`tests/fixtures/webhooks/`](../tests/fixtures/webhooks/). **Eles são snapshots
do nosso próprio output, não capturas da Meta** — pegam drift acidental, não divergência. Quando alguém
capturar um payload real, é contra eles que se compara. Esse diff é a única coisa que mantém o sandbox
honesto.

---

## A janela de 24 horas

Fora dela, **só template passa**. Texto livre e interativo voltam como **131047**, que é *terminal* — o app
deve logar e parar, não deixar a fila retentar pra sempre.

Na tela: a bolinha ao lado de cada participante diz se a janela está aberta. A aba **Falhas** tem um botão
que a fecha **agora**, em vez de você esperar um dia.

A janela abre quando a pessoa responde. Só então.

---

## Injeção de falhas

A aba **Falhas** arma um erro no próximo envio. Ele dispara **uma vez** e se desarma — uma falha grudenta
transformaria um ensaio deliberado num sandbox que só parece quebrado.

O código de cada uma é o que a Meta devolveria de verdade, e `isTerminal()` aqui responde **o mesmo** que em
produção (é a mesma exception).

| Falha | Código | |
|---|---|---|
| Janela de 24h fechada | 131047 | terminal |
| Destinatário não recebe | 131026 | terminal |
| Template pausado | 132015 | terminal |
| Bloqueio por política | 368 | terminal |
| **Rate limit** | **80007** | **retentável** |
| Problema de pagamento | 131042 | retentável ⚠ |
| Erro interno da Meta | 131000 | retentável |
| Conexão morreu | — | retentável |

Os **retentáveis** são metade do valor do sandbox: são eles que exercitam o backoff da sua fila, o ramo que o
caminho feliz nunca toca.

> ⚠ **131042** (conta sem forma de pagamento) hoje é classificado como retentável — sua fila retentaria pra
> sempre numa conta impagável. É um bug pré-existente do pacote, fora do escopo do sandbox. Está numa issue.

---

## Filas

O sandbox funciona com fila, e fica **mais** realista assim (a mensagem aparece com atraso, como na vida
real). Duas coisas a saber:

- O worker é outro processo. Ele lê o `.env` — por isso o driver **tem** que estar lá, e por isso
  `queue:restart` depois de trocar.
- O inspector lista os listeners **registrados**, não os executados. Um `ShouldQueue` aparece na lista, mas
  roda noutro processo: o efeito dele surge depois, pelo polling. E se ele estourar, a exception **não**
  aparece no inspector — ela está no worker.

Com `QUEUE_CONNECTION=sync` tudo roda inline e o inspector vê tudo, inclusive as exceptions.

---

## O que o sandbox NÃO exercita

Seja honesto sobre os buracos, senão eles viram surpresa em produção:

- **O middleware do webhook.** O simulador invoca o `WebhookController` direto (veja o porquê abaixo). Se
  você plugou middleware na rota do webhook — um resolvedor de tenant pelo `phone_number_id`, digamos — ele
  **não roda** aqui.
- **A aprovação de template pela Meta.** O sandbox mostra o template como ele *será*; se a Meta vai aprovar o
  texto é outra conversa (as guard-rails do `TemplateBuilder` cobrem os motivos mais comuns de rejeição).
- **Mídia.** Uma imagem inbound vem com um `media.SANDBOX.<id>` que não existe. Um app que baixa mídia
  cegamente vai perceber aqui — de propósito.
- **Multi-tenant.** O sandbox opera no tenant `default`.

### Por que o simulador invoca o controller direto

Duas razões, e as duas doem:

1. `Kernel::handle()` faz `catch (Throwable)` e devolve um 500. A exception de um listener seu **sumiria**
   dentro do exception handler — matando justamente o que o inspector existe pra mostrar.
2. `Kernel::handle()` também faz `app()->instance('request', $fake)`, que re-liga o request no container
   inteiro (`UrlGenerator`, `AuthManager`…). A resposta Inertia externa — o `back()`, os props
   compartilhados, o Ziggy — passaria a enxergar o request do **webhook**. Sob Octane, vazaria pro request do
   próximo usuário.

O custo é o middleware acima. O `WebhookController` não depende de nenhum (só lê header, body e `all()`), mas
o buraco é real e está documentado.

---

## Rodando o sandbox dentro deste repositório

Não precisa de um app host pra ver o motor funcionando. O `testbench.yaml` sobe um Laravel de mentira já com
`driver=sandbox` — nada que ele fizer pode chegar à Meta.

```bash
vendor/bin/testbench migrate --force
vendor/bin/testbench tinker      # dirija o sandbox pelo REPL (veja os exemplos abaixo)
vendor/bin/testbench serve       # a tela — precisa dos assets compilados pelo app host
```

> A **tela** precisa de um build Vite, e este pacote não tem tooling de front (de propósito). Pra ver a
> interface, publique a página num app host (`vendor:publish --tag=whatsapp-cloud-sandbox`) e rode o
> `npm run build` de lá. O **motor**, esse você exercita inteiro pelo tinker.

---

## Dirigindo pelo código

A tela é uma janela pro motor, não o motor. Tudo que ela faz, um teste faz:

```php
use Callcocam\WhatsAppCloud\Sandbox\Sandbox;
use Callcocam\WhatsAppCloud\Facades\WhatsApp;

$sandbox = app(Sandbox::class);

$maria   = $sandbox->participant('5548999999999', 'Maria', 'customer');
$suporte = $sandbox->participant('5548911111111', 'Suporte', 'operator');

// O app envia normalmente — o transporte captura.
WhatsApp::for()->sendTemplate($maria->wa_id, TemplateMessage::make('assignment', [...]));

// A pessoa responde. O webhook entra pela rota real, assinado, e seus listeners rodam.
$sandbox->tapTemplateButton($maria, 'Aceitar', $wamid);   // → type: button
$sandbox->tapReplyButton($maria, '1', 'Novo lançamento', $wamid); // → interactive.button_reply
$sandbox->pickListRow($maria, '2', 'Alimentação', $wamid);        // → interactive.list_reply
$sandbox->reply($suporte, 'Aprovado');

// Ensaiar o que dá errado.
$maria->closeWindow();      // → o próximo texto livre estoura 131047
$maria->arm('rate_limited'); // → o próximo envio estoura 80007 (retentável)

// Avançar a entrega (a Meta manda isso num webhook separado).
$sandbox->advanceStatus($message, 'read');
```

O `SimulatedWebhook` devolvido carrega o status HTTP, os listeners e — se um listener estourou — **a própria
exception**.
