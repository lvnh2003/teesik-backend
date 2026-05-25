<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_id')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->index();
            $table->json('data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_id')->unique();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->index();
            $table->string('sku')->nullable()->index();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('original_price', 15, 2)->default(0);
            $table->json('category_ids')->nullable();
            $table->json('images')->nullable();
            $table->json('variations')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_id')->nullable()->unique();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_activated')->default(true)->index();
            $table->boolean('is_percent')->default(false);
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('max_discount', 15, 2)->default(0);
            $table->decimal('min_order_value', 15, 2)->default(0);
            $table->integer('usage_limit')->default(0);
            $table->integer('used_count')->default(0);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_id')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable()->index();
            $table->text('shipping_address')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->string('status')->default('pending')->index();
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->default('cod');
            $table->string('transaction_id')->nullable();
            $table->json('items')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('pancake');
            $table->string('entity')->index();
            $table->string('status')->default('idle')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->unsignedInteger('last_records_synced')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['source', 'entity']);
        });

        Schema::create('data_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('pancake');
            $table->string('entity')->index();
            $table->string('status')->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('upserted_count')->default(0);
            $table->text('error')->nullable();
            $table->json('logs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sync_runs');
        Schema::dropIfExists('data_sync_states');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
