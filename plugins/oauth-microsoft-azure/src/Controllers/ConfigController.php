<?php

namespace Blessing\OAuth\Azure;

use App\Services\Facades\Option;
use App\Services\OptionForm;
use Illuminate\Routing\Controller;

class ConfigController extends Controller
{
    public function render()
    {
        $form = Option::form(
            'oauth-microsoft-azure',
            trans('Blessing\OAuth\Azure::config.general.title'),
            function (OptionForm $form) {
                $form->text('oauth-azure-key', trans('Blessing\OAuth\Azure::config.general.clientId'));
                $form->text('oauth-azure-secret', trans('Blessing\OAuth\Azure::config.general.clientSecret'));
                $form->text('oauth-azure-redirect-uri', trans('Blessing\OAuth\Azure::config.general.redirectUri'));
                $form->text('oauth-azure-tenant', trans('Blessing\OAuth\Azure::config.general.tenantId'));
            }
        )->handle(function (OptionForm $ignored) {
            config(['services.azure' => [
                'client_id' => option('oauth-azure-key'),
                'client_secret' => option('oauth-azure-secret'),
                'redirect' => option('oauth-azure-redirect-uri'),
                'tenant' => option('oauth-azure-tenant'),
            ]]);
        });



        return view('Blessing\OAuth\Azure::config', ['form' => $form]);
    }
}
