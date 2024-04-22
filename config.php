<?php

	/** This location. */
	$location = 'Home';

	/** Submission Key. */
	$submissionKey = 'SomePassword';

	/** Collection URL. */
	$collectionServer = 'http://127.0.0.1/collector-web/submit.php';

	// Collection Server can also be an array.
	//
	// $collectionServer = array();
	// $collectionServer[] = 'http://127.0.0.1/wemo/submit.php';
	// $collectionServer[] = 'http://Home:SomeOtherPassword@10.0.0.2/wemo/submit.php';
	//
	// If no location/key is specified in the url, then the default values of
	// $location and $submissionKey will be used.

	/** Data storage directory. */
	$dataDir = dirname(__FILE__) . '/data/';

	/** Run each probe as a separate thread? */
	$useProbeThreads = true;

	/** Probes to enable */
	$probes = [];

	/** Collect from local 1wire probes */
	$probes[] = new \CollectorProbe\OneWire();

	/** Collect from local DHT11 probes */
	$probes[] = new \CollectorProbe\DHT11();

	/** Collect from MiTemperature devices */
	/** This requires an appropriate helper running to provide data. */
	// $probes[] = new \CollectorProbe\MiTemperature();

	/** Collect from Wemo devices */
	$probes[] = new \CollectorProbe\Wemo();

	/** Collect from local Energenie devices using EngergenieListen */
	/** This requires an appropriate helper running to provide data. */
	$probes[] = new \CollectorProbe\EnergenieListen();

	/** Phillips Hue Data Collection. */
	/** Need to get an API Key by following https://developers.meethue.com/develop/get-started-2/ */
	$probes[] = new \CollectorProbe\Hue('192.168.1.5', ['apikey' => '']);

	/** Collect data from Awair Elements devices. */
	$probes[] = new \CollectorProbe\Awair('192.168.1.6');

	/** Collect data from Tasmota devices. */
	/** If the device has a zigbee bridge, that can be collected from by passing 'zigbee' => true to the options array */
	$probes[] = new \CollectorProbe\Tasmota('192.168.1.7', ['username' => 'someuser', 'password' => 'somepassword', 'zigbee' => true]);

	if (file_exists(dirname(__FILE__) . '/config.user.php')) {
		require_once(dirname(__FILE__) . '/config.user.php');
	}

	// Support for old variable names.
	if (isset($hueDevices) && !empty($hueDevices)) {
		foreach ($hueDevices as $ip => $options) {
			$probes[] = new \CollectorProbe\Hue($ip, $options);
		}
	}
	if (isset($tasmotaDevices) && !empty($tasmotaDevices)) {
		foreach ($tasmotaDevices as $ip => $options) {
			$probes[] = new \CollectorProbe\Tasmota($ip, $options);
		}
	}
	if (isset($awairDevices) && !empty($awairDevices)) {
		foreach (array_keys($awairDevices) as $ip) {
			$probes[] = new \CollectorProbe\Awair($ip);
		}
	}

	if (!function_exists('afterProbeAction')) {
		/**
		 * Function to run after finding all wemo devices to perform
		 * additional tasks.
		 * (Saves modules needing to re-scan every time.)
		 *
		 * @param $devices Devices array
		 */
		function afterProbeAction($devices) { }
	}

	if (!function_exists('collectCustomSensorData')) {
		/**
		 * Function to run after finding all supported devices to collect additional sensors
		 * or modify existing sensor data.
		 *
		 * Devices is passed in by reference, so it can be modified directly.
		 *
		 * @param $devices Devices array
		 */
		function collectCustomSensorData(&$devices) { }
	}
