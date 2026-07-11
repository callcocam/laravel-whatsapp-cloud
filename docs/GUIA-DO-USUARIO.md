# Guia do usuário

Este guia é para **quem vai usar** o WhatsApp no dia a dia: instalar o pacote no
app, cadastrar o número, criar os templates e mandar mensagem — sem precisar
entender as entranhas do código.

Se você é (ou está orientando) um **agente de IA implementando a integração**, use
o [Guia para agentes de IA](AGENTS.md).

---

## Sumário

1. [O que este pacote faz (e o que não faz)](#1-o-que-este-pacote-faz-e-o-que-não-faz)
2. [As regras da Meta que você precisa conhecer](#2-as-regras-da-meta-que-você-precisa-conhecer)
3. [Instalação](#3-instalação)
4. [Onde achar cada credencial](#4-onde-achar-cada-credencial)
5. [Ligar o webhook](#5-ligar-o-webhook)
6. [Templates: criando sua primeira mensagem](#6-templates-criando-sua-primeira-mensagem)
7. [O painel web](#7-o-painel-web)
8. [Comandos do terminal](#8-comandos-do-terminal)
9. [Enviando mensagens pelo app](#9-enviando-mensagens-pelo-app)
10. [Problemas comuns](#10-problemas-comuns)
11. [Glossário](#11-glossário)

---

## 1. O que este pacote faz (e o que não faz)

**Faz:**

- Envia mensagens pelo WhatsApp oficial da Meta (Cloud API): template aprovado,
  texto livre e lista de opções.
- Recebe as respostas e os status de entrega através de um **webhook** seguro
  (assinatura conferida).
- Gerencia seus **templates** na Meta: criar, listar, editar, apagar e enviar
  teste — pelo terminal ou por um **painel web**.
- Funciona **multi-tenant**: cada time/cliente pode ter o seu próprio número.

**Não faz** (é o seu app que decide):

- Quando disparar cada mensagem, para quem, e com qual texto.
- Guardar histórico de conversa. O pacote **não salva nada** do que chega — ele
  avisa o app por evento e o app decide se guarda.

---

## 2. As regras da Meta que você precisa conhecer

Estas três regras explicam 90% das dúvidas e dos erros:

**A janela de 24 horas.** Quando o usuário te manda uma mensagem, abre uma janela
de 24h. **Dentro** dela você pode responder com texto livre. Passou das 24h (ou o
usuário nunca falou com você), a janela está fechada.

**Fora da janela, só template aprovado.** Para começar uma conversa você é
obrigado a usar uma mensagem pré-aprovada pela Meta — o **template**. Não existe
"mandar um oi" para quem nunca te respondeu.

**Template passa por análise.** Você escreve o template, envia para a Meta, e ele
fica **`PENDING`** até ser analisado. Só quando vira **`APPROVED`** dá para
enviar. Pode voltar **`REJECTED`**, e um template já aprovado pode ser
**`PAUSED`** se muita gente marcar suas mensagens como spam.

Além disso: **mensagem custa dinheiro**. A Meta cobra por conversa iniciada, e o
preço varia por categoria (`UTILITY`, `MARKETING`, `AUTHENTICATION`) e país. O
painel mostra uma **estimativa** do gasto do mês.

---

## 3. Instalação

**Pré-requisitos:** PHP 8.3+, Laravel 11, 12 ou 13.

O pacote vive num repositório Git privado. No `composer.json` do seu app:

```jsonc
"repositories": [
    { "type": "vcs", "url": "git@github.com:callcocam/laravel-whatsapp-cloud.git" }
]
```

Depois, no terminal:

```bash
composer require callcocam/laravel-whatsapp-cloud
php artisan whatsapp:install   # publica o config, a migration e imprime um checklist
php artisan migrate
```

O `whatsapp:install` cria o arquivo `config/whatsapp-cloud.php` (onde você ajusta
tudo) e a tabela `whatsapp_numbers` (onde pode guardar os números, se quiser).

### As variáveis do `.env`

```dotenv
# Identidade do app na Meta
WHATSAPP_CLOUD_APP_SECRET=...       # OBRIGATÓRIO — sem ele o webhook recusa tudo
WHATSAPP_CLOUD_VERIFY_TOKEN=...     # uma senha que VOCÊ inventa (ver seção 5)
WHATSAPP_CLOUD_GRAPH_VERSION=v21.0

# Credenciais do número (modo simples / um número só)
WHATSAPP_CLOUD_PHONE_NUMBER_ID=...
WHATSAPP_CLOUD_ACCESS_TOKEN=...
WHATSAPP_CLOUD_WABA_ID=...
```

> ⚠️ **Nunca** comite esses valores. O token dá acesso total ao seu WhatsApp
> Business.

Tem mais de um número (um por cliente/time)? Aí em vez do `.env` os números ficam
no banco — fale com quem desenvolve o app, ou aponte a pessoa para o
[Guia para agentes](AGENTS.md#5-credenciais-e-multi-tenant).

---

## 4. Onde achar cada credencial

Tudo vem do [Meta for Developers](https://developers.facebook.com/) → seu App →
**WhatsApp**.

| Valor | Onde fica | Parece com |
|---|---|---|
| **App Secret** | Configurações do app → Básico → *Chave secreta do app* | `a1b2c3...` |
| **Access Token** | WhatsApp → Configuração da API. O token de teste **expira em 24h** — para produção gere um **token permanente** por um Usuário do Sistema no Business Manager | `EAAG...` (bem longo) |
| **Phone Number ID** | WhatsApp → Configuração da API (é o **ID**, não o número de telefone) | `1270360136151311` |
| **WABA ID** | WhatsApp → Configuração da API (*ID da conta do WhatsApp Business*) | `2078742742716092` |
| **Verify Token** | **Você inventa.** É só uma senha que a Meta vai te devolver na hora de validar o webhook | `qualquer-coisa-secreta` |

O token precisa ter as permissões **`whatsapp_business_messaging`** (enviar) e
**`whatsapp_business_management`** (gerenciar template e ver o gasto estimado).

---

## 5. Ligar o webhook

O webhook é o caminho de volta: é por ele que você recebe as **respostas** dos
usuários e os **status de entrega** ("entregue", "lida", "falhou").

O pacote já cria a URL sozinho:

```
https://seu-app.com/webhooks/whatsapp/cloud
```

No painel da Meta (WhatsApp → Configuração → Webhooks → *Editar*):

1. **Callback URL**: a URL acima (precisa ser **HTTPS** e pública — em
   desenvolvimento use ngrok, Expose ou Herd Share).
2. **Verify token**: exatamente o mesmo valor do `WHATSAPP_CLOUD_VERIFY_TOKEN`.
3. Clique em **Verificar e salvar**. Se der erro aqui, os dois tokens estão
   diferentes.
4. Em **Campos do webhook**, assine o campo **`messages`**.

Pronto. A partir daí, toda mensagem e todo status chegam no seu app — e o app
decide o que fazer com eles.

---

## 6. Templates: criando sua primeira mensagem

Um template é um texto fixo com **variáveis** numeradas: `{{1}}`, `{{2}}`, `{{3}}`.

```
Olá, {{1}}! Lembrete: {{2}} acontece em {{3}}.
Conte com você!
```

Na hora de enviar você preenche as variáveis: `Maria`, `Reunião de voluntários`,
`10/07 às 19h30`.

### As regras que fazem a Meta rejeitar (o pacote te avisa antes)

- ❌ O texto **não pode começar** com variável (`{{1}}, tudo bem?`).
- ❌ O texto **não pode terminar** com variável (`... veja em {{3}}`). Coloque uma
  linha fixa depois.
- ❌ O nº de exemplos tem que bater com o nº de variáveis.
- ❌ Exemplo não pode ter quebra de linha, tabulação ou vários espaços seguidos.
- ❌ O **rodapé** não aceita variável.
- ❌ O **nome** do template só aceita minúsculas, números e `_`
  (`coordena_lembrete`).

### Categoria: escolha certo

- **`UTILITY`** — relacionado a algo que o usuário fez/espera (lembrete,
  confirmação, atualização de pedido). Mais barato e aprova mais fácil.
- **`MARKETING`** — promoção, novidade, convite. Mais caro.
- **`AUTHENTICATION`** — código de verificação.

A Meta pode **reclassificar** sua categoria por conta própria.

### O jeito mais fácil de criar: o painel

Veja a próxima seção. Se preferir o terminal, veja a
[seção 8](#8-comandos-do-terminal).

---

## 7. O painel web

Uma página pronta para **criar, listar, editar, apagar e enviar teste** de
template, com **pré-visualização** igual à bolha do WhatsApp.

Fica em **`/whatsapp/cloud/templates`** (precisa estar logado no app).

> O painel é **opcional**: ele só aparece se o app usar Inertia + Vue. Se a sua
> tela der 404, ou o app não tem Inertia, ou o painel está desligado
> (`WHATSAPP_CLOUD_PANEL_ENABLED=false`), ou faltou rodar `npm run build` depois
> de instalar. Chame quem desenvolve.

### O que tem lá

**Listagem** — todos os templates da sua WABA com nome, idioma, categoria e
status. Tem busca por nome, filtro por status/categoria e contadores no topo
(aprovados / pendentes / rejeitados / pausados).

**Novo / Editar** — formulário completo: cabeçalho, corpo com as variáveis,
exemplo de cada variável, rodapé e botões (resposta rápida, link ou telefone). A
pré-visualização atualiza enquanto você digita.

> Ao **editar**, o nome e o idioma são fixos (a Meta não deixa mudar). E atenção:
> **editar um template aprovado o joga de volta para análise** (`PENDING`) — ele
> para de poder ser enviado até ser reaprovado.

**Enviar teste** — dispara um template **aprovado** para um número, preenchendo as
variáveis. Use só dígitos com DDI: `5548999999999`.

**Apagar** — ⚠️ remove **todos os idiomas** com aquele nome. Tem confirmação.

**Gastos (estimado)** — card com o custo do mês atual e a quebra por categoria. É
uma **estimativa** da Meta; a fonte oficial de cobrança é o WhatsApp Manager. Se o
card não aparecer, é porque o token não tem a permissão
`whatsapp_business_management` — o resto do painel continua funcionando normal.

### Segurança — leia isto

O painel **cria, apaga e envia** mensagens de verdade, numa conta que costuma ser
**compartilhada entre todos os times**. Por padrão ele exige só login (`auth`).
Peça para quem desenvolve **restringir a um gate de autorização**
(`WHATSAPP_CLOUD_PANEL_GATE`), para que só administradores entrem.

---

## 8. Comandos do terminal

Alternativa ao painel — útil em servidor, CI ou quando não há Inertia.

```bash
# Ver todos os templates e seus status
php artisan whatsapp:template:list

# Detalhar um template
php artisan whatsapp:template:get coordena_lembrete

# Criar na Meta (envia para análise) a partir de um arquivo de definição
php artisan whatsapp:template:create coordena_lembrete

# Enviar um template APROVADO (as variáveis viram {{1}}, {{2}}, {{3}} na ordem)
php artisan whatsapp:template:send coordena_lembrete 5548999999999 Maria "Reunião" "10/07"
```

Todos aceitam `--tenant=` para escolher o número/cliente (quando o app é
multi-tenant), e o `send` aceita `--lang=` (padrão `pt_BR`).

O `create` lê um arquivo `<nome>.php` da pasta configurada em
`definitions_path`. O arquivo é assim:

```php
<?php
use Callcocam\WhatsAppCloud\Templates\TemplateBuilder;

return TemplateBuilder::make('coordena_lembrete', 'pt_BR', 'UTILITY')
    ->body('Olá, {{1}}! Lembrete: {{2}} em {{3}}.', ['Maria', 'Reunião', '10/07'])
    ->footer('Coordena')
    ->quickReply('Confirmar presença')
    ->toArray();
```

---

## 9. Enviando mensagens pelo app

Isto é para quem programa, mas ajuda a entender o fluxo:

```php
use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;

// Template aprovado — funciona sempre (única forma fora da janela de 24h)
WhatsApp::for($time)->sendTemplate('5548999999999', TemplateMessage::make('lembrete', [
    'nome' => 'Maria', 'evento' => 'Reunião', 'data' => '10/07',
]));

// Texto livre — SÓ dentro da janela de 24h
WhatsApp::for($time)->sendSessionText('5548999999999', 'Recebido, obrigado!');
```

Repare que o app usa uma **chave própria** (`lembrete`), e o arquivo de config
traduz essa chave para o nome real do template na Meta. Assim, se o template mudar
de nome, o código do app não muda.

---

## 10. Problemas comuns

**"Não consigo verificar o webhook na Meta."**
O `WHATSAPP_CLOUD_VERIFY_TOKEN` do `.env` está diferente do que você digitou no
painel da Meta. Confira letra por letra. A URL também precisa ser HTTPS e estar
acessível de fora.

**"O webhook responde 403 e nada chega no app."**
Falta o `WHATSAPP_CLOUD_APP_SECRET` no `.env` (ou está errado). Sem ele, o pacote
**recusa toda requisição** de propósito — ele não processa payload sem assinatura
válida.

**"Erro 131047 / 'Re-engagement message'."**
A janela de 24h fechou. Você tentou mandar texto livre para quem não te respondeu
nas últimas 24h. Use um **template aprovado**.

**"Erro 132001 / 'Template does not exist'."**
O nome, o **idioma** ou a categoria não batem com o que existe na WABA. Rode
`whatsapp:template:list` e confira. Idioma errado (`pt` em vez de `pt_BR`) é a
causa mais comum.

**"Erro 132000 / número de parâmetros."**
O template tem 3 variáveis e você mandou 2 (ou vice-versa). Precisa bater exato.

**"Meu template está PENDING há horas."**
Normal: a análise da Meta pode levar de minutos a ~24h. Não dá para acelerar.

**"Meu template foi REJECTED."**
Abra o detalhe (`whatsapp:template:get <nome>` ou o painel) e veja o
`rejected_reason`. As causas mais comuns: texto que parece promoção numa categoria
`UTILITY`, variável no começo/fim do texto, ou exemplos que não fazem sentido.

**"Meu template virou PAUSED."**
Muita gente bloqueou ou reportou suas mensagens. A Meta pausa automaticamente.
Revise o conteúdo e a lista de destinatários — insistir pode derrubar o número.

**"O erro é 'No WhatsApp Cloud credentials resolved'."**
Faltam as credenciais: ou o `.env` está incompleto, ou o número desse
time/cliente não está cadastrado no banco.

**"O painel dá 404."**
O app não tem Inertia instalado, o painel está desligado no config, ou faltou
rodar `npm run build` depois de publicar as páginas.

---

## 11. Glossário

| Termo | O que é |
|---|---|
| **WABA** | *WhatsApp Business Account* — sua conta na Meta. Os templates pertencem a ela. |
| **Phone Number ID** | O **ID interno** do número na Meta (não é o telefone). É de onde a mensagem sai. |
| **Template** | Mensagem pré-aprovada pela Meta, com variáveis. Única forma de iniciar conversa. |
| **Janela de 24h** | Período após a última mensagem do usuário em que você pode responder texto livre. |
| **wamid** | O ID que a Meta devolve para cada mensagem enviada. É por ele que o status de entrega volta. |
| **Webhook** | A URL do seu app que a Meta chama para entregar respostas e status. |
| **PENDING / APPROVED / REJECTED / PAUSED** | Os status de análise de um template. Só `APPROVED` pode ser enviado. |
