<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('default_group_id')
                  ->nullable()
                  ->constrained('groups')
                  ->nullOnDelete();
            $table->unsignedSmallInteger('sla_first_response_hours')->default(4); // horas para primeiro contato
            $table->unsignedSmallInteger('sla_resolution_hours')->default(24);    // horas para resolução
            $table->enum('default_priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('categories');
    }
};