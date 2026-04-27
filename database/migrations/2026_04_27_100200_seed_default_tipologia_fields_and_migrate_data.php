<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Seed que converte as 5 colunas fixas (bedrooms/suites/área/preço) em campos custom + migra os valores.
// As colunas antigas ficam — não dropei pra ter rollback rápido se der ruim em produção.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tipologia_field_definitions')) {

            return;
        }


        $defaults = [
            [
                'name'     => 'Quartos',
                'slug'     => 'bedrooms',
                'type'     => 'counter',
                'unit'     => null,
                'icon'     => 'bed',
                'order'    => 1,
                'src_col'  => 'bedrooms',
                'as'       => 'int',
            ],
            [
                'name'     => 'Suítes',
                'slug'     => 'suites',
                'type'     => 'counter',
                'unit'     => null,
                'icon'     => 'bath',
                'order'    => 2,
                'src_col'  => 'suites',
                'as'       => 'int',
            ],
            [
                'name'     => 'Área mínima',
                'slug'     => 'area_min_m2',
                'type'     => 'number',
                'unit'     => 'm²',
                'icon'     => 'ruler',
                'order'    => 3,
                'src_col'  => 'area_min_m2',
                'as'       => 'decimal',
            ],
            [
                'name'     => 'Área máxima',
                'slug'     => 'area_max_m2',
                'type'     => 'number',
                'unit'     => 'm²',
                'icon'     => 'ruler',
                'order'    => 4,
                'src_col'  => 'area_max_m2',
                'as'       => 'decimal',
            ],
            [
                'name'     => 'Preço a partir de',
                'slug'     => 'price_from',
                'type'     => 'number',
                'unit'     => 'R$',
                'icon'     => 'dollar-sign',
                'order'    => 5,
                'src_col'  => 'price_from',
                'as'       => 'decimal',
            ],
        ];

        $now = now();
        $defIdBySlug = [];

        foreach ($defaults as $d) {
            $existing = DB::table('tipologia_field_definitions')
                ->where('slug', $d['slug'])
                ->first();

            if ($existing) {
                $defIdBySlug[$d['slug']] = $existing->id;
                continue;
            }

            $defIdBySlug[$d['slug']] = DB::table('tipologia_field_definitions')->insertGetId([
                'name'       => $d['name'],
                'slug'       => $d['slug'],
                'type'       => $d['type'],
                'unit'       => $d['unit'],
                'group'      => null,
                'icon'       => $d['icon'],
                'options'    => null,
                'active'     => true,
                'required'   => false,
                'order'      => $d['order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }


        if (!Schema::hasTable('empreendimento_tipologias')) {
            return;
        }

        DB::table('empreendimento_tipologias')->orderBy('id')->chunkById(200, function ($tipologias) use ($defaults, $defIdBySlug, $now) {
            foreach ($tipologias as $tip) {
                foreach ($defaults as $d) {
                    if (!isset($defIdBySlug[$d['slug']])) continue;
                    $defId = $defIdBySlug[$d['slug']];

                    $rawValue = $tip->{$d['src_col']} ?? null;
                    if ($rawValue === null || $rawValue === '') continue;


                    $alreadyExists = DB::table('tipologia_field_values')
                        ->where('tipologia_id', $tip->id)
                        ->where('field_definition_id', $defId)
                        ->exists();

                    if ($alreadyExists) continue;

                    $stringValue = ($d['as'] === 'decimal')

                        ? rtrim(rtrim(number_format((float) $rawValue, 2, '.', ''), '0'), '.')
                        : (string) (int) $rawValue;

                    if ($stringValue === '') continue;

                    DB::table('tipologia_field_values')->insert([
                        'tipologia_id'        => $tip->id,
                        'field_definition_id' => $defId,
                        'value'               => $stringValue,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {

        if (Schema::hasTable('tipologia_field_values')) {
            DB::table('tipologia_field_values')->truncate();
        }
        if (Schema::hasTable('tipologia_field_definitions')) {
            DB::table('tipologia_field_definitions')
                ->whereIn('slug', ['bedrooms','suites','area_min_m2','area_max_m2','price_from'])
                ->delete();
        }
    }
};
