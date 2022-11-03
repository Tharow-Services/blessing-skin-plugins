<?php

use Blessing\Filter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

return function (Dispatcher $events, Filter $filter) {
    $events->listen(
        'SocialiteProviders\Manager\SocialiteWasCalled',
        'SocialiteProviders\Live\LiveExtendSocialite@handle'
    );

    config(['services.azure' => [
        'client_id' => option('oauth-azure-key'),
        'client_secret' => option('oauth-azure-secret'),
        'redirect' => option('oauth-azure-redirect-uri'),
        'tenant' => option('oauth-azure-tenant'),
    ]]);

    $filter->add('oauth_providers', function (Collection $providers) {
        $providers->put('azure', [
            'icon' => 'microsoft',
            'displayName' => trans("Blessing\OAuth\Azure::displayName")
        ]);

        return $providers;
    });
};
