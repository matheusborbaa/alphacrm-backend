<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Sprint Cargos — extensão do Role do Spatie.
 * ---------------------------------------------------------------
 * Adiciona ao $fillable as colunas que a migration
 * 2026_04_25_140000_add_type_and_system_to_roles introduziu, pra que
 * Role::create([...]) e $role->fill([...]) funcionem com elas (o
 * model do Spatie só permite name + guard_name por padrão).
 *
 * Tudo o mais (relations, queries, cache) é herdado intacto. Mantém
 * a tabela `roles` original.
 *
 * Pra esta classe ser usada em vez do default do Spatie, precisamos
 * apontar `config/permission.php` → `models.role` pra cá. Esse step
 * é parte do deploy desta fase (ver instruções no commit).
 */
class Role extends SpatieRole
{
    /**
     * Inclui as 3 colunas novas (type, is_system, description) além dos
     * fields default do Spatie (name, guard_name).
     */
    protected $fillable = [
        'name',
        'guard_name',
        'type',
        'is_system',
        'description',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];
}
