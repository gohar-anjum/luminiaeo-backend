<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle()
    {
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);

        return redirect("https://accounts.google.com/o/oauth2/v2/auth?$query");
    }

    public function handleGoogleCallback(Request $request)
    {
        $code = $request->get('code');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
        ]);

        $data = $response->json();

        if (isset($data['refresh_token'])) {
            file_put_contents(base_path('.env'), str_replace(
                'GOOGLE_ADS_REFRESH_TOKEN=',
                'GOOGLE_ADS_REFRESH_TOKEN='.$data['refresh_token'],
                file_get_contents(base_path('.env'))
            ));

            return "Refresh token generated successfully: ".$data['refresh_token'];
        }

        return "Error generating refresh token: ".json_encode($data);
    }
}
