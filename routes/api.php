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
use App\Http\Controllers\LeadCustomFieldFileController;
use App\Http\Controllers\AdminFilesController;
use App\Http\Controllers\MediaController;
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

    Route::post('/users/me/status', [UserController::class, 'updateStatus'])->middleware(['auth:sanctum']);

    Route::post('/users/me/heartbeat', [UserController::class, 'heartbeat'])->middleware(['auth:sanctum']);

    Route::post('/users/me/preferences', [UserController::class, 'updatePreferences'])->middleware(['auth:sanctum']);

    Route::get('/user/me', function (\Illuminate\Http\Request $request) {
        return response()->json($request->user());
    })->middleware('auth:sanctum');

    Route::post('/user/status', [UserController::class, 'updateStatus'])
        ->middleware('auth:sanctum');

Route::get('/dashboard/leads-atencao', function () {

    $limiteDias = (int) \App\Models\Setting::get('leads_atencao_dias_sem_contato', 5);
    $limiteDias = max(1, min(30, $limiteDias));

    $leads = Lead::where(function ($q) use ($limiteDias) {

        $q->whereNull('updated_at')

          ->orWhere('updated_at', '<=', now()->subDays($limiteDias));

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

Route::get('/meta/empreendimento-fields', function () {
    return \App\Models\EmpreendimentoFieldDefinition::orderBy('name')->get();
});

Route::get('/funnel', [DashboardHomeController::class, 'funnel'])
    ->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->get(
    '/empreendimentos/cities',
    [EmpreendimentoController::class, 'cities']
);

Route::middleware(['auth:sanctum', 'permission:empreendimentos.field_definitions.manage|settings.empreendimento_fields'])
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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/sessions',             [\App\Http\Controllers\SessionsController::class, 'index']);
    Route::delete('/auth/sessions/{token}',  [\App\Http\Controllers\SessionsController::class, 'destroy']);
    Route::post('/auth/confirm-password',    [AuthController::class, 'confirmPassword']);
});

Route::get(
    '/admin/empreendimentos/{empreendimento}/fields',
    [EmpreendimentoFieldValueController::class, 'index']
)->middleware(['auth:sanctum', 'permission:settings.empreendimento_fields|empreendimentos.field_definitions.manage|empreendimentos.update']);

Route::post(
    '/admin/empreendimentos/{empreendimento}/fields',
    [EmpreendimentoFieldValueController::class, 'store']
)->middleware(['auth:sanctum', 'permission:settings.empreendimento_fields|empreendimentos.field_definitions.manage|empreendimentos.update']);

Route::get('/public/', [EmpreendimentoController::class, 'publicIndex']);
Route::get('/public/home', [EmpreendimentoController::class, 'publicIndexHome']);
Route::get('/public/empreendimentos/{code}', [EmpreendimentoController::class, 'publicShow']);
Route::get('/public/empreendimentos/{code}/gallery', [EmpreendimentoController::class, 'publicGallery']);

Route::post(
    '/empreendimentos/{empreendimento}/images',
    [EmpreendimentoImageController::class, 'store']
)->middleware(['auth:sanctum', 'permission:empreendimentos.update|empreendimentos.manage']);

Route::delete(
    '/empreendimento-images/{image}',
    [EmpreendimentoImageController::class, 'destroy']
)->middleware(['auth:sanctum', 'permission:empreendimentos.update|empreendimentos.manage']);

Route::post(
    '/empreendimento-images/{image}/cover',
    [EmpreendimentoImageController::class, 'setCover']
)->middleware(['auth:sanctum', 'permission:empreendimentos.update|empreendimentos.manage']);

Route::post(
    '/empreendimentos/{empreendimento}/documents/{slot}',
    [\App\Http\Controllers\EmpreendimentoDocumentController::class, 'upload']
)->middleware(['auth:sanctum', 'permission:empreendimentos.update|empreendimentos.manage']);

Route::delete(
    '/empreendimentos/{empreendimento}/documents/{slot}',
    [\App\Http\Controllers\EmpreendimentoDocumentController::class, 'destroy']
)->middleware(['auth:sanctum', 'permission:empreendimentos.update|empreendimentos.manage']);

Route::middleware('auth:sanctum')->get('/lead-status', function () {

    return LeadStatus::select('id', 'name', 'order', 'color_hex', 'is_terminal')
        ->with(['substatus:id,lead_status_id,name,order,color_hex'])
        ->orderBy('order')
        ->get();
});

Route::post('/auth/login', [AuthController::class, 'login']);

Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

Route::get('/users', function(\Illuminate\Http\Request $request){

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

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/permissions/catalog', [\App\Http\Controllers\RoleController::class, 'catalog']);
    Route::get('/roles',               [\App\Http\Controllers\RoleController::class, 'index']);
    Route::get('/roles/{role}',        [\App\Http\Controllers\RoleController::class, 'show']);
    Route::post('/roles',              [\App\Http\Controllers\RoleController::class, 'store']);
    Route::put('/roles/{role}',        [\App\Http\Controllers\RoleController::class, 'update']);
    Route::delete('/roles/{role}',     [\App\Http\Controllers\RoleController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/lead-sources',   [App\Http\Controllers\LeadSourceController::class,   'index']);
    Route::get('/lead-channels',  [App\Http\Controllers\LeadChannelController::class,  'index']);
    Route::get('/lead-campaigns', [App\Http\Controllers\LeadCampaignController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'permission:settings.general|status_required_fields.manage'])->group(function () {
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

Route::middleware('auth:sanctum')->group(function () {
    Route::get   ('/users/admin',                  [UserController::class, 'index']);

    Route::get   ('/users/check-email',            [UserController::class, 'checkEmail']);
    Route::post  ('/users',                        [UserController::class, 'store']);
    Route::get   ('/users/{user}',                 [UserController::class, 'show']);
    Route::put   ('/users/{user}',                 [UserController::class, 'update']);
    Route::delete('/users/{user}',                 [UserController::class, 'destroy']);
    Route::post  ('/users/{user}/reactivate',      [UserController::class, 'reactivate']);
    Route::post  ('/users/{user}/send-invite',     [UserController::class, 'sendInvite']);
    Route::post  ('/users/{user}/photo',           [UserController::class, 'uploadPhoto'])
        ->middleware('permission:users.update|users.manage');
});

Route::middleware(['auth:sanctum', 'permission:leads.view_all|leads.view_team|leads.view_own|leads.view_any|empreendimentos.view'])->group(function () {

    Route::get('/leads',                  [LeadController::class, 'index']);
    Route::get('/leads/counts',           [LeadController::class, 'counts']);
    Route::get('/leads/check-duplicates', [LeadController::class, 'checkDuplicates']);

    Route::get('/leads/queue',            [LeadController::class, 'queue']);
    Route::get('/leads/queue/count',      [LeadController::class, 'queueCount']);

    Route::apiResource('empreendimentos', EmpreendimentoController::class);
});

Route::middleware(['auth:sanctum', 'permission:reports.productivity|reports.financial|leads.view_all|leads.view_team|reports.view'])->group(function () {

Route::get('/dashboard/atividades', function (Request $request) {

    [$start, $end] = \App\Support\DashboardPeriod::resolve($request);

    $base = \App\Models\Appointment::whereBetween('starts_at', [$start, $end])
        ->where('status', 'completed');

    return response()->json([
        'ligacao' => (clone $base)->where('type', 'ligacao')->count(),
        'whatsapp' => (clone $base)->where('type', 'whatsapp')->count(),
        'email' => (clone $base)->where('type', 'email')->count(),
        'visita' => (clone $base)->where('type', 'visit')->count(),
    ]);
})->middleware('auth:sanctum');

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

Route::get('/dashboard/appointments', function (Request $request) {

    $user = $request->user();
    if (!$user) {
        return response()->json(['message' => 'Não autenticado'], 401);
    }

    if ($request->hasAny(['periodo', 'from', 'to'])) {
        [$windowStart, $windowEnd] = \App\Support\DashboardPeriod::resolve($request);
    } else {
        $windowStart = now()->subDays(7);
        $windowEnd   = now()->addDays(30);
    }

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

    return $rows->map(function ($a) {
        $when = $a->due_at ?? $a->starts_at ?? $a->completed_at;
        $isCompleted = $a->completed_at !== null;
        return [
            'id'           => $a->id,
            'title'        => $a->title,
            'type'         => $a->type,
            'task_kind'    => $a->task_kind,
            'when'         => optional($when)->toIso8601String(),

            'is_overdue'   => !$isCompleted && $when && $when->isPast(),
            'is_completed' => $isCompleted,
            'completed_at' => optional($a->completed_at)->toIso8601String(),
            'lead'         => $a->lead ? ['id' => $a->lead->id, 'name' => $a->lead->name] : null,
        ];
    });
});

Route::post('/empreendimentos/{id}/fields', [EmpreendimentoFieldValueController::class,'storeCadastro']);
});

Route::middleware(['auth:sanctum', 'permission:agenda.view_all|agenda.view_team|agenda.view_own|appointments.view_any|appointments.view_own'])->group(function () {
    Route::get('/appointments/by-date',  [AppointmentController::class, 'byDate']);
    Route::get('/appointments/by-month', [AppointmentController::class, 'byMonth']);
    Route::get('/appointments/summary',  [AppointmentController::class, 'summary']);
    Route::get('/appointments/overdue',  [AppointmentController::class, 'overdueList']);

    Route::get('/appointments/list',     [AppointmentController::class, 'listUnified']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::put('/appointments/{id}/reschedule', [AppointmentController::class, 'reschedule']);
    Route::put('/appointments/{id}/complete',   [AppointmentController::class, 'complete']);
    Route::get('/appointments/{id}',            [AppointmentController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'permission:agenda.view_all|agenda.view_team|agenda.view_own|agenda.create|agenda.update_all|agenda.update_own|agenda.delete|appointments.view_any|appointments.view_own|appointments.manage_any|appointments.manage_own'])->group(function () {
    Route::get('/tasks',                 [TaskController::class, 'index']);
    Route::post('/tasks',                [TaskController::class, 'store']);
    Route::get('/tasks/{id}',            [TaskController::class, 'show']);
    Route::put('/tasks/{id}',            [TaskController::class, 'update']);
    Route::put('/tasks/{id}/complete',   [TaskController::class, 'complete']);
    Route::put('/tasks/{id}/reopen',     [TaskController::class, 'reopen']);
    Route::delete('/tasks/{id}',         [TaskController::class, 'destroy']);

    Route::get   ('/tasks/{id}/comments',              [TaskCommentController::class, 'index']);
    Route::post  ('/tasks/{id}/comments',              [TaskCommentController::class, 'store']);
    Route::delete('/tasks/{id}/comments/{commentId}',  [TaskCommentController::class, 'destroy']);
});

Route::post('/webhooks/manychat/leads', [
    \App\Http\Controllers\ManyChatWebhookController::class,
    'store'
]);

Route::post('/leads', [LeadController::class, 'store'])
    ->middleware('auth:sanctum');

Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/leads/{lead}/interactions', [LeadInteractionController::class, 'store']);

    Route::post('/leads/{id}/first-contact', [LeadController::class, 'firstContact']);

    Route::get('/leads/{lead}', [LeadController::class, 'show']);

    Route::get('/leads/{lead}/reveal', [LeadController::class, 'reveal']);
    Route::put('/leads/editar/{lead}', [LeadController::class, 'update']);
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);

    Route::get   ('/documents/pending-deletions',                             [LeadDocumentController::class, 'pendingDeletions']);

    Route::get   ('/documents/accesses',                                      [LeadDocumentController::class, 'allAccesses']);

    Route::get   ('/admin/files',                                             [AdminFilesController::class, 'index'])
        ->middleware('permission:settings.system');

    Route::get   ('/leads/{lead}/documents',                                  [LeadDocumentController::class, 'index']);
    Route::post  ('/leads/{lead}/documents',                                  [LeadDocumentController::class, 'store']);
    Route::get   ('/leads/{lead}/documents/{document}/download',              [LeadDocumentController::class, 'download']);
    Route::get   ('/leads/{lead}/documents/{document}/preview',               [LeadDocumentController::class, 'preview']);
    Route::post  ('/leads/{lead}/documents/{document}/request-deletion',      [LeadDocumentController::class, 'requestDeletion']);
    Route::post  ('/leads/{lead}/documents/{document}/cancel-deletion',       [LeadDocumentController::class, 'cancelDeletionRequest']);
    Route::post  ('/leads/{lead}/documents/{document}/approve-deletion',      [LeadDocumentController::class, 'approveDeletion']);
    Route::post  ('/leads/{lead}/documents/{document}/reject-deletion',       [LeadDocumentController::class, 'rejectDeletion']);

    Route::post  ('/leads/{lead}/documents/{document}/restore',               [LeadDocumentController::class, 'restore']);
    Route::post  ('/leads/{lead}/documents/{document}/force-purge',           [LeadDocumentController::class, 'forcePurge']);

    Route::get   ('/leads/{lead}/documents/{document}/accesses',              [LeadDocumentController::class, 'accesses']);

});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/settings/email',       [EmailSettingsController::class, 'index']);
    Route::put('/settings/email',       [EmailSettingsController::class, 'update']);
    Route::post('/settings/email/test', [EmailSettingsController::class, 'test']);

    Route::get('/admin/email-logs',        [EmailLogsController::class, 'index']);
    Route::get('/admin/email-logs/{log}',  [EmailLogsController::class, 'show']);

    Route::get('/settings',            [SettingController::class, 'index']);
    Route::get('/settings/{key}',      [SettingController::class, 'show']);
    Route::put('/settings/{key}',      [SettingController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'permission:settings.system'])->group(function () {
    Route::get('/vps/status', [VpsStatusController::class, 'show']);

    Route::get('/server/capacity-alerts', [VpsStatusController::class, 'capacityAlerts']);
});

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

Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    $u = $request->user();

    $payload = $u->toArray();
    if (method_exists($u, 'effectiveRole')) {
        $eff = $u->effectiveRole();
        if ($eff !== '') {
            $payload['role'] = $eff;
        }
    }
    return response()->json($payload);
});

Route::middleware(['auth:sanctum', 'permission:reports.productivity|reports.financial|reports.view'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/dashboard/funnel', [DashboardHomeController::class, 'funnel']);
});

    Route::get('/dashboard/resumo', [DashboardController::class, 'resumo'])
        ->middleware('auth:sanctum');

Route::patch('/kanban/{lead}/move', [KanbanController::class, 'move'])
    ->middleware('auth:sanctum');
Route::get('/kanban', [KanbanController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:kanban.view']);
Route::post('/kanban/reorder', [KanbanController::class, 'reorder'])
    ->middleware('auth:sanctum');

Route::get('/reports/marketing', [MarketingReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:reports.productivity|reports.view']);

Route::get('/leads/{lead}/audits', [\App\Http\Controllers\AuditController::class, 'index'])
    ->middleware('auth:sanctum');

Route::get('/reports/commissions', [CommissionReportController::class, 'index'])
    ->middleware(['auth:sanctum', 'permission:commissions.view_all|commissions.view_team|reports.financial']);

Route::middleware(['auth:sanctum', 'permission:reports.productivity|reports.financial|reports.view'])->group(function () {
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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user-metas',            [UserMetaController::class, 'index']);
    Route::get('/user-metas/{userMeta}', [UserMetaController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'permission:users.update|users.manage'])->group(function () {
    Route::post  ('/user-metas',             [UserMetaController::class, 'store']);
    Route::put   ('/user-metas/{userMeta}',  [UserMetaController::class, 'update']);
    Route::delete('/user-metas/{userMeta}',  [UserMetaController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'permission:reports.productivity|reports.financial|commissions.view_all|commissions.view_team|commissions.view_own|reports.view'])->group(function () {
    Route::get('/home/summary',                [HomeController::class, 'summary']);
    Route::get('/dashboard/next-commissions',  [HomeController::class, 'nextCommissions']);

    Route::get('/task-kind-colors',          [\App\Http\Controllers\TaskKindColorController::class, 'index']);
    Route::put('/task-kind-colors/{kind}',   [\App\Http\Controllers\TaskKindColorController::class, 'update']);

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

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/myCommissions', [CommissionReportController::class, 'myCommissions']);

    Route::get('/agenda', [AppointmentController::class, 'index']);
    Route::post('/agenda', [AppointmentController::class, 'store']);
    Route::get('/agenda/{appointment}', [AppointmentController::class, 'show']);
    Route::put('/agenda/{appointment}', [AppointmentController::class, 'update']);
    Route::delete('/agenda/{appointment}', [AppointmentController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->post('/auth/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();
    return response()->json(['message' => 'Logout realizado']);
});

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

    Route::get   ('/conversations/{id}/pinned',   [ChatMessageController::class, 'pinned'])
        ->whereNumber('id');
    Route::post  ('/messages/{id}/pin',           [ChatMessageController::class, 'togglePin'])
        ->whereNumber('id');
    Route::delete('/messages/{id}/pin',           [ChatMessageController::class, 'togglePin'])
        ->whereNumber('id');

    Route::patch ('/messages/{id}',               [ChatMessageController::class, 'update'])
        ->whereNumber('id');
    Route::delete('/messages/{id}',               [ChatMessageController::class, 'destroy'])
        ->whereNumber('id');

    Route::get   ('/search',                      [ChatMessageController::class, 'search']);

    Route::post  ('/attachments/upload',          [ChatAttachmentController::class, 'upload']);
    Route::get   ('/attachments/{id}/download',   [ChatAttachmentController::class, 'download'])
        ->whereNumber('id');
    Route::delete('/attachments/{id}/draft',      [ChatAttachmentController::class, 'cancelDraft'])
        ->whereNumber('id');

    Route::get   ('/attachments/{id}/lead-document', [ChatAttachmentController::class, 'openLeadDocument'])
        ->whereNumber('id');
});

Route::middleware('auth:sanctum')->get('/realtime/config', function () {
    $driver = config('broadcasting.default');
    if ($driver !== 'reverb') {
        return response()->json(['enabled' => false]);
    }

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

Route::middleware('auth:sanctum')->group(function () {

    Route::get   ('/custom-fields',              [CustomFieldController::class, 'index']);
    Route::get   ('/custom-fields/{customField}', [CustomFieldController::class, 'show']);
    Route::post  ('/custom-fields',              [CustomFieldController::class, 'store'])
        ->middleware('permission:custom_fields.manage|settings.pipeline');
    Route::put   ('/custom-fields/{customField}', [CustomFieldController::class, 'update'])
        ->middleware('permission:custom_fields.manage|settings.pipeline');
    Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])
        ->middleware('permission:custom_fields.manage|settings.pipeline');

    Route::get   ('/status-required-fields',                           [StatusRequiredFieldController::class, 'index']);
    Route::get   ('/status-required-fields/for-target',                [StatusRequiredFieldController::class, 'forTarget']);
    Route::post  ('/status-required-fields',                           [StatusRequiredFieldController::class, 'store'])
        ->middleware('permission:status_required_fields.manage|settings.pipeline');
    Route::put   ('/status-required-fields/{statusRequiredField}',     [StatusRequiredFieldController::class, 'update'])
        ->middleware('permission:status_required_fields.manage|settings.pipeline');
    Route::delete('/status-required-fields/{statusRequiredField}',     [StatusRequiredFieldController::class, 'destroy'])
        ->middleware('permission:status_required_fields.manage|settings.pipeline');

    Route::get ('/leads/{lead}/custom-field-values', [LeadCustomFieldValueController::class, 'index']);
    Route::post('/leads/{lead}/custom-field-values', [LeadCustomFieldValueController::class, 'bulkStore']);

    Route::post  ('/leads/{lead}/custom-field-files/{slug}', [LeadCustomFieldFileController::class, 'store']);
    Route::get   ('/leads/{lead}/custom-field-files/{slug}', [LeadCustomFieldFileController::class, 'download']);
    Route::delete('/leads/{lead}/custom-field-files/{slug}', [LeadCustomFieldFileController::class, 'destroy']);

    Route::middleware('corretor.area.enabled')->group(function () {
        Route::get   ('/media/contents',                   [MediaController::class, 'contents'])->middleware('permission:media.view');
        Route::get   ('/media/folders/{folder}/contents',  [MediaController::class, 'contents'])->middleware('permission:media.view');
        Route::get   ('/media/files/{file}/download',      [MediaController::class, 'downloadFile'])->middleware('permission:media.view');
        Route::post  ('/media/folders',                    [MediaController::class, 'storeFolder'])->middleware('permission:media.create_folder');
        Route::post  ('/media/files',                      [MediaController::class, 'uploadFile'])->middleware('permission:media.upload');
        Route::delete('/media/folders/{folder}',           [MediaController::class, 'destroyFolder'])->middleware('permission:media.delete');
        Route::delete('/media/files/{file}',               [MediaController::class, 'destroyFile'])->middleware('permission:media.delete');
    });
});

Route::post('/emails/create', [EmailController::class, 'store'])
    ->middleware(['auth:sanctum', 'permission:settings.email']);

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