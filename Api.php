<?php

namespace Omnibank\Qonto;

use Omnibank\Exception\ProviderException;
use Omnibank\Exception\UnavailableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Qonto's Business API with an API key: "Authorization: {login}:{secret-key}"
 * on https://thirdparty.qonto.com. The sandbox
 * (https://thirdparty-sandbox.staging.qonto.co) wants the staging token of
 * the developer portal too, in X-Qonto-Staging-Token.
 */
final class Api
{
    public const HOST = 'https://thirdparty.qonto.com';
    public const SANDBOX_HOST = 'https://thirdparty-sandbox.staging.qonto.co';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $login,
        private readonly string $secretKey,
        private readonly string $host = self::HOST,
        private readonly ?string $stagingToken = null,
        private readonly int $timeout = 20,
    ) {
    }

    /**
     * A GET on the API, its query written as Qonto (Rails) reads it: a list
     * as "key[]=a&key[]=b".
     *
     * @param array<string, scalar|list<scalar>|null> $query
     *
     * @return array<mixed> the JSON answer
     *
     * @throws ProviderException   when Qonto refuses (4xx)
     * @throws UnavailableException when Qonto cannot be reached or fails (network, 5xx, 429)
     */
    public function get(string $path, array $query = []): array
    {
        $url = rtrim($this->host, '/').'/'.ltrim($path, '/').self::query($query);
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => array_filter([
                    'Authorization' => $this->login.':'.$this->secretKey,
                    'Accept' => 'application/json',
                    'X-Qonto-Staging-Token' => $this->stagingToken,
                ]),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new UnavailableException('qonto', 'Qonto cannot be reached: '.$e->getMessage(), null, $e);
        }
        if ($status >= 500 || 429 === $status) {
            throw new UnavailableException('qonto', \sprintf('Qonto answered HTTP %d: try again later.', $status), (string) $status);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if (!\is_array($data)) {
            throw new UnavailableException('qonto', \sprintf('Qonto answered HTTP %d with a body that is not JSON.', $status), (string) $status);
        }
        if ($status >= 400) {
            $error = $data['errors'][0] ?? [];
            throw new ProviderException('qonto', (string) ($error['detail'] ?? $error['message'] ?? $data['message'] ?? \sprintf('HTTP %d', $status)), (string) ($error['code'] ?? $status));
        }

        return $data;
    }

    /** @param array<string, scalar|list<scalar>|null> $query */
    private static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            foreach (\is_array($value) ? $value : [$value] as $one) {
                if (null !== $one) {
                    $pairs[] = rawurlencode($key).(\is_array($value) ? '[]' : '').'='.rawurlencode(\is_bool($one) ? ($one ? 'true' : 'false') : (string) $one);
                }
            }
        }

        return $pairs ? '?'.implode('&', $pairs) : '';
    }
}
