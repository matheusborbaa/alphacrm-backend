<?php
use App\Http\Controllers\EmailController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LeadInteractionController;
use App\Http\Controllers\LeadController;
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

    Route::post('/me', [UserController::class, 'updateProfile'])->middleware(['auth:sanctum']);
// usuario rotas


/* leads que precisam de atenção */
Route::get('/dashboard/leads-atencao', function () {

    $limiteDias = 5;

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
});



/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/
Route::get('/meta/empreendimento-fields', function () {
    return \App\Models\EmpreendimentoFieldDefinition::orderBy('name')->get();
});
Route::get('/funnel', [DashboardHomeController::class, 'funnel']);



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


/*
|--------------------------------------------------------------------------
| LEAD STATUS
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->get('/lead-status', function () {
    return LeadStatus::select('id', 'name', 'order', 'color_hex')
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


/*
|--------------------------------------------------------------------------
| API PRIVADA (LEADS + EMPREENDIMENTOS)
|--------------------------------------------------------------------------
*/
Route::get('/users', function(){
    return \App\Models\User::select('id','name')->get();
})->middleware('auth:sanctum');
Route::get('/empreendimentos-lista', function(){
    return \App\Models\Empreendimento::select('id','name')->get();
})->middleware('auth:sanctum');


/*
|--------------------------------------------------------------------------
| ADMIN — CONFIGURAÇÃO DO PIPELINE (STATUS / SUBSTATUS)
|--------------------------------------------------------------------------
| Protegido por permissão: status_required_fields.manage.
| (Admin e gestor têm por padrão; corretor não.)
*/
Route::middleware(['auth:sanctum', 'can:status_required_fields.manage'])
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
| ADMIN — GERENCIAMENTO DE USUÁRIOS (CORRETORES)
|--------------------------------------------------------------------------
| Usa UserPolicy via authorize() dentro do controller.
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get   ('/users/admin',                  [UserController::class, 'index']);
    Route::post  ('/users',                        [UserController::class, 'store']);
    Route::get   ('/users/{user}',                 [UserController::class, 'show']);
    Route::put   ('/users/{user}',                 [UserController::class, 'update']);
    Route::delete('/users/{user}',                 [UserController::class, 'destroy']);
    Route::post  ('/users/{user}/reactivate',      [UserController::class, 'reactivate']);
    Route::post  ('/users/{user}/send-invite',     [UserController::class, 'sendInvite']);
});

Route::middleware(['auth:sanctum', 'role:admin,gestor,corretor'])->group(function () {

    Route::get('/leads',                  [LeadController::class, 'index']);
    Route::get('/leads/counts',           [LeadController::class, 'counts']);
    Route::get('/leads/check-duplicates', [LeadController::class, 'checkDuplicates']);

    Route::apiResource('empreendimentos', EmpreendimentoController::class);






});
Route::middleware(['auth:sanctum', 'role:admin,gestor'])->group(function () {
Route::get('/user/me', function (Request $request) {
    return response()->json($request->user());
})->middleware('auth:sanctum');

// buscar resumo inicial 
Route::get('/dashboard/atividades', function (Request $request) {

    $periodo = $request->get('periodo', 'mensal');

    switch ($periodo) {
        case 'diario':
            $start = now()->startOfDay();
            $end   = now()->endOfDay();
            break;

        case 'semanal':
            $start = now()->startOfWeek();
            $end   = now()->endOfWeek();
            break;

        default:
            $start = now()->startOfMonth();
            $end   = now()->endOfMonth();
            break;
    }

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
Route::post('/user/status', function (Request $request) {

    $user = auth()->user();

    $request->validate([
        'status' => 'required|in:disponivel,ocupado,offline'
    ]);

    $user->status_corretor = $request->status;
    $user->save();

    return response()->json([
        'message' => 'Status atualizado',
        'status' => $user->status_corretor
    ]);
})->middleware('auth:sanctum');
// APPOINTMENTS — rotas movidas pra fora do group (ver abaixo do fechamento do group).


Route::get('/dashboard/appointments', function (Request $request) {

    $periodo = $request->get('periodo', 'mensal');

    switch ($periodo) {
        case 'diario':
            $start = now()->startOfDay();
            $end   = now()->endOfDay();
            break;

        case 'semanal':
            $start = now()->startOfWeek();
            $end   = now()->endOfWeek();
            break;

        default:
            $start = now()->startOfMonth();
            $end   = now()->endOfMonth();
            break;
    }

    return Appointment::with('lead')
        ->whereBetween('starts_at', [$start, $end]) // 🔥 FILTRO AQUI
        ->orderBy('starts_at', 'asc')
        ->limit(10)
        ->get();
});

Route::post('/empreendimentos', [EmpreendimentoController::class,'store']);
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
    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    Route::put('/leads/editar/{lead}', [LeadController::class, 'update']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

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
    return response()->json($request->user());
});


/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin,gestor'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/funnel', [DashboardController::class, 'funnel']);
});
    Route::get('/dashboard/resumo', [DashboardController::class, 'resumo']);

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
    Route::get('/home/summary', [HomeController::class, 'summary']);
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






// rotas criação email
Route::post('/emails/create', [EmailController::class, 'store']);

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