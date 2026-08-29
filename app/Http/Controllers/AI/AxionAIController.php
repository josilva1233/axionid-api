<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\AIChatHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AxionAIController extends Controller
{
    /**
     * Enviar mensagem para a IA
     */
    public function chat(Request $request)
    {
        try {
            $request->validate([
                'message' => 'required|string|max:5000',
                'model' => 'nullable|string|in:llama3.1,mistral,phi3',
            ]);

            $message = $request->message;
            $model = $request->model ?? 'llama3.1';

            // Detectar se é uma consulta de dados
            $intent = $this->detectIntent($message);

            if ($intent['type'] === 'data_query') {
                $result = $this->executeFunction($intent['function'], $intent['parameters'] ?? []);
                $response = $this->generateResponseFromData($message, $result);
                
                return response()->json([
                    'message' => $response,
                    'data' => $result,
                    'model' => $model,
                    'timestamp' => now(),
                ]);
            }

            // Chat normal com IA
            $systemPrompt = $this->getSystemPrompt();
            $response = $this->callOllama($systemPrompt, $message, [], $model);

            return response()->json([
                'message' => $response,
                'model' => $model,
                'timestamp' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na IA:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Desculpe, tive um problema ao processar sua mensagem. Tente novamente.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar histórico do usuário
     */
    public function history(Request $request)
    {
        try {
            $history = AIChatHistory::where('user_id', auth()->id())
                ->orderBy('created_at', 'asc')
                ->get();

            $messages = [];
            foreach ($history as $item) {
                $messages[] = ['role' => 'user', 'content' => $item->user_message];
                $messages[] = ['role' => 'assistant', 'content' => $item->ai_response];
            }

            return response()->json($messages);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar histórico:', ['error' => $e->getMessage()]);
            return response()->json([], 500);
        }
    }

    /**
     * Limpar histórico do usuário
     */
    public function clearHistory(Request $request)
    {
        try {
            AIChatHistory::where('user_id', auth()->id())->delete();
            
            return response()->json([
                'message' => 'Histórico limpo com sucesso!'
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao limpar histórico:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erro ao limpar histórico.'
            ], 500);
        }
    }

    /**
     * Verificar status da IA
     */
    public function status()
    {
        $ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434');
        
        try {
            $response = Http::timeout(5)->get($ollamaUrl . '/api/tags');
            
            if ($response->successful()) {
                $models = $response->json();
                return response()->json([
                    'status' => 'online',
                    'models' => array_column($models['models'] ?? [], 'name'),
                    'active_model' => 'llama3.1',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Erro ao verificar status da IA:', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'status' => 'offline',
            'message' => 'IA indisponível no momento.'
        ], 503);
    }

    // =========================================================
    // 🔥 FUNÇÕES DISPONÍVEIS PARA A IA
    // =========================================================

    private function getAvailableFunctions()
    {
        return [
            [
                'name' => 'get_user_info',
                'description' => 'Busca informações de um usuário pelo nome ou email',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type' => 'string',
                            'description' => 'Nome ou email do usuário'
                        ]
                    ],
                    'required' => ['search']
                ]
            ],
            [
                'name' => 'get_service_orders',
                'description' => 'Busca ordens de serviço com filtros',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'description' => 'Status da OS: open, in_progress, resolved, closed',
                            'enum' => ['open', 'in_progress', 'resolved', 'closed']
                        ],
                        'priority' => [
                            'type' => 'string',
                            'description' => 'Prioridade: low, medium, high, urgent',
                            'enum' => ['low', 'medium', 'high', 'urgent']
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Número máximo de resultados',
                            'default' => 5
                        ]
                    ]
                ]
            ],
            [
                'name' => 'get_dashboard_stats',
                'description' => 'Busca estatísticas gerais do sistema',
                'parameters' => [
                    'type' => 'object',
                    'properties' => []
                ]
            ],
        ];
    }

    // =========================================================
    // 🔥 EXECUTAR FUNÇÕES
    // =========================================================

    private function executeFunction($functionName, $parameters)
    {
        switch ($functionName) {
            case 'get_user_info':
                return $this->executeGetUserInfo($parameters['search'] ?? '');
                
            case 'get_service_orders':
                return $this->executeGetServiceOrders(
                    $parameters['status'] ?? null,
                    $parameters['priority'] ?? null,
                    $parameters['limit'] ?? 5
                );
                
            case 'get_dashboard_stats':
                return $this->executeGetDashboardStats();
                
            default:
                return ['error' => "Função {$functionName} não encontrada."];
        }
    }

    // =========================================================
    // 🔥 IMPLEMENTAÇÃO DAS FUNÇÕES
    // =========================================================

    private function executeGetUserInfo($search)
    {
        $query = \App\Models\User::query();
        
        if (filter_var($search, FILTER_VALIDATE_EMAIL)) {
            $user = $query->where('email', $search)->first();
        } else {
            $user = $query->where('name', 'like', "%{$search}%")->first();
        }
        
        if (!$user) {
            return ['message' => "Usuário '{$search}' não encontrado."];
        }
        
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at->format('d/m/Y H:i'),
            'groups' => $user->groups->pluck('name')->toArray(),
        ];
    }

    private function executeGetServiceOrders($status, $priority, $limit)
    {
        $query = \App\Models\ServiceOrder::with(['user', 'technician', 'group']);
        
        if ($status) {
            $query->where('status', $status);
        }
        if ($priority) {
            $query->where('priority', $priority);
        }
        
        $orders = $query->latest()->limit($limit)->get();
        
        if ($orders->isEmpty()) {
            return ['message' => 'Nenhuma ordem de serviço encontrada.'];
        }
        
        return $orders->map(function ($order) {
            return [
                'id' => $order->id,
                'protocol' => $order->protocol,
                'title' => $order->title,
                'status' => $order->status,
                'priority' => $order->priority,
                'user' => $order->user?->name ?? 'N/A',
                'technician' => $order->technician?->name ?? 'Não atribuído',
                'group' => $order->group?->name ?? 'Sem grupo',
                'created_at' => $order->created_at->format('d/m/Y H:i'),
            ];
        })->toArray();
    }

    private function executeGetDashboardStats()
    {
        return [
            'total_users' => \App\Models\User::count(),
            'active_users' => \App\Models\User::where('is_active', true)->count(),
            'total_groups' => \App\Models\Group::count(),
            'total_orders' => \App\Models\ServiceOrder::count(),
            'orders_by_status' => [
                'open' => \App\Models\ServiceOrder::where('status', 'open')->count(),
                'in_progress' => \App\Models\ServiceOrder::where('status', 'in_progress')->count(),
                'resolved' => \App\Models\ServiceOrder::where('status', 'resolved')->count(),
                'closed' => \App\Models\ServiceOrder::where('status', 'closed')->count(),
            ],
            'urgent_orders' => \App\Models\ServiceOrder::where('priority', 'urgent')
                ->where('status', '!=', 'closed')
                ->count(),
        ];
    }

    // =========================================================
    // 🔥 DETECTAR INTENÇÃO
    // =========================================================

    private function detectIntent($message)
    {
        $message = strtolower($message);
        
        // Usuário
        if (strpos($message, 'usuário') !== false || strpos($message, 'usuario') !== false || 
            strpos($message, 'user') !== false || strpos($message, 'funcionário') !== false) {
            preg_match('/buscar|encontrar|procurar|quem é|sobre|dados de|info de\s+([a-zA-Z0-9@._\-]+)/', $message, $matches);
            $search = $matches[1] ?? '';
            if ($search) {
                return ['type' => 'data_query', 'function' => 'get_user_info', 'parameters' => ['search' => $search]];
            }
        }
        
        // Chamados
        if (strpos($message, 'chamado') !== false || strpos($message, 'os ') !== false || 
            strpos($message, 'ordem') !== false || strpos($message, 'atendimento') !== false) {
            $params = [];
            if (strpos($message, 'aberto') !== false) $params['status'] = 'open';
            if (strpos($message, 'andamento') !== false) $params['status'] = 'in_progress';
            if (strpos($message, 'resolvido') !== false) $params['status'] = 'resolved';
            if (strpos($message, 'fechado') !== false) $params['status'] = 'closed';
            if (strpos($message, 'urgente') !== false) $params['priority'] = 'urgent';
            if (strpos($message, 'alta') !== false) $params['priority'] = 'high';
            if (strpos($message, 'média') !== false || strpos($message, 'media') !== false) $params['priority'] = 'medium';
            if (strpos($message, 'baixa') !== false) $params['priority'] = 'low';
            
            return ['type' => 'data_query', 'function' => 'get_service_orders', 'parameters' => $params];
        }
        
        // Estatísticas
        if (strpos($message, 'estatística') !== false || strpos($message, 'estatistica') !== false || 
            strpos($message, 'resumo') !== false || strpos($message, 'dashboard') !== false ||
            strpos($message, 'total') !== false || strpos($message, 'quantos') !== false) {
            return ['type' => 'data_query', 'function' => 'get_dashboard_stats', 'parameters' => []];
        }
        
        return ['type' => 'chat'];
    }

    // =========================================================
    // 🔥 GERAR RESPOSTA
    // =========================================================

    private function generateResponseFromData($question, $data)
    {
        if (isset($data['error'])) {
            return $data['error'];
        }
        
        if (isset($data['id']) && isset($data['name']) && !isset($data['protocol'])) {
            return $this->formatUserResponse($data);
        }
        
        if (is_array($data) && isset($data[0]['protocol'])) {
            return $this->formatOrdersResponse($data);
        }
        
        if (isset($data['total_users'])) {
            return $this->formatStatsResponse($data);
        }
        
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function formatUserResponse($data)
    {
        return "**👤 {$data['name']}**\n" .
               "📧 Email: {$data['email']}\n" .
               "🛡️ Admin: " . ($data['is_admin'] ? 'Sim' : 'Não') . "\n" .
               "📅 Cadastro: {$data['created_at']}\n" .
               "📁 Grupos: " . (empty($data['groups']) ? 'Nenhum' : implode(', ', $data['groups']));
    }

    private function formatOrdersResponse($orders)
    {
        if (empty($orders)) {
            return "📋 Nenhuma ordem de serviço encontrada.";
        }
        
        $response = "📋 **Ordens de Serviço Encontradas:**\n\n";
        foreach ($orders as $order) {
            $response .= "🔹 **#{$order['protocol']}** - {$order['title']}\n" .
                         "   Status: {$order['status']} | Prioridade: {$order['priority']}\n" .
                         "   Solicitante: {$order['user']} | Técnico: {$order['technician']}\n" .
                         "   Data: {$order['created_at']}\n\n";
        }
        return $response;
    }

    private function formatStatsResponse($stats)
    {
        $response = "📊 **Estatísticas do Sistema:**\n\n" .
                    "👥 Usuários: {$stats['total_users']} (ativos: {$stats['active_users']})\n" .
                    "📁 Grupos: {$stats['total_groups']}\n" .
                    "🎫 Chamados: {$stats['total_orders']}\n" .
                    "\n**Status dos Chamados:**\n" .
                    "  📂 Abertos: {$stats['orders_by_status']['open']}\n" .
                    "  🔧 Em andamento: {$stats['orders_by_status']['in_progress']}\n" .
                    "  ✅ Resolvidos: {$stats['orders_by_status']['resolved']}\n" .
                    "  🔒 Fechados: {$stats['orders_by_status']['closed']}\n";
        
        if (isset($stats['urgent_orders'])) {
            $response .= "\n🚨 Chamados Urgentes: {$stats['urgent_orders']}";
        }
        
        return $response;
    }

    // =========================================================
    // 🔥 PROMPT E CHAMADA OLLAMA
    // =========================================================

    private function getSystemPrompt()
    {
        return "Você é o Axion AI, assistente operacional do sistema AxionID. " .
               "Você ajuda usuários com gestão de chamados, usuários, grupos e permissões. " .
               "Seja profissional, objetivo e útil. Responda em português. " .
               "Se não souber algo, diga que vai pesquisar e sugere contato com o suporte.";
    }

    private function callOllama($systemPrompt, $message, $history, $model)
    {
        $ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434');
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$history,
            ['role' => 'user', 'content' => $message],
        ];

        try {
            $response = Http::timeout(60)->post($ollamaUrl . '/api/chat', [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'options' => [
                    'temperature' => 0.7,
                    'top_p' => 0.9,
                    'max_tokens' => 1000,
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['message']['content'] ?? 'Desculpe, não consegui processar sua solicitação.';
            }

            Log::error('Erro Ollama:', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return $this->getFallbackResponse($message);

        } catch (\Exception $e) {
            Log::error('Erro ao chamar Ollama:', ['error' => $e->getMessage()]);
            return $this->getFallbackResponse($message);
        }
    }

    private function getFallbackResponse($message)
    {
        return "⚠️ No momento estou com dificuldades técnicas. " .
               "Por favor, tente novamente em alguns instantes. " .
               "Enquanto isso, você pode verificar a documentação ou entrar em contato com o suporte.";
    }
}