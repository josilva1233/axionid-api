<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('service_orders', function (Blueprint $table) {
            // Adiciona category_id (se não existir)
            if (!Schema::hasColumn('service_orders', 'category_id')) {
                $table->foreignId('category_id')
                      ->nullable()
                      ->after('group_id')
                      ->constrained('categories')
                      ->nullOnDelete();
            }

            // Adiciona sla_first_response_due_at (se não existir)
            if (!Schema::hasColumn('service_orders', 'sla_first_response_due_at')) {
                $table->timestamp('sla_first_response_due_at')->nullable()->after('category_id');
            }

            // Adiciona sla_resolution_due_at (se não existir)
            if (!Schema::hasColumn('service_orders', 'sla_resolution_due_at')) {
                $table->timestamp('sla_resolution_due_at')->nullable()->after('sla_first_response_due_at');
            }

            // Adiciona first_response_at (se não existir) - mas talvez já exista?
            if (!Schema::hasColumn('service_orders', 'first_response_at')) {
                $table->timestamp('first_response_at')->nullable()->after('sla_resolution_due_at');
            }

            // NÃO ADICIONAMOS resolved_at novamente porque já existe!
            // Se quiser garantir que exista, pode verificar, mas não precisa.
        });
    }

    public function down()
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn([
                'category_id',
                'sla_first_response_due_at',
                'sla_resolution_due_at',
                'first_response_at',
            ]);
            // Não removemos resolved_at porque já existia antes
        });
    }
};