<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadHistory extends Model
{
    protected $fillable = [
        'lead_id',
        'user_id',
        'type',
        'from',
        'to',
        'description'
    ];

    public function lead(){
        return $this->belongsTo(Lead::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }

    /**
     * Cria uma entrada de histórico do tipo field_change a partir de um diff.
     *
     * $diffs é um array de ['label' => string, 'from' => mixed, 'to' => mixed].
     * Usado pelos controllers que salvam campos custom fora do update()
     * (ex: KanbanController@move e LeadCustomFieldValueController@bulkStore,
     * acionados pelo wizard RequiredFields quando o usuário preenche um
     * campo obrigatório no drag entre etapas do kanban).
     *
     * Em ambiente CLI (seeders, jobs) $userId pode ser nulo e a entrada
     * é silenciosamente ignorada — histórico sem autor confunde mais do
     * que ajuda.
     */
    public static function logFieldChangeDiffs(Lead $lead, array $diffs, ?int $userId = null): void
    {
        if (empty($diffs)) return;

        $uid = $userId ?? (auth()->check() ? auth()->id() : null);
        if (!$uid) return;

        foreach ($diffs as $d) {
            self::create([
                'lead_id'     => $lead->id,
                'user_id'     => $uid,
                'type'        => 'field_change',
                'description' => $d['label'] ?? 'Campo',
                'from'        => isset($d['from']) && $d['from'] !== null ? (string) $d['from'] : null,
                'to'          => isset($d['to'])   && $d['to']   !== null ? (string) $d['to']   : null,
            ]);
        }
    }
}
