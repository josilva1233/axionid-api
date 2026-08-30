<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Verifica se a tabela users existe
        if (!Schema::hasTable('users')) {
            return;
        }

        // Verifica se a coluna deleted_at já existe
        if (Schema::hasColumn('users', 'deleted_at')) {
            // Remove o registro da migration para evitar duplicidade
            DB::table('migrations')
                ->where('migration', '2026_08_30_164310_add_deleted_at_to_users_table')
                ->delete();
            return;
        }

        // Adiciona a coluna deleted_at
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};