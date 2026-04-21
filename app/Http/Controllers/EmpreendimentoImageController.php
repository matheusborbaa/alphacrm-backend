<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use App\Models\EmpreendimentoImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmpreendimentoImageController extends Controller
{
   public function store(
    Request $request,
    Empreendimento $empreendimento
)
{

    $request->validate([
            'image' => 'required|mimes:jpg,jpeg,png,webp,heic,PNG,JPEG|max:20480',
            'order' => 'nullable|integer'
    ]);
    $path = $request->file('image')
        ->store("empreendimentos/{$empreendimento->code}", 'public');

    return EmpreendimentoImage::create([
        'empreendimento_id' => $empreendimento->id,
        'image_path' => Storage::url($path),
        'order' => $request->order ?? 0
    ]);
}
}
