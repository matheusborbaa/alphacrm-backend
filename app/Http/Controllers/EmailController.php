<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\WhmService;

class EmailController extends Controller
{
    public function store(Request $request, WhmService $whm)
    {
        $request->validate([
            'email' => 'required|string',
            'domain' => 'required|string',
            'password' => 'required|min:6'
        ]);

        $result = $whm->createEmail(
            $request->email,
            $request->domain,
            $request->password
        );

        if (isset($result['cpanelresult']['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['cpanelresult']['error']
            ], 400);
        }

       return response()->json([
    'status_http' => $response->status(),
    'body' => $response->body(),
    'json' => $response->json(),
]);
    }
}