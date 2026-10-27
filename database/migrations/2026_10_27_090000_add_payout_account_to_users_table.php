<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Where the team sends a seller's money for their products (My account; App\Shop\SellerPayouts).
            // Only the member and the team see it.
            $table->string('payout_bank', 60)->nullable()->after('phone');
            $table->string('payout_account_name', 100)->nullable()->after('payout_bank');
            $table->string('payout_account_number', 40)->nullable()->after('payout_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['payout_bank', 'payout_account_name', 'payout_account_number']);
        });
    }
};
