<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanAuditLogs extends Command
{
    // O nome que você vai digitar no terminal
    protected $signature = 'audit:clean {days=30}'; 

    protected $description = 'Remove logs de auditoria mais antigos que X dias';

    public function handle()
    {
        $days = $this->argument('days');
        $date = now()->subDays($days);

        $deleted = DB::table('audit_logs')
            ->where('created_at', '<', $date)
            ->delete();

        $this->info("Limpeza concluída! {$deleted} logs antigos foram removidos.");
    }
}