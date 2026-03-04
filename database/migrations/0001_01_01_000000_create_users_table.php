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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            
            // Colunas para Integrações
            $table->string('google_id')->nullable()->unique();
            $table->string('govbr_id')->nullable()->unique();
            
            // O CPF/CNPJ deve ser único e nulo inicialmente para quem vem do Google
            $table->string('cpf_cnpj')->nullable()->unique(); 
            
            $table->string('password');

            // Controle de Status e Permissões
            $table->boolean('profile_completed')->default(false); // Vital para o Step 2 do Google
            $table->boolean('is_admin')->default(false);          // Vital para o Painel Administrativo
            $table->boolean('is_active')->default(true);          // Para bloqueio de usuários
            $table->boolean('from_google')->default(false);       // Identifica origem do cadastro

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};