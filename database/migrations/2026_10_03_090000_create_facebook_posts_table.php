<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per entry (per language) already posted to the Facebook Page, so it is never posted twice.
    public function up(): void
    {
        Schema::create('facebook_posts', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id')->unique();
            $table->string('facebook_post_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_posts');
    }
};
