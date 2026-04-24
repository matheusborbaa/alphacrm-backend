<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @group Empreendimentos
 *
 * Gestão de empreendimentos imobiliários.
 */
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
/**
 * @group Site - Empreendimentos
 *
 * Endpoint público utilizado na Home do site institucional
 * para listar os empreendimentos em destaque.
 *
 * Retorna apenas empreendimentos ativos, limitados a 3 registros,
 * contendo informações básicas para exibição em cards.
 *
 * Não requer autenticação.
 *
 * @response 200 [
 *   {
 *     "id": 1,
 *     "name": "Architecto",
 *     "code": "architecto",
 *     "cover_image": "https://app.alphadomusimobiliaria.com.br/storage/empreendimentos/architecto/capa.jpg",
 *     "locationcity": "São Paulo",
 *     "average_sale_value": 6200000
 *   },
 *   {
 *     "id": 2,
 *     "name": "Paradise Village",
 *     "code": "paradise-village",
 *     "cover_image": "https://app.alphadomusimobiliaria.com.br/storage/empreendimentos/paradise/capa.jpg",
 *     "locationcity": "Rio de Janeiro",
 *     "average_sale_value": 4850000
 *   }
 * ]
 */
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

    if ($request->filled('search')) {
        $query->where('name', 'like', '%' . $request->search . '%');
    }

    if ($request->filled('city')) {
        $query->where('locationcity', $request->city);
    }

    if ($request->filled('delivery_until')) {
        $query->whereDate('ends_at', '<=', $request->delivery_until);
    }

    // Filtro "active" aceita '1', '0' ou 'all'. Default: só ativos,
    // exceto pra admin/gestor que viram tudo quando explicitamente
    // pedem active=all. Corretor sempre vê só ativos.
    $activeFilter = $request->input('active');
    $userRole     = $request->user()?->role;

    if ($activeFilter === 'all' && in_array($userRole, ['admin', 'gestor'], true)) {
        // Sem filtro — inclui ativos e inativos.
    } elseif ($activeFilter === '0' && in_array($userRole, ['admin', 'gestor'], true)) {
        $query->where('active', 0);
    } elseif ($activeFilter === '1' || $activeFilter === null || $activeFilter === '') {
        $query->where('active', 1);
    } else {
        // Qualquer outro valor vira default (só ativos).
        $query->where('active', 1);
    }

    // Status da obra — aceita múltiplos separados por vírgula (ex.: "lancamento,em_obras").
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

    // Finalidade (residencial/comercial/misto) — também aceita múltiplos.
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

    // Tipologia (apartamento/casa/...) — também aceita múltiplos.
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

    // Faixa de valor médio de venda (price_min / price_max, em reais).
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
    $data = $request->validate([
        'name'                  => 'required|string|max:255',
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
        'cover_image'           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096'
    ]);

    if($request->hasFile('cover_image')){
        $path = $request->file('cover_image')
            ->store('empreendimentos','public');

        $data['cover_image'] = $path;
    }

    // Regra de negócio: empreendimento sem capa não pode ficar ativo —
    // força active=false independente do que veio no payload. Quando o
    // admin subir a primeira imagem e marcar como capa (ou já mandar
    // capa junto), a ativação é feita em outro ponto.
    if (empty($data['cover_image'])) {
        $data['active'] = false;
    }

    $empreendimento = Empreendimento::create($data);

    return response()->json($empreendimento,201);
}

 public function show(Empreendimento $empreendimento)
{
    $empreendimento->load([
        'leads.status',
        'images',
        'fieldValues.definition'
    ]);

    return $empreendimento;
}

    public function update(Request $request, Empreendimento $empreendimento)
    {
        // Só admin/gestor pode editar. A rota já limita a admin,gestor,corretor
        // via middleware do grupo, então aqui blindamos o corretor.
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
            // ATENÇÃO: precisa estar na validação pra o file ser aceito — sem
            // isso o Laravel só dropa silenciosamente o arquivo (bug antigo
            // onde "trocar a capa" não funcionava).
            'cover_image'           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        // Quando o usuário troca a capa: sobe o novo arquivo, apaga o antigo.
        if ($request->hasFile('cover_image')) {
            $oldPath = $empreendimento->cover_image;

            $data['cover_image'] = $request->file('cover_image')
                ->store('empreendimentos', 'public');

            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                // Best-effort: se falhar por permissão, segue sem derrubar o update.
                try { Storage::disk('public')->delete($oldPath); } catch (\Throwable $e) {}
            }
        }

        // Regra: sem capa → empreendimento fica inativo. Se o admin tentar
        // ativar via UI sem ter capa, o backend sobrescreve pra false.
        $effectiveCover = $data['cover_image']
            ?? $empreendimento->cover_image;

        if (empty($effectiveCover) && !empty($data['active'])) {
            $data['active'] = false;
        }

        $empreendimento->update($data);

        return $empreendimento;
    }

    /**
     * Exclui um empreendimento.
     *
     * Regras:
     *   - Só admin/gestor. Corretor nunca apaga.
     *   - Se tem leads vinculados (pivot lead_empreendimentos OU FK direta
     *     em leads.empreendimento_id), recusa pra não deixar lead órfão.
     *   - Apaga a capa do storage (best-effort). Imagens da galeria caem em
     *     cascata via FK da tabela empreendimento_images (onDelete cascade).
     */
    public function destroy(Request $request, Empreendimento $empreendimento)
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'gestor'], true), 403);

        // Leads vinculados — protege contra exclusão acidental.
        $leadsByPivot = $empreendimento->leads()->count();
        $leadsByFk    = \App\Models\Lead::where('empreendimento_id', $empreendimento->id)->count();

        if ($leadsByPivot + $leadsByFk > 0) {
            return response()->json([
                'message' => 'Este empreendimento tem leads vinculados e não pode ser excluído. '
                    . 'Desvincule ou reatribua os leads antes de excluir.',
                'leads_count' => $leadsByPivot + $leadsByFk,
            ], 409);
        }

        // Apaga capa do storage (se existir)
        if ($empreendimento->cover_image && Storage::disk('public')->exists($empreendimento->cover_image)) {
            try { Storage::disk('public')->delete($empreendimento->cover_image); } catch (\Throwable $e) {}
        }

        $empreendimento->delete();

        return response()->json(['deleted' => true]);
    }


/**
 * @group Site - Empreendimentos
 *
 * Endpoint público para retornar a galeria de fotos
 * de um empreendimento específico.
 *
 * Utilizado pelo site institucional para sliders,
 * carrosséis e lightbox de imagens.
 *
 * Não requer autenticação.
 *
 * @urlParam code string required Código (slug) do empreendimento. Example: architecto
 *
 * @response 200 [
 *   {
 *     "id": 1,
 *     "image": "https://app.alphadomusimobiliaria.com.br/storage/empreendimentos/architecto/img1.jpg",
 *     "order": 1
 *   },
 *   {
 *     "id": 2,
 *     "image": "https://app.alphadomusimobiliaria.com.br/storage/empreendimentos/architecto/img2.jpg",
 *     "order": 2
 *   }
 * ]
 */
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
