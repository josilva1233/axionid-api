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
    Schema::create('service_orders', function (Blueprint $table) {
        $table->id();
        $table->string('protocol')->unique();
        $table->string('title');
        $table->text('description');
        $table->string('attachment_path')->nullable();
        
        // Relacionamentos
        $table->foreignId('user_id')->constrained('users'); // Quem abriu
        $table->foreignId('group_id')->nullable()->constrained('groups'); // Se nulo, é individual
        $table->foreignId('technician_id')->nullable()->constrained('users');
        
        $table->enum('status', ['open', 'in_progress', 'completed', 'cancelled'])->default('open');
        $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
