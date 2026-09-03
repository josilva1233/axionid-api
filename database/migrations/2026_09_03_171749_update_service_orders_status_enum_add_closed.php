<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // MySQL: alterar o ENUM para incluir 'closed'
        DB::statement("ALTER TABLE service_orders MODIFY COLUMN status ENUM('open', 'in_progress', 'completed', 'cancelled', 'closed') NOT NULL DEFAULT 'open'");
    }

    public function down()
    {
        // Reverter para o estado anterior (sem 'closed')
        DB::statement("ALTER TABLE service_orders MODIFY COLUMN status ENUM('open', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'open'");
    }
};