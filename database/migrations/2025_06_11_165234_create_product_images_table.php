<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('image_path');
            $table->string('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->enum('type', ['general', 'variant'])->default('general');
            $table->timestamps();
            
            $table->index(['product_id', 'type']);
            $table->index(['product_variant_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_images');
    }
};