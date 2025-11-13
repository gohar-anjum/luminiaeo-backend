<?php

namespace App\Services\DataForSEO;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class DataForSEOService
{
    protected string $baseUrl;
    protected string $login;
    protected string $password;

    public function __construct()
    {
        $this->baseUrl = config('services.dataforseo.base_url');
        $this->login   = config('services.dataforseo.login');
        $this->password = config('services.dataforseo.password');
    }

    protected function client()
    {
        return Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->baseUrl($this->baseUrl)
            ->timeout(30);
    }

    public function getSearchVolume(array $keywords): array
    {
        $payload = [
            "data" => [
                [
                    "keywords" => $keywords,
                    "language_code" => "en",
                    "location_code" => 2840, // United States (you can change)
                ]
            ]
        ];

        try {
            $response = $this->client()
                ->post('/keywords_data/google_ads/search_volume/live', $payload)
                ->throw()
                ->json();
            dd($response);
            return $response['tasks'][0]['result'][0]['items'] ?? [];
        } catch (RequestException $e) {
            report($e);
            return ['error' => $e->getMessage()];
        }
    }
}
