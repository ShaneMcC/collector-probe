<?php

namespace CollectorProbe;

class DHT11 extends AbstractProbe {
    public function __construct() { }

    public function getDevices() {
		foreach (glob('/sys/bus/iio/devices/iio:*/') as $basedir) {
			$name = str_replace('@', '_', trim(file_get_contents($basedir . '/name')));

			// These things don't have a real serial :( They are 1-per-GPIO Pin
			// though, so we can use that as an identifier.
			$gpio = base_convert(unpack('H2', file_get_contents($basedir . '/of_node/gpios'), 7)[1], 16, 10);
			$serial = $name . '-gpio-' . $gpio;

			$dev = array();
			$dev['name'] = $name;
			$dev['serial'] = $serial;
			$dev['datasource'] = ['type' => 'dht11', 'basedir' => $basedir];
			$dev['data'] = [];

			yield $dev;
		}

		return [];
	}

	public function getDeviceData(&$dev) {
		$basedir = $dev['datasource']['basedir'];
		unset($dev['datasource']['basedir']);

        foreach (glob($basedir . '/' . '*_input') as $sensor) {
            $sensorName = preg_replace('#^in_(.*)_input$#', '\1', basename($sensor));
            $sensorValue = trim(file_get_contents($sensor));
            $dev['data'][$sensorName] = $sensorValue;
        }
    }
}
