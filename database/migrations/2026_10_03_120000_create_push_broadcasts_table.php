<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per entry (per language) whose phone notification went out, so it is never sent twice.
    public function up(): void
    {
        Schema::create('push_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id')->unique();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_broadcasts');
    }
};
