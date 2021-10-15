<?php

use GuzzleHttp\Client;

class TGSHandler
{
	private $defaultHeaders = [
		'Api' 	 => 'Tgstation.Server.Api/9.2.0',
		'Accept' => 'application/json'
	];

	private string $baseUri;
	private Client $client;

	public function __construct(string $host, $port = null)
	{
		$this->baseUri = $host . ($port ? ":$port" : '');
		$this->client = new Client([
			'base_uri' => $this->baseUri,
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
			'base_uri' => $this->baseUri,
			'headers' => array_merge($this->defaultHeaders, [
				'Authorization' => "Bearer {$body->bearer}"
			])
		]);
	}

	// Get the ID of the current user
	public function getUserId()
	{
		$response = $this->client->get('/User');
		$body = json_decode($response->getBody());
		return $body->id;
	}

	// Update the repository
	public function updateRepo(int $instance, string $branch)
	{
		$this->client->post('/Repository', [
			'headers' => ['Instance' => $instance],
			'json' => [
				"updateFromOrigin" => true,
				"reference"		   => $branch
			]
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
