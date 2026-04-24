<?php

namespace App\Http\Controllers;

use App\Models\LeadChannel;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadChannelController extends Controller
{
    public function index()
    {
        return LeadChannel::orderBy('name')->get(['id', 'name']);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $channel = LeadChannel::create($data);
        return response()->json($channel, 201);
    }

    public function update(Request $request, LeadChannel $leadChannel)
    {
        $data = $this->validateData($request, $leadChannel->id);
        $leadChannel->update($data);
        return $leadChannel;
    }

    public function destroy(LeadChannel $leadChannel)
    {
        $count = Lead::where('channel', $leadChannel->name)->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'channel' => "Não é possível excluir: existem {$count} lead(s) com esse canal.",
            ]);
        }
        $leadChannel->delete();
        return response()->json(['deleted' => true]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('lead_channels', 'name')->ignore($ignoreId),
            ],
        ]);
    }
}
