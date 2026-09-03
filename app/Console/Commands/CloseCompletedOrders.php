<?php

namespace App\Console\Commands;

use App\Models\ServiceOrder;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CloseCompletedOrders extends Command
{
    protected $signature = 'service-orders:close-completed';
    protected $description = 'Fecha OSs completadas há mais de 2 dias.';

    public function handle()
    {
        $twoDaysAgo = Carbon::now()->subDays(2);

        $orders = ServiceOrder::where('status', ServiceOrder::STATUS_COMPLETED)
            ->where('resolved_at', '<=', $twoDaysAgo)
            ->get();

        foreach ($orders as $order) {
            $order->status = ServiceOrder::STATUS_CLOSED;
            $order->save();
            $this->info("OS #{$order->id} ({$order->protocol}) fechada.");
        }

        $this->info("✅ {$orders->count()} OSs fechadas.");
    }
}