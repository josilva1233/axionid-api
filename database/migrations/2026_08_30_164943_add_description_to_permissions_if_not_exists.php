<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Verifica se a tabela existe
        if (!Schema::hasTable('permissions')) {
            return;
        }

        // Verifica se a coluna já existe
        if (Schema::hasColumn('permissions', 'description')) {
            // Remove o registro da migration para não dar conflito
            DB::table('migrations')
                ->where('migration', 'like', '%add_description_to_permissions_if_not_exists%')
                ->delete();
            return;
        }

        // Adiciona a coluna
        Schema::table('permissions', function (Blueprint $table) {
            $table->text('description')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasColumn('permissions', 'description')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};