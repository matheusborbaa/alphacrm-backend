<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use Illuminate\Http\Request;

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

    if ($request->filled('active')) {
        $query->where('active', $request->active);
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
        'name' => 'required|string|max:255',
        'code' => 'nullable|string|max:255',
        'location_city' => 'nullable|string|max:255',
        'active' => 'boolean',
        'commission_percentage' => 'nullable|numeric',
        'average_sale_value' => 'nullable|numeric',
        'starts_at' => 'nullable|date',
        'ends_at' => 'nullable|date',
        'shortdescription' => 'nullable|string',
        'description' => 'nullable|string',
        'cover_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096'
    ]);

    if($request->hasFile('cover_image')){
        $path = $request->file('cover_image')
            ->store('empreendimentos','public');

        $data['cover_image'] = $path;
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
        $empreendimento->update(
            $request->only([
                'name',
                'commission_percentage',
                'average_sale_value',
                'active'
            ])
        );

        return $empreendimento;
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
