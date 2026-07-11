# Changelog

All notable changes to `callcocam/laravel-whatsapp-cloud` will be documented in this file.

## [Unreleased]

### Added
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
