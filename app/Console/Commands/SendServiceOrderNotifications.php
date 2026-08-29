<?php

namespace App\Console\Commands;

use App\Models\ServiceOrder;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Console\Command;

class SendServiceOrderNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-service-order-notifications 
                            {--id= : ID específico da OS para enviar notificações}
                            {--status= : Status específico para filtrar OS}
                            {--test : Modo de teste - apenas simula o envio}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envia notificações para usuários sobre ordens de serviço';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Iniciando envio de notificações de OS...');

        // Buscar OS para notificar
        $query = ServiceOrder::with(['user', 'technician', 'group', 'messages']);

        // Filtrar por ID específico
        if ($id = $this->option('id')) {
            $query->where('id', $id);
            $this->info("📌 Filtrando OS ID: {$id}");
        }

        // Filtrar por status
        if ($status = $this->option('status')) {
            $query->where('status', $status);
            $this->info("📌 Filtrando por status: {$status}");
        }

        // Buscar apenas OS que não estão fechadas e têm mensagens recentes
        $orders = $query->where('status', '!=', 'closed')
            ->whereHas('messages', function($q) {
                $q->where('created_at', '>=', now()->subHours(24));
            })
            ->get();

        if ($orders->isEmpty()) {
            $this->warn('⚠️ Nenhuma OS encontrada para enviar notificações.');
            return 0;
        }

        $this->info("📊 Encontradas {$orders->count()} OS para processar.");
        $totalNotifications = 0;
        $testMode = $this->option('test');

        if ($testMode) {
            $this->warn('🧪 MODO DE TESTE ATIVADO - Nenhuma notificação será enviada.');
        }

        foreach ($orders as $order) {
            $this->info("\n📋 Processando OS: #{$order->protocol} - {$order->title}");
            
            // Buscar destinatários
            $recipients = $this->getRecipients($order);
            
            if ($recipients->isEmpty()) {
                $this->warn("   ⚠️ Nenhum destinatário encontrado para OS #{$order->protocol}");
                continue;
            }

            // Buscar última mensagem
            $lastMessage = $order->messages()->latest()->first();
            
            if (!$lastMessage) {
                $this->warn("   ⚠️ Nenhuma mensagem encontrada para OS #{$order->protocol}");
                continue;
            }

            // Buscar remetente
            $sender = User::find($lastMessage->user_id);
            
            if (!$sender) {
                $this->warn("   ⚠️ Remetente não encontrado para mensagem #{$lastMessage->id}");
                continue;
            }

            $this->info("   👤 Remetente: {$sender->name}");
            $this->info("   📨 Mensagem: " . substr($lastMessage->message, 0, 50) . "...");

            // Enviar notificações
            foreach ($recipients as $recipient) {
                // Não notificar o próprio remetente
                if ($recipient->id === $sender->id) {
                    continue;
                }

                $totalNotifications++;
                
                if ($testMode) {
                    $this->line("   🧪 [TESTE] Notificação para: {$recipient->name} ({$recipient->email})");
                } else {
                    try {
                        $recipient->notify(new NewMessageNotification($order, $lastMessage, $sender));
                        $this->line("   ✅ Notificação enviada para: {$recipient->name} ({$recipient->email})");
                    } catch (\Exception $e) {
                        $this->error("   ❌ Erro ao enviar para {$recipient->name}: {$e->getMessage()}");
                    }
                }
            }
        }

        $this->info("\n✅ Processamento concluído!");
        $this->info("📊 Total de notificações: " . ($testMode ? "[TESTE] " : "") . $totalNotifications);
        
        return 0;
    }

    /**
     * Obtém os destinatários para uma OS
     */
    private function getRecipients($order)
    {
        $recipients = collect();

        // Solicitante
        if ($order->user) {
            $recipients->push($order->user);
        }

        // Técnico
        if ($order->technician) {
            $recipients->push($order->technician);
        }

        // Administradores
        $admins = User::where('is_admin', true)->get();
        $recipients = $recipients->merge($admins);

        // Membros do grupo
        if ($order->group) {
            $members = User::whereHas('groups', function($q) use ($order) {
                $q->where('groups.id', $order->group_id);
            })->get();
            $recipients = $recipients->merge($members);
        }

        // Remover duplicados
        return $recipients->unique('id');
    }
}