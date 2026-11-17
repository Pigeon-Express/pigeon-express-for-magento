<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Api;

use PigeonExpress\HttpClient\HttpClientInterface;
use PigeonExpress\HttpClient\Response;

/**
 * Answers SDK requests from a script and records what was sent.
 *
 * Each scripted answer is a Response to return or a Throwable to throw. The
 * last one repeats once the script runs out.
 */
class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, Response|\Throwable> */
    private $script;

    /** @var array<int, array{method:string, url:string, body:array}> */
    public $requests = [];

    /**
     * @param array<int, Response|\Throwable> $script
     */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers, (string) json_encode($body));
    }

    public function get($url, array $query = [], array $headers = [])
    {
        return $this->answer('GET', $url, $query);
    }

    public function post($url, array $data = [], array $headers = [])
    {
        return $this->answer('POST', $url, $data);
    }

    public function put($url, array $data = [], array $headers = [])
    {
        return $this->answer('PUT', $url, $data);
    }

    public function delete($url, array $headers = [])
    {
        return $this->answer('DELETE', $url, []);
    }

    /**
     * @return Response
     */
    private function answer(string $method, string $url, array $body)
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        $next = count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
