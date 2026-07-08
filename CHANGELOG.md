# Changelog

All notable changes to `callcocam/laravel-whatsapp-cloud` will be documented in this file.

## [0.1.0] - 2026-07-08

### Added
- Core sending over the Meta Cloud API: templates, session text and interactive lists (`CloudApiClient`).
- Per-tenant credentials via the `WhatsAppCredentials` / `WhatsAppCredentialsResolver` contracts, with a publishable `WhatsAppNumber` model and `HasWhatsAppCredentials` trait.
- `WhatsApp` facade / `WhatsAppManager::for()` entrypoint.
- Signed webhook (`X-Hub-Signature-256`) with `WhatsAppMessageReceived` / `WhatsAppStatusReceived` / `WhatsAppWebhookVerified` events.
- Template management Artisan commands (`whatsapp:template:{list,get,create,send}`) plus `TemplateBuilder` / `TemplateInput`.
- `php artisan whatsapp:install` and publishable config + migration.
