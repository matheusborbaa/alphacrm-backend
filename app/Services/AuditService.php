<?php

namespace App\Services;

use App\Models\Audit;

class AuditService
{
    public static function log(
        string $event,
        string $entityType,
        int $entityId,
        ?int $userId = null,
        array $old = null,
        array $new = null,
        string $source = null
    ): void {
        Audit::create([
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'old_values' => $old,
            'new_values' => $new,
            'source' => $source,
        ]);
    }
}
