<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable log of every inbound WhatsApp message the webhook received. The
 * package parses and dispatches; this table is what makes "store everything"
 * opt-in without the host writing a single line — a listener fills it when
 * `whatsapp-cloud.inbound.store` is on.
 *
 * The row is written synchronously in the webhook request (never queued), so a
 * message survives even when the workers are down. `status` starts at `received`
 * and the host app moves it (e.g. to `forwarded`/`unhandled`) as it acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbound_messages', function (Blueprint $table) {
            $table->id();

            // The business number that received it — the host resolves its tenant
            // from this, exactly as the webhook event does.
            $table->string('phone_number_id')->nullable()->index();

            // The sender's wa_id (their phone, digits only, as Meta sends it).
            $table->string('wa_id')->index();

            // The WhatsApp profile name, when Meta included it in `contacts`.
            $table->string('contact_name')->nullable();

            // Meta's message id. Unique so a webhook re-delivery updates the same
            // row instead of duplicating it.
            $table->string('wamid')->unique();

            $table->string('type'); // text | button | interactive | image | ...

            // The plain body, extracted for text messages so a reader/list does
            // not have to dig through the payload. Null for non-text types.
            $table->text('text')->nullable();

            // The wamid this message replied to (button/quote context), if any.
            $table->string('context_id')->nullable();

            // The verbatim `value.messages[]` entry, so nothing is ever lost.
            $table->json('payload')->nullable();

            $table->string('status')->default('received')->index();

            // Set by the host when it routes the message onward.
            $table->string('forwarded_to')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_messages');
    }
};
