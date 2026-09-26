# Changelog

All notable changes to `callcocam/laravel-whatsapp-cloud` will be documented in this file.

## [Unreleased]

### Added
- **Setup wizard** at `/whatsapp/cloud/setup`: configure the whole integration from
  the browser. Validates the app id/secret against Meta before saving, generates the
  verify token and registers the webhook by API, stores the Embedded Signup
  `config_id`, connects a number (Embedded Signup or manual, checked against its
  WABA) and makes it the default, then sends a test template and shows the reply
  arriving through the webhook. "Validar tudo" re-checks every piece against Meta.
  Values are saved encrypted in `whatsapp_settings` (tag
  `whatsapp-cloud-settings-migrations`) and layered over the config on boot — a
  value saved in the panel wins over the .env. JSON export/import carries the
  configuration between projects. See [docs/CONFIGURACAO.md](docs/CONFIGURACAO.md).
  The setup and connected-numbers pages need a gate (`WHATSAPP_CLOUD_SETUP_GATE` /
  `WHATSAPP_CLOUD_NUMBERS_GATE`, falling back to `WHATSAPP_CLOUD_PANEL_GATE`); without
  one they only open in the local environment.
- **Connected numbers + Embedded Signup.** A page at `/whatsapp/cloud/numbers` where a
  business connects its WhatsApp number by logging in with Facebook — no copying ids
  and tokens by hand. The server exchanges the code, checks the number belongs to the
  WABA, subscribes the app to the WABA's webhooks, optionally registers the number
  with a PIN and stores it on `whatsapp-cloud.model`. Numbers can be refreshed and
  disconnected from the same page. Fires `WhatsAppNumberConnected` so a multi-tenant
  app can tie the row to its tenant. Config under `whatsapp-cloud.embedded_signup`
  (`WHATSAPP_CLOUD_EMBEDDED_SIGNUP_CONFIG_ID`); new columns in the
  `whatsapp-cloud-embedded-signup-migrations` tag. See
  [docs/EMBEDDED-SIGNUP.md](docs/EMBEDDED-SIGNUP.md).

### Fixed
- **Interactive list options no longer show their text twice.** `sendInteractive()`
  sent every row's `description` — even when the label fit the 24-char title — so
  WhatsApp printed title and description alike ("Confirmar / Confirmar") on the
  list and on the person's reply. The description now goes only on labels longer
  than 24 chars (the full label, still capped at 72). Row ids (`opt_N`) and
  `InteractiveMessage` are unchanged. The sandbox's `list_reply` now echoes a
  row's description only when the sent row had one, as Meta does.
- **Sandbox: interactive reply buttons are tappable.** The screen only read
  `interactive.action.sections.0.rows`, so a message carrying reply BUTTONS rendered
  with no buttons at all and the rehearsal dead-ended on the very message that asked
  a question. The blind spot had a cause: the package's own `sendInteractive()` always
  builds a list, so the button shape was never exercised — only an app that builds its
  own envelope (to choose the option ids) sends one. `Sandbox::tapReplyButton()` now
  fires the `interactive.button_reply` webhook that `InboundPayloadFactory` could
  already produce but nothing called. A list with several sections also lost every row
  after the first section; all of them are tappable now.

### Added
- **Sandbox** — a simulator that replaces the wire to Meta, so a whole conversation
  (including a handoff to a human operator) can be rehearsed without a phone, and
  **before a template is ever submitted**. See [docs/SANDBOX.md](docs/SANDBOX.md).
  - `whatsapp-cloud.driver` (env `WHATSAPP_CLOUD_DRIVER`): `cloud` (default) or `sandbox`.
    The app's code does not change — only the transport does.
  - Replies are signed with the real HMAC and delivered through the REAL webhook
    route, so the app's listeners run exactly as they do in production.
  - Meta's 24h session window is enforced for real: outside it, only a template gets
    through; free text comes back as a terminal `131047`. It can be closed on demand
    rather than waited out.
  - Fault injection for the errors that actually bite, including **retryable** ones —
    they are what exercises the queue's backoff, the branch the happy path never touches.
  - The template body is resolved from the LOCAL definition file, falling back to Meta.
    That is what lets a template be rehearsed before `whatsapp:template:create` burns
    its name (which is one-way).
  - A WhatsApp-looking screen at `/whatsapp/cloud/sandbox` with an inspector showing the
    exact envelope, the exact webhook, the signature, the listeners, and any exception a
    listener threw.
  - Refuses to boot in production, and the screen does not register unless the driver is
    already `sandbox`.
- **`MessageTransport` contract** — the single seam every outbound message passes
  through, for both the data plane (`CloudApiClient`) and the panel's template
  test-send (`TemplateManager::send`). Additive: the constructors gained an optional
  trailing parameter, so nothing existing breaks.
- Template management panel (Inertia + Vue): create/list/edit/delete/send with a
  WhatsApp-style live preview, published via `vendor:publish --tag=whatsapp-cloud-inertia`.
- **Configurable panel component** — `panel.component` (env `WHATSAPP_CLOUD_PANEL_COMPONENT`)
  lets the host own a native Inertia page at the panel route while the package keeps
  the backend; defaults to the self-contained fallback page.
- **Optional authorization gate** — `panel.gate` (env `WHATSAPP_CLOUD_PANEL_GATE`).
  When set, the provider appends `can:<gate>` to the panel middleware so the shared
  WABA isn't mutable by every authenticated user.
- **Native scaffold command** — `php artisan whatsapp:panel:scaffold` copies a
  shadcn-vue version of the panel (`@/components/ui/*`, `@lucide/vue`, `vue-sonner`,
  `AppLayout`) into `resources/js/pages/WhatsAppCloud/Templates/`, dropping the
  `.stub` suffix. The host then owns the page; the package still owns the backend
  and the frozen props contract.
- **Estimated-cost card** — `TemplateManager::costs()` reads the WABA
  `conversation_analytics` edge; the panel renders a current-month cost summary
  grouped by category (fallback + native). Best-effort: hidden when the token
  lacks `whatsapp_business_management`. Currency via `panel.currency`
  (`WHATSAPP_CLOUD_PANEL_CURRENCY`).

### Changed
- **Panel pages now publish to `resources/js/pages/`** (lowercase), the path the
  Laravel starter kits' Inertia resolver (`resolvePageComponent('./pages/**/*.vue')`)
  scans — and the same destination `whatsapp:panel:scaffold` already wrote to. Both
  panel modes finally land in one place. **Apps that published before this change**
  have the old copy in `resources/js/Pages/WhatsAppCloud/`: delete it and re-run
  `vendor:publish --tag=whatsapp-cloud-inertia` (on a case-insensitive filesystem
  the two paths collide, so remove the stale one either way).
- **Normalized success flash** — the panel controller emits
  `flash.toast = { type: 'success', message }` (the `send` action also sets
  `flash.sent_id`). The fallback page (which toasts client-side) is unaffected;
  native pages drive vue-sonner straight from the server flash.
- `whatsapp:install` checklist and the composer `suggest` note now mention the
  native scaffold and the authorization gate.
- `whatsapp:install` checklist printed a `sendTemplate('key', [...])` snippet that
  did not match the real signature; it now shows
  `sendTemplate($to, TemplateMessage::make('key', [...]))`.

### Deprecated
- **`WhatsAppException::isTemporaryRestriction()`** — renamed to
  **`isTerminal()`**, which is what it actually answers: `true` means the error is
  terminal and the caller must NOT retry. The old name said the opposite of its
  behaviour. It stays as an alias delegating to `isTerminal()`, so existing callers
  keep working; migrate at your convenience.

### Documentation
- Added [`docs/AGENTS.md`](docs/AGENTS.md) (integration reference for AI agents:
  file map, exact signatures, contracts, invariants, pitfalls, anti-error checklist)
  and [`docs/GUIA-DO-USUARIO.md`](docs/GUIA-DO-USUARIO.md) (end-user guide: Meta's
  rules, where to find each credential, wiring the webhook, the panel, common
  errors). The README routes readers to the right one.

## [0.1.0] - 2026-07-08

### Added
- Core sending over the Meta Cloud API: templates, session text and interactive lists (`CloudApiClient`).
- Per-tenant credentials via the `WhatsAppCredentials` / `WhatsAppCredentialsResolver` contracts, with a publishable `WhatsAppNumber` model and `HasWhatsAppCredentials` trait.
- `WhatsApp` facade / `WhatsAppManager::for()` entrypoint.
- Signed webhook (`X-Hub-Signature-256`) with `WhatsAppMessageReceived` / `WhatsAppStatusReceived` / `WhatsAppWebhookVerified` events.
- Template management Artisan commands (`whatsapp:template:{list,get,create,send}`) plus `TemplateBuilder` / `TemplateInput`.
- `php artisan whatsapp:install` and publishable config + migration.
