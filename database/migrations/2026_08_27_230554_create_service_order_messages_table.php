<?php
// database/migrations/2026_08_27_230554_create_service_order_messages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained('service_orders')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('message');
            $table->string('attachment_path')->nullable();
            $table->boolean('is_system_message')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices para performance
            $table->index('service_order_id');
            $table->index('user_id');
            $table->index(['service_order_id', 'created_at']);
            $table->index('is_system_message');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_messages');
    }
};