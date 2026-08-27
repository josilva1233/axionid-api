<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('service_order_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('message');
            $table->string('attachment_path')->nullable(); // opcional: anexo por mensagem
            $table->timestamps();

            // índices para consultas rápidas
            $table->index('service_order_id');
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('service_order_messages');
    }
};