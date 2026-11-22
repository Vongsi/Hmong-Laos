<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voice messages in private chats (App\Support\MessageVoice): the recording, an M4A file on the private disk next to
 * the conversation's photos, and how long it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('voice')->nullable()->after('photo');
            $table->unsignedSmallInteger('voice_seconds')->nullable()->after('voice');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['voice', 'voice_seconds']);
        });
    }
};
