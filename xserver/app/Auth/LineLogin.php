<?php

declare(strict_types=1);

namespace App\Auth;

use App\Services\HttpClient;
use App\Support\Config;

/**
 * LINE Login (OAuth 2.0 / OIDC) クライアント。
 * Workers版 src/services/line-login.ts の移植。検証は一切弱めていない。
 *
 * - state 検証（呼び出し側のコントローラで実施）
 * - PKCE (S256)
 * - scope: openid profile
 * - nonce を id_token で検証
 * - id_token は HS256 署名（Channel Secret）を検証し、iss / aud / exp も確認
 */
final class LineLogin
{
    private const AUTHORIZE_ENDPOINT = 'https://access.line.me/oauth2/v2.1/authorize';
    private const TOKEN_ENDPOINT = 'https://api.line.me/oauth2/v2.1/token';
    private const PROFILE_ENDPOINT = 'https://api.line.me/v2/profile';
    private const FRIENDSHIP_ENDPOINT = 'https://api.line.me/friendship/v1/status';
    private const VERIFY_ENDPOINT = 'https://api.line.me/oauth2/v2.1/verify';

    public function __construct(
        private Config $config,
        private HttpClient $http
    ) {
    }

    public function callbackUrl(): string
    {
        return $this->config->baseUrl() . '/auth/line/callback';
    }

    /**
     * 認可URLを組み立てる。
     *
     * `bot_prompt` は既定では付けない。
     * これは公式アカウントの友だち追加を促すための「任意」パラメータで、
     * リンク設定が未完了の状態で送ると authorize が 400 になり、
     * ログイン自体ができなくなる。
     * 予約という主目的が、任意機能の設定漏れで止まらないようにする。
     *
     * 友だち追加導線を出したい場合だけ config の LINE_LOGIN_BOT_PROMPT に
     * 'normal' / 'aggressive' を設定する（未設定なら送らない）。
     *
     * 注意: `bot_prompt` が任意なだけで、
     * 「LINE Loginチャネルと予約専用LINE公式アカウントのリンク」自体は必須。
     * friendship/v1/status がリンク済みアカウントとの友だち状態を返すため、
     * リンクしていないと友だち判定が成立せず、公開予約が一切通らない。
     */
    public function buildAuthorizeUrl(
        string $state,
        string $nonce,
        string $codeChallenge
    ): string {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config->str('LINE_LOGIN_CHANNEL_ID'),
            'redirect_uri' => $this->callbackUrl(),
            'state' => $state,
            'scope' => 'openid profile',
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        $botPrompt = $this->config->lineLoginBotPrompt();
        if ($botPrompt !== null) {
            $params['bot_prompt'] = $botPrompt;
        }

        return self::AUTHORIZE_ENDPOINT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * ID token を LINE Platform で検証する（サーバー側検証）。
     *
     * LIFF から受け取った raw ID token の検証に使う。
     * ローカルでの署名検証（verifyIdToken）は HS256 前提だが、
     * LIFF/チャネル設定によっては ES256 で発行されるため、
     * LINE公式の検証エンドポイントへ投げて判定する。
     *
     * このメソッドは「クライアントの申告値を信用しない」ための境界。
     * 戻り値の claims だけを信頼し、画面から送られた userId や
     * displayName、getDecodedIDToken() の内容は一切使わない。
     *
     * @param string|null $nonce 照合する nonce（LIFFでは発行しないので通常null）
     * @return array<string, mixed> 検証済み claims
     * @throws LineLoginError 検証に失敗した場合
     */
    public function verifyIdTokenViaApi(
        string $idToken,
        ?string $nonce = null,
        ?int $nowSeconds = null
    ): array {
        $nowSeconds ??= time();
        $channelId = $this->config->str('LINE_LOGIN_CHANNEL_ID');

        $response = $this->http->post(
            self::VERIFY_ENDPOINT,
            http_build_query([
                'id_token' => $idToken,
                // client_id を渡すと LINE 側でも aud を検証してくれる
                'client_id' => $channelId,
            ]),
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            // 本文にトークンは含めない
            throw new LineLoginError('id_token verification failed (HTTP ' . $response['status'] . ')');
        }

        $claims = json_decode($response['body'], true);
        if (!is_array($claims)) {
            throw new LineLoginError('malformed id_token verification response');
        }

        // LINE側の検証に加えて、こちらでも必ず確認する（多層防御）
        if (($claims['iss'] ?? null) !== 'https://access.line.me') {
            throw new LineLoginError('invalid id_token issuer');
        }
        if (!hash_equals($channelId, (string) ($claims['aud'] ?? ''))) {
            throw new LineLoginError('invalid id_token audience');
        }
        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] < $nowSeconds) {
            throw new LineLoginError('expired id_token');
        }
        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            throw new LineLoginError('id_token has no subject');
        }
        if ($nonce !== null) {
            if (!isset($claims['nonce']) || !is_string($claims['nonce'])
                || !hash_equals($nonce, $claims['nonce'])
            ) {
                throw new LineLoginError('invalid id_token nonce');
            }
        }

        return $claims;
    }

    /**
     * 認可コードをトークンへ交換する。
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = $this->http->post(
            self::TOKEN_ENDPOINT,
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callbackUrl(),
                'client_id' => $this->config->str('LINE_LOGIN_CHANNEL_ID'),
                'client_secret' => $this->config->str('LINE_LOGIN_CHANNEL_SECRET'),
                'code_verifier' => $codeVerifier,
            ]),
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            // アクセストークン等は絶対にログへ出さない。ステータスのみ扱う。
            throw new LineLoginError('token exchange failed with status ' . $response['status']);
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            throw new LineLoginError('malformed token response');
        }
        return $decoded;
    }

    /**
     * id_token (JWS / HS256, 署名鍵 = Channel Secret) を検証してクレームを返す。
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken, string $nonce, ?int $nowSeconds = null): array
    {
        $nowSeconds ??= time();
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new LineLoginError('malformed id_token');
        }
        [$headerPart, $payloadPart, $signaturePart] = $segments;

        $header = json_decode(Session::base64UrlDecode($headerPart), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new LineLoginError('unsupported id_token algorithm');
        }

        $expected = Session::base64UrlEncode(hash_hmac(
            'sha256',
            $headerPart . '.' . $payloadPart,
            $this->config->str('LINE_LOGIN_CHANNEL_SECRET'),
            true
        ));
        if (!hash_equals($expected, $signaturePart)) {
            throw new LineLoginError('invalid id_token signature');
        }

        $claims = json_decode(Session::base64UrlDecode($payloadPart), true);
        if (!is_array($claims)) {
            throw new LineLoginError('malformed id_token payload');
        }
        if (($claims['iss'] ?? null) !== 'https://access.line.me') {
            throw new LineLoginError('invalid id_token issuer');
        }
        if ((string) ($claims['aud'] ?? '') !== $this->config->str('LINE_LOGIN_CHANNEL_ID')) {
            throw new LineLoginError('invalid id_token audience');
        }
        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] < $nowSeconds) {
            throw new LineLoginError('expired id_token');
        }
        if (!isset($claims['nonce']) || !is_string($claims['nonce']) || !hash_equals($nonce, $claims['nonce'])) {
            throw new LineLoginError('invalid id_token nonce');
        }
        if (!isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '') {
            throw new LineLoginError('id_token has no subject');
        }
        return $claims;
    }

    /** @return array<string, mixed> */
    public function fetchProfile(string $accessToken): array
    {
        $response = $this->http->get(
            self::PROFILE_ENDPOINT,
            ['Authorization' => 'Bearer ' . $accessToken]
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new LineLoginError('profile fetch failed with status ' . $response['status']);
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new LineLoginError('malformed profile response');
        }
        return $decoded;
    }

    /**
     * 友だち状態。
     *
     * 重要: このAPIが返す friendFlag は
     * **LINE Loginチャネルにリンクされた LINE公式アカウント** との友だち状態。
     * 任意の公式アカウントを指定できるわけではないので、
     * 予約専用LINE公式アカウントを対象にするには、
     * そのアカウントとLINE Loginチャネルをリンクしておく必要がある
     * （デプロイの必須前提。README「6-8. LINE Developers 側の設定」参照）。
     *
     * 取得できない場合は null（不明）として扱い、
     * 呼び出し側は未追加と同じ扱いにする（fail closed）。
     */
    public function fetchFriendshipStatus(string $accessToken): ?bool
    {
        try {
            $response = $this->http->get(
                self::FRIENDSHIP_ENDPOINT,
                ['Authorization' => 'Bearer ' . $accessToken]
            );
            if ($response['status'] < 200 || $response['status'] >= 300) {
                return null;
            }
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded) && isset($decoded['friendFlag']) && is_bool($decoded['friendFlag'])) {
                return $decoded['friendFlag'];
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
