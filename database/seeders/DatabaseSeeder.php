<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Address;
use App\Models\Group;
use App\Models\Term;
use App\Models\ServiceOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Iniciando seed do banco de dados...');

        // 🔥 USUÁRIO ADMIN
        $admin = User::create([
            'name' => 'Josias Santos da Silva',
            'email' => 'josilva1233@gmail.com',
            'password' => Hash::make('password123'),
            'is_admin' => true,
            'is_active' => true,
            'cpf_cnpj' => '12328361765',
        ]);
        $this->command->info('✅ Admin criado: josilva1233@gmail.com');

        // 🔥 USUÁRIO COMUM
        $user = User::create([
            'name' => 'Juliane Machado de Farias',
            'email' => 'juliane.fariasp@gmail.com',
            'password' => Hash::make('password123'),
            'is_admin' => false,
            'is_active' => true,
            'cpf_cnpj' => '12322842702',
        ]);
        $this->command->info('✅ Usuário criado: juliane.fariasp@gmail.com');

        // 🔥 ENDEREÇO PARA O ADMIN
        Address::create([
            'user_id' => $admin->id,
            'zip_code' => '25230274',
            'street' => 'Rua Afonso Costa',
            'number' => 'SN',
            'neighborhood' => 'Pilar',
            'city' => 'Duque de Caxias',
            'state' => 'RJ',
            'complement' => 'LT 23 QD 27',
        ]);
        $this->command->info('✅ Endereço criado');

        // 🔥 GRUPO ADMINISTRADORES
        $group = Group::create([
            'name' => 'Administradores',
            'description' => 'Grupo de administradores do sistema',
            'is_default' => true,
        ]);

        // Adicionar admin ao grupo
        $group->users()->attach($admin->id, ['role' => 'admin']);
        $this->command->info('✅ Grupo criado');

        // 🔥 TERMOS DE USO
        Term::create([
            'content' => 'TERMOS DE USO DA PLATAFORMA AXIONID

1. Aceitação dos Termos
Ao utilizar a plataforma AxionID, você concorda com estes termos de uso.

2. Responsabilidades
O usuário é responsável por manter a confidencialidade de suas credenciais.

3. Privacidade
Seus dados são protegidos conforme a Lei Geral de Proteção de Dados (LGPD).

4. Uso Permitido
A plataforma deve ser utilizada apenas para fins autorizados.

5. Alterações
Estes termos podem ser atualizados a qualquer momento.

6. Contato
Para dúvidas, entre em contato com o suporte.

Data de vigência: 30/08/2026',
            'version' => '1.0.0',
            'is_active' => true,
            'created_by' => $admin->id,
            'published_at' => now(),
        ]);
        $this->command->info('✅ Termos de uso criados');

        // 🔥 ORDEM DE SERVIÇO ID 1 - Em andamento
        ServiceOrder::create([
            'protocol' => ServiceOrder::generateProtocol(),
            'title' => 'Chamado ID 1 - Em Andamento',
            'description' => 'Chamado em andamento para testes',
            'user_id' => $user->id,
            'group_id' => $group->id,
            'technician_id' => $admin->id,
            'status' => 'in_progress',
            'priority' => 'medium',
        ]);
        $this->command->info('✅ Chamado ID 1 criado');

        // 🔥 ORDEM DE SERVIÇO ID 2 - Aberto
        ServiceOrder::create([
            'protocol' => ServiceOrder::generateProtocol(),
            'title' => 'Chamado ID 2 - Aberto',
            'description' => 'Chamado aberto aguardando atendimento',
            'user_id' => $user->id,
            'group_id' => $group->id,
            'technician_id' => null,
            'status' => 'open',
            'priority' => 'high',
        ]);
        $this->command->info('✅ Chamado ID 2 criado');

        // 🔥 ORDEM DE SERVIÇO ID 3 - RESOLVIDO
        ServiceOrder::create([
            'protocol' => ServiceOrder::generateProtocol(),
            'title' => 'Chamado ID 3 - Resolvido',
            'description' => 'Chamado resolvido para teste de fechamento automático',
            'user_id' => $user->id,
            'group_id' => $group->id,
            'technician_id' => $admin->id,
            'status' => 'completed',
            'priority' => 'urgent',
            'resolved_at' => now(),
        ]);
        $this->command->info('✅ Chamado ID 3 criado e resolvido!');

        $this->command->info('========================================');
        $this->command->info('✅ Database seeded successfully!');
        $this->command->info('📧 Admin: josilva1233@gmail.com');
        $this->command->info('📧 User: juliane.fariasp@gmail.com');
        $this->command->info('🔑 Password: Jo@90849204');
        $this->command->info('📋 Chamado ID 3 resolvido em: ' . now()->format('d/m/Y H:i:s'));
        $this->command->info('========================================');
    }
}