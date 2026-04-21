<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhmService
{
    private $baseUrl;
    private $token;
    private $cpanelUser;

    public function __construct()
    {
        $this->baseUrl = config('services.whm.url');
        $this->token = config('services.whm.token');
        $this->cpanelUser = config('services.whm.user');
    }

    public function createEmail($email, $domain, $password, $quota = 1024)
    {
        $response = Http::withOptions([
    'verify' => false
])->withHeaders([
    'Authorization' => "whm encu0499:{$this->token}"
])->get("{$this->baseUrl}/json-api/uapi_cpanel", [
    'cpanel.user' => 'alphadom',
    'module' => 'Email',
    'function' => 'add_pop',
    'email' => $email,
    'domain' => $domain,
    'password' => $password,
    'quota' => 1024
]);

        return $response->json();
    }
}