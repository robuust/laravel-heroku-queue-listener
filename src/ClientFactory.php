<?php

declare(strict_types=1);

namespace Robuust\HerokuQueueListener;

use HerokuClient\Client;
use InvalidArgumentException;

/**
 * Creates worker clients from paired gateway or legacy configuration.
 */
class ClientFactory
{
    /** @var int Maximum duration of a gateway request, including connection setup. */
    private const GATEWAY_TIMEOUT_SECONDS = 35;

    /** @var int Maximum duration of a direct Heroku request, including connection setup. */
    private const HEROKU_TIMEOUT_SECONDS = 5;

    /**
     * Create a client without falling back from incomplete gateway configuration.
     *
     * @return Client|null The configured client, or null when autoscaling is unconfigured.
     */
    public static function create(): ?Client
    {
        $controlUrl = (string) config('queue-autoscaler.heroku_control_url');
        $controlToken = (string) config('queue-autoscaler.heroku_control_token');

        if ($controlUrl !== '' || $controlToken !== '') {
            if ($controlUrl === '' || ! preg_match('/^hc_[A-Za-z0-9_-]{43}$/D', $controlToken)) {
                throw new InvalidArgumentException('HEROKU_CONTROL_URL and a valid HEROKU_CONTROL_TOKEN must be configured together.');
            }

            $parts = parse_url($controlUrl);

            if (filter_var($controlUrl, FILTER_VALIDATE_URL) === false
                || $parts === false
                || ($parts['scheme'] ?? null) !== 'https'
                || empty($parts['host'])
                || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))) {
                throw new InvalidArgumentException('HEROKU_CONTROL_URL must be an HTTPS base URL without credentials, query, or fragment.');
            }

            return new Client([
                'baseUrl' => rtrim($controlUrl, '/').'/',
                'apiKey' => $controlToken,
                'curlOptions' => [
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_TIMEOUT => self::GATEWAY_TIMEOUT_SECONDS,
                    CURLOPT_FOLLOWLOCATION => false,
                ],
            ]);
        }

        $apiKey = (string) config('queue-autoscaler.heroku_api_key');

        if ($apiKey === '') {
            return null;
        }

        if (str_starts_with($apiKey, 'hc_')) {
            throw new InvalidArgumentException('Gateway credentials require HEROKU_CONTROL_URL and HEROKU_CONTROL_TOKEN.');
        }

        return new Client([
            'apiKey' => $apiKey,
            'curlOptions' => [
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => self::HEROKU_TIMEOUT_SECONDS,
            ],
        ]);
    }

    /**
     * Return the request bound used to keep the cache lock longer than the request.
     *
     * @return int The total request timeout in seconds.
     */
    public static function timeoutSeconds(): int
    {
        return (string) config('queue-autoscaler.heroku_control_url') !== '' || (string) config('queue-autoscaler.heroku_control_token') !== ''
            ? self::GATEWAY_TIMEOUT_SECONDS
            : self::HEROKU_TIMEOUT_SECONDS;
    }
}
