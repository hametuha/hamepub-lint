<?php

namespace Tests\Functional;

use Hametuha\PubLint\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\EpubFactory;

class ApiTest extends TestCase
{

    /**
     * Run the application.
     *
     * @param string $method
     * @param string $uri
     * @param string $body
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function runApp($method, $uri, $body = '')
    {
        $app = require dirname(__DIR__, 2) . '/includes/app.php';
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody((new StreamFactory())->createStream($body));
        return $app->handle($request);
    }

    public function testHomepageListsVersions()
    {
        $response = $this->runApp('GET', '/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Latest(' . Validator::latest() . ')', (string) $response->getBody());
    }

    public function testMonitor()
    {
        $response = $this->runApp('GET', '/monitor');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', json_decode((string) $response->getBody())->status);
    }

    public function testPayloadIsGone()
    {
        $this->assertSame(404, $this->runApp('POST', '/payload')->getStatusCode());
    }

    public function testValidateWithDefaultVersion()
    {
        // Same as hametuha's CompiledFiles::validate().
        $response = $this->runApp('POST', '/validator', base64_encode(EpubFactory::create()));
        $this->assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getBody());
        $this->assertTrue($json->success, implode("\n", $json->messages));
    }

    public function testValidateBrokenEpub()
    {
        $response = $this->runApp('POST', '/validator/' . Validator::latest(), base64_encode(EpubFactory::create(true)));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(json_decode((string) $response->getBody())->success);
    }

    public function testUnknownVersion()
    {
        $response = $this->runApp('POST', '/validator/1.0.0', base64_encode(EpubFactory::create()));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse(json_decode((string) $response->getBody())->success);
    }

    public function testEmptyBody()
    {
        $this->assertSame(400, $this->runApp('POST', '/validator')->getStatusCode());
    }
}
