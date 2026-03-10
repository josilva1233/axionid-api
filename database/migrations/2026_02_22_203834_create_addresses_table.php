<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void 
    {
      Schema::create('addresses', function (Blueprint $table) {
          $table->id();
          $table->foreignId('user_id')->constrained()->onDelete('cascade');
          $table->string('zip_code', 8);
          $table->string('street');
          $table->string('number');
          $table->string('complement')->nullable();
          $table->string('neighborhood'); 
          $table->string('city');
          $table->string('state', 2);

          // CAMPOS DE RASTREABILIDADE
          // Armazena qual Admin fez a última alteração
          $table->unsignedBigInteger('updated_by_admin_id')->nullable();
          $table->timestamp('admin_updated_at')->nullable();

          // Chave estrangeira para a tabela users (opcional, mas recomendado)
          $table->foreign('updated_by_admin_id')->references('id')->on('users')->onDelete('set null');

          $table->timestamps();
      });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};