# Configurar tudo pelo painel (assistente)

A tela **`/whatsapp/cloud/setup`** leva uma instalação do zero até um número
funcionando, sem editar o `.env`. Tudo o que ela salva vai para a tabela
`whatsapp_settings`, **criptografado com a `APP_KEY`**.

## Instalar

```bash
php artisan whatsapp:install        # já publica a migration de settings
php artisan vendor:publish --tag=whatsapp-cloud-embedded-signup-migrations
php artisan migrate
npm run build
```

Antes de abrir a tela, **defina um gate** — ela lê e exporta segredos. Sem gate,
a tela (e a de números conectados) **só abre no ambiente `local`**; em produção
responde 403 dizendo qual variável falta:

```dotenv
WHATSAPP_CLOUD_SETUP_GATE=manage-whatsapp   # cai no WHATSAPP_CLOUD_PANEL_GATE se vazio
```

```php
Gate::define('manage-whatsapp', fn ($user) => $user->is_admin);
```

## Os 5 passos

| Passo | O que você faz | O que o sistema faz sozinho |
|---|---|---|
| **1. App da Meta** | Cola o App ID e o App Secret (Configurações do app → Básico) | Confere o par na Meta **antes** de salvar e mostra o nome do app |
| **2. Webhook** | Clica em "Registrar automaticamente" | Gera o verify token, salva e registra a URL de callback no app pela API (`/{app_id}/subscriptions`) |
| **3. Embedded Signup** | Cria a configuração na Meta pelo modelo e cola o `config_id` | Mostra o domínio exato para liberar no SDK do JavaScript |
| **4. Número** | "Conectar com o Facebook" **ou** "Cadastrar manualmente" | Confere que o número pertence à WABA, inscreve o app nos webhooks e define o número padrão |
| **5. Teste** | Informa o seu celular e responde a mensagem | Envia o `hello_world` e mostra a resposta que chegou pelo webhook |

**🩺 Validar tudo** refaz cada verificação na Meta (credenciais, webhook ativo e
apontando para cá, token válido e com permissão, número na WABA, `config_id`) e
mostra ✅/❌ com o motivo.

### O que a Meta não deixa automatizar

Não existe API para **criar o app**, **ler o App Secret** nem **criar a
configuração do Embedded Signup**. A tela guia esses passos com links diretos; todo
o resto é automático.

## `.env` ou painel?

Os dois funcionam. Um valor salvo no painel **vence** o do `.env`; apagar no
painel devolve o controle ao `.env`. A tela mostra a origem de cada valor
(*painel* / *.env*).

| Chave no painel / JSON | Equivale a |
|---|---|
| `app_id` | `WHATSAPP_CLOUD_APP_ID` |
| `app_secret` | `WHATSAPP_CLOUD_APP_SECRET` |
| `verify_token` | `WHATSAPP_CLOUD_VERIFY_TOKEN` |
| `graph_version` | `WHATSAPP_CLOUD_GRAPH_VERSION` |
| `embedded_signup_config_id` | `WHATSAPP_CLOUD_EMBEDDED_SIGNUP_CONFIG_ID` |
| `register_pin` | `WHATSAPP_CLOUD_REGISTER_PIN` |
| `default_phone_number_id` | `WHATSAPP_CLOUD_PHONE_NUMBER_ID` |
| `default_waba_id` | `WHATSAPP_CLOUD_WABA_ID` |
| `default_access_token` | `WHATSAPP_CLOUD_ACCESS_TOKEN` |

Para ignorar a tabela por completo: `WHATSAPP_CLOUD_SETTINGS_STORE=false`.

> **Filas e Octane** leem as configurações ao iniciar. Depois de salvar no painel,
> rode `php artisan queue:restart` (e recarregue o Octane), como faria ao editar o
> `.env`. O `config:cache` nunca grava os valores do painel no cache.

## Exportar / importar (JSON)

**⬇ Exportar** baixa a configuração efetiva (painel + `.env`) — útil para levar
de um projeto para outro:

```json
{
    "whatsapp-cloud": {
        "app_id": "889546406946370",
        "app_secret": "…",
        "verify_token": "…",
        "graph_version": "v21.0",
        "embedded_signup_config_id": "1634670798041792"
    },
    "exported_at": "2026-09-27T12:00:00+00:00"
}
```

O arquivo tem **segredos em texto puro** — guarde como guardaria o `.env`.
**⬆ Importar** aceita esse arquivo (ou um objeto simples `{chave: valor}`) e
recusa chaves desconhecidas. Depois de importar, rode **Validar tudo**.

## Rotas

| Rota | Nome |
|---|---|
| `GET /whatsapp/cloud/setup` | `whatsapp.cloud.setup.index` |
| `POST …/app`, `…/webhook`, `…/signup`, `…/number`, `…/default/{id}`, `…/test`, `…/diagnose`, `…/import` | `whatsapp.cloud.setup.*` |
| `GET …/export` | `whatsapp.cloud.setup.export` |

Prefixo em `WHATSAPP_CLOUD_SETUP_PREFIX`; desligue com
`WHATSAPP_CLOUD_SETUP_ENABLED=false`. Página própria (design system do app):
`WHATSAPP_CLOUD_SETUP_COMPONENT`.
