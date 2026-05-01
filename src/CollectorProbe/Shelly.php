<?php

namespace CollectorProbe;

class Shelly extends AbstractProbe {

	private $device = '';
	private $options = [];

	public function __construct($device, $options = []) {
		$this->device = $device;
		$this->options = is_array($options) ? $options : [];
	}

	public function getDevices() {
		// Query device info endpoint
		$deviceInfo = json_decode(@curl_get_contents('http://' . $this->device . '/rpc/Shelly.GetDeviceInfo'), true);

		// Query switch status endpoint (id=0 for first switch)
		$switchStatus = json_decode(@curl_get_contents('http://' . $this->device . '/rpc/Switch.GetStatus?id=0'), true);

		// Validate responses
		if (!isset($deviceInfo['id']) || !isset($switchStatus['id'])) {
			return [];
		}

		$dev = [];
		// Use device name from device info, fallback to ID
		$dev['name'] = $deviceInfo['name'] ?? $deviceInfo['id'];
		// Use MAC address as serial
		$dev['serial'] = strtoupper($deviceInfo['mac']);
		$dev['datasource'] = ['type' => 'shelly', 'addr' => $this->device];
		$dev['data'] = [];

		// Map power and temperature data with standard units
		// apower: Watts to milliwatts (multiply by 1000)
		// temperature.tC: Celsius to millidegrees Celsius (multiply by 1000)
		if (isset($switchStatus['apower'])) {
			$dev['data']['realpower'] = intval($switchStatus['apower'] * 1000);
		}

		if (isset($switchStatus['temperature']['tC'])) {
			$dev['data']['temp'] = intval($switchStatus['temperature']['tC'] * 1000);
		}

		// Power state (output): true/false to 1/0
		if (isset($switchStatus['output'])) {
			$dev['data']['powered'] = $switchStatus['output'] ? 1 : 0;
		}

		// Voltage
		if (isset($switchStatus['voltage'])) {
			$dev['data']['voltage'] = floatval($switchStatus['voltage']);
		}

		// Current: Amps to milliamps
		if (isset($switchStatus['current'])) {
			$dev['data']['current'] = intval($switchStatus['current'] * 1000);
		}

		yield $dev;
	}

	public function getDataSource($sensor) {
		return $sensor['datasource']['type'] . ' - ' . $sensor['datasource']['addr'];
	}
}
