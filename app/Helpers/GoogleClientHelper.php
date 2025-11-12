<?php

namespace App\Helpers;

use Google\Ads\GoogleAds\Lib\V19\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;

class GoogleClientHelper
{
    public static function getGoogleAdsClient()
    {
        $oAuth2Credential = (new OAuth2TokenBuilder())
            ->withClientId(config('services.google.client_id'))
            ->withClientSecret(config('services.google.client_secret'))
            ->withRefreshToken(config('services.google.refresh_token'))
            ->build();

        return (new GoogleAdsClientBuilder())
            ->withDeveloperToken(config('services.google.developer_token'))
            ->withLoginCustomerId(config('services.google.login_customer_id'))
            ->withOAuth2Credential($oAuth2Credential)
            ->build();
    }
}
