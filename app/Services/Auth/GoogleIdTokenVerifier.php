<?php

namespace App\Services\Auth;

use Google\Client as GoogleClient;

class GoogleIdTokenVerifier
{
    public function __construct(
        private ?string $clientId = null
    ) {
        $this->clientId = $clientId ?? (string) config('services.google_oauth.client_id', '');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array
    {
        if ($this->clientId === '' || $this->clientId === '0') {
            return null;
        }

        $client = new GoogleClient;
        $client->setClientId($this->clientId);
        $payload = $client->verifyIdToken($idToken);
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['aud'] ?? null) !== $this->clientId) {
            return null;
        }

        return $payload;
    }
}
