<?php

namespace nineteenninetyfour\reviews\http;

use Craft;
use NineteenNinetyFour\Reviews\Core\Http\Client;
use Throwable;

/**
 * The core's HTTP client, through Craft's own Guzzle (so its proxy and
 * timeout settings apply). Null for anything but a JSON answer.
 */
class CraftClient implements Client
{
    public function __construct(private readonly int $timeout = 15) {}

    public function postForm(string $url, array $form): ?array
    {
        return $this->send('POST', $url, ['form_params' => $form]);
    }

    public function getJson(string $url, array $query = [], array $headers = []): ?array
    {
        return $this->send('GET', $url, ['query' => $query, 'headers' => $headers + ['Accept' => 'application/json']]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<mixed>|null
     */
    protected function send(string $method, string $url, array $options): ?array
    {
        try {
            $response = Craft::createGuzzleClient(['timeout' => $this->timeout, 'connect_timeout' => 5])->request($method, $url, $options + ['http_errors' => false]);

            if ($response->getStatusCode() >= 400) {
                Craft::warning(sprintf('Reviews: %s %s answered %d.', $method, preg_replace('/\?.*/', '', $url), $response->getStatusCode()), 'reviews');

                return null;
            }

            $data = json_decode((string) $response->getBody(), true);

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            // Without the keys: platforms take them in the query string,
            // which connection errors repeat in their messages.
            $message = (string) preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~i', '$1', $e->getMessage());
            Craft::warning(sprintf('Reviews: %s %s failed: %s: %s', $method, preg_replace('/[?#].*/s', '', $url), get_class($e), $message), 'reviews');

            return null;
        }
    }
}
