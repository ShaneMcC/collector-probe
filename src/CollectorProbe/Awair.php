<?php

namespace CollectorProbe;

class Awair extends AbstractProbe {

	private $device = '';

	public function __construct($device) {
		$this->device = $device;
	}

	public function getDevices() {
		$awairsettings = json_decode(@curl_get_contents('http://' . $this->device . '/settings/config/data'), true);
		$awairdata = json_decode(@curl_get_contents('http://' . $this->device . '/air-data/latest'), true);

		if (!isset($awairsettings['device_uuid']) || !isset($awairdata['timestamp'])) {
			return [];
		}

		$dev = [];
		$dev['name'] = $awairsettings['device_uuid'];
		$dev['serial'] = strtoupper(str_replace(':', '', $awairsettings['wifi_mac']));
		$dev['datasource'] = ['type' => 'awair', 'addr' => $this->device];
		$dev['data'] = [];

		// Convert temp/humidity to be in line with other sensors.
		$modifiers = ['temp' => ['value' => function($v) { return $v * 1000;}],
		              'humid' => ['name' => 'humidityrelative', 'value' => function($v) { return $v * 1000;}],
		              'timestamp' => ['value' => function ($v) { return null; }]];

		foreach ($awairdata as $dName => $dValue) {
			$thisModifiers = isset($modifiers[$dName]) ? $modifiers[$dName] : [];
			if (isset($thisModifiers['name'])) { $dName = $thisModifiers['name']; }
			if (isset($thisModifiers['value'])) { $dValue = $thisModifiers['value']($dValue); }

			if ($dValue !== null) {
				$dev['data'][$dName] = $dValue;
			}
		}

		yield $dev;
	}

	public function getDataSource($sensor) {
		return $sensor['datasource']['type'] . ' - ' . $sensor['datasource']['addr'];
	}
}
