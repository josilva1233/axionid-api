<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('category_id')
                  ->nullable()
                  ->after('group_id')
                  ->constrained('categories')
                  ->nullOnDelete();

            // Datas previstas para SLA (calculadas ao criar/atualizar)
            $table->timestamp('sla_first_response_due_at')->nullable()->after('category_id');
            $table->timestamp('sla_resolution_due_at')->nullable()->after('sla_first_response_due_at');

            // Campos para controle de cumprimento
            $table->timestamp('first_response_at')->nullable()->after('sla_resolution_due_at');
            $table->timestamp('resolved_at')->nullable()->after('first_response_at');

            // Índices para consultas rápidas
            $table->index('category_id');
            $table->index('sla_resolution_due_at');
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
                'resolved_at'
            ]);
        });
    }
};