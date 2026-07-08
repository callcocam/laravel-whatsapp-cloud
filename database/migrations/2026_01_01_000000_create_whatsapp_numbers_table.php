<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            // Optional tenant key for ModelCredentialsResolver (e.g. team slug).
            $table->string('key')->nullable()->unique();
            $table->string('waba_id')->nullable();
            $table->string('phone_number_id')->nullable();
            // Stored encrypted (see WhatsAppNumber::casts()).
            $table->text('cloud_access_token')->nullable();
            $table->string('app_id')->nullable();
            $table->string('verified_name')->nullable();
            $table->string('quality_rating')->nullable();
            $table->string('messaging_limit')->nullable();
            // Optional per-number Graph API version override.
            $table->string('graph_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_numbers');
    }
};
