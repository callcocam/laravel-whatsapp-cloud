# Painel de templates: página nativa do host (headless package + scaffold)

**Data:** 2026-07-09
**Repo do pacote:** `callcocam/laravel-whatsapp-cloud` — branch `feature/template-panel`
**Repo consumidor:** `coordena` — branch a criar `dev-feature/template-panel` (ou a que o time do Coordena escolher)

---

## 1. Contexto e problema

O painel de templates (Inertia + Vue) hoje é uma **ilha de design totalmente autônoma**:

- CSS próprio: `resources/js/Pages/WhatsAppCloud/Templates/partials/panel.css` (~1032 linhas)
- Toasts próprios: `partials/toasts.js`
- Ícones **emoji** (`💬 ↻ + 👁️ ✏️ ✈️ 🗑️`)
- `<header class="topbar">` próprio — **sem layout de app, sem sidebar**
- `<dialog>` nativo do browser em vez de um Dialog do design system

Quando o Coordena publica essa página (`vendor:publish --tag=whatsapp-cloud-inertia` → `resources/js/pages/WhatsAppCloud`), ela destoa por completo da área protegida do app (que usa `AppLayout` + shadcn-vue + `@lucide/vue` + toasts via `flash.toast`/`vue-sonner`).

**Causa raiz (não é "o agente não conhecia os padrões"):** um componente Vue **compilado dentro do pacote não consegue resolver o alias `@/` do host** (`@/layouts/AppLayout.vue`, `@/components/ui/button`…). Esses aliases só resolvem no build do app consumidor. Portanto qualquer UI pré-compilada que o pacote entregue é obrigada a ter CSS próprio e parece estrangeira.

## 2. Decisão de arquitetura (confirmada pelo dono)

> **Pacote reutilizável de verdade** (não é só-Coordena) **+ execução nos 2 repos.**

Modelo **shadcn** ("nós te damos o código, no seu projeto, usando os seus componentes"):

- **Pacote = headless + contrato + fallback.**
  - Backend estável: controller, rotas, `TemplateManager`, CRUD da Meta.
  - Um **contrato de props** congelado (abaixo).
  - Mantém a **página fallback autônoma** (a atual) para apps que não têm design system — publicada via `whatsapp-cloud-inertia`.
  - Passa a permitir **nome do componente Inertia configurável** e **flash normalizado** para o host.
  - Novo: **comando de scaffold** que gera, no host, uma **página NATIVA** (shadcn-vue + lucide + AppLayout + `flash.toast`) a partir de stubs.
- **Host = dono da página.** O Coordena passa a ser dono de `resources/js/pages/WhatsAppCloud/Templates/*`, escrita com o próprio design system. O backend fica no pacote; só o **contrato de props** atravessa a fronteira → **mudança no pacote não exige recopiar a página** (mata a dor do "recopiar toda vez").

## 3. Contrato (CONGELAR — pacote e host dependem disto)

### Props que o controller injeta (`index`)
| Prop | Tipo | Descrição |
|---|---|---|
| `templates` | `array<object>` | Templates crus da Meta. Cada item: `{ id, name, language, category, status, components[], rejected_reason? }` |
| `waConfig` | `{ waba_id, phone_number_id, api_version }` | Credenciais **públicas** (nunca o token) para o cabeçalho |
| `loadError` | `string \| null` | Erro ao carregar a lista (credencial ruim etc.) |
| `panelUrl` | `string` | URL base das rotas de mutação (o host concatena; **sem acoplar wayfinder**) |

### Rotas (relativas ao `panelUrl`, nomes sob `whatsapp.cloud.panel.*`)
| Método | Caminho | Ação | Body |
|---|---|---|---|
| GET | `/` | `index` | — |
| POST | `/` | `store` (cria; vai p/ análise) | `TemplateInput` payload |
| POST | `/{id}/edit` | `update` (reseta p/ PENDING) | idem |
| DELETE | `/{name}` | `destroy` (apaga todos os idiomas) | — |
| POST | `/send` | `send` (teste) | `{ name, to, params[], language }` |

### Flash e erros (o que o controller devolve)
- **Sucesso:** `flash.toast = { type: 'success', message }` (novo shape — ver A2). No `send`, adiciona `flash.sent_id`.
- **Erro:** validation errors do Inertia — `errors.meta` (falha Meta/API) e `errors.form` (guard-rail local).

### Helpers reaproveitáveis (JS puro, sem CSS — **copiar verbatim** para a página nativa)
`partials/format.js`: `escapeHtml`, `formatWa` (markdown WA→HTML), `maxVar`, `statusClass`, `statusLabel`, `catClass`, `varLabel`, `parseTemplate`, `validateForm`. **Não reescrever** — é lógica de negócio (espelha os guard-rails do servidor).

---

## 4. PARTE A — Pacote (`feature/template-panel`)

Tudo mecânico e testável em isolamento (testbench). **Não** precisa do design system do host.

### A1. `config/whatsapp-cloud.php` — bloco `panel`
Adicionar `component` e `gate`:

```php
'panel' => [
    'enabled'    => env('WHATSAPP_CLOUD_PANEL_ENABLED', true),
    'prefix'     => env('WHATSAPP_CLOUD_PANEL_PREFIX', 'whatsapp/cloud/templates'),
    'name'       => 'whatsapp.cloud.panel',
    'middleware' => ['web', 'auth'],
    // Nome do componente Inertia a renderizar. O host pode publicar uma página
    // NATIVA no mesmo caminho e mantê-la; o pacote continua dono do backend.
    'component'  => env('WHATSAPP_CLOUD_PANEL_COMPONENT', 'WhatsAppCloud/Templates/Index'),
    // Gate de autorização opcional. Quando setado, o provider anexa
    // "can:<gate>" à middleware — o painel mexe na WABA (compartilhada em
    // multi-tenant), então recomenda-se restringir além de [web, auth].
    'gate'       => env('WHATSAPP_CLOUD_PANEL_GATE'),
    'ui_token'   => env('WHATSAPP_CLOUD_PANEL_UI_TOKEN'),
],
```

### A2. `TemplatePanelController` — componente configurável + flash normalizado
- **index:** trocar o literal pelo config:
  ```php
  return Inertia::render(
      (string) config('whatsapp-cloud.panel.component', 'WhatsAppCloud/Templates/Index'),
      [ /* ...mesmas props... */ ]
  );
  ```
- **Flash normalizado** (`store`/`update`/`destroy`/`send`): substituir `->with('flash', ['success' => ...])` por um helper privado que emite o shape `toast`:
  ```php
  private function ok(string $message, array $extra = []): RedirectResponse
  {
      return back()->with('flash', [
          'toast' => ['type' => 'success', 'message' => $message],
      ] + $extra);
  }
  ```
  - `store`  → `return $this->ok("Template \"{$payload['name']}\" enviado para análise.");`
  - `update` → `return $this->ok("Template \"{$payload['name']}\" enviado para nova análise.");`
  - `destroy`→ `return $this->ok("Template \"{$name}\" apagado.");`
  - `send`   → `return $this->ok("Mensagem enviada para {$to}.", ['sent_id' => is_string($id) ? $id : null]);`
    (mantém `flash.sent_id` no mesmo nível — a fallback page e a nativa leem `page.props.flash.sent_id`.)
- **Erros** (`run()`): mantém `withErrors(['meta'=>...])` / `['form'=>...]` — inalterado.
- **Compat da fallback page:** a página fallback atual dispara os toasts client-side no `onSuccess` (não lê `flash.success`), então trocar `success`→`toast` **não a quebra**. Verificar que `SendTestModal` continua lendo `page.props.flash.sent_id` (continua).

### A3. `WhatsAppCloudServiceProvider::registerPanelRoutes()` — gate → middleware
Anexar `can:<gate>` quando `panel.gate` estiver setado:

```php
$middleware = (array) $config->get('whatsapp-cloud.panel.middleware', ['web', 'auth']);
if ($gate = $config->get('whatsapp-cloud.panel.gate')) {
    $middleware[] = 'can:'.$gate;
}

Route::group([
    'prefix'     => $config->get('whatsapp-cloud.panel.prefix', 'whatsapp/cloud/templates'),
    'middleware' => $middleware,
    'as'         => $config->get('whatsapp-cloud.panel.name', 'whatsapp.cloud.panel').'.',
], function () {
    $this->loadRoutesFrom(__DIR__.'/../routes/panel.php');
});
```

### A4. Stubs nativos + comando de scaffold
Objetivo: o host roda **um comando** e recebe uma página NATIVA (shadcn-vue) em `resources/js/pages/WhatsAppCloud/Templates/`, que ele passa a ser dono.

1. **Stubs** em `resources/stubs/inertia-native/WhatsAppCloud/Templates/`:
   - `Index.vue.stub`
   - `partials/TemplateDetailModal.vue.stub`
   - `partials/TemplateFormModal.vue.stub`
   - `partials/SendTestModal.vue.stub`
   - `partials/WhatsAppPreview.vue.stub`
   - `partials/StatusBadge.vue.stub`
   - `partials/format.js` (copiar verbatim — sem `.stub`, é JS puro)

   > **Importante:** os `.vue.stub` assumem um host **shadcn-vue** (`@/components/ui/*`), `@lucide/vue`, `@/layouts/AppLayout.vue` e toasts via `flash.toast`/`vue-sonner`. Isso é **pré-requisito documentado** do scaffold nativo (apps sem isso usam a fallback autônoma). Ver a Parte B para o conteúdo exato dos stubs — **o Coordena vai produzir e validar essas páginas primeiro (onde o design system existe) e elas voltam pra cá como a fonte dos stubs.** Não autore shadcn "às cegas" aqui.

2. **Comando** `src/Console/ScaffoldPanel.php`:
   ```php
   protected $signature = 'whatsapp:panel:scaffold {--force : Overwrite existing files}';
   protected $description = 'Copy the native (shadcn-vue) template-panel pages into the host resources/js/pages';
   ```
   - Copia recursivamente `resources/stubs/inertia-native/WhatsAppCloud` → `resource_path('js/pages/WhatsAppCloud')`, renomeando `*.stub` → sem sufixo. Respeita `--force` (senão pula os que já existem e avisa).
   - Ao final, imprime checklist: setar `whatsapp-cloud.panel.component` = `WhatsAppCloud/Templates/Index`; garantir shadcn-vue + `@lucide/vue` + `vue-sonner` + `AppLayout`; `npm run build`.
   - Registrar em `WhatsAppCloudServiceProvider::boot()` no array de `commands()`.

3. `InstallCommand` — acrescentar na checklist a linha: "Para uma UI nativa no seu design system, rode `php artisan whatsapp:panel:scaffold` e ajuste `panel.component`." E no `composer.json` `suggest`, mencionar o scaffold nativo.

### A5. Manter a fallback page
A página atual em `resources/js/Pages/WhatsAppCloud/Templates/*` **permanece** (publicada via `whatsapp-cloud-inertia`). É o caminho para apps sem design system. Só ganha a compat de flash (não quebra).

### A6. Testes (Pest / testbench) + qualidade
- Novo/ajustado: `panel.component` é respeitado por `index` (assert Inertia component name via config override).
- `panel.gate` setado → a rota do painel ganha `can:<gate>` (registrar um Gate no teste e assert 403 sem permissão / 200 com).
- Flash: `store`/`send` devolvem `flash.toast.type == 'success'`; `send` inclui `flash.sent_id`.
- Comando `whatsapp:panel:scaffold` copia os arquivos para um `resource_path` de teste (usar `--force`) e renomeia `.stub`.
- Rodar: `composer test` (pint --test, phpstan, pest). Corrigir o que quebrar.

### A7. Docs
- `README.md`: seção do painel — explicar os **dois modos** (fallback autônoma vs. scaffold nativo), o contrato de props, `panel.component`, `panel.gate`, e o comando `whatsapp:panel:scaffold`.
- `CHANGELOG.md`: entrada (component configurável, flash `toast`, gate opcional, scaffold nativo).

---

## 5. PARTE B — Coordena (repo consumidor)

Precisa do design system do host — **fazer NO Coordena** (é lá que resolvem `@/components/ui/*`, `AppLayout`, `vue-sonner`, wayfinder).

### B1. Página nativa em `resources/js/pages/WhatsAppCloud/Templates/`
Reescrever a cópia publicada usando o design system do Coordena. **Mapa de conversão** (o comportamento e o contrato não mudam):

| Atual (ilha) | Nativo (Coordena) |
|---|---|
| `<header class="topbar">` + sem layout | `AppLayout` (`@/layouts/AppLayout.vue`) com `:breadcrumbs` e `#headerAction` (botões "Atualizar"/"Novo template") |
| `panel.css` (1032 linhas) | **apagar**; usar classes Tailwind + componentes shadcn |
| `toasts.js` + `pushToast` | **apagar**; sucesso via `flash.toast` (vue-sonner, já global em [flashToast.ts](resources/js/lib/flashToast.ts)); erro via `usePage().props.errors` |
| `<table>` cru | `@/components/ui/table` (ou o padrão de tabela já usado no admin) |
| `<select>`/`<input>` crus | `@/components/ui/select`, `@/components/ui/input`, `@/components/ui/label` |
| `<button class="btn">` | `@/components/ui/button` (variantes) |
| `<dialog>` nativo | `@/components/ui/dialog` (form/detail/send) + `@/components/ui/alert-dialog` para o confirm de apagar |
| Emoji (`👁️ ✏️ ✈️ 🗑️ ↻ +`) | `@lucide/vue`: `Eye`, `Pencil`, `Send`, `Trash2`, `RefreshCw`, `Plus` (seguir o set já usado em `pages/admin/**`) |
| Badges de status/categoria | `@/components/ui/badge` (mapear cores via `statusClass`/`catClass`) |
| `window.confirm` no apagar | `AlertDialog` de confirmação |
| `Head title` solto | manter `<Head>` + `Heading` (`@/components/Heading.vue`) se o padrão do admin usar |
| `format.js` | **copiar verbatim** do pacote (lógica pura) |
| `WhatsAppPreview` (balão) | manter o balão, mas estilizar com Tailwind (é UI específica de WhatsApp; pode ficar com CSS escopado mínimo) |

**Regras que NÃO mudam** (já implementadas no `format.js`/modais — preservar):
- Filtros status/categoria/busca; contadores approved/pending/rejected/paused + total.
- Editar desabilitado quando `PENDING`.
- Validação client-side (`validateForm`) espelhando o servidor.
- Preview ao vivo no form (`formatWa`, `{{n}}`, `*bold*`/`_it_`/`~s~`).
- `send`: número só dígitos `^\d{8,15}$`, params por variável, mostra `flash.sent_id`.
- Mutações via `router.post/delete` no `panelUrl` (prop) — **sem wayfinder** (evita acoplar a rota gerada; o pacote é quem registra a rota).

> Estas páginas nativas, depois de validadas aqui, **voltam para o pacote** como o conteúdo dos `.stub` da Parte A4 (com o path `pages/` → `Pages/` e imports genéricos onde fizer sentido).

### B2. `config/whatsapp-cloud.php` (publicado no Coordena)
- `panel.component` = `WhatsAppCloud/Templates/Index` (a página nativa).
- `panel.gate` = `manage-whatsapp-templates` (ou `WHATSAPP_CLOUD_PANEL_GATE` no `.env`).

### B3. Endurecer autorização (a observação de segurança)
Hoje o painel roda só com `['web','auth']` → **qualquer logado abre `/whatsapp/cloud/templates` e cria/apaga template na WABA compartilhada** (afeta todas as equipes). Como o Coordena não tem super-admin:

- Definir um Gate `manage-whatsapp-templates` (em `AppServiceProvider::boot` ou um `AuthServiceProvider`):
  ```php
  Gate::define('manage-whatsapp-templates', function (User $user) {
      $allow = array_filter(array_map('trim', explode(',', (string) config('services.whatsapp_cloud.panel_emails'))));
      return in_array($user->email, $allow, true);
  });
  ```
- `config/services.php` → bloco `whatsapp_cloud`: `'panel_emails' => env('WHATSAPP_CLOUD_PANEL_EMAILS')`.
- `.env` / `.env.example`: `WHATSAPP_CLOUD_PANEL_EMAILS=callcocam@gmail.com` e `WHATSAPP_CLOUD_PANEL_GATE=manage-whatsapp-templates`.
- (Alternativa se preferirem por-time: gate em `TeamPermission::ManageWhatsapp` do `currentTeam` — **mas** lembrar que a WABA é compartilhada, então allowlist por e-mail é mais seguro para uma ferramenta cross-tenant.)

### B4. Menu (nota — trabalho paralelo do dono)
`resources/js/composables/useAppNav.ts` e `lang/pt_BR/app/nav.php` já estão modificados (item do WhatsApp Cloud, trabalho em paralelo). **Não sobrescrever.** Só garantir que o item do menu fique escondido para quem não passa no gate (ex.: expor um flag `canManageWaTemplates` no `HandleInertiaRequests::share` e checar no nav). Combinar com o dono para não colidir.

### B5. Verificação (no host — Node só existe no host, não nos containers)
- `php artisan wayfinder:generate --with-form` **se** alguma rota nova entrar (o painel não usa wayfinder; provavelmente desnecessário).
- `npx vue-tsc --noEmit` (typecheck) — a página nativa tem que passar.
- `npm run build` — compilar de fato.
- **Nunca** `npm run format` sem escopo (reformata `resources/` inteiro). Se formatar, escopar aos arquivos tocados.
- Artisan/testes do Coordena sempre dentro dos containers (`docker compose`).

### B6. Commit
- Coordena: branch `dev-feature/template-panel`, commitar página nativa + config + gate + env.example. **Não** tocar nos arquivos do trabalho paralelo do dono (useAppNav.ts, nav.php, etc. — coordenar).
- Mensagem de commit terminando com:
  `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

---

## 6. Ordem de execução recomendada (evita autorar shadcn às cegas)

O erro original foi autorar UI shadcn **sem** o design system em contexto. Para não repetir:

1. **Coordena primeiro (Parte B1):** escrever e **typecheckar/buildar** a página nativa onde os componentes existem. Rápido e correto.
2. **Pacote depois (Parte A):** trabalho mecânico (config, controller, provider, comando, testes, docs) e, para os `.stub`, **copiar as páginas já validadas do Coordena** (A4) — nada de shadcn no escuro.
3. **Coordena fecha (B2/B3/B6):** config `component`+`gate`, o Gate, `.env`, commit.

Isto quer dizer: **a Parte B (página nativa) é melhor executada AQUI (chat do Coordena)**, e a **Parte A (pacote) no chat do pacote**, recebendo as páginas prontas. Se preferir tudo no chat do pacote, ele terá que autorar os stubs sem typecheck — funciona, mas o build/validação final tem que rodar no Coordena de qualquer forma.

## 7. Checklist final
- [ ] A1 config `component`+`gate`
- [ ] A2 controller: component configurável + flash `toast`
- [ ] A3 provider: `can:<gate>` na middleware
- [ ] A4 stubs nativos + `whatsapp:panel:scaffold` + registrar comando
- [ ] A5 fallback page intacta
- [ ] A6 testes pest + pint + phpstan verdes
- [ ] A7 README + CHANGELOG
- [ ] B1 página nativa (shadcn + lucide + AppLayout + flash.toast) typecheck/build OK
- [ ] B2 config Coordena (`component`, `gate`)
- [ ] B3 Gate `manage-whatsapp-templates` + `panel_emails` + `.env.example`
- [ ] B4 menu gated (coordenar com trabalho paralelo)
- [ ] B5 vue-tsc + build OK
- [ ] B6 commits nos 2 repos
