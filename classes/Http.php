<?php
/**
 * Lightweight HTTP client with Laravel-style faking for Dropday CI.
 */
class DropdayHttp
{
    /** @var callable|true|null */
    private static $fake = null;

    /** @var array<int, array{url: string, body: mixed, headers: array}> */
    private static $recorded = [];

    /**
     * Enable faking. Optional callback receives the request and may return a response.
     *
     * @param callable|null $callback
     */
    public static function fake($callback = null)
    {
        self::$recorded = [];
        self::$fake = $callback !== null ? $callback : true;
    }

    /**
     * Build a fake response payload.
     *
     * @param array|string $body
     * @param int $status
     * @return array{status: int, body: string, error: string|null}
     */
    public static function response($body, $status = 200)
    {
        return [
            'status' => (int) $status,
            'body' => is_string($body) ? $body : json_encode($body),
            'error' => null,
        ];
    }

    /**
     * POST JSON (or array body) to a URL. When faked, records the request and skips the network.
     *
     * @param string $url
     * @param mixed $body
     * @param array $headers
     * @return array{status: int, body: string, error: string|null}
     */
    public static function post($url, $body, $headers = [])
    {
        $request = [
            'url' => (string) $url,
            'body' => $body,
            'headers' => $headers,
        ];

        if (self::$fake !== null) {
            self::$recorded[] = $request;
            if (is_callable(self::$fake)) {
                return call_user_func(self::$fake, $request);
            }

            return self::response(['reference' => 'CI-REF'], 200);
        }

        $payload = is_string($body) ? $body : json_encode($body);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $result === false ? '' : (string) $result,
            'error' => $error,
        ];
    }

    /**
     * @return array<int, array{url: string, body: mixed, headers: array}>
     */
    public static function recorded()
    {
        return self::$recorded;
    }

    /**
     * Reset fake state (useful between tests).
     */
    public static function reset()
    {
        self::$fake = null;
        self::$recorded = [];
    }
}
