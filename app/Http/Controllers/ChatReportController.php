<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatReportController extends Controller
{
    // =========================================================
    // STATUS DA IA (sempre online)
    // =========================================================
    public function status()
    {
        return response()->json(['status' => 'online']);
    }

    // =========================================================
    // CHAT PRINCIPAL
    // =========================================================
    public function chat(Request $request)
    {
        try {
            $message = trim($request->input('message'));
            $response = $this->processMessage($message);
            return response()->json(['message' => $response]);
        } catch (\Exception $e) {
            Log::error('ChatReportController: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response()->json([
                'message' => '❌ Erro interno ao processar sua pergunta. Tente novamente.'
            ], 500);
        }
    }

    // =========================================================
    // LIMPAR HISTÓRICO (mock)
    // =========================================================
    public function clearHistory()
    {
        return response()->json(['message' => 'Histórico limpo com sucesso.']);
    }

    // =========================================================
    // PROCESSADOR DE MENSAGENS
    // =========================================================
    private function processMessage($message)
    {
        $msg = strtolower($message);

        // --- Chamados ---
        if (strpos($msg, 'chamado') !== false ||
            strpos($msg, 'ordem de serviço') !== false ||
            strpos($msg, 'os') !== false ||
            strpos($msg, 'ticket') !== false ||
            strpos($msg, 'solicitação') !== false) {
            return $this->handleOrders($msg);
        }

        // --- Grupos ---
        if (strpos($msg, 'grupo') !== false || strpos($msg, 'equipe') !== false) {
            return $this->handleGroups($msg);
        }

        // --- Relatório completo ---
        if (strpos($msg, 'relatório') !== false || strpos($msg, 'resumo') !== false) {
            return $this->handleFullReport();
        }

        // --- Fallback ---
        return "🤖 Desculpe, não entendi sua pergunta.\n\n" .
               "Você pode perguntar sobre:\n" .
               "• Quantos chamados eu tenho?\n" .
               "• Quantos chamados abertos?\n" .
               "• Quantos grupos eu criei?\n" .
               "• Quantos membros nos meus grupos?\n" .
               "• Listar meus chamados\n" .
               "• Listar meus grupos\n" .
               "• Relatório completo";
    }

    // =========================================================
    // HANDLER: ORDENS DE SERVIÇO
    // =========================================================
    private function handleOrders($msg)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return "⚠️ Você precisa estar logado para ver seus chamados.";
            }

            $statusMap = [
                'aberto' => 'open',
                'abertos' => 'open',
                'andamento' => 'in_progress',
                'atendimento' => 'in_progress',
                'resolvido' => 'completed',
                'concluído' => 'completed',
                'concluido' => 'completed',
                'fechado' => 'closed',
                'cancelado' => 'canceled',
            ];

            $status = null;
            foreach ($statusMap as $key => $value) {
                if (strpos($msg, $key) !== false) {
                    $status = $value;
                    break;
                }
            }

            $wantList = strpos($msg, 'listar') !== false ||
                        strpos($msg, 'mostrar') !== false ||
                        strpos($msg, 'quais') !== false;

            $query = ServiceOrder::where('user_id', $user->id);
            if ($status) {
                $query->where('status', $status);
            }
            $count = $query->count();

            if ($wantList) {
                $orders = $query->limit(5)->get(['id', 'title', 'status', 'created_at']);
                if ($orders->isEmpty()) {
                    return "📭 Você não tem chamados." . ($status ? " com status '{$status}'." : "");
                }
                $list = $orders->map(function ($order) {
                    return "• #{$order->id} - {$order->title} ({$order->status}) - " . $order->created_at->format('d/m/Y H:i');
                })->implode("\n");
                return "📋 Seus chamados:\n{$list}\n" . ($count > 5 ? "\n... e mais " . ($count - 5) . " chamados." : "");
            }

            if ($status) {
                return "📊 Você tem **{$count}** chamado(s) com status **'{$status}'**.";
            }
            return "📊 Você tem um total de **{$count}** chamado(s).";
        } catch (\Exception $e) {
            Log::error('handleOrders: ' . $e->getMessage());
            return "❌ Erro ao buscar chamados.";
        }
    }

    // =========================================================
    // HANDLER: GRUPOS
    // =========================================================
    private function handleGroups($msg)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return "⚠️ Você precisa estar logado para ver seus grupos.";
            }

            if (strpos($msg, 'criou') !== false || strpos($msg, 'criei') !== false || strpos($msg, 'criados') !== false) {
                $count = Group::where('creator_id', $user->id)->count();
                if (strpos($msg, 'listar') !== false || strpos($msg, 'mostrar') !== false || strpos($msg, 'quais') !== false) {
                    $groups = Group::where('creator_id', $user->id)->get(['id', 'name']);
                    if ($groups->isEmpty()) {
                        return "📭 Você ainda não criou nenhum grupo.";
                    }
                    $list = $groups->map(fn($g) => "• {$g->name} (ID: {$g->id})")->implode("\n");
                    return "📋 Grupos que você criou:\n{$list}";
                }
                return "📊 Você criou **{$count}** grupo(s).";
            }

            if (strpos($msg, 'membro') !== false || strpos($msg, 'membros') !== false || strpos($msg, 'pessoas') !== false) {
                $groups = Group::where('creator_id', $user->id)->withCount('users')->get();
                $totalMembers = $groups->sum('users_count');
                $groupCount = $groups->count();
                if ($groupCount == 0) {
                    return "📭 Você não criou nenhum grupo, então não tem membros.";
                }
                return "👥 Seus **{$groupCount}** grupo(s) têm um total de **{$totalMembers}** membro(s).";
            }

            $count = $user->groups()->count();

            if (strpos($msg, 'listar') !== false || strpos($msg, 'mostrar') !== false || strpos($msg, 'quais') !== false) {
                $groups = $user->groups()->get(['id', 'name']);
                if ($groups->isEmpty()) {
                    return "📭 Você não está em nenhum grupo.";
                }
                $list = $groups->map(fn($g) => "• {$g->name} (ID: {$g->id})")->implode("\n");
                return "📋 Grupos que você participa:\n{$list}";
            }

            return "📊 Você está em **{$count}** grupo(s).";
        } catch (\Exception $e) {
            Log::error('handleGroups: ' . $e->getMessage());
            return "❌ Erro ao buscar grupos.";
        }
    }

    // =========================================================
    // HANDLER: RELATÓRIO COMPLETO
    // =========================================================
    private function handleFullReport()
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return "⚠️ Você precisa estar logado para ver seu relatório.";
            }

            $totalOrders = ServiceOrder::where('user_id', $user->id)->count();
            $openOrders = ServiceOrder::where('user_id', $user->id)->where('status', 'open')->count();
            $inProgressOrders = ServiceOrder::where('user_id', $user->id)->where('status', 'in_progress')->count();
            $completedOrders = ServiceOrder::where('user_id', $user->id)->where('status', 'completed')->count();

            $totalGroups = $user->groups()->count();
            $createdGroups = Group::where('creator_id', $user->id)->count();

            return "📊 **Relatório do Usuário**\n\n" .
                   "👤 Nome: {$user->name}\n" .
                   "📧 Email: {$user->email}\n\n" .
                   "📌 **Chamados:**\n" .
                   "• Total: {$totalOrders}\n" .
                   "• Abertos: {$openOrders}\n" .
                   "• Em andamento: {$inProgressOrders}\n" .
                   "• Resolvidos: {$completedOrders}\n\n" .
                   "📌 **Grupos:**\n" .
                   "• Participa de: {$totalGroups}\n" .
                   "• Criou: {$createdGroups}";
        } catch (\Exception $e) {
            Log::error('handleFullReport: ' . $e->getMessage());
            return "❌ Erro ao gerar relatório.";
        }
    }
}