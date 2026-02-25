<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up()
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('google_id')->nullable()->unique()->after('id');
        $table->string('govbr_id')->nullable()->unique()->after('google_id');
        // O campo password precisa passar a ser nullable, 
        // pois quem entra via Google/Gov.br pode não ter uma senha local inicial.
        $table->string('password')->nullable()->change();
    });
}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
