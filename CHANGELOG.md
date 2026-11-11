# Changelog

All notable changes to `callcocam/laravel-whatsapp-cloud` will be documented in this file.

## [Unreleased]

### Added
- **Handlers for received photos, documents and audio**, off by default and turned on
  per kind (`WHATSAPP_CLOUD_IMAGES_ENABLED`, `WHATSAPP_CLOUD_DOCUMENTS_ENABLED`,
  `WHATSAPP_CLOUD_AUDIO_ENABLED` → `whatsapp-cloud.media.handle`). A queued listener
  (`HandleInboundMedia`, one try) hands each one to `InboundFileHandler` or
  `InboundAudioHandler` as a `ReceivedMedia` (media, sender, `download()`, `reply()`).
  The package's defaults only answer the person: the document's name, kind and size,
  the photo's size, the caption, "Recebi seu áudio"; a file over the limit is
  explained, not failed. Outside production the answer ends with a hint to bind
  your own handler (`WHATSAPP_CLOUD_MEDIA_HINT`). Texts in `whatsapp-cloud.media.replies`.
- **Audio transcription.** `AudioTranscriber` contract, `WHATSAPP_CLOUD_TRANSCRIBER=cloudflare`
  for Workers AI (`@cf/openai/whisper-large-v3-turbo`, `CLOUDFLARE_ACCOUNT_ID` +
  `CLOUDFLARE_AI_TOKEN`) or `openai` for OpenAI's `/v1/audio/transcriptions`
  (`whisper-1`); both in `pt` by default. The default audio
  handler then answers what the audio said. Failures are logged and answered without
  the text.
- **Audio in the sandbox.** 🎤 records a voice note in the browser and sends it as the
  contact (`type: audio`, `voice: true`); 📎 sends an audio file; the bubble plays it.
  `InboundPayloadFactory::audio()`, `Sandbox::sendMedia(..., voice: true)` and a bundled
  Ogg/Opus sample for downloads of ids nobody put.

### Changed
- `InboundMedia::fromMessage()` now reads `audio` too (with `voice` / `isVoiceNote()`),
  where it returned null. Apps that took null as "not a photo or document" must check
  the type.

## [0.6.1] - 2026-11-11

### Changed
- **Sandbox bubbles seen from the contact's phone**, as in WhatsApp: what the contact
  sends is on the right in green, what the system sends on the left. On screens 1440px
  and wider the sandbox sits in a centred box (max 1400px) over the WhatsApp green band,
  so the chat no longer stretches edge to edge. Apps that published the page
  (`whatsapp-cloud-sandbox`) carry their own copy and must update it themselves.

## [0.6.0] - 2026-11-09

### Added
- **Sending a file as the contact in the sandbox.** A 📎 in the composer (speaking as the
  contact) sends a chosen file: JPEG/PNG as `type: image`, anything else as
  `type: document` with its filename; what is typed goes as the caption, and the open
  bubble as `context.id`. The bytes are put under the media id, so the app's
  `downloadMedia()` gets that very file (a real receipt, a PDF over the app's limit).
  The bubble shows the photo or a link to the file (`GET messages/{message}/media`).
  Same thing from a test: `Sandbox::sendMedia($conversation, $bytes, $mime, $filename)`.
- `InboundPayloadFactory::image()` takes the mime type (`image/png`).

## [0.5.0] - 2026-11-09

### Added
- **Media download.** `CloudApiClient::downloadMedia($mediaId, $maxBytes = null, $allowedMimeTypes = [])`
  fetches a file someone sent (`GET /{media-id}` → temporary URL → bytes, both with the
  token) and returns `DownloadedMedia` (contents, Meta's mime type, size and the sha256 of
  what arrived). Size and type are checked before downloading; the size again after.
  Refusals throw `MediaDownloadException` (`too_large` / `type_not_allowed`, terminal).
  Default limit: `whatsapp-cloud.media.max_download_bytes` (16 MB). New `MediaDownloader`
  contract on the same `whatsapp-cloud.driver`: `CloudApiMediaDownloader` for real,
  `SandboxMediaDownloader` (refuses production) answers what a test put under the id
  (`SandboxMediaDownloader::put()`) or a bundled sample JPEG/PDF.
- **`InboundMedia::fromMessage($message)`** reads the `image`/`document` part of an
  inbound message (id, mime type, sha256, filename, caption); anything else is `null`.
- **`InboundPayloadFactory::document()`**, and a `$mediaId` on `image()`, so a test can
  pick the file the download answers.

## [0.4.0] - 2026-11-08

### Added
- **Quick-reply payloads on templates.** `TemplateMessage::withButtonPayloads([index => payload])`
  sends the payload each QUICK_REPLY button returns (`button.payload` on the webhook),
  so a tap says which record it is about. The sandbox echoes the payload the send
  chose (and the text when there is none, as Meta does).

### Changed
- **Reply buttons.** `sendInteractive()` sends up to 3 options that all fit a button
  title (≤ 20 chars, no repeats) as Meta reply buttons, tapped right on the message;
  anything else is still an interactive list. Ids stay `opt_{index}` in both shapes:
  read `button_reply.id` as well as `list_reply.id` (the sandbox already does).

## [0.3.0] - 2026-11-08

### Added
- **Document header on templates.** `TemplateMessage::withDocument($mediaId, $filename)`
  sends a PDF in the template's DOCUMENT header, before the body params.
- **Media upload.** `CloudApiClient::uploadMedia($contents, $mime, $filename)` (also on
  `MessageGateway`) posts the file to the number's `/media` endpoint and returns the
  media id — no public URL needed. New `MediaUploader` contract, chosen by the same
  `whatsapp-cloud.driver`: `CloudApiMediaUploader` for real, `SandboxMediaUploader`
  (refuses production) keeps the file on the machine.
- **`TemplateBuilder::headerDocument($exampleFile)`.** `TemplateManager::create()` uploads
  the example through the Resumable Upload API (needs `whatsapp-cloud.app_id`) and
  submits Meta's `header_handle`; `TemplateManager::uploadExample()` is public.

### Changed
- The sandbox reads the body params from the `body` component (not the first one) and
  shows `📎 <file>` above a template that carries a document.
- `MessageGateway` gained `uploadMedia()`: a custom implementation must add it.

## [0.2.0] - 2026-09-26

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
