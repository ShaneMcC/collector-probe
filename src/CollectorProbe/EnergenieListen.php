<?php

namespace CollectorProbe;

class EnergenieListen extends AbstractProbe {
	private $discoveryFiles;

	public function __construct($options = []) {
		$this->discoveryFiles = $options['discoveryFiles'] ?? array('/tmp/energenie-listen.json');
	}

	public function getDevices() {
		foreach ($this->discoveryFiles as $file) {
			$json = json_decode(file_get_contents($file), true);

			foreach ($json as $serial => $data) {
				if ($serial == '__META') { continue; }

				$dev = array();
				$dev['name'] = $serial;
				$dev['serial'] = $serial;
				$dev['datasource'] = ['type' => 'energenie-listen'];
				$dev['data'] = $data['data'];

				yield $dev;
			}
		}
	}
}
