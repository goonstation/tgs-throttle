<?php

use GuzzleHttp\Client;

class TGSHandler
{
    private $defaultHeaders = [
        'Api' 	 => 'Tgstation.Server.Api/9.2.0',
        'Accept' => 'application/json'
    ];

    private string $host;
    private int $port;
    private Client $client;

    public function __construct(string $host, int $port = 80)
    {
        $this->host = $host;
        $this->port = $port;
        $this->client = new Client([
			'base_uri' => "$host:$port",
			'headers' => $this->defaultHeaders
		]);
    }

    // Login to TGS
    public function login(string $user, string $pass)
    {
		$response = $this->client->post('/', [
			'auth' => [$user, $pass]
		]);
		$body = json_decode($response->getBody());

        // Guzzle clients are immutable, so we just create a new one with the bearer
        $this->client = new Client([
			'base_uri' => "{$this->host}:{$this->port}",
			'headers' => array_merge($this->defaultHeaders, [
                'Authorization' => "Bearer {$body->bearer}"
            ])
		]);
    }

    // Update the repository
    public function updateRepo(int $instance)
    {
        $this->client->post('/Repository', [
            'headers' => ['Instance' => $instance],
            'json' => ["updateFromOrigin" => true]
        ]);
    }

    // Deploy the instance
    public function deploy(int $instance)
    {
        $this->client->put('/DreamMaker', [
            'headers' => ['Instance' => $instance]
        ]);
    }
}
