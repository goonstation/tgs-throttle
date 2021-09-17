<?php

require 'vendor/autoload.php';
require 'TGSHandler.php';

class Throttler
{
	private $maxCompileJobs = 2; // How many compilation jobs we want to allow at one time
	private $debug = false; // Output verbose debug information if true
	
	private $activeCompileJobs = 0; // How many active compilation jobs exist
	private array $config;
	private PDO $pdo;
	private TGSHandler $tgs;

	public function __construct()
	{
		$this->debugLog('Starting throttle');
		try {
			$this->config = parse_ini_file(dirname(__FILE__) . '/config.ini', true);
			$this->maxCompileJobs = (int) $this->config['throttle']['max'];
		} catch (\Exception $e) {
			$this->log('Unable to get config: ' . $e->getMessage());
		}

		try {
			$this->dbConnect();
		} catch (\Exception $e) {
			$this->log('Unable to connect to database: ' . $e->getMessage());
		}

		try {
			$this->tgs = new TGSHandler($this->config['tgs']['host'], $this->config['tgs']['port']);
			$this->tgs->login($this->config['tgs']['user'], $this->config['tgs']['pass']);
		} catch (\Exception $e) {
			$this->log('Unable to connect to TGS: ' . $e->getMessage());
		}
	}

	// Logging!
	private function log($e)
	{
		echo '[' . date('Y-m-d H:i:s', time()) . '] ' . $e . PHP_EOL;
	}

	// Debug logging
	private function debugLog($e)
	{
		if ($this->debug) $this->log($e);
	}

	// Connect to the database
	private function dbConnect()
	{
		$host = $this->config['db']['host'];
		$db   = $this->config['db']['db'];
		$user = $this->config['db']['user'];
		$pass = $this->config['db']['pass'];
		$charset = 'utf8mb4';
		
		$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
		$options = [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		];
		try {
			$this->pdo = new PDO($dsn, $user, $pass, $options);
		} catch (\PDOException $e) {
			throw new \PDOException($e->getMessage(), (int)$e->getCode());
		}
	}

	// Get all active instances
	private function getInstances() : array
	{
		$query = "
			SELECT Id, Name, Path
			FROM Instances
			WHERE Online = 1
		";
		$stmt = $this->pdo->query($query);
		return $stmt->fetchAll();
	}

	// Get all active compilation jobs
	private function getActiveCompilations() : array
	{
		/*
		TGS currently has no sane way of looking for "only compile-type jobs"
		so we have to check the description string, which is vaguely gross
		Feature request to resolve this currently open at:
		https://github.com/tgstation/tgstation-server/issues/1318
		*/
		$query = "
			SELECT InstanceId
			FROM Jobs
			WHERE Description = 'Compile active repository code'
			AND StoppedAt IS NULL
			AND StartedById = :currentUserId
		";
		$stmt = $this->pdo->prepare($query);
		$stmt->execute(['currentUserId' => $this->tgs->getUserId()]);
		return $stmt->fetchAll();
	}

	// Get the commit hash of the last successful deployment
	private function getLastSuccessfulHash(int $instanceId) : string
	{
		$query = "
			SELECT ri.CommitSha AS last_success_sha
			FROM Jobs j 
			JOIN CompileJobs cj ON cj.JobId = j.Id
			JOIN RevisionInformations ri ON ri.Id = cj.RevisionInformationId
			WHERE j.InstanceId = :instanceId
			AND j.Cancelled = 0
			AND j.StoppedAt IS NOT NULL
			AND j.ErrorCode IS NULL
			ORDER BY j.Id DESC
			LIMIT 1;
		";
		$stmt = $this->pdo->prepare($query);
		$stmt->execute(['instanceId' => $instanceId]);
		return $stmt->fetchColumn();
	}

	// Get the commit hash for the latest remote origin commit
	private function getLatestOriginHash(string $instancePath) : string
	{
		$cmd = [
			"cd \"$instancePath/Repository\"",
			'git fetch -q --recurse-submodules=no --depth=1 $(git rev-parse --abbrev-ref --symbolic-full-name @{u} | sed \'s!/! !\')',
			'git rev-parse $(git rev-parse --abbrev-ref --symbolic-full-name @{u})'
		];
		return trim(shell_exec(implode(';', $cmd)));
	}

	// Trigger a deployment in TGS
	private function triggerUpdate(int $instanceId)
	{
		if ($this->activeCompileJobs >= $this->maxCompileJobs) {
			// Additional capacity safety check
			$this->debugLog('Unable to update: max compilation jobs reached');
			return;
		}

		$this->debugLog('Triggering update!');
		$this->tgs->updateRepo($instanceId);
		$this->tgs->deploy($instanceId);

		$this->activeCompileJobs++;
	}

	public function run()
	{
		if (empty($this->config) || !$this->pdo || !$this->tgs) return;
		$instances = $this->getInstances();
		$compileJobs = $this->getActiveCompilations();
		$this->activeCompileJobs = count($compileJobs);
		$this->debugLog("Active compile jobs: {$this->activeCompileJobs}");

		// Randomise order of instances to give the impression of fairness when capacity blocking
		shuffle($instances);

		$instancesTotal       = count($instances);
		$instancesProcessed   = 0;
		$instancesCompiling   = 0;
		$instancesLatest      = 0;
		$deploymentsTriggered = 0;

		foreach ($instances as $instance) {
			$instancesProcessed++;
			$this->debugLog("Processing instance: {$instance['Name']}");

			if ($this->activeCompileJobs >= $this->maxCompileJobs) {
				// Max compilation jobs reached, abort out
				$this->debugLog('Aborting run loop: max compilation jobs reached');
				break;
			}

			if (array_search($instance['Id'], array_column($compileJobs, 'InstanceId')) !== false) {
				// Instance is already being compiled, skip it
				$this->debugLog('Skipping update: instance is already being compiled');
				$instancesCompiling++;
				continue;
			}

			$lastSuccessHash = $this->getLastSuccessfulHash($instance['Id']);
			$this->debugLog("Last successful deployment SHA: $lastSuccessHash");

			$latestOriginHash = $this->getLatestOriginHash($instance['Path']);
			$this->debugLog("Latest origin SHA: $latestOriginHash");

			if ($lastSuccessHash === $latestOriginHash) {
				// Last deployment matches latest origin, which means we don't need to compile
				$this->debugLog('Skipping update: deployment is already latest');
				$instancesLatest++;
				continue;
			}

			$this->triggerUpdate($instance['Id']);
			$deploymentsTriggered++;
		}

		$this->log(
			"Triggered $deploymentsTriggered deployments. ".
			"Instances: $instancesProcessed/$instancesTotal processed, ".
			"$instancesCompiling already compiling, ".
			"$instancesLatest up to date."
		);
	}
}

$Throttler = new Throttler();
$Throttler->run();
