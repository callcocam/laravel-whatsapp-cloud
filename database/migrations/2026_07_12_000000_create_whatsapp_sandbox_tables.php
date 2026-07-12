<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sandbox's own storage. Never present in production: these tables only
 * matter when `whatsapp-cloud.driver` is `sandbox`, and the transport that writes
 * to them refuses to boot in a production environment.
 *
 * A phone number has exactly one thread with the business number, so the contact
 * and the conversation are one row — there is no separate participants table to
 * keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sandbox_conversations', function (Blueprint $table) {
            $table->id();

            // Which business number this thread belongs to. The REAL phone number
            // id — the app resolves its tenant from it.
            $table->string('phone_number_id');

            $table->string('wa_id');
            $table->string('name');

            // A label for the humans reading the screen (customer / operator).
            // Meta has no such concept — to WhatsApp everyone is just a number.
            $table->string('role')->nullable();

            // Meta's 24h session window: free text is only allowed until here.
            // Null means it never opened (the contact has never replied).
            $table->timestamp('window_expires_at')->nullable();

            // Failures armed for the next send. A column, not the session: a
            // queued listener sends from a worker process, which would never see
            // session state.
            $table->json('faults')->nullable();

            $table->timestamps();

            $table->unique(['phone_number_id', 'wa_id']);
        });

        Schema::create('whatsapp_sandbox_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('whatsapp_sandbox_conversations')
                ->cascadeOnDelete();

            $table->string('direction'); // outbound | inbound
            $table->string('wamid')->index();
            $table->string('type'); // template | text | interactive | button | image

            // The two payloads, verbatim. This is what the inspector shows, and
            // the reason to keep them raw: a summary would hide the very drift
            // you opened the sandbox to find.
            $table->json('envelope')->nullable();         // exactly what we POSTed
            $table->json('inbound_payload')->nullable();  // exactly what the webhook carried

            $table->string('template_name')->nullable();
            // Snapshot of the template's content AT SEND TIME, so the bubble still
            // renders after the definition file changes.
            $table->json('template_components')->nullable();

            $table->text('rendered_text')->nullable();

            $table->string('delivery_status')->nullable(); // sent | delivered | read | failed
            $table->integer('error_code')->nullable();

            $table->json('meta')->nullable(); // listeners, exceptions, notes

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sandbox_messages');
        Schema::dropIfExists('whatsapp_sandbox_conversations');
    }
};
