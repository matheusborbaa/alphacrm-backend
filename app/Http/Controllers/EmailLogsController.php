<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Request;

/**
 * Histórico de e-mails enviados (ou que tentaram ser enviados) pelo sistema.
 *
 * Alimentado pelo App\Services\EmailLoggerService. Leitura admin-only
 * (segue mesmo padrão do EmailSettingsController).
 *
 * Rotas:
 *   GET /admin/email-logs        paginado com filtros ?type=&status=&search=&page=&per_page=
 *   GET /admin/email-logs/{log}  detalhe (útil pra ver error_message completo)
 */
class EmailLogsController extends Controller
{
    /**
     * GET /admin/email-logs
     *
     * Filtros suportados (todos opcionais):
     *   - type     : welcome | reset | invite | test | other
     *   - status   : sent | failed
     *   - search   : busca em to_email, to_name, subject (LIKE %...%)
     *   - per_page : default 20, máx 100
     *
     * Retorna paginação do Laravel com relacionamentos triggeredBy e relatedUser.
     */
    public function index(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'type'     => 'nullable|string|in:welcome,reset,invite,test,other',
            'status'   => 'nullable|string|in:sent,failed',
            'search'   => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page'     => 'nullable|integer|min:1',
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);

        $query = EmailLog::query()
            ->with([
                'triggeredBy:id,name,email',
                'relatedUser:id,name,email',
            ])
            ->orderByDesc('created_at');

        if (!empty($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (!empty($data['search'])) {
            $term = '%' . $data['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('to_email', 'like', $term)
                  ->orWhere('to_name', 'like', $term)
                  ->orWhere('subject', 'like', $term);
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data'         => $page->items(),
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ]);
    }

    /**
     * GET /admin/email-logs/{log}
     * Detalhe completo — inclui error_message integral.
     */
    public function show(EmailLog $log)
    {
        $this->ensureAdmin();

        $log->load([
            'triggeredBy:id,name,email',
            'relatedUser:id,name,email',
        ]);

        return response()->json($log);
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        // Sprint Hierarquia (fix) — usa effectiveRole() (coluna + Spatie).
        $role = method_exists($u, 'effectiveRole')
            ? $u->effectiveRole()
            : strtolower(trim((string) ($u->role ?? '')));
        if ($role !== 'admin') {
            abort(403, 'Ação restrita ao administrador.');
        }
    }
}
