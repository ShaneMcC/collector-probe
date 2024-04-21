<?php

namespace CollectorProbe;

class MiTemperature extends AbstractProbe {
	private $dataPath;

	public function __construct($dataPath = '/run/MiTemp2') {
		$this->dataPath = $dataPath;
	}

	public function getDevices() {
		foreach (glob($this->dataPath . '/' . '*.json') as $dataFile) {
			$mtime = filemtime($dataFile);
			if ($mtime < (time() - 120)) { continue; } // Ignore stale files.
			$data = json_decode(file_get_contents($dataFile), true);

			$dev = [];
			$dev['name'] = $data['name'];
			$dev['serial'] = $data['name'];
			$dev['datasource'] = ['type' => 'mitemp'];
			$dev['data'] = [];

			// Convert the data values to the same format as others.
			foreach (['temperature' => 'temp', 'humidity' => 'humidityrelative', 'voltage' => 'voltage'] as $dType => $dName) {
				if (isset($data[$dType])) {
					$dev['data'][$dName] = $data[$dType] * 1000;
				}
			}

			if (!empty($dev['data'])) {
				yield $dev;
			}
		}

		return [];
	}
}
