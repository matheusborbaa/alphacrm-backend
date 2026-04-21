<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SwaggerTestController extends Controller
{
    public function test()
    {
        return response()->json(['ok' => true]);
    }
}
