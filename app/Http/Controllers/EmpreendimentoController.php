<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmpreendimentoController extends Controller
{

public function publicIndex()
{
    return Empreendimento::where('active', true)
        ->orderBy('name')
        ->get([
            'id',
            'name',
            'code',
            'cover_image',
            'locationcity',
            'average_sale_value'
        ]);
}

public function publicIndexHome()
{
    $empreendimentos = Empreendimento::where('active', true)
        ->with([
            'fieldValues.field' => function ($q) {
                $q->where('active', true);
            }
        ])
        ->orderBy('name')
        ->get();

    return $empreendimentos->map(function ($emp) {
        $fields = [];

        foreach ($emp->fieldValues as $value) {
            if (!$value->field) {
                continue;
            }

            $fields[$value->field->slug] = [
                'label' => $value->field->name,
                'value' => $value->value,
                'unit'  => $value->field->unit,
                'icon'  => $value->field->icon,
                'type'  => $value->field->type,
            ];
        }

        return [
            'id' => $emp->id,
            'name' => $emp->name,
            'code' => $emp->code,
            'cover_image' => $emp->cover_image,
            'locationcity' => $emp->locationcity,
            'neighborhood' => $emp->neighborhood,
            'tipo' => $emp->tipo,
            'status' => $emp->status,
            'tipo' => $emp->tipo,
            'finalidade' => $emp->finalidade,
            'average_sale_value' => $emp->average_sale_value,
            'fields' => $fields
        ];
    });
}

public function publicShow(string $code)
{
    $empreendimento = Empreendimento::where('code', $code)
        ->where('active', true)
        ->with([
            'fieldValues.field' => function ($q) {
                $q->where('active', true)
                  ->orderBy('group')
                  ->orderBy('order');
            }
        ])
        ->firstOrFail();

    $fields = [];

    foreach ($empreendimento->fieldValues as $value) {
        if (!$value->field) {
            continue;
        }

        $group = $value->field->group ?? 'Outros';

        $fields[$group][] = [
            'slug'  => $value->field->slug,
            'label' => $value->field->name,
            'value' => $value->value,
            'unit'  => $value->field->unit,
            'icon'  => $value->field->icon,
            'type'  => $value->field->type,
        ];
    }

    return response()->json([
        'id' => $empreendimento->id,
        'name' => $empreendimento->name,
        'code' => $empreendimento->code,
        'cover_image' => $empreendimento->cover_image,
        'locationcity' => $empreendimento->locationcity,
        'average_sale_value' => $empreendimento->average_sale_value,
        'description' => $empreendimento->description,
        'shortdescription' => $empreendimento->shortdescription,
        'fields' => $fields
    ]);
}

   public function index(Request $request)
{
    $query = Empreendimento::query();


    if (\Illuminate\Support\Facades\Schema::hasTable('empreendimento_tipologias')) {
        $query->addSelect([
            '*',
            'tipologias_area_min' => \Illuminate\Support\Facades\DB::table('empreendimento_tipologias')
                ->whereColumn('empreendimento_id', 'empreendimentos.id')
                ->selectRaw('MIN(area_min_m2)'),
            'tipologias_area_max' => \Illuminate\Support\Facades\DB::table('empreendimento_tipologias')
                ->whereColumn('empreendimento_id', 'empreendimentos.id')
                ->selectRaw('MAX(COALESCE(area_max_m2, area_min_m2))'),
        ]);
    }

    if ($request->filled('search')) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    if ($request->filled('city')) {
        $query->where('locationcity', $request->city);
    }

    if ($request->filled('delivery_until')) {
        $query->whereDate('ends_at', '<=', $request->delivery_until);
    }

    $activeFilter = $request->input('active');
    $userRole     = $request->user()?->role;

    if ($activeFilter === 'all' && in_array($userRole, ['admin', 'gestor'], true)) {

    } elseif ($activeFilter === '0' && in_array($userRole, ['admin', 'gestor'], true)) {
        $query->where('active', 0);
    } elseif ($activeFilter === '1' || $activeFilter === null || $activeFilter === '') {
        $query->where('active', 1);
    } else {

        $query->where('active', 1);
    }

    if ($request->filled('status')) {
        $statuses = collect(explode(',', (string) $request->input('status')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }
    }

    if ($request->filled('finalidade')) {
        $finalidades = collect(explode(',', (string) $request->input('finalidade')))
            ->map(fn ($f) => trim($f))
            ->filter()
            ->values()
            ->all();
        if (!empty($finalidades)) {
            $query->whereIn('finalidade', $finalidades);
        }
    }

    if ($request->filled('tipo')) {
        $tipos = collect(explode(',', (string) $request->input('tipo')))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->values()
            ->all();
        if (!empty($tipos)) {
            $query->whereIn('tipo', $tipos);
        }
    }

    if ($request->filled('price_min')) {
        $query->where('average_sale_value', '>=', (float) $request->input('price_min'));
    }
    if ($request->filled('price_max')) {
        $query->where('average_sale_value', '<=', (float) $request->input('price_max'));
    }

    return response()->json(
        $query->orderByDesc('created_at')->paginate(10)
    );
}
public function cities()
{
    $cities = Empreendimento::whereNotNull('locationcity')
        ->where('active', 1)
        ->distinct()
        ->orderBy('locationcity')
        ->pluck('locationcity');

    return response()->json($cities);
}

    public function store(Request $request)
{


    $valueRequired = (bool) \App\Models\Setting::get('empreendimento_value_required', true);
    $priceRule = $valueRequired
        ? 'required|numeric|min:0.01'
        : 'nullable|numeric|min:0';

    $data = $request->validate([
        'name'                  => 'required|string|max:255',
        'code'                  => 'nullable|string|max:255',
        'locationcity'          => 'nullable|string|max:255',
        'neighborhood'          => 'nullable|string|max:255',
        'tipo'                  => 'nullable|string|max:60',
        'finalidade'            => 'nullable|string|max:60',
        'status'                => 'nullable|string|max:60',
        'metragem'              => 'nullable|numeric|min:0',
        'initial_price'         => $priceRule,
        'active'                => 'boolean',
        'commission_percentage' => 'nullable|numeric',
        'average_sale_value'    => 'nullable|numeric',
        'starts_at'             => 'nullable|date',
        'ends_at'               => 'nullable|date',
        'shortdescription'      => 'nullable|string',
        'description'           => 'nullable|string',
        'cover_image'           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096'
    ], [

        'initial_price.required' => 'Informe o valor do imóvel.',
        'initial_price.min'      => 'O valor do imóvel deve ser maior que zero.',
    ]);

    if($request->hasFile('cover_image')){
        $path = $request->file('cover_image')
            ->store('empreendimentos','public');

        $data['cover_image'] = $path;
    }

    if (empty($data['cover_image'])) {
        $data['active'] = false;
    }

    $empreendimento = Empreendimento::create($data);

    return response()->json($empreendimento,201);
}

 public function show(Empreendimento $empreendimento)
{

    $base = ['leads.status', 'images', 'fieldValues.definition'];

    try {

        if (\Illuminate\Support\Facades\Schema::hasTable('tipologia_field_values')
            && \Illuminate\Support\Facades\Schema::hasTable('tipologia_field_definitions')
            && class_exists(\App\Models\TipologiaFieldValue::class)
            && class_exists(\App\Models\TipologiaFieldDefinition::class)) {
            $empreendimento->load(array_merge($base, ['tipologias.fieldValues.definition']));
        } else {
            $empreendimento->load(array_merge($base, ['tipologias']));
        }
    } catch (\Throwable $e) {

        \Log::warning('[EmpreendimentoController@show] Falha ao carregar tipologias.fieldValues, fallback pra tipologias simples: ' . $e->getMessage());
        $empreendimento->load(array_merge($base, ['tipologias']));
    }

    return $empreendimento;
}


public function listTipologias(Empreendimento $empreendimento)
{
    $query = $empreendimento->tipologias();

    try {
        if (\Illuminate\Support\Facades\Schema::hasTable('tipologia_field_values')
            && \Illuminate\Support\Facades\Schema::hasTable('tipologia_field_definitions')
            && class_exists(\App\Models\TipologiaFieldValue::class)
            && class_exists(\App\Models\TipologiaFieldDefinition::class)) {
            $query->with('fieldValues.definition');
        }
    } catch (\Throwable $e) {
        \Log::warning('[EmpreendimentoController@listTipologias] Falha no with(fieldValues): ' . $e->getMessage());
    }

    return response()->json($query->get());
}

public function storeTipologia(Request $request, Empreendimento $empreendimento)
{
    abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

    $data = $request->validate([
        'name'         => 'required|string|max:120',
        'bedrooms'     => 'nullable|integer|min:0|max:20',
        'suites'       => 'nullable|integer|min:0|max:20',
        'area_min_m2'  => 'nullable|numeric|min:0',
        'area_max_m2'  => 'nullable|numeric|min:0',
        'price_from'   => 'nullable|numeric|min:0',
        'order'        => 'nullable|integer|min:0',
    ]);

    $data['empreendimento_id'] = $empreendimento->id;
    if (!isset($data['order'])) {
        $data['order'] = ($empreendimento->tipologias()->max('order') ?? -1) + 1;
    }

    $tip = \App\Models\EmpreendimentoTipologia::create($data);

    return response()->json($tip, 201);
}

public function updateTipologia(Request $request, Empreendimento $empreendimento, $tipologiaId)
{
    abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

    $tip = \App\Models\EmpreendimentoTipologia::where('empreendimento_id', $empreendimento->id)
        ->where('id', $tipologiaId)
        ->firstOrFail();

    $data = $request->validate([
        'name'         => 'sometimes|required|string|max:120',
        'bedrooms'     => 'sometimes|nullable|integer|min:0|max:20',
        'suites'       => 'sometimes|nullable|integer|min:0|max:20',
        'area_min_m2'  => 'sometimes|nullable|numeric|min:0',
        'area_max_m2'  => 'sometimes|nullable|numeric|min:0',
        'price_from'   => 'sometimes|nullable|numeric|min:0',
        'order'        => 'sometimes|integer|min:0',
    ]);

    $tip->update($data);
    return response()->json($tip->fresh());
}

public function destroyTipologia(Request $request, Empreendimento $empreendimento, $tipologiaId)
{
    abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

    $tip = \App\Models\EmpreendimentoTipologia::where('empreendimento_id', $empreendimento->id)
        ->where('id', $tipologiaId)
        ->firstOrFail();

    $tip->delete();
    return response()->json(['deleted' => true]);
}

    public function update(Request $request, Empreendimento $empreendimento)
    {

        abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

        $data = $request->validate([
            'name'                  => 'sometimes|required|string|max:255',
            'code'                  => 'nullable|string|max:255',
            'locationcity'          => 'nullable|string|max:255',
            'neighborhood'          => 'nullable|string|max:255',
            'tipo'                  => 'nullable|string|max:60',
            'finalidade'            => 'nullable|string|max:60',
            'status'                => 'nullable|string|max:60',
            'metragem'              => 'nullable|numeric|min:0',
            'initial_price'         => 'nullable|numeric|min:0',
            'active'                => 'boolean',
            'commission_percentage' => 'nullable|numeric',
            'average_sale_value'    => 'nullable|numeric',
            'starts_at'             => 'nullable|date',
            'ends_at'               => 'nullable|date',
            'shortdescription'      => 'nullable|string',
            'description'           => 'nullable|string',

            'cover_image'           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        if ($request->hasFile('cover_image')) {
            $oldPath = $empreendimento->cover_image;

            $data['cover_image'] = $request->file('cover_image')
                ->store('empreendimentos', 'public');

            if ($oldPath && Storage::disk('public')->exists($oldPath)) {

                try { Storage::disk('public')->delete($oldPath); } catch (\Throwable $e) {}
            }
        }

        $effectiveCover = $data['cover_image']
            ?? $empreendimento->cover_image;

        if (empty($effectiveCover) && !empty($data['active'])) {
            $data['active'] = false;
        }

        $empreendimento->update($data);

        return $empreendimento;
    }

    public function destroy(Request $request, Empreendimento $empreendimento)
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

        $leadsByPivot = $empreendimento->leads()->count();
        $leadsByFk    = \App\Models\Lead::where('empreendimento_id', $empreendimento->id)->count();

        if ($leadsByPivot + $leadsByFk > 0) {
            return response()->json([
                'message' => 'Este empreendimento tem leads vinculados e não pode ser excluído. '
                    . 'Desvincule ou reatribua os leads antes de excluir.',
                'leads_count' => $leadsByPivot + $leadsByFk,
            ], 409);
        }

        if ($empreendimento->cover_image && Storage::disk('public')->exists($empreendimento->cover_image)) {
            try { Storage::disk('public')->delete($empreendimento->cover_image); } catch (\Throwable $e) {}
        }

        $empreendimento->delete();

        return response()->json(['deleted' => true]);
    }

public function publicGallery(string $code)
{
    $empreendimento = Empreendimento::where('code', $code)
        ->where('active', true)
        ->firstOrFail();

    return $empreendimento->images()
        ->orderBy('order')
        ->get()
        ->map(function ($img) {
            return [
                'id'    => $img->id,
                'image' => $img->image_path,
                'order' => $img->order,
            ];
        });
}

}
