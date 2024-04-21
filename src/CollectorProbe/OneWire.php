<?php

namespace CollectorProbe;

class OneWire extends AbstractProbe {
    public function __construct() {
    }

    public function getDevices() {
		foreach (glob('/sys/bus/w1/devices/28-*') as $basedir) {
			$name = trim(file_get_contents($basedir . '/name'));
			$serial = preg_replace('#.*-(.*)$#', '\1', $name);

			$dev = array();
			$dev['name'] = $name;
			$dev['serial'] = $serial;
			$dev['datasource'] = ['type' => '1wire', 'basedir' => $basedir];
			$dev['data'] = [];

			yield $dev;
		}

		return [];
	}

	public function getDeviceData(&$dev) {
		$basedir = $dev['datasource']['basedir'];
		unset($dev['datasource']['basedir']);

		$foundInputs = false;
		foreach (glob($basedir . '/hwmon/hwmon*/*_input') as $sensor) {
			$foundInputs = true;
			$sensorName = preg_replace('#^(.*)_input$#', '\1', basename($sensor));
			$sensorValue = trim(file_get_contents($sensor));

			$dev['data'][$sensorName] = $sensorValue;
		}

		// Sometimes we don't get nice endpoints, just the w1_slave endpoint, so grab data from that.
		if (!$foundInputs && file_exists($basedir . '/w1_slave')) {
			$data = trim(file_get_contents($basedir . '/w1_slave'));
			foreach (explode("\n", $data) as $dataline) {
				if (!preg_match('/^.*\s([^\s]+)=(.*)$/', trim($dataline), $databits)) { continue; }

				if ($databits[1] == 'crc') {
					// Invalid data, ignore.
					if (strpos($databits[2], 'YES') === FALSE) {
						break;
					}
				} else if ($databits[1] == 't') {
					$sensorName = 'temp1';
					$sensorValue = $databits[2];

					$dev['data'][$sensorName] = $sensorValue;
				}
			}
		}
    }
}
