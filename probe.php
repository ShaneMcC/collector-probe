#!/usr/bin/php
<?php

	require_once(dirname(__FILE__) . '/functions.php');

	addCLIParam('s', 'search', 'Just search for devices, don\'t collect or post any data.');
	addCLIParam('p', 'post', 'Just post stored data to collector, don\'t collect any new data.');
	addCLIParam('d', 'debug', 'Don\'t save data or attempt to post to collector, just dump to CLI instead.');
	addCLIParam('', 'key', 'Submission to key use rather than config value', true);
	addCLIParam('', 'location', 'Submission location to use rather than config value', true);
	addCLIParam('', 'server', 'Submission server to use rather than config value', true);

	$daemon['cli'] = parseCLIParams($_SERVER['argv']);
	if (isset($daemon['cli']['help'])) {
		echo 'Usage: ', $_SERVER['argv'][0], ' [options]', "\n\n";
		echo 'Options:', "\n\n";
		echo showCLIParams(), "\n";
		die(0);
	}

	if (isset($daemon['cli']['key'])) { $submissionKey = end($daemon['cli']['key']['values']); }
	if (isset($daemon['cli']['location'])) { $location = end($daemon['cli']['location']['values']); }
	if (isset($daemon['cli']['server'])) { $collectionServer = $daemon['cli']['server']['values']; }

	if (!is_array($collectionServer)) { $collectionServer = array($collectionServer); }

	$time = time();

	$devices = array();

	function processProbe($probeid) {
		global $probes, $daemon, $probeName, $extraTags;
		$probe = $probes[$probeid];

		$devices = [];

		foreach ($probe->getDevices() as $dev) {
			echo sprintf('Found: %s [%s] (%s)' . "\n", $dev['name'], $dev['serial'], $probe->getDataSource($dev));

			if ($extraTags) {
				if (!isset($dev['tags'])) { $dev['tags'] = []; }
				if (isset($probeName) && !empty($probeName)) { $dev['tags']['probe'] = $probeName; }
				$dev['tags']['datasourceprobe'] = get_class($probe);
				$dev['tags']['datasourcetype'] = $dev['datasource']['type'] ?? $dev['tags']['datasourceprobe'];
				$dev['tags']['datasource'] = $probe->getDataSource($dev);
			} else {
				unset($dev['tags']);
			}

			if (isset($daemon['cli']['search'])) { continue; }
			$probe->getDeviceData($dev);

			if (!empty($dev['data'])) {
				$devices[] = $dev;
			}
		}

		return $devices;
	}

	if (!isset($daemon['cli']['post'])) {

		$probeDevices = [];
		$runningThreads = [];
		$useProbeThreads = $useProbeThreads && FakeThread::available();

		foreach (array_keys($probes) as $probeid) {
			if ($useProbeThreads) {
				$thread = new FakeThread('processProbe');
				$thread->start($probeid);
				$runningThreads["process-{$probeid}"] = ['thread' => $thread, 'probeid' => $probeid, 'type' => 'process'];
			} else {
				$probeDevices[] = processProbe($probeid);
			}
		}

		if ($useProbeThreads) {
			set_time_limit(0);
			while (count($runningThreads) > 0) {
				$currentThreads = $runningThreads;
				foreach ($currentThreads as $name => $t) {
					if (!$t['thread']->isAlive()) {
						unset($runningThreads[$name]);

						if ($t['type'] == 'process') {
							$probeDevices[] = $t['thread']->getData();
						}
					}
				}
				// Sleep for 10ms
				usleep(100000);
			}
		}

		foreach ($probeDevices as $deviceResults) {
			foreach ($deviceResults as $device) {
				$devices[] = $device;
			}
		}

		if (function_exists('collectCustomSensorData')) {
			collectCustomSensorData($devices);
		}

		if (count($devices) > 0 && !isset($daemon['cli']['debug'])) {
			$data = json_encode(array('time' => $time, 'devices' => $devices));

			foreach ($collectionServer as $url) {
				$serverDataDir = $dataDir . '/' . parse_url($url, PHP_URL_HOST) . '-' . crc32($url) . '/';
				if (!file_exists($serverDataDir)) { @mkdir($serverDataDir, 0755, true); }
				if (file_exists($serverDataDir) && is_dir($dataDir)) {
					file_put_contents($serverDataDir . '/' . $time . '.js', $data);
				}
			}
		}
	}

	if (isset($daemon['cli']['search'])) { die(0); }
	if (isset($daemon['cli']['debug'])) {
		echo json_encode($devices, JSON_PRETTY_PRINT), "\n";
		die(0);
	}

	// Submit Data.
	foreach ($collectionServer as $url) {
		$serverDataDir = $dataDir . '/' . parse_url($url, PHP_URL_HOST) . '-' . crc32($url) . '/';

		if (file_exists($serverDataDir) && is_dir($serverDataDir)) {
			foreach (glob($serverDataDir . '/*.js') as $dataFile) {
				$data = file_get_contents($dataFile);
				$test = json_decode($data, true);
				if (isset($test['time']) && isset($test['devices'])) {
					$submitted = submitData($data, $url);
					if (isset($submitted['success'])) {
						echo 'Submitted data for: ', $test['time'], ' to ', $url, "\n";
						unlink($dataFile);
					} else {
						if (startsWith($submitted['error'], "illegal attempt to update using time")) {
							echo 'Data for ', $test['time'], ' to ', $url, ' is illegal - discarding.', "\n";
							unlink($dataFile);
						} else {
							echo 'Unable to submit data for: ', $test['time'], ' to ', $url, "\n";
						}
					}
				}
			}
		}
	}

	if (count($devices) > 0 && function_exists('afterProbeAction')) {
		afterProbeAction($devices);
	}
