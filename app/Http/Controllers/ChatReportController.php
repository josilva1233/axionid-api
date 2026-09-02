<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ChatReportController extends Controller
{
    public function status()
    {
        return response()->json(['status' => 'online']);
    }

    public function chat(Request $request)
    {
        try {
            $message = trim($request->input('message'));
            $response = $this->processMessage($message);
            return response()->json(['message' => $response]);
        } catch (\Exception $e) {
            Log::error('ChatReportController: ' . $e->getMessage());
            return response()->json([
                'message' => '❌ Erro interno ao processar sua pergunta. Tente novamente.'
            ], 500);
        }
    }

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

        // --- AÇÕES: CRIAR GRUPO ---
        if (strpos($msg, 'criar grupo') !== false || strpos($msg, 'novo grupo') !== false) {
            return $this->handleCreateGroup($message);
        }

        // --- AÇÕES: ABRIR CHAMADO ---
        if (strpos($msg, 'abrir chamado') !== false || strpos($msg, 'novo chamado') !== false || strpos($msg, 'criar chamado') !== false) {
            return $this->handleCreateOrder($message);
        }

        // --- CONSULTAS: CHAMADOS ---
        if (strpos($msg, 'chamado') !== false ||
            strpos($msg, 'ordem de serviço') !== false ||
            strpos($msg, 'os') !== false ||
            strpos($msg, 'ticket') !== false ||
            strpos($msg, 'solicitação') !== false) {
            return $this->handleOrders($msg);
        }

        // --- CONSULTAS: GRUPOS ---
        if (strpos($msg, 'grupo') !== false || strpos($msg, 'equipe') !== false) {
            return $this->handleGroups($msg);
        }

        // --- RELATÓRIO COMPLETO ---
        if (strpos($msg, 'relatório') !== false || strpos($msg, 'resumo') !== false) {
            return $this->handleFullReport();
        }

        // --- FALLBACK ---
        return $this->getHelpMessage();
    }

    // =========================================================
    // HELP / COMANDOS DISPONÍVEIS
    // =========================================================
    private function getHelpMessage()
    {
        return "🤖 Comandos disponíveis:\n\n" .
               "📌 **Consultas:**\n" .
               "• Quantos chamados eu tenho?\n" .
               "• Quantos chamados abertos?\n" .
               "• Quantos grupos eu criei?\n" .
               "• Quantos membros nos meus grupos?\n" .
               "• Listar meus chamados\n" .
               "• Listar meus grupos\n" .
               "• Relatório completo\n\n" .
               "🚀 **Ações:**\n" .
               "• Criar grupo Nome do Grupo\n" .
               "• Abrir chamado com título Problema, prioridade alta, grupo TI\n" .
               "• Novo chamado: Descrição do problema (prioridade média, grupo RH)";
    }

    // =========================================================
    // AÇÃO: CRIAR GRUPO
    // =========================================================
    private function handleCreateGroup($message)
    {
        $user = Auth::user();
        if (!$user) {
            return "⚠️ Você precisa estar logado para criar um grupo.";
        }

        // Extrair nome do grupo (ex: "criar grupo Financeiro" -> "Financeiro")
        $name = $this->extractGroupName($message);
        if (!$name) {
            return "❌ Não consegui identificar o nome do grupo.\nExemplo: 'criar grupo Financeiro'";
        }

        // Verificar se já existe grupo com esse nome
        if (Group::where('name', $name)->exists()) {
            return "❌ Já existe um grupo com o nome '{$name}'. Escolha outro nome.";
        }

        try {
            $group = Group::create([
                'name' => $name,
                'creator_id' => $user->id,
            ]);

            // Adicionar criador como admin do grupo
            $group->users()->attach($user->id, ['role' => 'admin']);

            return "✅ Grupo '{$name}' criado com sucesso! Você é o administrador do grupo.";
        } catch (\Exception $e) {
            Log::error('Erro ao criar grupo: ' . $e->getMessage());
            return "❌ Erro ao criar o grupo. Tente novamente.";
        }
    }

    private function extractGroupName($message)
    {
        // Remove palavras-chave
        $patterns = ['/criar grupo/i', '/novo grupo/i'];
        $clean = preg_replace($patterns, '', $message);
        $clean = trim($clean);
        // Se ficou vazio, tenta pegar a última palavra
        if (empty($clean)) {
            $words = explode(' ', $message);
            $clean = end($words);
        }
        return $clean;
    }

    // =========================================================
    // AÇÃO: ABRIR CHAMADO
    // =========================================================
    private function handleCreateOrder($message)
    {
        $user = Auth::user();
        if (!$user) {
            return "⚠️ Você precisa estar logado para abrir um chamado.";
        }

        // Extrair campos da mensagem
        $data = $this->extractOrderData($message);

        if (empty($data['title'])) {
            return "❌ Não consegui identificar o título do chamado.\nExemplo: 'abrir chamado com título Problema no login, prioridade alta, grupo TI'";
        }

        // Validar dados
        $validator = Validator::make($data, [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'group_id' => 'nullable|exists:groups,id',
        ]);

        if ($validator->fails()) {
            return "❌ Dados inválidos. Verifique os campos.";
        }

        try {
            $order = ServiceOrder::create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'priority' => $data['priority'] ?? 'medium',
                'group_id' => $data['group_id'] ?? null,
                'status' => 'open',
                'protocol' => $this->generateProtocol(),
            ]);

            return "✅ Chamado aberto com sucesso!\n" .
                   "📋 Protocolo: {$order->protocol}\n" .
                   "📌 Título: {$order->title}\n" .
                   "🟡 Prioridade: {$order->priority}\n" .
                   "👥 Grupo: " . ($order->group ? $order->group->name : 'Não definido');
        } catch (\Exception $e) {
            Log::error('Erro ao abrir chamado: ' . $e->getMessage());
            return "❌ Erro ao abrir chamado. Tente novamente.";
        }
    }

    private function extractOrderData($message)
    {
        $data = ['title' => '', 'description' => '', 'priority' => 'medium', 'group_id' => null];

        // Tenta extrair título entre "com título" ou "título:"
        if (preg_match('/título[: ]+(.*?)(,|$)/i', $message, $matches)) {
            $data['title'] = trim($matches[1]);
        } elseif (preg_match('/com título[: ]+(.*?)(,|$)/i', $message, $matches)) {
            $data['title'] = trim($matches[1]);
        } else {
            // Tenta pegar tudo após a primeira palavra (sem comando)
            $commandWords = ['abrir chamado', 'novo chamado', 'criar chamado'];
            $clean = $message;
            foreach ($commandWords as $cmd) {
                $clean = str_ireplace($cmd, '', $clean);
            }
            $parts = explode(',', $clean);
            $data['title'] = trim($parts[0]);
            array_shift($parts); // Remove o título dos extras
            $extras = implode(',', $parts);
            // Processa extras
            $this->parseExtras($extras, $data);
        }

        // Processar extras se ainda não foram processados
        if (!empty($extras)) {
            $this->parseExtras($extras, $data);
        }

        return $data;
    }

    private function parseExtras($text, &$data)
    {
        // Prioridade
        if (preg_match('/prioridade[: ]+(baixa|média|alta|urgente|low|medium|high|urgent)/i', $text, $matches)) {
            $priorityMap = [
                'baixa' => 'low',
                'média' => 'medium',
                'alta' => 'high',
                'urgente' => 'urgent',
                'low' => 'low',
                'medium' => 'medium',
                'high' => 'high',
                'urgent' => 'urgent',
            ];
            $data['priority'] = $priorityMap[strtolower($matches[1])] ?? 'medium';
        }

        // Grupo
        if (preg_match('/grupo[: ]+([^,]+)/i', $text, $matches)) {
            $groupName = trim($matches[1]);
            $group = Group::where('name', $groupName)->first();
            if ($group) {
                $data['group_id'] = $group->id;
            } else {
                // Se não encontrar, tenta por palavra-chave
                $group = Group::where('name', 'like', "%{$groupName}%")->first();
                if ($group) {
                    $data['group_id'] = $group->id;
                }
            }
        }

        // Descrição (restante)
        if (preg_match('/descrição[: ]+(.*?)(,|$)/i', $text, $matches)) {
            $data['description'] = trim($matches[1]);
        }
    }

    private function generateProtocol()
    {
        $prefix = 'OS';
        $date = date('Ymd');
        $random = strtoupper(substr(uniqid(), -4));
        return $prefix . '-' . $date . '-' . $random;
    }

    // =========================================================
    // CONSULTAS: ORDENS DE SERVIÇO
    // =========================================================
    private function handleOrders($msg)
    {
        try {
            $user = Auth::user();
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
                return "📊 Você tem {$count} chamado(s) com status '{$status}'.";
            }
            return "📊 Você tem um total de {$count} chamado(s).";
        } catch (\Exception $e) {
            Log::error('handleOrders: ' . $e->getMessage());
            return "❌ Erro ao buscar chamados.";
        }
    }

    // =========================================================
    // CONSULTAS: GRUPOS
    // =========================================================
    private function handleGroups($msg)
    {
        try {
            $user = Auth::user();
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
                return "📊 Você criou {$count} grupo(s).";
            }

            if (strpos($msg, 'membro') !== false || strpos($msg, 'membros') !== false || strpos($msg, 'pessoas') !== false) {
                $groups = Group::where('creator_id', $user->id)->withCount('users')->get();
                $totalMembers = $groups->sum('users_count');
                $groupCount = $groups->count();
                if ($groupCount == 0) {
                    return "📭 Você não criou nenhum grupo, então não tem membros.";
                }
                return "👥 Seus {$groupCount} grupo(s) têm um total de {$totalMembers} membro(s).";
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

            return "📊 Você está em {$count} grupo(s).";
        } catch (\Exception $e) {
            Log::error('handleGroups: ' . $e->getMessage());
            return "❌ Erro ao buscar grupos.";
        }
    }

    // =========================================================
    // RELATÓRIO COMPLETO
    // =========================================================
    private function handleFullReport()
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return "⚠️ Você precisa estar logado para ver seu relatório.";
            }

            $totalOrders = ServiceOrder::where('user_id', $user->id)->count();
            $openOrders = ServiceOrder::where('user_id', $user->id)->where('status', 'open')->count();
            $inProgressOrders = ServiceOrder::where('user_id', $user->id)->where('status', 'in_progress')->count();
            $completedOrders = ServiceOrder::where('user_id', $user->id)->where('status', 'completed')->count();

            $totalGroups = $user->groups()->count();
            $createdGroups = Group::where('creator_id', $user->id)->count();

            return "📊 Relatório do Usuário\n\n" .
                   "👤 Nome: {$user->name}\n" .
                   "📧 Email: {$user->email}\n\n" .
                   "📌 Chamados:\n" .
                   "• Total: {$totalOrders}\n" .
                   "• Abertos: {$openOrders}\n" .
                   "• Em andamento: {$inProgressOrders}\n" .
                   "• Resolvidos: {$completedOrders}\n\n" .
                   "📌 Grupos:\n" .
                   "• Participa de: {$totalGroups}\n" .
                   "• Criou: {$createdGroups}";
        } catch (\Exception $e) {
            Log::error('handleFullReport: ' . $e->getMessage());
            return "❌ Erro ao gerar relatório.";
        }
    }
}