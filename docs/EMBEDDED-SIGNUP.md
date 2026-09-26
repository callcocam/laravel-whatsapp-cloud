# Conectar números pelo painel (Embedded Signup)

Em vez de copiar `phone_number_id`, `waba_id` e token à mão, o cliente clica em
**"Conectar WhatsApp"**, entra com o Facebook, escolhe (ou cria) a conta e o número,
e o pacote salva tudo sozinho em `whatsapp_numbers`.

## O que acontece por baixo

1. **Navegador** — o SDK do Facebook abre o popup (`FB.login` com o `config_id`).
   A Meta devolve um `code` e, por `postMessage`, o `waba_id`, o `phone_number_id`
   e o `business_id` escolhidos.
2. **Servidor** (`Onboarding\EmbeddedSignup`):
   1. troca o `code` pelo token do negócio (`app_id` + `app_secret`);
   2. confere que o número pertence à WABA (os ids vieram do navegador — só são
      aceitos se o token enxergar o número);
   3. inscreve o app na WABA (`subscribed_apps`) → os webhooks passam a chegar;
   4. registra o número na Cloud API (`/register`) **se houver PIN**;
   5. salva no model de credenciais e dispara `WhatsAppNumberConnected`.

Os passos 3 e 4 não derrubam o cadastro: se falharem, o número é salvo e a tela
mostra o aviso. Corrija e use o botão **↻ Atualizar** (reinscreve os webhooks).

## Configuração na Meta (uma vez)

Tudo no **mesmo app** que já envia/recebe o WhatsApp (o do `WHATSAPP_CLOUD_APP_ID`).

1. **developers.facebook.com/apps** → seu app → **Adicionar produto** →
   **Login do Facebook para Empresas**.
2. **Login do Facebook para Empresas → Modelos** → *"Configuração do cadastro
   incorporado do WhatsApp…"* → **Usar modelo**. Anote a **Identificação da
   configuração** — é o `config_id`.
3. **Login do Facebook para Empresas → Configurações** (ajustes de OAuth):
   - **Entrar com o SDK do JavaScript** → **Sim**;
   - **Domínios permitidos para o SDK do JavaScript** → o domínio do painel
     (HTTPS obrigatório; para testar local use um túnel — ngrok, Expose, Herd).

Com o app em modo **Desenvolvimento**, só quem tem função no app (admin/dev/testador)
consegue concluir o cadastro — suficiente para testar com o seu próprio número.
Para **clientes de fora** o app precisa ser **Tech Provider** (empresa verificada,
acesso avançado a `whatsapp_business_management` e `whatsapp_business_messaging`).

## Configuração no app Laravel

```bash
php artisan vendor:publish --tag=whatsapp-cloud-embedded-signup-migrations
php artisan vendor:publish --tag=whatsapp-cloud-inertia --force   # traz a página Numbers
php artisan migrate
npm run build
```

```dotenv
WHATSAPP_CLOUD_APP_ID=889546406946370
WHATSAPP_CLOUD_APP_SECRET=...
WHATSAPP_CLOUD_EMBEDDED_SIGNUP_CONFIG_ID=1634670798041792

# Opcional: PIN de 6 dígitos (verificação em duas etapas) para registrar cada
# número novo na Cloud API. Sem ele, a tela pede um PIN opcional no cadastro.
WHATSAPP_CLOUD_REGISTER_PIN=

# Opcional: restringir quem conecta números (cai no WHATSAPP_CLOUD_PANEL_GATE)
WHATSAPP_CLOUD_NUMBERS_GATE=manage-whatsapp
```

A página fica em **`/whatsapp/cloud/numbers`** (`WHATSAPP_CLOUD_NUMBERS_PREFIX`),
atrás de `['web', 'auth']` + o gate, se configurado.

## Usar os números conectados para enviar

Os números são salvos no model `whatsapp-cloud.model` (padrão `WhatsAppNumber`).
Para o `WhatsApp::for(...)` achá-los, binde o resolver por model:

```php
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Support\ModelCredentialsResolver;

$this->app->bind(WhatsAppCredentialsResolver::class, fn () =>
    new ModelCredentialsResolver(WhatsAppNumber::class, 'key'));
```

### Multi-tenant: ligar o número ao tenant

O pacote não sabe de qual tenant é o número. Ouça o evento e preencha a `key`:

```php
use Callcocam\WhatsAppCloud\Events\WhatsAppNumberConnected;

Event::listen(function (WhatsAppNumberConnected $event) {
    $event->number->update(['key' => auth()->user()->currentTeam->slug]);
});
```

Depois: `WhatsApp::for($team->slug)->sendTemplate(...)`.

## Token

O modelo de configuração da Meta define se o token expira (ex.: 60 dias). A data
fica em `token_expires_at` e a tela avisa quando faltam 7 dias ou menos — basta
**conectar de novo** o mesmo número (a linha é atualizada, a `key` é mantida).

## Página nativa

A página padrão usa o CSS autocontido do painel de templates. Para usar o design
system do app, crie a sua e aponte `WHATSAPP_CLOUD_NUMBERS_COMPONENT` para ela —
as props são `numbers`, `signup` (`app_id`, `config_id`, `graph_version`, `ready`,
`pin_configured`), `warnings`, `loadError` e `panelUrl`.
