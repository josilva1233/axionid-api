<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\Group;
use App\Models\Term;
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
        if (strpos($msg, 'abrir chamado') !== false || 
            strpos($msg, 'novo chamado') !== false || 
            strpos($msg, 'criar chamado') !== false) {
            return $this->handleCreateOrder($message);
        }

        // --- AÇÕES: RELATÓRIO ---
        if (strpos($msg, 'relatório') !== false || strpos($msg, 'gerar') !== false) {
            if (strpos($msg, 'excel') !== false || strpos($msg, 'xlsx') !== false) {
                return $this->handleGenerateReport('excel');
            }
            if (strpos($msg, 'pdf') !== false) {
                return $this->handleGenerateReport('pdf');
            }
            return "📊 Escolha o formato do relatório: **Excel** ou **PDF**.\nEx: 'gerar relatório Excel'";
        }

        // --- AÇÕES: TERMOS DE USO (admin) ---
        if (strpos($msg, 'criar termo') !== false || strpos($msg, 'novo termo') !== false) {
            return $this->handleCreateTerm($message);
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

        // --- RELATÓRIO COMPLETO (resumo) ---
        if (strpos($msg, 'resumo') !== false) {
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
               "• Resumo (relatório completo)\n\n" .
               "🚀 **Ações:**\n" .
               "• Criar grupo Nome do Grupo\n" .
               "• Abrir chamado com título Problema, prioridade alta, grupo TI, categoria Rede, departamento TI, prazo 2025-12-31, contato 1199999999\n" .
               "• Gerar relatório Excel\n" .
               "• Gerar relatório PDF\n" .
               "• Criar termo versão 1.0.0 | Conteúdo do termo";
    }

    // =========================================================
    // AÇÃO: CRIAR GRUPO
    // =========================================================
    private function handleCreateGroup($message)
    {
        $user = Auth::user();
        if (!$user) return "⚠️ Você precisa estar logado para criar um grupo.";

        $name = $this->extractGroupName($message);
        if (!$name) {
            return "❌ Não consegui identificar o nome do grupo.\nExemplo: 'criar grupo Financeiro'";
        }

        if (Group::where('name', $name)->exists()) {
            return "❌ Já existe um grupo com o nome '{$name}'. Escolha outro nome.";
        }

        try {
            $group = Group::create(['name' => $name, 'creator_id' => $user->id]);
            $group->users()->attach($user->id, ['role' => 'admin']);
            return "✅ Grupo '{$name}' criado com sucesso! Você é o administrador.";
        } catch (\Exception $e) {
            Log::error('Erro ao criar grupo: ' . $e->getMessage());
            return "❌ Erro ao criar o grupo. Tente novamente.";
        }
    }

    private function extractGroupName($message)
    {
        $patterns = ['/criar grupo/i', '/novo grupo/i'];
        $clean = preg_replace($patterns, '', $message);
        $clean = trim($clean);
        if (empty($clean)) {
            $words = explode(' ', $message);
            $clean = end($words);
        }
        return $clean;
    }

    // =========================================================
    // AÇÃO: ABRIR CHAMADO (completo)
    // =========================================================
    private function handleCreateOrder($message)
    {
        $user = Auth::user();
        if (!$user) return "⚠️ Você precisa estar logado para abrir um chamado.";

        // Extrair todos os campos
        $data = $this->extractOrderData($message);

        if (empty($data['title'])) {
            return "❌ Não consegui identificar o título.\nExemplo: 'abrir chamado com título Problema no login, prioridade alta, grupo TI, categoria Rede, departamento TI, prazo 2025-12-31, contato 1199999999'";
        }

        $validator = Validator::make($data, [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'group_id' => 'nullable|exists:groups,id',
            'category' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'deadline' => 'nullable|date',
            'contact_phone' => 'nullable|string|max:20',
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
                'category' => $data['category'] ?? null,
                'department' => $data['department'] ?? null,
                'deadline' => $data['deadline'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'status' => 'open',
                'protocol' => $this->generateProtocol(),
            ]);

            return "✅ Chamado aberto com sucesso!\n" .
                   "📋 Protocolo: {$order->protocol}\n" .
                   "📌 Título: {$order->title}\n" .
                   "🟡 Prioridade: {$order->priority}\n" .
                   "📂 Categoria: " . ($order->category ?? 'N/A') . "\n" .
                   "🏢 Departamento: " . ($order->department ?? 'N/A') . "\n" .
                   "📅 Prazo: " . ($order->deadline ? $order->deadline->format('d/m/Y') : 'N/A') . "\n" .
                   "📞 Contato: " . ($order->contact_phone ?? 'N/A') . "\n" .
                   "👥 Grupo: " . ($order->group ? $order->group->name : 'Não definido');
        } catch (\Exception $e) {
            Log::error('Erro ao abrir chamado: ' . $e->getMessage());
            return "❌ Erro ao abrir chamado. Tente novamente.";
        }
    }

    private function extractOrderData($message)
    {
        $data = [
            'title' => '',
            'description' => '',
            'priority' => 'medium',
            'group_id' => null,
            'category' => null,
            'department' => null,
            'deadline' => null,
            'contact_phone' => null,
        ];

        // Tenta extrair título
        if (preg_match('/título[: ]+(.*?)(,|$)/i', $message, $matches)) {
            $data['title'] = trim($matches[1]);
        } elseif (preg_match('/com título[: ]+(.*?)(,|$)/i', $message, $matches)) {
            $data['title'] = trim($matches[1]);
        } else {
            // Remove palavras-chave e pega primeiro segmento
            $commandWords = ['abrir chamado', 'novo chamado', 'criar chamado'];
            $clean = $message;
            foreach ($commandWords as $cmd) {
                $clean = str_ireplace($cmd, '', $clean);
            }
            $parts = explode(',', $clean);
            $data['title'] = trim($parts[0]);
            array_shift($parts);
            $extras = implode(',', $parts);
            $this->parseExtras($extras, $data);
        }

        // Se ainda tem extras não processados
        if (!empty($extras)) {
            $this->parseExtras($extras, $data);
        }

        return $data;
    }

    private function parseExtras($text, &$data)
    {
        // Prioridade
        if (preg_match('/prioridade[: ]+(baixa|média|alta|urgente|low|medium|high|urgent)/i', $text, $matches)) {
            $map = ['baixa'=>'low','média'=>'medium','alta'=>'high','urgente'=>'urgent','low'=>'low','medium'=>'medium','high'=>'high','urgent'=>'urgent'];
            $data['priority'] = $map[strtolower($matches[1])] ?? 'medium';
        }

        // Grupo
        if (preg_match('/grupo[: ]+([^,]+)/i', $text, $matches)) {
            $groupName = trim($matches[1]);
            $group = Group::where('name', $groupName)->first() ?? Group::where('name', 'like', "%{$groupName}%")->first();
            if ($group) $data['group_id'] = $group->id;
        }

        // Categoria
        if (preg_match('/categoria[: ]+([^,]+)/i', $text, $matches)) {
            $data['category'] = trim($matches[1]);
        }

        // Departamento
        if (preg_match('/departamento[: ]+([^,]+)/i', $text, $matches)) {
            $data['department'] = trim($matches[1]);
        }

        // Prazo (deadline)
        if (preg_match('/prazo[: ]+([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}\/[0-9]{2}\/[0-9]{4})/i', $text, $matches)) {
            $date = trim($matches[1]);
            // Converte dd/mm/yyyy para yyyy-mm-dd
            if (strpos($date, '/') !== false) {
                $parts = explode('/', $date);
                $date = $parts[2].'-'.$parts[1].'-'.$parts[0];
            }
            $data['deadline'] = $date;
        }

        // Contato (telefone)
        if (preg_match('/contato[: ]+([^,]+)/i', $text, $matches)) {
            $data['contact_phone'] = trim($matches[1]);
        }

        // Descrição (restante)
        if (preg_match('/descrição[: ]+(.*?)(,|$)/i', $text, $matches)) {
            $data['description'] = trim($matches[1]);
        }
    }

    private function generateProtocol()
    {
        return 'OS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
    }

    // =========================================================
    // AÇÃO: GERAR RELATÓRIO
    // =========================================================
    private function handleGenerateReport($format)
    {
        $user = Auth::user();
        if (!$user) return "⚠️ Você precisa estar logado.";

        // Verifica se é admin
        if (!$user->is_admin) {
            return "🔒 Apenas administradores podem gerar relatórios.";
        }

        $url = url('/api/v1/admin/reports/orders/' . $format);
        return "📊 Relatório em {$format} gerado!\n🔗 Clique para baixar: {$url}";
    }

    // =========================================================
    // AÇÃO: CRIAR TERMO DE USO (admin)
    // =========================================================
    private function handleCreateTerm($message)
    {
        $user = Auth::user();
        if (!$user) return "⚠️ Você precisa estar logado.";
        if (!$user->is_admin) return "🔒 Apenas administradores podem criar termos.";

        // Extrair versão e conteúdo
        preg_match('/versão[: ]+([0-9.]+)/i', $message, $versionMatch);
        preg_match('/conteúdo[: ]+(.*?)$/i', $message, $contentMatch);

        if (empty($versionMatch) || empty($contentMatch)) {
            return "❌ Para criar um termo, informe versão e conteúdo.\nExemplo: 'criar termo versão 1.0.0 | conteúdo: Regras de uso do sistema...'";
        }

        $version = trim($versionMatch[1]);
        $content = trim($contentMatch[1]);

        if (Term::where('version', $version)->exists()) {
            return "❌ Já existe um termo com a versão '{$version}'.";
        }

        try {
            // Se for a primeira versão, ativa automaticamente
            $isActive = Term::count() === 0;

            $term = Term::create([
                'version' => $version,
                'content' => $content,
                'is_active' => $isActive,
                'created_by' => $user->id,
            ]);

            if ($isActive) {
                return "✅ Termo v{$version} criado e ativado com sucesso!";
            } else {
                return "✅ Termo v{$version} criado com sucesso! (inativo)";
            }
        } catch (\Exception $e) {
            Log::error('Erro ao criar termo: ' . $e->getMessage());
            return "❌ Erro ao criar termo. Tente novamente.";
        }
    }

    // =========================================================
    // CONSULTAS: ORDENS DE SERVIÇO
    // =========================================================
    private function handleOrders($msg)
    {
        try {
            $user = Auth::user();
            if (!$user) return "⚠️ Você precisa estar logado.";

            $statusMap = [
                'aberto' => 'open', 'abertos' => 'open',
                'andamento' => 'in_progress', 'atendimento' => 'in_progress',
                'resolvido' => 'completed', 'concluído' => 'completed', 'concluido' => 'completed',
                'fechado' => 'closed', 'cancelado' => 'canceled',
            ];

            $status = null;
            foreach ($statusMap as $key => $value) {
                if (strpos($msg, $key) !== false) { $status = $value; break; }
            }

            $wantList = strpos($msg, 'listar') !== false || strpos($msg, 'mostrar') !== false || strpos($msg, 'quais') !== false;
            $query = ServiceOrder::where('user_id', $user->id);
            if ($status) $query->where('status', $status);
            $count = $query->count();

            if ($wantList) {
                $orders = $query->limit(5)->get(['id', 'title', 'status', 'created_at']);
                if ($orders->isEmpty()) return "📭 Você não tem chamados." . ($status ? " com status '{$status}'." : "");
                $list = $orders->map(fn($o) => "• #{$o->id} - {$o->title} ({$o->status}) - " . $o->created_at->format('d/m/Y H:i'))->implode("\n");
                return "📋 Seus chamados:\n{$list}\n" . ($count > 5 ? "\n... e mais " . ($count - 5) . " chamados." : "");
            }

            return $status ? "📊 Você tem {$count} chamado(s) com status '{$status}'." : "📊 Você tem um total de {$count} chamado(s).";
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
            if (!$user) return "⚠️ Você precisa estar logado.";

            if (strpos($msg, 'criou') !== false || strpos($msg, 'criei') !== false || strpos($msg, 'criados') !== false) {
                $count = Group::where('creator_id', $user->id)->count();
                if (strpos($msg, 'listar') !== false || strpos($msg, 'mostrar') !== false || strpos($msg, 'quais') !== false) {
                    $groups = Group::where('creator_id', $user->id)->get(['id', 'name']);
                    if ($groups->isEmpty()) return "📭 Você ainda não criou nenhum grupo.";
                    $list = $groups->map(fn($g) => "• {$g->name} (ID: {$g->id})")->implode("\n");
                    return "📋 Grupos que você criou:\n{$list}";
                }
                return "📊 Você criou {$count} grupo(s).";
            }

            if (strpos($msg, 'membro') !== false || strpos($msg, 'membros') !== false || strpos($msg, 'pessoas') !== false) {
                $groups = Group::where('creator_id', $user->id)->withCount('users')->get();
                $totalMembers = $groups->sum('users_count');
                $groupCount = $groups->count();
                if ($groupCount == 0) return "📭 Você não criou nenhum grupo, então não tem membros.";
                return "👥 Seus {$groupCount} grupo(s) têm um total de {$totalMembers} membro(s).";
            }

            $count = $user->groups()->count();
            if (strpos($msg, 'listar') !== false || strpos($msg, 'mostrar') !== false || strpos($msg, 'quais') !== false) {
                $groups = $user->groups()->get(['id', 'name']);
                if ($groups->isEmpty()) return "📭 Você não está em nenhum grupo.";
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
    // RELATÓRIO COMPLETO (RESUMO)
    // =========================================================
    private function handleFullReport()
    {
        try {
            $user = Auth::user();
            if (!$user) return "⚠️ Você precisa estar logado.";

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