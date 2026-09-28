<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comments on any Statamic entry (posts, events, interviews).
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('published'); // published, hidden
            $table->timestamps();
        });

        Schema::create('comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['comment_id', 'user_id']);
        });

        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['entry_id', 'user_id']);
        });

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('going'); // going, interested
            $table->boolean('volunteer')->default(false);
            $table->timestamps();
            $table->unique(['entry_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('likes');
        Schema::dropIfExists('comment_reports');
        Schema::dropIfExists('comments');
    }
};
