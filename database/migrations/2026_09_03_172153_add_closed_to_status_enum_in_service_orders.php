<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE service_orders MODIFY status ENUM('open','in_progress','completed','cancelled','closed') DEFAULT 'open'");
    }

    public function down()
    {
        DB::statement("ALTER TABLE service_orders MODIFY status ENUM('open','in_progress','completed','cancelled') DEFAULT 'open'");
    }
};