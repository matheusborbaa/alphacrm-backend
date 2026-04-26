<?php
use App\Http\Controllers\EmailController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LeadInteractionController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadDocumentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\EmailLogsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardHomeController;
use App\Http\Controllers\MarketingReportController;
use App\Http\Controllers\CommissionReportController;
use App\Http\Controllers\EmpreendimentoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmpreendimentoImageController;
use App\Http\Controllers\EmpreendimentoFieldDefinitionController;
use App\Http\Controllers\EmpreendimentoFieldValueController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\MyCommissionController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\StatusRequiredFieldController;
use App\Http\Controllers\LeadCustomFieldValueController;
use App\Models\Appointment;
use App\Models\LeadStatus;
use App\Models\Lead;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LeadStatusController;
use App\Http\Controllers\LeadSubstatusController;
use App\Http\Controllers\RelatoriosController;
use App\Http\Controllers\UserMetaController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ChatConversationController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\VpsStatusController;

    Route::post('/me', [UserController::class, 'updateProfile'])->middleware(['auth:sanctum']);
    // Status do corretor (disponivel/ocupado/offline) — usado pelo rodízio.
    Route::post('/users/me/status', [UserController::class, 'updateStatus'])->middleware(['auth:sanctum']);
    // Sprint 3.8d — preferências pessoais (self-service). Hoje só
    // chat_read_receipts; futuras preferências de usuário entram aqui.
    Route::post('/users/me/preferences', [UserController::class, 'updatePreferences'])->middleware(['auth:sanctum']);

    // GET do usuário logado — precisa estar acessível pra QUALQUER role
    // (admin, gestor, corretor), senão o corretor não consegue sincronizar
    // o status_corretor do dropdown do header. A versão antiga dessa rota
    // mora dentro do grupo role:admin,gestor — mantemos ela lá por compat,
    // mas essa aqui é a canônica pra uso do frontend.
    Route::get('/user/me', function (\Illuminate\Http\Request $request) {
        return response()->json($request->user());
    })->middleware('auth:sanctum');

    // ALIAS LEGADO: home.js antigo (em cache do browser) chama POST /user/status.
    // A rota oficial é /users/me/status, mas enquanto os browsers não baixam
    // a versão nova do JS, redireciona pro mesmo controller pra manter o fluxo
    // (inclusive o rodízio de órfãos). Auth-only, sem role gate.
    Route::post('/user/status', [UserController::class, 'updateStatus'])
        ->middleware('auth:sanctum');
// usuario rotas


/* leads que precisam de atenção */
// Protegida com auth:sanctum — expõe nomes de leads, não pode ficar aberta.
Route::get('/dashboard/leads-atencao', function () {

    // Sprint H1.3 — limite de dias é configurável via Settings.
    // Default 5 dias (mantém compat com behavior anterior).
    // Range válido: 1-30 dias (validado tanto na escrita quanto aqui no
    // read pra defender contra valores absurdos que escapem da UI).
    $limiteDias = (int) \App\Models\Setting::get('leads_atencao_dias_sem_contato', 5);
    $limiteDias = max(1, min(30, $limiteDias));

    $leads = Lead::where(function ($q) use ($limiteDias) {

        $q->whereNull('updated_at') // nunca teve interação

          ->orWhere('updated_at', '<=', now()->subDays($limiteDias)); // mais de X dias

    })->orderBy('updated_at', 'asc')
    ->limit(5)
    ->get();

    $result = $leads->map(function ($lead) {

        if (!$lead->updated_at) {
            return [
                'id' => $lead->id,
                'name' => $lead->name,
                'dias' => 'Nunca'
            ];
        }

        $dias = (int) Carbon::parse($lead->updated_at)
            ->startOfDay()
            ->diffInDays(now()->startOfDay());

        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'dias' => $dias
        ];
    });

    return response()->json($result);
})->middleware('auth:sanctum');



/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/
Route::get('/meta/empreendimento-fields', function () {
    return \App\Models\EmpreendimentoFieldDefinition::orderBy('name')->get();
});
// Funil de conversão — exige auth pra não vazar dados de pipeline.
Route::get('/funnel', [DashboardHomeController::class, 'funnel'])
    ->middleware('auth:sanctum');



// Route::get('/ksanban', [KanbanController::class, 'index']);
/*
|--------------------------------------------------------------------------
| CIDADES (FILTRO)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->get(
    '/empreendimentos/cities',
    [EmpreendimentoController::class, 'cities']
);


/*
|--------------------------------------------------------------------------
| ADMIN - FIELD DEFINITIONS
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::apiResource(
            'empreendimento-field-definitions',
            EmpreendimentoFieldDefinitionController::class
        );
    });


Route::post('/auth/refresh', [AuthController::class, 'refresh']);
Route::get('/auth/permissions', [AuthController::class, 'permissions'])
    ->middleware('auth:sanctum');

// Sprint 3.0a — sessões + reauth. Essas rotas NÃO recebem o middleware
// 'fresh-auth' — senão vira deadlock (o user precisaria estar "fresh"
// pra poder confirmar a senha que o torna fresh).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/sessions',             [\App\Http\Controllers\SessionsController::class, 'index']);
    Route::delete('/auth/sessions/{token}',  [\App\Http\Controllers\SessionsController::class, 'destroy']);
    Route::post('/auth/confirm-password',    [AuthController::class, 'confirmPassword']);
});

Route::get(
    '/admin/empreendimentos/{empreendimento}/fields',
    [EmpreendimentoFieldValueController::class, 'index']
)->middleware(['auth:sanctum', 'role:admin,gestor']);

Route::post(
    '/admin/empreendimentos/{empreendimento}/fields',
    [EmpreendimentoFieldValueController::class, 'store']
)->middleware(['auth:sanctum', 'role:admin,gestor']);


/*
|--------------------------------------------------------------------------
| ROTAS PÚBLICAS (SITE)
|--------------------------------------------------------------------------
*/
Route::get('/public/', [EmpreendimentoController::class, 'publicIndex']);
Route::get('/public/home', [EmpreendimentoController::class, 'publicIndexHome']);
Route::get('/public/empreendimentos/{code}', [EmpreendimentoController::class, 'publicShow']);
Route::get('/public/empreendimentos/{code}/gallery', [EmpreendimentoController::class, 'publicGallery']);


/*
|--------------------------------------------------------------------------
| IMAGENS EMPREENDIMENTOS
|--------------------------------------------------------------------------
*/
Route::post(
    '/empreendimentos/{empreendimento}/images',
    [EmpreendimentoImageController::class, 'store']
)->middleware(['auth:sanctum', 'role:admin,gestor']);

// Remover imagem da galeria (admin/gestor). A rota usa a FK direta da
// image (não aninhada no empreendimento) porque o frontend só tem o id
// da imagem no contexto de delete.
Route::delete(
    '/empreendimento-images/{image}',
    [EmpreendimentoImageController::class, 'destroy']
)->middleware(['auth:sanctum', 'role:admin,gestor']);

// Marca uma imagem como capa do empreendimento (limpa is_cover das outras
// + atualiza empreendimentos.cover_image pra manter compat).
Route::post(
    '/empreendimento-images/{image}/cover',
    [EmpreendimentoImageController::class, 'setCover']
)->middleware(['auth:sanctum', 'role:admin,gestor']);


/*
|--------------------------------------------------------------------------
| DOCUMENTOS DO EMPREENDIMENTO (Book + Tabela de Valores)
|--------------------------------------------------------------------------
| Slots fixos — o slot vai no path como {slot} e é validado no controller
| contra whitelist ['book', 'price_table'].
*/
Route::post(
    '/empreendimentos/{empreendimento}/documents/{slot}',
    [\App\Http\Controllers\EmpreendimentoDocumentController::class, 'upload']
)->middleware(['auth:sanctum', 'role:admin,gestor']);

Route::delete(
    '/empreendimentos/{empreendimento}/documents/{slot}',
    [\App\Http\Controllers\EmpreendimentoDocumentController::class, 'destroy']
)->middleware(['auth:sanctum', 'role:admin,gestor']);


/*
|--------------------------------------------------------------------------
| LEAD STATUS
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->get('/lead-status', function () {
    // Sprint H1.4 — inclui is_terminal pra Configurações poder ler o estado
    // atual do checkbox "Etapa terminal" ao abrir o modal de edição. Sem
    // isso o checkbox sempre abria desmarcado e gravava false ao salvar,
    // sobrescrevendo a flag silenciosamente.
    return LeadStatus::select('id', 'name', 'order', 'color_hex', 'is_terminal')
        ->with(['substatus:id,lead_status_id,name,order,color_hex'])
        ->orderBy('order')
        ->get();
});


/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login']);

// Fluxo de recuperação de senha. Ambas as rotas são públicas (sem auth):
//  - forgot-password recebe email e dispara o ResetPasswordMail;
//  - reset-password recebe email+token+senha nova e grava a senha.
// Throttle por IP pra dificultar abuso (5 tentativas/minuto).
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1');


/*
|--------------------------------------------------------------------------
| API PRIVADA (LEADS + EMPREENDIMENTOS)
|--------------------------------------------------------------------------
*/
Route::get('/users', function(\Illuminate\Http\Request $request){
    // Inclui email e avatar — o chat usa isso pra renderizar a foto
    // do corretor nos itens de conversa e no seletor "Nova conversa".
    // Se virar pesado, paginar ou filtrar por active=true.
    //
    // Sprint Hierarquia — quando ?scope=hierarchy, filtra pela árvore
    // do caller (admin vê todos; gestor vê self+subordinados; corretor
    // vê só self). Por padrão (sem scope) retorna TODOS pra preservar
    // funcionamento do chat e outros lugares que precisam ver todo mundo.
    // Inclui empreendimento_access_mode + parent_user_id pra cascata
    // funcionar nos dropdowns que dependem dessa info (ex: cadastro user).
    $q = \App\Models\User::select(
        'id','name','email','avatar','role',
        'empreendimento_access_mode','parent_user_id'
    );

    if ($request->input('scope') === 'hierarchy') {
        $allowedIds = $request->user()->accessibleUserIds();
        if ($allowedIds !== null) {
            $q->whereIn('id', $allowedIds);
        }
    }

    $users = $q->get();

    // Sprint Hierarquia — anexa empreendimento_ids aos users de modo
    // 'specific' pra a UI de cadastro fazer cascata sem 1 fetch por user.
    // Carrega tudo de uma vez via whereIn → pluck. Barato (<<1k users).
    $specificIds = $users->where('empreendimento_access_mode', 'specific')->pluck('id');
    if ($specificIds->isNotEmpty()) {
        $pivot = \Illuminate\Support\Facades\DB::table('user_empreendimentos')
            ->whereIn('user_id', $specificIds)
            ->get(['user_id', 'empreendimento_id'])
            ->groupBy('user_id');

        $users->each(function ($u) use ($pivot) {
            $u->empreendimento_ids = $u->empreendimento_access_mode === 'specific'
                ? ($pivot->get($u->id)?->pluck('empreendimento_id')->all() ?? [])
                : [];
        });
    } else {
        $users->each(fn ($u) => $u->empreendimento_ids = []);
    }

    return $users;
})->middleware('auth:sanctum');
Route::get('/empreendimentos-lista', function(){
    return \App\Models\Empreendimento::select('id','name')->get();
})->middleware('auth:sanctum');


/*
|--------------------------------------------------------------------------
| ADMIN — CONFIGURAÇÃO DO PIPELINE (STATUS / SUBSTATUS)
|--------------------------------------------------------------------------
| Sprint Cargos — usa middleware `permission:` do Spatie (registrado em
| bootstrap/app.php) que SUPORTA `|` como OR. O `can:` nativo do Laravel
| NÃO trata pipe — lê tudo como um nome único de permission e dá 403.
| Aceita legacy `status_required_fields.manage` OU nova `settings.pipeline`
| pra cargos system + custom funcionarem juntos.
*/
Route::middleware(['auth:sanctum', 'permission:status_required_fields.manage|settings.pipeline'])
    ->prefix('admin')
    ->group(function () {

        Route::post('/lead-status/reorder',        [LeadStatusController::class, 'reorder']);
        Route::apiResource('lead-status',          LeadStatusController::class)
            ->parameters(['lead-status' => 'leadStatus'])
            ->except(['show']);

        Route::post('/lead-substatus/reorder',     [LeadSubstatusController::class, 'reorder']);
        Route::apiResource('lead-substatus',       LeadSubstatusController::class)
            ->parameters(['lead-substatus' => 'leadSubstatus'])
            ->except(['show']);
    });


/*
|--------------------------------------------------------------------------
| ADMIN — CARGOS E PERMISSÕES (Sprint Cargos — fase 2)
|--------------------------------------------------------------------------
| CRUD de cargos custom + leitura do catálogo de permissions.
| Admin-only (RoleController valida via ensureAdmin internamente
| usando effectiveRole — coluna + Spatie).
|
| Endpoints:
|   GET    /admin/permissions/catalog → estrutura agrupada pra UI
|   GET    /admin/roles               → lista cargos com perms + counts
|   GET    /admin/roles/{role}        → detalhe
|   POST   /admin/roles               → cria cargo custom (vazio)
|   PUT    /admin/roles/{role}        → atualiza nome/desc/perms
|                                       (cargos system: só desc/perms)
|   DELETE /admin/roles/{role}        → só se !is_system + 0 usuários
|
| Anti lock-out embutido: PUT bloqueia se admin removeria sua própria
| permission `settings.roles` (perderia acesso à tela).
*/
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/permissions/catalog', [\App\Http\Controllers\RoleController::class, 'catalog']);
    Route::get('/roles',               [\App\Http\Controllers\RoleController::class, 'index']);
    Route::get('/roles/{role}',        [\App\Http\Controllers\RoleController::class, 'show']);
    Route::post('/roles',              [\App\Http\Controllers\RoleController::class, 'store']);
    Route::put('/roles/{role}',        [\App\Http\Controllers\RoleController::class, 'update']);
    Route::delete('/roles/{role}',     [\App\Http\Controllers\RoleController::class, 'destroy']);
});


/*
|--------------------------------------------------------------------------
| LEAD SOURCES / CHANNELS / CAMPAIGNS — cadastros base
|--------------------------------------------------------------------------
| GET é liberado pra qualquer user autenticado (corretor também lê pra
| popular os selects dos formulários). Write (POST/PUT/DELETE) só admin
| e gestor, que gerenciam esses cadastros em Configurações.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/lead-sources',   [App\Http\Controllers\LeadSourceController::class,   'index']);
    Route::get('/lead-channels',  [App\Http\Controllers\LeadChannelController::class,  'index']);
    Route::get('/lead-campaigns', [App\Http\Controllers\LeadCampaignController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'role:admin,gestor'])->group(function () {
    Route::apiResource('lead-sources',   App\Http\Controllers\LeadSourceController::class)
        ->parameters(['lead-sources' => 'leadSource'])
        ->only(['store', 'update', 'destroy']);

    Route::apiResource('lead-channels',  App\Http\Controllers\LeadChannelController::class)
        ->parameters(['lead-channels' => 'leadChannel'])
        ->only(['store', 'update', 'destroy']);

    Route::apiResource('lead-campaigns', App\Http\Controllers\LeadCampaignController::class)
        ->parameters(['lead-campaigns' => 'leadCampaign'])
        ->only(['store', 'update', 'destroy']);
});


/*
|--------------------------------------------------------------------------
| ADMIN — GERENCIAMENTO DE USUÁRIOS (CORRETORES)
|--------------------------------------------------------------------------
| Usa UserPolicy via authorize() dentro do controller.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get   ('/users/admin',                  [UserController::class, 'index']);
    // Precisa vir ANTES de /users/{user} senão 'check-email' bate no wildcard.
    Route::get   ('/users/check-email',            [UserController::class, 'checkEmail']);
    Route::post  ('/users',                        [UserController::class, 'store']);
    Route::get   ('/users/{user}',                 [UserController::class, 'show']);
    Route::put   ('/users/{user}',                 [UserController::class, 'update']);
    Route::delete('/users/{user}',                 [UserController::class, 'destroy']);
    Route::post  ('/users/{user}/reactivate',      [UserController::class, 'reactivate']);
    Route::post  ('/users/{user}/send-invite',     [UserController::class, 'sendInvite']);
    Route::post  ('/users/{user}/photo',           [UserController::class, 'uploadPhoto'])
        ->middleware('role:admin,gestor');
});

Route::middleware(['auth:sanctum', 'role:admin,gestor,corretor'])->group(function () {

    Route::get('/leads',                  [LeadController::class, 'index']);
    Route::get('/leads/counts',           [LeadController::class, 'counts']);
    Route::get('/leads/check-duplicates', [LeadController::class, 'checkDuplicates']);

    // Fila de órfãos (read-only, admin+gestor). O próprio controller filtra role.
    Route::get('/leads/queue',            [LeadController::class, 'queue']);
    Route::get('/leads/queue/count',      [LeadController::class, 'queueCount']);

    Route::apiResource('empreendimentos', EmpreendimentoController::class);






});
Route::middleware(['auth:sanctum', 'role:admin,gestor'])->group(function () {
// /user/me agora é declarado acima fora do grupo role:admin,gestor — qualquer
// usuário autenticado (inclusive corretor) precisa dela pra sincronizar dados
// próprios no frontend (ex.: dropdown statusCorretor).

// buscar resumo inicial
Route::get('/dashboard/atividades', function (Request $request) {

    // Sprint 3.5a — DashboardPeriod resolve diario/semanal/mensal + range
    // customizado (periodo=custom&from=X&to=Y) vindo do filtro do Resumo
    // de Produtividade. Sem esse helper, o range custom era ignorado e
    // caía no default mensal.
    [$start, $end] = \App\Support\DashboardPeriod::resolve($request);

    // 🔥 AJUSTA NOMES DAS COLUNAS AQUI SE NECESSÁRIO
    $base = \App\Models\Appointment::whereBetween('starts_at', [$start, $end])
        ->where('status', 'completed');

    return response()->json([
        'ligacao' => (clone $base)->where('type', 'ligacao')->count(),
        'whatsapp' => (clone $base)->where('type', 'whatsapp')->count(),
        'email' => (clone $base)->where('type', 'email')->count(),
        'visita' => (clone $base)->where('type', 'visit')->count(),
    ]);
})->middleware('auth:sanctum');


// busca global
Route::get('/search', function (Request $request) {

    $q = $request->get('q');

    if (!$q) return [];

    return response()->json([

        'leads' => \App\Models\Lead::where('name', 'like', "%$q%")
            ->limit(5)
            ->get(['id', 'name']),

        'empreendimentos' => \App\Models\Empreendimento::where('name', 'like', "%$q%")
            ->limit(5)
            ->get(['id', 'name']),

        'appointments' => \App\Models\Appointment::where('title', 'like', "%$q%")
            ->limit(5)
            ->get(['id', 'title'])

    ]);
})->middleware('auth:sanctum');
// /user/status LEGADO: a rota oficial agora é /users/me/status (declarada
// no topo, fora do grupo role). Mantida aqui só como alias pra browsers
// com home.js antigo em cache — delega pra UserController@updateStatus
// (que também dispara rodízio de órfão quando vira 'disponivel').
// APPOINTMENTS — rotas movidas pra fora do group (ver abaixo do fechamento do group).


Route::get('/dashboard/appointments', function (Request $request) {

    // Sprint 3.1b — "Próximas Tarefas" do dashboard.
    //
    // Antes: filtrava só por starts_at num intervalo fixo (diário/semanal/mensal)
    // e NÃO filtrava por user — o que deixava o card:
    //   1) vazio quase sempre (tarefas novas usam due_at, não starts_at);
    //   2) vazando tarefas de outros corretores pro admin/gestor (e pro
    //      corretor, qualquer horário errado no sistema expõe tarefa alheia);
    //   3) misturando tarefas já concluídas.
    //
    // Agora:
    //   - Só do user autenticado (admin/gestor veem as PRÓPRIAS tarefas; pra
    //     visão de time, a /agenda.php tem filtro de usuário).
    //   - Não concluídas (completed_at IS NULL).
    //   - Janela: de 7 dias atrás (pra mostrar atrasadas) até 30 dias à frente.
    //   - Usa COALESCE(due_at, starts_at) pra ordenar por "quando" real,
    //     cobrindo tanto tarefas (due_at) quanto agendamentos (starts_at).
    $user = $request->user();
    if (!$user) {
        return response()->json(['message' => 'Não autenticado'], 401);
    }

    // Sprint 3.5+ — janela aceita range customizado (from/to) vindo do
    // filtro do Resumo de Produtividade. Sem filtro explícito, usa o
    // default "7 dias atrás → 30 dias à frente" pra mostrar atrasadas +
    // próximas no widget. Com filtro, usa o range/periodo do front.
    if ($request->hasAny(['periodo', 'from', 'to'])) {
        [$windowStart, $windowEnd] = \App\Support\DashboardPeriod::resolve($request);
    } else {
        $windowStart = now()->subDays(7);
        $windowEnd   = now()->addDays(30);
    }

    // Quando o filtro de produtividade está ativo (chip ou range), o front
    // manda ?include_completed=1 pra também trazer tarefas já concluídas,
    // que aparecem riscadas no widget.
    $includeCompleted = (bool) $request->boolean('include_completed', false);

    $query = Appointment::with('lead:id,name')
        ->where('user_id', $user->id)
        ->where(function ($q) use ($windowStart, $windowEnd) {
            $q->whereBetween('due_at',    [$windowStart, $windowEnd])
              ->orWhereBetween('starts_at', [$windowStart, $windowEnd])
              ->orWhereBetween('completed_at', [$windowStart, $windowEnd]);
        });

    if (!$includeCompleted) {
        $query->whereNull('completed_at');
    }

    $rows = $query
        ->orderByRaw('COALESCE(due_at, starts_at, completed_at) ASC')
        ->limit(20)
        ->get(['id', 'lead_id', 'title', 'type', 'task_kind',
               'due_at', 'starts_at', 'completed_at']);

    // Normaliza o campo "when" pro frontend não precisar escolher entre
    // due_at e starts_at (cada tarefa tem um ou outro, às vezes os dois).
    return $rows->map(function ($a) {
        $when = $a->due_at ?? $a->starts_at ?? $a->completed_at;
        $isCompleted = $a->completed_at !== null;
        return [
            'id'           => $a->id,
            'title'        => $a->title,
            'type'         => $a->type,
            'task_kind'    => $a->task_kind,
            'when'         => optional($when)->toIso8601String(),
            // Atrasada só faz sentido se ainda estiver aberta.
            'is_overdue'   => !$isCompleted && $when && $when->isPast(),
            'is_completed' => $isCompleted,
            'completed_at' => optional($a->completed_at)->toIso8601String(),
            'lead'         => $a->lead ? ['id' => $a->lead->id, 'name' => $a->lead->name] : null,
        ];
    });
});

// /empreendimentos POST já é coberto por Route::apiResource acima (grupo
// role:admin,gestor,corretor). A rota duplicada que existia aqui cadastrava
// o mesmo verbo pra `role:admin,gestor`, e como Laravel usa "última vence"
// ela sobrescrevia a do apiResource — efeito colateral: excluía corretor
// mesmo quando a política de fato permitia. Removida.
Route::post('/empreendimentos/{id}/fields', [EmpreendimentoFieldValueController::class,'storeCadastro']);
});


/*
|--------------------------------------------------------------------------
| APPOINTMENTS
|--------------------------------------------------------------------------
| IMPORTANTE: rotas com segmentos estáticos (/by-date, /by-month, /summary,
| /overdue) DEVEM vir antes da rota genérica /{id} — caso contrário, o
| Laravel interpreta "by-month" como id de Appointment.
|
| Mantidas FORA do group `role:admin,gestor` pra que o corretor também tenha
| acesso (agenda pessoal).
*/
Route::middleware(['auth:sanctum', 'role:admin,gestor,corretor'])->group(function () {
    Route::get('/appointments/by-date',  [AppointmentController::class, 'byDate']);
    Route::get('/appointments/by-month', [AppointmentController::class, 'byMonth']);
    Route::get('/appointments/summary',  [AppointmentController::class, 'summary']);
    Route::get('/appointments/overdue',  [AppointmentController::class, 'overdueList']);

    // Listagem unificada — alimenta o MODO LISTA da /agenda.php
    // (ligação + visita + reunião + tarefa em um só feed).
    Route::get('/appointments/list',     [AppointmentController::class, 'listUnified']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule']);
    Route::put('/appointments/{id}/complete',   [AppointmentController::class, 'complete']);
    Route::get('/appointments/{id}',            [AppointmentController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| TASKS / FOLLOW-UPS
|--------------------------------------------------------------------------
| Tarefas são appointments com type='task'. Controller próprio pra manter
| o fluxo (complete/reopen + filtros hoje/atrasadas/futuras) separado do
| de eventos de agenda.
|
| Regras de acesso:
|   - admin/gestor: enxergam e editam tudo.
|   - corretor:     próprias + scope='company' (leitura); só próprias (edição).
*/
Route::middleware(['auth:sanctum', 'role:admin,gestor,corretor'])->group(function () {
    Route::get('/tasks',                 [TaskController::class, 'index']);
    Route::post('/tasks',                [TaskController::class, 'store']);
    Route::get('/tasks/{id}',            [TaskController::class, 'show']);
    Route::put('/tasks/{id}',            [TaskController::class, 'update']);
    Route::put('/tasks/{id}/complete',   [TaskController::class, 'complete']);
    Route::put('/tasks/{id}/reopen',     [TaskController::class, 'reopen']);
    Route::delete('/tasks/{id}',         [TaskController::class, 'destroy']);

    // Comentários da tarefa — quem vê a tarefa pode comentar.
    Route::get   ('/tasks/{id}/comments',              [TaskCommentController::class, 'index']);
    Route::post  ('/tasks/{id}/comments',              [TaskCommentController::class, 'store']);
    Route::delete('/tasks/{id}/comments/{commentId}',  [TaskCommentController::class, 'destroy']);
});


/*
|--------------------------------------------------------------------------
| MANYCHAT WEBHOOK
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/manychat/leads', [
    \App\Http\Controllers\ManyChatWebhookController::class,
    'store'
]);

Route::post('/leads', [LeadController::class, 'store'])
    ->middleware('auth:sanctum');
/*
|--------------------------------------------------------------------------
| LEADS
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/leads/{lead}/interactions', [LeadInteractionController::class, 'store']);

    // Registro de primeiro contato — usado pelo banner dentro de lead.php.
    // Fecha o SLA (sla_status='met'), opcionalmente move o lead pra etapa+subetapa
    // definidas em /configuracoes (lead_after_first_contact_status_id/substatus_id)
    // e loga LeadHistory (first_contact + status_change + substatus_change quando aplicável).
    // Permissão: corretor responsável, admin ou gestor.
    Route::post('/leads/{id}/first-contact', [LeadController::class, 'firstContact']);

    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    // LGPD: devolve o valor cleartext de um campo sensível (fixo ou custom)
    // e registra LeadHistory type='pii_revealed'. Permissão: gestor/admin
    // ou corretor responsável pelo lead.
    Route::get('/leads/{lead}/reveal', [LeadController::class, 'reveal']);
    Route::put('/leads/editar/{lead}', [LeadController::class, 'update']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | DOCUMENTOS DO LEAD
    |----------------------------------------------------------------------
    | Uploads ficam em storage/app/private/leads/{lead}/ — nunca servidos
    | estaticamente. Download sempre passa pelo controller, que valida
    | permissão via LeadPolicy@view.
    |
    | Fluxo de exclusão (produto):
    |  - qualquer usuário com acesso ao lead SOLICITA (request-deletion)
    |  - o solicitante pode CANCELAR a própria solicitação
    |  - apenas admin APROVA (remove arquivo + row) ou REJEITA
    |
    | O LeadDocumentController encapsula a checagem de role='admin' para
    | approve/reject (ensureAdmin), então não precisamos de middleware
    | role aqui — a proteção continua funcionando mesmo se alguém trocar
    | a rota por engano.
    */
    // Lista GLOBAL de solicitações de exclusão pendentes (admin-only).
    // Fica antes da rota com {lead} pra não ser interpretada como lead_id.
    Route::get   ('/documents/pending-deletions',                             [LeadDocumentController::class, 'pendingDeletions']);

    // Lista GLOBAL de acessos/downloads de documentos (admin-only).
    // Alimenta a tela "Configurações → Logs de Download".
    Route::get   ('/documents/accesses',                                      [LeadDocumentController::class, 'allAccesses']);

    Route::get   ('/leads/{lead}/documents',                                  [LeadDocumentController::class, 'index']);
    Route::post  ('/leads/{lead}/documents',                                  [LeadDocumentController::class, 'store']);
    Route::get   ('/leads/{lead}/documents/{document}/download',              [LeadDocumentController::class, 'download']);
    Route::get   ('/leads/{lead}/documents/{document}/preview',               [LeadDocumentController::class, 'preview']);
    Route::post  ('/leads/{lead}/documents/{document}/request-deletion',      [LeadDocumentController::class, 'requestDeletion']);
    Route::post  ('/leads/{lead}/documents/{document}/cancel-deletion',       [LeadDocumentController::class, 'cancelDeletionRequest']);
    Route::post  ('/leads/{lead}/documents/{document}/approve-deletion',      [LeadDocumentController::class, 'approveDeletion']);
    Route::post  ('/leads/{lead}/documents/{document}/reject-deletion',       [LeadDocumentController::class, 'rejectDeletion']);

    // Retenção / lixeira (admin)
    Route::post  ('/leads/{lead}/documents/{document}/restore',               [LeadDocumentController::class, 'restore']);
    Route::post  ('/leads/{lead}/documents/{document}/force-purge',           [LeadDocumentController::class, 'forcePurge']);

    // Auditoria de acesso (admin)
    Route::get   ('/leads/{lead}/documents/{document}/accesses',              [LeadDocumentController::class, 'accesses']);

});


/*
|--------------------------------------------------------------------------
| SETTINGS (CONFIGURAÇÕES GLOBAIS)
|--------------------------------------------------------------------------
| Leitura: qualquer usuário autenticado (páginas leem flags como
| watermark_enabled pra decidir comportamento).
| Escrita: só admin — o controller valida role via ensureAdmin().
|
| Chaves conhecidas ficam no array ALLOWED_KEYS do controller; chave
| desconhecida retorna 404 tanto em leitura quanto em escrita.
*/
Route::middleware('auth:sanctum')->group(function () {
    // Rotas específicas de email vêm ANTES da wildcard /settings/{key}
    // pra o Laravel resolver corretamente (senão 'email' bateria no {key}).
    Route::get('/settings/email',       [EmailSettingsController::class, 'index']);
    Route::put('/settings/email',       [EmailSettingsController::class, 'update']);
    Route::post('/settings/email/test', [EmailSettingsController::class, 'test']);

    // Histórico de envios (admin-only). Namespace /admin/email-logs pra
    // deixar claro o escopo; roteado junto com os demais de settings.
    Route::get('/admin/email-logs',        [EmailLogsController::class, 'index']);
    Route::get('/admin/email-logs/{log}',  [EmailLogsController::class, 'show']);

    Route::get('/settings',            [SettingController::class, 'index']);
    Route::get('/settings/{key}',      [SettingController::class, 'show']);
    Route::put('/settings/{key}',      [SettingController::class, 'update']);
});


/*
|--------------------------------------------------------------------------
| INFRA / VPS — MONITORAMENTO DA HOSTINGER
|--------------------------------------------------------------------------
| GET /vps/status        → status + uptime + RAM + disco + CPU + rede
|   ?refresh=1           → força bypass do cache de 60s
| Admin-only. Se a integração não estiver configurada (HOSTINGER_API_KEY
| + HOSTINGER_VPS_ID no .env), devolve ok=false sem 5xx — o frontend
| renderiza um estado amigável com instruções.
*/
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/vps/status', [VpsStatusController::class, 'show']);

    // /server/capacity-alerts — fonte dos alertas que aparecem no
    // dashboard do admin. Diferente de /vps/status, só devolve o que
    // está ACIMA do threshold (lista pode vir vazia). Usado por
    // modules/home.js pra renderizar o banner de capacidade.
    Route::get('/server/capacity-alerts', [VpsStatusController::class, 'capacityAlerts']);
});


/*
|--------------------------------------------------------------------------
| NOTIFICAÇÕES
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')
    ->get('/notifications', function (Request $request) {

        $count = $request->user()
            ->unreadNotifications()
            ->count();

        $notifications = $request->user()
            ->unreadNotifications()
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'unread' => $notifications,
            'count'  => $count
        ]);
    });

Route::middleware('auth:sanctum')
    ->post('/notifications/{id}/read', function ($id, Request $request) {

        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['success' => true]);
    });


/*
|--------------------------------------------------------------------------
| USER
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    $u = $request->user();
    // Sprint Hierarquia (fix) — força o `role` no payload a refletir o
    // effectiveRole (coluna + Spatie), e não só a coluna. O frontend
    // sincroniza isso no localStorage via core/auth.js → refreshCurrentUser,
    // e o gate de páginas admin-only depende desse valor estar certo.
    $payload = $u->toArray();
    if (method_exists($u, 'effectiveRole')) {
        $eff = $u->effectiveRole();
        if ($eff !== '') {
            $payload['role'] = $eff;
        }
    }
    return response()->json($payload);
});


/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin,gestor'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index']);
    // Sprint H1.4 fix — aponta pro DashboardHomeController@funnel (novo
    // controller com filtro is_terminal + cálculo de tempo médio +
    // taxa de conversão). O DashboardController@funnel antigo tinha
    // shape incompatível com o frontend novo (chaves hardcoded em vez
    // de array de stages com totals/conv/avg_days).
    Route::get('/dashboard/funnel', [DashboardHomeController::class, 'funnel']);
});
    // Fora do group acima de propósito? Não — só faltava middleware. Agora
    // exige auth:sanctum pra não expor resumo de métricas pra anônimos.
    Route::get('/dashboard/resumo', [DashboardController::class, 'resumo'])
        ->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| KANBAN
|--------------------------------------------------------------------------
*/


Route::patch('/kanban/{lead}/move', [KanbanController::class, 'move'])
    ->middleware('auth:sanctum');
Route::get('/kanban', [KanbanController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:admin,gestor,corretor']);
Route::post('/kanban/reorder', [KanbanController::class, 'reorder'])
    ->middleware('auth:sanctum');
// Route::patch('/kanbans/leads/{lead}/move', [KanbanController::class, 'move'])
//  ->middleware(['auth:sanctum', 'role:admin,gestor,corretor']);


/*
|--------------------------------------------------------------------------
| CALENDÁRIO
|--------------------------------------------------------------------------
| Rotas movidas pra cima (antes de /appointments/{id}) pra evitar que o
| Laravel interprete "by-date", "by-month", "summary" e "overdue" como {id}.
*/




/*
|--------------------------------------------------------------------------
| RELATÓRIOS
|--------------------------------------------------------------------------
*/
Route::get('/reports/marketing', [MarketingReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:admin,gestor']);

Route::get('/leads/{lead}/audits', [\App\Http\Controllers\AuditController::class, 'index'])
    ->middleware('auth:sanctum');

Route::get('/reports/commissions', [CommissionReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'role:admin,gestor']);

/*
|--------------------------------------------------------------------------
| RELATÓRIOS — MÓDULO #44
|--------------------------------------------------------------------------
| Endpoints do novo módulo de relatórios (Corretor + Gerente).
| Middleware role:admin,gestor,corretor — o controller faz o escopo:
| corretor só vê os próprios dados; admin/gestor vê tudo e pode filtrar
| por corretor_id via query param.
|
| /reports/funnel            → Funil de conversão (por status)
| /reports/productivity      → Appointments, SLA, tempo de resposta
| /reports/origin-campaign   → Leads por origem / canal / campanha / cidade
| /reports/ranking           → Ranking de corretores + % meta
| /reports/evolution         → Série temporal últimos N meses
| /reports/export/{tipo}/{formato} → PDF ou XLSX
*/
Route::middleware(['auth:sanctum', 'role:admin,gestor,corretor'])->group(function () {
    Route::get('/reports/funnel',           [RelatoriosController::class, 'funnel']);
    Route::get('/reports/productivity',     [RelatoriosController::class, 'productivity']);
    Route::get('/reports/origin-campaign',  [RelatoriosController::class, 'originCampaign']);
    Route::get('/reports/ranking',          [RelatoriosController::class, 'ranking']);
    Route::get('/reports/evolution',        [RelatoriosController::class, 'evolution']);

    Route::get('/reports/export/{tipo}/{formato}',
        [\App\Http\Controllers\RelatoriosExportController::class, 'export'])
        ->where('tipo', 'funnel|productivity|origin|ranking')
        ->where('formato', 'pdf|xlsx');
});

/*
|--------------------------------------------------------------------------
| METAS DOS CORRETORES (gamificação / ranking)
|--------------------------------------------------------------------------
| Admin/gestor gerenciam. Corretor só lê a própria.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user-metas',            [UserMetaController::class, 'index']);
    Route::get('/user-metas/{userMeta}', [UserMetaController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'role:admin,gestor'])->group(function () {
    Route::post  ('/user-metas',             [UserMetaController::class, 'store']);
    Route::put   ('/user-metas/{userMeta}',  [UserMetaController::class, 'update']);
    Route::delete('/user-metas/{userMeta}',  [UserMetaController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| HOME — MÓDULO #45
|--------------------------------------------------------------------------
| Endpoint consolidado da Home (financeiro + gamificação + metas do mês).
*/
Route::middleware(['auth:sanctum', 'role:admin,gestor,corretor'])->group(function () {
    Route::get('/home/summary',                [HomeController::class, 'summary']);
    // Sprint 3.5b — Minhas Próximas Comissões.
    Route::get('/dashboard/next-commissions',  [HomeController::class, 'nextCommissions']);

    // Sprint 3.6a — Cores customizáveis por tipo de tarefa.
    // GET é pra todos autenticados (precisa do mapa pra renderizar badges).
    // O PUT abaixo é restrito a admin dentro do próprio controller.
    Route::get('/task-kind-colors',          [\App\Http\Controllers\TaskKindColorController::class, 'index']);
    Route::put('/task-kind-colors/{kind}',   [\App\Http\Controllers\TaskKindColorController::class, 'update']);

    // Sprint 3.7b — Gestão de comissões.
    // CommissionController faz authorization interna: corretor só vê as dele;
    // admin/gestor têm acesso completo + transições.
    Route::get('/commissions',                 [\App\Http\Controllers\CommissionController::class, 'index']);
    Route::get('/commissions/summary',         [\App\Http\Controllers\CommissionController::class, 'summary']);
    Route::get('/commissions/{id}',            [\App\Http\Controllers\CommissionController::class, 'show']);
    Route::put('/commissions/{id}',            [\App\Http\Controllers\CommissionController::class, 'update']);
    Route::post('/commissions/{id}/confirm',   [\App\Http\Controllers\CommissionController::class, 'confirm']);
    Route::post('/commissions/{id}/approve',   [\App\Http\Controllers\CommissionController::class, 'approve']);
    Route::post('/commissions/{id}/pay',       [\App\Http\Controllers\CommissionController::class, 'pay']);
    Route::post('/commissions/{id}/partial',   [\App\Http\Controllers\CommissionController::class, 'partial']);
    Route::post('/commissions/{id}/cancel',    [\App\Http\Controllers\CommissionController::class, 'cancel']);
    Route::get('/commissions/{id}/comments',   [\App\Http\Controllers\CommissionController::class, 'comments']);
    Route::post('/commissions/{id}/comments',  [\App\Http\Controllers\CommissionController::class, 'addComment']);
});


/*
|--------------------------------------------------------------------------
| AGENDA
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/myCommissions', [CommissionReportController::class, 'myCommissions']);

    Route::get('/agenda', [AppointmentController::class, 'index']);
    Route::post('/agenda', [AppointmentController::class, 'store']);
    Route::get('/agenda/{appointment}', [AppointmentController::class, 'show']);
    Route::put('/agenda/{appointment}', [AppointmentController::class, 'update']);
    Route::delete('/agenda/{appointment}', [AppointmentController::class, 'destroy']);
});


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->post('/auth/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();
    return response()->json(['message' => 'Logout realizado']);
});


/*
|--------------------------------------------------------------------------
| CHAT INTERNO (DM 1-a-1)
|--------------------------------------------------------------------------
| Fase D - Sprint 1: só texto, sem anexos (anexos vêm no Sprint 2).
| Qualquer usuário autenticado pode abrir DM com qualquer outro ativo.
| O controller garante autorização por conversa (ensureParticipant).
|
| Endpoints:
|   GET  /chat/conversations                      -> lista conversas do user (com unread_count)
|   POST /chat/conversations                      -> abre/retorna DM com user_id
|   GET  /chat/conversations/{id}/messages        -> lista msgs (paginada)
|   POST /chat/conversations/{id}/messages        -> envia msg
|   POST /chat/conversations/{id}/read            -> marca conversa como lida (Sprint 3)
|   GET  /chat/unread-count                       -> total consolidado p/ badge global (Sprint 3)
|
| Sprint 2 - Anexos:
|   POST /chat/attachments/upload                 -> upload draft (multipart file)
|   GET  /chat/attachments/{id}/download          -> baixa/preview do anexo upload
|   DELETE /chat/attachments/{id}/draft           -> cancela upload draft (antes de enviar msg)
*/
Route::middleware(['auth:sanctum', 'chat.enabled'])->prefix('chat')->group(function () {
    Route::get ('/unread-count',                  [ChatConversationController::class, 'unreadCount']);

    Route::get ('/conversations',                 [ChatConversationController::class, 'index']);
    Route::post('/conversations',                 [ChatConversationController::class, 'store']);
    Route::get ('/conversations/{id}/messages',   [ChatMessageController::class, 'index'])
        ->whereNumber('id');
    Route::post('/conversations/{id}/messages',   [ChatMessageController::class, 'store'])
        ->whereNumber('id');
    Route::post('/conversations/{id}/read',       [ChatMessageController::class, 'markRead'])
        ->whereNumber('id');

    // Sprint 4.1 — Pin de mensagens importantes
    Route::get   ('/conversations/{id}/pinned',   [ChatMessageController::class, 'pinned'])
        ->whereNumber('id');
    Route::post  ('/messages/{id}/pin',           [ChatMessageController::class, 'togglePin'])
        ->whereNumber('id');
    Route::delete('/messages/{id}/pin',           [ChatMessageController::class, 'togglePin'])
        ->whereNumber('id');

    // Sprint 4.6 — Editar e apagar mensagem.
    // PATCH valida regras de janela (15min) + não-lida; DELETE valida
    // não-lida pra autor mas é livre pra admin (LGPD/moderação).
    // Eventos ChatMessageEdited/ChatMessageDeleted disparam pro canal
    // private-conversation.{id} pra UI atualizar nos dois lados.
    Route::patch ('/messages/{id}',               [ChatMessageController::class, 'update'])
        ->whereNumber('id');
    Route::delete('/messages/{id}',               [ChatMessageController::class, 'destroy'])
        ->whereNumber('id');

    // Sprint 4.2 — Busca no histórico. Escopa por default só às conversas
    // do user; conversation_id (opcional) restringe a uma conversa específica.
    Route::get   ('/search',                      [ChatMessageController::class, 'search']);

    // Sprint 2 — Anexos
    Route::post  ('/attachments/upload',          [ChatAttachmentController::class, 'upload']);
    Route::get   ('/attachments/{id}/download',   [ChatAttachmentController::class, 'download'])
        ->whereNumber('id');
    Route::delete('/attachments/{id}/draft',      [ChatAttachmentController::class, 'cancelDraft'])
        ->whereNumber('id');

    // Sprint 4.x — endpoint dedicado pra abrir lead_document pelo link do chat.
    // Valida participação + availability ao vivo (bloqueia mesmo admin se o
    // doc foi excluído / tem solicitação pendente).
    Route::get   ('/attachments/{id}/lead-document', [ChatAttachmentController::class, 'openLeadDocument'])
        ->whereNumber('id');
});


/*
|--------------------------------------------------------------------------
| REALTIME (Sprint 4.5)
|--------------------------------------------------------------------------
| Config pública do Reverb (pubkey, host, port, scheme) pro frontend
| inicializar o Echo. NUNCA expõe o app_secret. Fora do grupo /chat
| de propósito — esse endpoint é genérico de realtime, não chat-específico,
| e não passa pelo middleware chat.enabled (precisamos saber se realtime
| está disponível mesmo se o chat estiver desligado).
*/
Route::middleware('auth:sanctum')->get('/realtime/config', function () {
    $driver = config('broadcasting.default');
    if ($driver !== 'reverb') {
        return response()->json(['enabled' => false]);
    }

    // Sprint 4.5 — host PÚBLICO (browser conecta via nginx) é diferente
    // do host SERVER-SIDE (Laravel publica direto no daemon local).
    // Lemos via config() em vez de env() porque com config:cache em
    // produção o env() retorna null fora dos arquivos de config.
    // Se 'public' não foi configurado, cai pro 'options' (compat com
    // setups single-host antigos).
    $publicHost   = config('broadcasting.connections.reverb.public.host')
                    ?: config('broadcasting.connections.reverb.options.host');
    $publicPort   = config('broadcasting.connections.reverb.public.port')
                    ?: config('broadcasting.connections.reverb.options.port');
    $publicScheme = config('broadcasting.connections.reverb.public.scheme')
                    ?: config('broadcasting.connections.reverb.options.scheme', 'http');

    return response()->json([
        'enabled' => true,
        'driver'  => 'reverb',
        'key'     => config('broadcasting.connections.reverb.key'),
        'host'    => $publicHost,
        'port'    => (int) $publicPort,
        'scheme'  => $publicScheme,
        'tls'     => $publicScheme === 'https',
    ]);
});


/*
|--------------------------------------------------------------------------
| CAMPOS CUSTOMIZADOS + REGRAS DE OBRIGATORIEDADE POR STATUS
|--------------------------------------------------------------------------
| - custom-fields           : CRUD do catálogo de campos customizados
| - status-required-fields  : CRUD das regras "quando status X, campo Y obrigatório"
| - for-target              : endpoint consumido pelo modal no frontend
|                             (dado um status/substatus, quais campos pedir)
| - leads/{lead}/custom-field-values : salvar/ler valores custom do lead
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Catálogo de campos customizados (admin-only seria ideal, mas leitura é livre)
    Route::get   ('/custom-fields',              [CustomFieldController::class, 'index']);
    Route::get   ('/custom-fields/{customField}', [CustomFieldController::class, 'show']);
    Route::post  ('/custom-fields',              [CustomFieldController::class, 'store'])
        ->middleware('role:admin,gestor');
    Route::put   ('/custom-fields/{customField}', [CustomFieldController::class, 'update'])
        ->middleware('role:admin,gestor');
    Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])
        ->middleware('role:admin,gestor');

    // Regras de obrigatoriedade
    Route::get   ('/status-required-fields',                           [StatusRequiredFieldController::class, 'index']);
    Route::get   ('/status-required-fields/for-target',                [StatusRequiredFieldController::class, 'forTarget']);
    Route::post  ('/status-required-fields',                           [StatusRequiredFieldController::class, 'store'])
        ->middleware('role:admin,gestor');
    Route::put   ('/status-required-fields/{statusRequiredField}',     [StatusRequiredFieldController::class, 'update'])
        ->middleware('role:admin,gestor');
    Route::delete('/status-required-fields/{statusRequiredField}',     [StatusRequiredFieldController::class, 'destroy'])
        ->middleware('role:admin,gestor');

    // Valores dos custom fields por lead
    Route::get ('/leads/{lead}/custom-field-values', [LeadCustomFieldValueController::class, 'index']);
    Route::post('/leads/{lead}/custom-field-values', [LeadCustomFieldValueController::class, 'bulkStore']);
});



/*
|--------------------------------------------------------------------------
| ================= ROTAS DUPLICADAS (ISOLADAS PARA TESTE) =================
|--------------------------------------------------------------------------
| Mantidas apenas para verificação.
| NÃO ESTÃO ATIVAS.
|--------------------------------------------------------------------------
*/

// Route::middleware(['auth:sanctum', 'role:admin,gestor'])->group(function () {
//     Route::apiResource('empreendimentos', EmpreendimentoController::class);
// });






// rotas criação email — admin/gestor só (cria contas de email no cPanel).
Route::post('/emails/create', [EmailController::class, 'store'])
    ->middleware(['auth:sanctum', 'role:admin,gestor']);

Route::get('/teste-whm', function () {
    return Http::withOptions([
        'verify' => false
    ])->withHeaders([
        'Authorization' => 'whm encu0499:2CRS1I7WJCDHJ9UXATQX7CN5MIS05KG3'
    ])->get('https://us155-cp.valueserver.com.br:2087/json-api/version');
});

Route::get('/teste-contas', function () {
    return Http::withOptions([
        'verify' => false
    ])->withHeaders([
        'Authorization' => 'whm encu0499:2CRS1I7WJCDHJ9UXATQX7CN5MIS05KG3'
    ])->get('https://us155-cp.valueserver.com.br:2087/json-api/listaccts');
});

Route::get('/teste-cpanel', function () {
    return Http::withOptions([
        'verify' => false
    ])->withHeaders([
        'Authorization' => 'whm encu0499:2CRS1I7WJCDHJ9UXATQX7CN5MIS05KG3'
    ])->get('https://us155-cp.valueserver.com.br:2087/json-api/cpanel', [
        'cpanel_jsonapi_user' => 'alphadom',
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module' => 'Email',
        'cpanel_jsonapi_func' => 'listpops'
    ]);
});

Route::get('/criar-email-teste', function () {

    $response = Http::withOptions([
        'verify' => false
    ])->withBasicAuth('alphadom', 'appalpha123A@!')
    ->get('https://alphadomusimobiliaria.com.br:2083/execute/Email/add_pop', [
        'email' => 'teste'.rand(100,999),
        'domain' => 'alphadomusimobiliaria.com.br',
        'password' => 'SenhaForte@123',
        'quota' => 1024
    ]);

    return response()->json([
        'status' => $response->status(),
        'body' => $response->json()
    ]);
});