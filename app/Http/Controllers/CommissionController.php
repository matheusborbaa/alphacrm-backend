<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\CommissionComment;
use App\Models\FinanceEntry;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\CommissionStatusChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CommissionController extends Controller
{

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Commission::query()
            ->with([
                'lead:id,name,empreendimento_id',
                'lead.empreendimento:id,name',
                'corretor:id,name',
            ])

            ->whereHas('lead')
            ->when($user->role === 'corretor', fn ($q) => $q->where('user_id', $user->id));

        if ($request->filled('status')) {
            $statuses = $this->csv($request->input('status'));
            if (!empty($statuses)) $query->whereIn('status', $statuses);
        }

        if ($request->filled('corretor')) {

            if (in_array($user->role, ['admin', 'gestor'], true)) {
                $query->where('user_id', (int) $request->corretor);
            }
        }

        if ($request->filled('empreendimento')) {
            $empId = (int) $request->empreendimento;
            $query->whereHas('lead', fn ($q) => $q->where('empreendimento_id', $empId));
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('lead', fn ($q) => $q->where('name', 'like', "%{$s}%"));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function summary(Request $request)
    {
        $user = $request->user();

        $base = Commission::query()

            ->whereHas('lead')
            ->when($user->role === 'corretor', fn ($q) => $q->where('user_id', $user->id));

        if ($request->filled('from')) $base->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to'))   $base->whereDate('created_at', '<=', $request->to);

        $clone = fn () => (clone $base);

        $live = $clone()->whereNotIn('status', [
            Commission::STATUS_DRAFT,
            Commission::STATUS_CANCELLED,
        ]);

        $totalSales       = (clone $live)->sum('sale_value');
        $totalCommissions = (clone $live)->sum('commission_value');

        return response()->json([
            'total_draft'     => (float) $clone()->where('status', Commission::STATUS_DRAFT)->sum('commission_value'),
            'total_pending'   => (float) $clone()->where('status', Commission::STATUS_PENDING)->sum('commission_value'),
            'total_approved'  => (float) $clone()->where('status', Commission::STATUS_APPROVED)->sum('commission_value'),
            'total_partial'   => (float) $clone()->where('status', Commission::STATUS_PARTIAL)->sum('commission_value'),
            'total_paid'      => (float) $clone()->where('status', Commission::STATUS_PAID)->sum('commission_value'),

            'count_draft'     => (int)   $clone()->where('status', Commission::STATUS_DRAFT)->count(),
            'count_pending'   => (int)   $clone()->where('status', Commission::STATUS_PENDING)->count(),
            'count_approved'  => (int)   $clone()->where('status', Commission::STATUS_APPROVED)->count(),
            'count_paid'      => (int)   $clone()->where('status', Commission::STATUS_PAID)->count(),

            'total_sales'        => (float) $totalSales,
            'total_commissions'  => (float) $totalCommissions,

            'alpha_net_revenue'  => (float) ($totalSales - $totalCommissions),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();

        $commission = Commission::with([
            'lead:id,name,phone,email,empreendimento_id,value',
            'lead.empreendimento:id,name,commission_percentage',
            'corretor:id,name,email',
            'approver:id,name',
            'canceller:id,name',
            'comments.user:id,name',
        ])

            ->whereHas('lead')
            ->findOrFail($id);

        $this->authorizeRead($commission, $user);

        $data = $commission->toArray();
        $data['payment_receipt_url'] = $commission->payment_receipt_path
            ? Storage::url($commission->payment_receipt_path)
            : null;

        return response()->json($data);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureManager($user);

        $commission = Commission::findOrFail($id);

        if (in_array($commission->status, [
            Commission::STATUS_PAID,
            Commission::STATUS_CANCELLED,
        ], true)) {
            return response()->json([
                'message' => 'Comissão quitada/cancelada não pode mais ser editada.',
            ], 422);
        }

        $data = $request->validate([
            'commission_percentage' => 'nullable|numeric|min:0|max:100',
            'commission_value'      => 'nullable|numeric|min:0',
            'sale_value'            => 'nullable|numeric|min:0',
            'expected_payment_date' => 'nullable|date',
            'notes'                 => 'nullable|string|max:2000',
        ]);

        if (isset($data['commission_percentage']) && !isset($data['commission_value'])) {
            $sale = $data['sale_value'] ?? $commission->sale_value;
            $data['commission_value'] = round($sale * $data['commission_percentage'] / 100, 2);
        }

        $commission->fill($data)->save();

        return response()->json($commission->fresh());
    }

    private function notifyBroker(Commission $commission, string $event, ?string $reason = null): void
    {
        $broker = User::find($commission->user_id);
        if (!$broker) return;

        try {
            $broker->notify(new CommissionStatusChangedNotification(
                $commission->load('lead:id,name'),
                $event,
                $reason,
            ));
        } catch (\Throwable $e) {

            Log::warning('Falha ao notificar corretor de comissão', [
                'commission_id' => $commission->id,
                'event'         => $event,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    public function confirm(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureManager($user);

        $commission = Commission::findOrFail($id);
        $this->assertStatus($commission, [Commission::STATUS_DRAFT]);

        DB::transaction(function () use ($commission, $user) {
            $commission->status = Commission::STATUS_PENDING;
            if (!$commission->expected_payment_date) {

                $commission->expected_payment_date = now()->addDays(30);
            }
            $commission->save();

            FinanceEntry::create([
                'direction'      => FinanceEntry::DIRECTION_IN,
                'category'       => FinanceEntry::CATEGORY_SALE,
                'amount'         => $commission->sale_value,
                'entry_date'     => now()->toDateString(),
                'reference_type' => Commission::class,
                'reference_id'   => $commission->id,
                'created_by'     => $user->id,
                'description'    => "Venda confirmada — comissão #{$commission->id}",
            ]);
        });

        $commission = $commission->fresh();
        $this->notifyBroker($commission, CommissionStatusChangedNotification::EVENT_CONFIRMED);

        return response()->json($commission);
    }

    public function approve(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureManager($user);

        $commission = Commission::findOrFail($id);
        $this->assertStatus($commission, [Commission::STATUS_PENDING]);

        $commission->status      = Commission::STATUS_APPROVED;
        $commission->approved_at = now();
        $commission->approved_by = $user->id;
        $commission->save();

        $commission = $commission->fresh();
        $this->notifyBroker($commission, CommissionStatusChangedNotification::EVENT_APPROVED);

        return response()->json($commission);
    }

    public function pay(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureManager($user);

        $commission = Commission::findOrFail($id);
        $this->assertStatus($commission, [
            Commission::STATUS_APPROVED,
            Commission::STATUS_PARTIAL,

            Commission::STATUS_PENDING,
        ]);

        $data = $request->validate([
            'paid_at' => 'nullable|date',
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        DB::transaction(function () use ($commission, $data, $user, $request) {
            if ($request->hasFile('receipt')) {

                if ($commission->payment_receipt_path) {
                    Storage::disk('public')->delete($commission->payment_receipt_path);
                }
                $path = $request->file('receipt')->store('commission_receipts', 'public');
                $commission->payment_receipt_path = $path;
            }

            $commission->status  = Commission::STATUS_PAID;
            $commission->paid_at = $data['paid_at'] ?? now()->toDateString();
            $commission->save();

            FinanceEntry::create([
                'direction'      => FinanceEntry::DIRECTION_OUT,
                'category'       => FinanceEntry::CATEGORY_COMMISSION,
                'amount'         => $commission->commission_value,
                'entry_date'     => Carbon::parse($commission->paid_at)->toDateString(),
                'reference_type' => Commission::class,
                'reference_id'   => $commission->id,
                'created_by'     => $user->id,
                'description'    => "Comissão paga — corretor #{$commission->user_id}",
            ]);
        });

        $commission = $commission->fresh();
        $this->notifyBroker($commission, CommissionStatusChangedNotification::EVENT_PAID);

        return response()->json($commission);
    }

    public function partial(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureManager($user);

        $commission = Commission::findOrFail($id);
        $this->assertStatus($commission, [
            Commission::STATUS_APPROVED,
            Commission::STATUS_PARTIAL,
            Commission::STATUS_PENDING,
        ]);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'notes'  => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($commission, $data, $user) {
            $commission->status = Commission::STATUS_PARTIAL;
            if ($data['notes'] ?? null) {
                $commission->notes = trim(($commission->notes ? $commission->notes . "\n\n" : '')
                    . "Pagamento parcial R$ " . number_format((float) $data['amount'], 2, ',', '.')
                    . ": " . $data['notes']);
            }
            $commission->save();

            FinanceEntry::create([
                'direction'      => FinanceEntry::DIRECTION_OUT,
                'category'       => FinanceEntry::CATEGORY_COMMISSION,
                'amount'         => $data['amount'],
                'entry_date'     => now()->toDateString(),
                'reference_type' => Commission::class,
                'reference_id'   => $commission->id,
                'created_by'     => $user->id,
                'description'    => "Comissão parcial — corretor #{$commission->user_id}",
                'notes'          => $data['notes'] ?? null,
            ]);
        });

        $commission = $commission->fresh();
        $this->notifyBroker($commission, CommissionStatusChangedNotification::EVENT_PARTIAL);

        return response()->json($commission);
    }

    public function cancel(Request $request, int $id)
    {
        $user = $request->user();
        $this->ensureManager($user);

        $commission = Commission::findOrFail($id);

        if ($commission->status === Commission::STATUS_CANCELLED) {
            return response()->json(['message' => 'Já está cancelada.'], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($commission, $data, $user) {
            $wasLive = $commission->isLive();

            $commission->status        = Commission::STATUS_CANCELLED;
            $commission->cancelled_at  = now();
            $commission->cancelled_by  = $user->id;
            $commission->cancel_reason = $data['reason'];
            $commission->save();

            if ($wasLive) {
                $entries = FinanceEntry::for($commission)->get();
                foreach ($entries as $e) {
                    FinanceEntry::create([
                        'direction'      => $e->direction === 'in' ? 'out' : 'in',
                        'category'       => FinanceEntry::CATEGORY_REFUND,
                        'amount'         => $e->amount,
                        'entry_date'     => now()->toDateString(),
                        'reference_type' => Commission::class,
                        'reference_id'   => $commission->id,
                        'created_by'     => $user->id,
                        'description'    => "Estorno — comissão cancelada (#{$commission->id})",
                        'notes'          => $data['reason'],
                    ]);
                }
            }
        });

        $commission = $commission->fresh();
        $this->notifyBroker(
            $commission,
            CommissionStatusChangedNotification::EVENT_CANCELLED,
            $data['reason'],
        );

        return response()->json($commission);
    }

    public function comments(Request $request, int $id)
    {
        $user = $request->user();
        $commission = Commission::findOrFail($id);
        $this->authorizeRead($commission, $user);

        $comments = $commission->comments()->with('user:id,name')->get();
        return response()->json($comments);
    }

    public function addComment(Request $request, int $id)
    {
        $user = $request->user();
        $commission = Commission::findOrFail($id);
        $this->authorizeRead($commission, $user);

        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $comment = CommissionComment::create([
            'commission_id' => $commission->id,
            'user_id'       => $user->id,
            'body'          => $data['body'],
        ]);

        return response()->json($comment->load('user:id,name'), 201);
    }

    private function csv($raw): array
    {
        $arr = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_filter(array_map('trim', $arr)));
    }

    private function ensureManager($user): void
    {
        if (!in_array($user->role, ['admin', 'gestor'], true)) {
            abort(403, 'Apenas admin/gestor podem executar essa ação.');
        }
    }

    private function authorizeRead(Commission $commission, $user): void
    {
        if (in_array($user->role, ['admin', 'gestor'], true)) return;
        if ((int) $commission->user_id === (int) $user->id)     return;
        abort(403, 'Você não tem acesso a essa comissão.');
    }

    private function assertStatus(Commission $c, array $allowed): void
    {
        if (!in_array($c->status, $allowed, true)) {
            abort(422, "Transição inválida a partir de '{$c->status}'.");
        }
    }
}
