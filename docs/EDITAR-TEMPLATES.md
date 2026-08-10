# Editar templates existentes na Meta

Este guia mostra como atualizar o conteúdo de um template existente sem mudar o
nome usado pelo aplicativo.

## Quando editar e quando criar

Edite quando o template já existir na mesma WABA e no mesmo idioma. A Meta
identifica a edição pelo `id` do template.

Crie um template quando o nome ainda não existir naquela WABA:

```bash
php artisan whatsapp:template:create nome_do_template
```

A definição local deve existir em `whatsapp-cloud.definitions_path`, normalmente
`whatsapp-templates/nome_do_template.php`.

## Regras da Meta

- A edição é feita pelo `id`, não pelo nome.
- `name` e `language` não podem ser alterados.
- É possível alterar `components` (corpo, exemplos e botões) e a categoria.
- Templates `APPROVED`, `REJECTED` ou `PAUSED` podem ser editados.
- Templates `PENDING` não podem ser editados.
- Uma edição aceita normalmente muda o status para `PENDING` e inicia nova
  análise.
- Enquanto estiver `PENDING`, o envio pode retornar `#132001` porque a tradução
  ainda não está disponível para envio.
- A operação de gestão de templates sempre atinge uma WABA real, mesmo quando o
  driver de mensagens está configurado como `sandbox`.

## Antes de editar

Confirme a WABA efetiva e o template atual:

```bash
php artisan tinker --execute='dump(
    config("whatsapp-cloud.default.waba_id"),
    config("whatsapp-cloud.default.phone_number_id")
);'

php artisan whatsapp:template:get nome_do_template
```

Confira especialmente:

- WABA correta;
- nome e idioma exatos;
- status permitido para edição;
- token com `whatsapp_business_management` válido.

## Caminho recomendado: painel

O painel em `/whatsapp/cloud/templates` usa `TemplateManager::edit()` e edita o
template pelo ID. Abra o template, altere os componentes e envie para nova
análise.

O painel deve estar protegido por autenticação e pelo gate configurado em
`whatsapp-cloud.panel.gate`.

## Alternativa: editar pelo terminal

Não existe atualmente um comando Artisan `whatsapp:template:update`. O script
abaixo carrega o Laravel, procura o nome por igualdade exata, verifica o status e
aplica a definição local ao mesmo ID:

```bash
TEMPLATE_NAME=coordena_meeting_agenda_portal php -r '
require "vendor/autoload.php";

$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = getenv("TEMPLATE_NAME");
$api = app(Callcocam\WhatsAppCloud\WhatsAppManager::class)->templateApi();
$templates = $api->all(null, 200)["data"] ?? [];

$meta = collect($templates)->first(
    static fn (array $template): bool => ($template["name"] ?? null) === $name
        && ($template["language"] ?? null) === "pt_BR"
);

if ($meta === null) {
    throw new RuntimeException("Template {$name} (pt_BR) não encontrado na WABA.");
}

$status = strtoupper((string) ($meta["status"] ?? ""));
if (! in_array($status, ["APPROVED", "REJECTED", "PAUSED"], true)) {
    throw new RuntimeException("Template {$name} não pode ser editado no status {$status}.");
}

$file = base_path("whatsapp-templates/{$name}.php");
if (! is_file($file)) {
    throw new RuntimeException("Definição local não encontrada: {$file}");
}

$local = require $file;
$result = $api->edit(
    (string) $meta["id"],
    (array) $local["components"],
    (string) ($meta["category"] ?? $local["category"] ?? ""),
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
), PHP_EOL;
'
```

O script preserva a categoria que a Meta já atribuiu. Isso evita tentar forçar
`UTILITY` em um template que a Meta classificou como `MARKETING`.

Nunca selecione o template apenas por correspondência parcial. Nomes como
`coordena_meeting_agenda` e `coordena_meeting_agenda_item` podem ser confundidos
se a busca não exigir igualdade exata.

## Validar depois da edição

```bash
php artisan whatsapp:template:get nome_do_template
php artisan whatsapp:template:list | grep nome_do_template
```

Resultado esperado imediatamente após uma edição aceita: `PENDING`. Aguarde
`APPROVED` antes de testar o envio:

```bash
php artisan whatsapp:template:send \
  nome_do_template \
  5548999999999 \
  "valor de {{1}}" \
  "valor de {{2}}"
```

Os parâmetros devem seguir exatamente a ordem declarada no template.

## Erros comuns

### `#132001 Template name does not exist in the translation`

O nome/idioma não existe nessa WABA, ou o template ainda está `PENDING`. Confirme
WABA, idioma e status antes de criar uma duplicata.

### `Invalid parameter`

Causas frequentes:

- tentativa de criar um nome que já existe;
- template `PENDING` sendo editado;
- ID obtido de outro template;
- componentes incompatíveis com as regras da Meta;
- categoria inválida para a edição.

### Token expirado (`code 190`)

Renove o token usado pelas credenciais da WABA, limpe o cache de configuração e
repita a consulta:

```bash
php artisan config:clear
php artisan whatsapp:template:get nome_do_template
```
