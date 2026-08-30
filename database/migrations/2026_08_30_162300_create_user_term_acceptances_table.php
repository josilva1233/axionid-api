<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verifica se a tabela já existe
        if (Schema::hasTable('user_term_acceptances')) {
            return; // Pula a criação se já existir
        }

        Schema::create('user_term_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('term_id')->constrained('terms')->onDelete('cascade');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
            
            // Índices para melhor performance
            $table->index(['user_id', 'term_id']);
            $table->unique(['user_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_term_acceptances');
    }
};