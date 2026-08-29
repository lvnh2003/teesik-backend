<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('transaction_id')->index();
            $table->string('provider_order_id')->nullable()->after('provider')->index();
            $table->string('provider_request_id')->nullable()->after('provider_order_id')->index();
            $table->string('provider_transaction_id')->nullable()->after('provider_request_id');
            $table->integer('provider_result_code')->nullable()->after('provider_transaction_id');
            $table->json('provider_payload')->nullable()->after('provider_result_code');
            $table->timestamp('paid_at')->nullable()->after('provider_payload');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'provider_order_id',
                'provider_request_id',
                'provider_transaction_id',
                'provider_result_code',
                'provider_payload',
                'paid_at',
            ]);
        });
    }
};
