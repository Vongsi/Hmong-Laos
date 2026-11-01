<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The WhatsApp number a member signs in with (a code sent there: App\Auth\WhatsAppCodes), international
            // digits like 8562055551234. One number, one member. Separate from the contact phone, which is free text.
            $table->string('whatsapp', 20)->nullable()->unique()->after('phone');
            $table->timestamp('whatsapp_verified_at')->nullable()->after('whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['whatsapp']);
            $table->dropColumn(['whatsapp', 'whatsapp_verified_at']);
        });
    }
};
