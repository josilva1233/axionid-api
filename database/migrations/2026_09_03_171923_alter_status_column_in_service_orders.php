<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Se a coluna for ENUM, precisamos convertê-la primeiro
        Schema::table('service_orders', function (Blueprint $table) {
            // Remove o ENUM e converte para VARCHAR
            $table->string('status', 20)->default('open')->change();
        });
    }

    public function down()
    {
        // Se quiser voltar atrás, pode definir os valores ENUM novamente
        Schema::table('service_orders', function (Blueprint $table) {
            $table->enum('status', ['open', 'in_progress', 'completed', 'cancelled', 'closed'])
                ->default('open')
                ->change();
        });
    }
};