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

### Changed
- **Normalized success flash** — the panel controller emits
  `flash.toast = { type: 'success', message }` (the `send` action also sets
  `flash.sent_id`). The fallback page (which toasts client-side) is unaffected;
  native pages drive vue-sonner straight from the server flash.
- `whatsapp:install` checklist and the composer `suggest` note now mention the
  native scaffold and the authorization gate.

## [0.1.0] - 2026-07-08

### Added
- Core sending over the Meta Cloud API: templates, session text and interactive lists (`CloudApiClient`).
- Per-tenant credentials via the `WhatsAppCredentials` / `WhatsAppCredentialsResolver` contracts, with a publishable `WhatsAppNumber` model and `HasWhatsAppCredentials` trait.
- `WhatsApp` facade / `WhatsAppManager::for()` entrypoint.
- Signed webhook (`X-Hub-Signature-256`) with `WhatsAppMessageReceived` / `WhatsAppStatusReceived` / `WhatsAppWebhookVerified` events.
- Template management Artisan commands (`whatsapp:template:{list,get,create,send}`) plus `TemplateBuilder` / `TemplateInput`.
- `php artisan whatsapp:install` and publishable config + migration.
