<?php

namespace Yggdrasil\Controllers;

use App\Models\Player;
use App\Models\User;
use Cache;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Lcobucci\JWT;
use Log;
use Ramsey\Uuid\Uuid;
use Yggdrasil\Exceptions\ForbiddenOperationException;
use Yggdrasil\Exceptions\IllegalArgumentException;
use Yggdrasil\Models\Profile;
use Yggdrasil\Models\Token;

class ServicesController extends Controller
{
    /**
     * Get a instance of the Guzzle HTTP client.
     *
     * @return \GuzzleHttp\Client
     */
    protected function getHttpClient()
    {
        if (is_null($this->httpClient)) {
            $this->httpClient = new Client();
        }

        return $this->httpClient;
    }

    /**
     * @throws IllegalArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function login(Request $request) {
        if (is_null($request->input('xtoken')) && str_contains($request->input('xtoken'), 'XBL3.0 x=')) {
            throw new IllegalArgumentException(trans('Yggdrasil::exceptions.auth.empty'));
        }
        $rawToken = $request->input('xtoken');
        $UserHashAndToken = preg_split(";", $rawToken);
        $msToken = $UserHashAndToken[1];

        $response = $this->getHttpClient()->get(
            'https://graph.microsoft.com/v1.0/me',
            ['headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer '.$msToken,
            ],
            ]
        );
        $back = json_decode($response->getBody()->getContents(), true);

        $user = $this->checkUserCredentials($back['userPrincipalName'], null, false, false);
        $email = $user->email;
        $userUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, $email)->getHex()->toString();
        $clientToken = Uuid::NIL;

        $jwtConfig = JWT\Configuration::forSymmetricSigner(
            new JWT\Signer\Hmac\Sha256(),
            JWT\Signer\Key\InMemory::plainText(config('jwt.secret', ''))
        );
        $builder = $jwtConfig->builder();
        $builder->relatedTo($userUuid)
            ->withClaim('yggt', Uuid::uuid4()->getHex()->toString());

        $token = new Token($clientToken);
        $token->owner = $email;

        $expires_in = option('ygg_token_expire_1');

        $resp = [
            'access_token' => '',
            'token_type' => "Bearer",
            'expires_in' => $expires_in
        ];



        $now = CarbonImmutable::now();
        $accessToken = (string) $builder->issuedBy('Yggdrasil-Auth')
            ->expiresAt($now->addSeconds((int) option('ygg_token_expire_1')))
            ->issuedAt($now)
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
            ->toString();

        $resp['accessToken'] = $accessToken;
        $token->accessToken = $accessToken;

        $this->storeToken($token, $email);
        Log::channel('ygg')->info("New access token [$accessToken] generated for user [$email]");

        Log::channel('ygg')->info("User [$email] authenticated successfully");

        ygg_log([
            'action' => 'authenticate_microsoft',
            'user_id' => $user->uid,
            'parameters' => "Only Token",
        ]);

        return json($resp);

    }

    public function profile(Request $request) {
        $accessToken=$request->bearerToken();
        /** @var Token $token */
        $token = Cache::get("yggdrasil-token-$accessToken");
        /** @var User */
        $user = User::where('email', $token->owner)->first();
        if (empty($user)) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.not-existed'));
        }
        $availableProfiles = $this->getAvailableProfiles($user);



        //** @var Profile $profile */
        $profile = Profile::createFromUuid($token->profileId);
        app('url')->forceRootUrl(option('site_url'));
        $skin = [
            'id'=>$profile->uuid,
            'state'=>'ACTIVE',
            "variant"=>"classic",
            'url' => url("textures/{$profile->skin}"),
        ];

        if ($profile) {
            $resp = [
                'id'=>$profile->uuid,
                'name'=>$profile->name,
                'skins'=>[$skin],
                'capes'=>null,
            ];
        }
        return json($resp);
    }

    protected static function privilegeWrap(bool $enabled) {
        return array(
            'enabled'=>$enabled
        );
    }

    protected static function arrayWrap($str, $arr=array()) {
        $ret = array();
        $ret[$str]=$arr;
        return $ret;
    }

    public function attributes(Request $request) {
        $resp = [
            'privileges'=>array(
                "onlineChat"=> self::privilegeWrap(true),
                "multiplayerServer"=> self::privilegeWrap(true),
                "multiplayerRealms"=> self::privilegeWrap(false),
                "telemetry"=> self::privilegeWrap(false)
            ),
            'profanityFilterPreferences'=>self::arrayWrap('profanityFilterOn', false),
            "banStatus"=>self::arrayWrap("bannedScopes", []),
        ];
        return json($resp);
    }

    public function certificates(Request $request) {
        $resp = [
            'keyPair'=>array(
                'privateKey'=>'',
                'publicKey'=>'',
            ),
            'publicKeySignature'=>'',
            'publicKeySignatureV2'=>'',
            'expiresAt'=>now()->serialize(),
            'refreshedAfter'=>now()->addDay()->serialize()
        ];

        return json($resp);
    }

    protected function checkUserCredentials($identification, $password, $checkBanned, $skipPassword) {
        if (is_null($identification) || (is_null($password))&&!$skipPassword) {
            throw new IllegalArgumentException(trans('Yggdrasil::exceptions.auth.empty'));
        }

        $authType = filter_var($identification, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        if ($authType === 'email') {
            /** @var User */
            $user = User::where('email', $identification)->first();
        } else {
            $player = Player::where('name', $identification)->first();
            /** @var User */
            $user = optional($player)->user;
        }

        if (is_null($user)) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.auth.not-existed', compact('identification')));
        }
        if (!is_null($user->locale)) {
            app()->setLocale($user->locale);
        }

        if (!$user->verifyPassword($password)&&!$skipPassword) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.auth.not-matched'));
        }

        if ($checkBanned && $user->permission == User::BANNED) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
        }

        if (option('require_verification') && $user->verified === false) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.not-verified'));
        }

        return $user;
    }

    protected function getAvailableProfiles(User $user)
    {
        $profiles = [];

        foreach ($user->players as $player) {
            $uuid = Profile::getUuidFromName($player->name);

            $profiles[] = [
                'id' => $uuid,
                'name' => $player->name,
            ];
        }

        return $profiles;
    }

    protected function storeToken(Token $token, $identification)
    {
        $timeToFullyExpired = option('ygg_token_expire_2');
        Cache::put("yggdrasil-token-{$token->accessToken}", $token, $timeToFullyExpired);

        $limit = (int) option('ygg_tokens_limit', 10);
        $tokens = Arr::wrap(Cache::get("yggdrasil-id-$identification"));
        if (count($tokens) >= $limit) {
            $expired = array_shift($tokens);
            if ($expired) {
                Cache::forget('yggdrasil-token-'.$expired->accessToken);
            }
        }
        $tokens[] = $token;
        Cache::put("yggdrasil-id-$identification", $tokens);

        Log::channel('ygg')->info("Serialized token stored to cache with expiry time $timeToFullyExpired minutes", [
            'keys' => ["yggdrasil-token-{$token->accessToken}"],
            'token' => $token,
        ]);
    }
}
