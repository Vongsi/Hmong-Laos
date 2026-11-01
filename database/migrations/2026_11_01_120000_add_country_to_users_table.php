<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Members' country (three-letter code, App\Support\Countries), for members outside Laos; the province stays for
 * those in Laos. Everyone who already chose a province lives in Laos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('country', 3)->nullable()->after('province')->index();
        });

        DB::table('users')->whereNotNull('province')->where('province', '!=', '')->update(['country' => 'LAO']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['country']);
            $table->dropColumn('country');
        });
    }
};
