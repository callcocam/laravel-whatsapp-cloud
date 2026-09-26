<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            // Filled by Embedded Signup (see Onboarding\EmbeddedSignup).
            $table->string('display_phone_number')->nullable()->after('phone_number_id');
            $table->string('business_id')->nullable()->after('waba_id');
            // Null when Meta issued a token that does not expire.
            $table->timestamp('token_expires_at')->nullable()->after('cloud_access_token');
            $table->timestamp('connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->dropColumn(['display_phone_number', 'business_id', 'token_expires_at', 'connected_at']);
        });
    }
};
