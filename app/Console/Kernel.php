<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\CleanAuditLogs;
use App\Console\Commands\CloseCompletedOrders;
use App\Console\Commands\SendServiceOrderNotifications;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        CleanAuditLogs::class,
        CloseCompletedOrders::class,
        SendServiceOrderNotifications::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Fechar OSs completadas há mais de 2 dias (diariamente às 00:00)
        $schedule->command('service-orders:close-completed')->daily();

        // Limpar logs de auditoria com mais de 30 dias (diariamente às 02:00)
        $schedule->command('audit:clean')->dailyAt('02:00');

        // Enviar notificações de OSs pendentes (a cada 10 minutos)
        $schedule->command('service-orders:send-notifications')->everyTenMinutes();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}