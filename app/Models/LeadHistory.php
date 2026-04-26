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
