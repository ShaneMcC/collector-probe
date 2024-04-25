<?php

namespace CollectorProbe;

class Tasmota extends AbstractProbe {

	private $device = '';
	private $options = [];

	public function __construct($device, $options = []) {
		$this->device = $device;
		$this->options = is_array($options) ? $options : [];
	}

	public function getDevices() {
		$tasmotaUrl  = 'http://' . $this->device . '/cm?';
		$baseQueryParams = [];
		if (isset($this->options['username'])) { $baseQueryParams['user'] = $this->options['username']; }
		if (isset($this->options['password'])) { $baseQueryParams['password'] = $this->options['password']; }
		$baseQueryParams['cmnd'] = '';
		$tasmotaUrl .= http_build_query($baseQueryParams);

		$status0 = json_decode(@file_get_contents($tasmotaUrl . urlencode('Status 0')), true);

		$tasmotaEnergyDataValues['voltage'] = ['type' => 'Voltage', 'value' => function($v) { return $v['Voltage']; }]; // Volts
		$tasmotaEnergyDataValues['current'] = ['type' => 'Current', 'value' => function($v) { return intval($v['Current'] * 1000); }]; // Amps => Milliamps
		$tasmotaEnergyDataValues['powerfactor'] = ['type' => 'Factor', 'value' => function($v) { return $v['Factor']; }]; // Power Factor

		$tasmotaEnergyDataValues['realpower'] = ['type' => 'Power', 'value' => function($v) { return intval($v['Power'] * 1000); }]; // W to mW
		$tasmotaEnergyDataValues['apparentpower'] = ['type' => 'ApparentPower', 'value' => function($v) { return intval($v['ApparentPower'] * 1000); }]; // VA => mVA
		$tasmotaEnergyDataValues['reactivepower'] = ['type' => 'ReactivePower', 'value' => function($v) { return intval($v['ReactivePower'] * 1000); }]; // VAr => mVAr

		$tasmotaEnergyDataValues['todaywh'] = ['type' => 'Today', 'value' => function($v) { return intval($v['Today'] * 1000); }]; // kWH to WH

		if (isset($status0['Status'])) {
			$dev = [];
			$dev['name'] = $status0['Status']['DeviceName'];
			$dev['serial'] = str_replace(':', '', strtolower($status0['StatusNET']['Mac']));
			$dev['datasource'] = ['type' => 'tasmota', 'addr' => $this->device];
			$dev['data'] = [];

			if (isset($status0['Status']['Power'])) {
				$dev['data']['powered'] = $status0['Status']['Power'];
			}

			if (isset($status0['StatusSNS']['ENERGY'])) {
				$sensor = $status0['StatusSNS']['ENERGY'];
				foreach ($tasmotaEnergyDataValues as $key => $keyInfo) {
					if (isset($sensor[$keyInfo['type']])) {
						$dev['data'][$key] = call_user_func($keyInfo['value'], $sensor);
						if ($dev['data'][$key] === null) { unset($dev['data'][$key]); }
					}
				}
			}

			if (!empty($dev['data'])) {
				yield $dev;
			}
		} else {
			return [];
		}

		if (isset($this->options['zigbee']) && $this->options['zigbee']) {
			$zigbeeList = json_decode(@file_get_contents($tasmotaUrl . urlencode('ZbStatus1')), true);

			if (!isset($zigbeeList['ZbStatus1'])) { return; }

			// What values do we understand, and convert them to match others.
			$zbDataValues = [];
			$zbDataValues['battery'] = ['type' => 'BatteryPercentage', 'value' => function($v) { return intval($v['BatteryPercentage']); }];
			$zbDataValues['temp'] = ['type' => 'Temperature', 'value' => function($v) { return intval($v['Temperature'] * 1000); }];
			$zbDataValues['humidityrelative'] = ['type' => 'Humidity', 'value' => function($v) { return intval($v['Humidity'] * 1000); }];
			$zbDataValues['pressure'] = ['type' => 'Pressure', 'value' => function($v) { return (!isset($v['PressureUnit']) || $v['PressureUnit'] == 'hPa') ? intval($v['Pressure'] * 1000) : null; }];

			// https://docs.espressif.com/projects/esp-zigbee-sdk/en/latest/esp32/api-reference/zcl/esp_zigbee_zcl_ias_zone.html
			// https://github.com/espressif/esp-zigbee-sdk/blob/b198d7b/components/esp-zigbee-lib/include/zcl/esp_zigbee_zcl_ias_zone.h
			$zbDataValues['open'] = ['type' => 'ZoneStatus', 'value' => function($v) { return ((isset($v['ZoneType']) && $v['ZoneType'] == 0x15) ? (($v['ZoneStatus'] & (1 << 0)) !== 0) : null); }];
			$zbDataValues['tampered'] = ['type' => 'ZoneStatus', 'value' => function($v) { return ($v['ZoneStatus'] & (1 << 2)) !== 0; }];

			$zbDataValues['voltage'] = ['type' => 'RMSVoltage', 'value' => function($v) { return $v['RMSVoltage']; }]; // Volts
			$zbDataValues['realpower'] = ['type' => 'ActivePower', 'value' => function($v) { return intval($v['ActivePower'] * 1000); }]; // W to mW
			$zbDataValues['powered'] = ['type' => 'Power', 'value' => function($v) { return intval($v['Power']); }]; // On or Off.

			foreach ($zigbeeList['ZbStatus1'] as $dev) {
				$devid = $dev['Device'];
				$sensor = json_decode(@file_get_contents($tasmotaUrl . urlencode('ZbStatus3 ' . $devid)), true);

				if (!isset($sensor['ZbStatus3'][0])) { continue; }
				$sensor = $sensor['ZbStatus3'][0];

				// Ignore unreachable sensors, or sensors that we last recieved data from over an hour ago.
				if (!$sensor['Reachable'] || $sensor['LastSeen'] > 3600) { continue; }

				$dev = [];
				$dev['name'] = '';
				if (!isset($this->options['usedevname']) || $this->options['usedevname']) {
					$dev['name'] = $sensor['Name'] ?? '';
				}
				if (empty($dev['name'])) { $dev['name'] = $sensor['Device']; }
				$dev['serial'] = preg_replace('/^0x/', '', strtolower($sensor['IEEEAddr']));
				$dev['datasource'] = ['type' => 'tasmota-zigbee', 'bridge' => $this->device];
				$dev['data'] = [];

				foreach ($zbDataValues as $key => $keyInfo) {
					if (isset($sensor[$keyInfo['type']])) {
						$dev['data'][$key] = call_user_func($keyInfo['value'], $sensor);
						if ($dev['data'][$key] === null) { unset($dev['data'][$key]); }
					}
				}

				yield $dev;
			}
		}

		return [];
	}

	public function getDataSource($sensor) {
		return $sensor['datasource']['type'] . (isset($sensor['datasource']['bridge']) ? ' - ' . $sensor['datasource']['bridge'] : '') . (isset($sensor['datasource']['addr']) ? ' - ' . $sensor['datasource']['addr'] : '');
	}
}
