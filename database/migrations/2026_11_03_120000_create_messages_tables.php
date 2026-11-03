<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members' private messages (App\Support\Messaging): conversations between two members or in a group, each member's
 * place in them, the messages (text encrypted), blocks and reports; and two member settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('direct');
            $table->string('name', 80)->nullable();
            // Two members (lower id first), so a pair has one direct conversation. Empty for groups.
            $table->foreignId('user_one_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Who wrote first, or made the group.
            $table->unsignedBigInteger('started_by');
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['user_one_id', 'user_two_id']);
            $table->index('user_two_id');
        });

        Schema::create('conversation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            // Empty while it's a request (direct) or an invitation (group) they haven't accepted.
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->unsignedBigInteger('invited_by')->nullable();
            // Message ids: the last one they've seen, and up to which they don't see (deleted the chat, or joined later).
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->unsignedBigInteger('cleared_id')->default(0);
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // text; system ("Alice added Bob", "Voice call · 3 min"; what happened is in meta); or call, a missed call,
            // which counts as unread for the member who was called.
            $table->string('kind', 10)->default('text');
            $table->text('body')->nullable();
            // A photo on the private disk (storage/app/private/messages/<conversation>/…), for the conversation only.
            $table->string('photo')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['conversation_id', 'id']);
        });

        // Voice and video calls between two members (App\Support\Calls): who, when, and how it went. Nothing else.
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('video')->default(false);
            // ringing, active, ended, missed or declined.
            $table->string('status', 10)->default('ringing');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('member_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'blocked_user_id']);
            $table->index('blocked_user_id');
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The other member of a direct chat; empty when a group was reported.
            $table->foreignId('reported_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // The latest messages when it was reported (encrypted), as the team saw them.
            $table->text('excerpt')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('accepts_messages')->default(true);
            $table->boolean('messages_disabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['accepts_messages', 'messages_disabled']);
        });
        Schema::dropIfExists('message_reports');
        Schema::dropIfExists('member_blocks');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_members');
        Schema::dropIfExists('conversations');
    }
};
