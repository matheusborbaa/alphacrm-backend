<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function update(Request $request)
{
    $user = $request->user();

    $data = $request->validate([
        'name' => 'nullable|string|max:255',
        'email' => 'nullable|email|max:255',
        'phone' => 'nullable|string|max:20',
        'password' => 'nullable|min:6',
        'avatar' => 'nullable|image|max:2048'
    ]);

    // senha
    if (!empty($data['password'])) {
        $data['password'] = Hash::make($data['password']);
    } else {
        unset($data['password']);
    }

    // upload avatar
    if ($request->hasFile('avatar')) {
        $path = $request->file('avatar')->store('avatars', 'public');
        $data['avatar'] = '/storage/' . $path;
    }

    $user->update($data);
   

    return response()->json([
        'success' => true,
        'user' => $user
    ]);
}
}
