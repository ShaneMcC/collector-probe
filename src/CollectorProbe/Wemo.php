<?php

namespace CollectorProbe;

class Wemo extends AbstractProbe {
    private $discoveryIPs;
    private $allowUnicastDiscovery;
    private $ssdpTimeout;
    private $insightService;
    private $metainfoService;

	public function __construct($options = []) {
        /** IPs to send SSDP Discovery to. */
        $this->discoveryIPs = $options['discoveryIPs'] ?? ['239.255.255.250'];

        /** Try setting this to false if unicast discovery doesn't work. */
        $this->allowUnicastDiscovery = $options['allowUnicastDiscovery'] ?? true;

        /** Timeout for discovery packets */
        $this->ssdpTimeout = $options['ssdpTimeout'] ?? 2;

        /** insightService name */
        $this->insightService = $options['insightService'] ?? 'urn:Belkin:service:insight:1';

        /** metainfoService name */
        $this->metainfoService = $options['metainfoService'] ?? 'urn:Belkin:service:metainfo:1';
    }

	public function getDevices() {
        $ssdp = new \SSDP($this->discoveryIPs);

        $knownSerials = [];

        foreach ($ssdp->search('urn:Belkin:device:**', $this->ssdpTimeout, $this->allowUnicastDiscovery) as $device) {
            $loc = @file_get_contents($device['location']);
			$xml = simplexml_load_string($loc);
			if ($xml === FALSE) { continue; }

			$dev = array();
			$dev['name'] = (String)$xml->device->friendlyName;
			$dev['serial'] = (String)$xml->device->serialNumber;
            $dev['datasource'] = ['type' => 'wemo-ssdp', 'ip' => $device['__IP'], 'port' => $device['__PORT'], 'device' => $device, 'xml' => $xml];
			$dev['data'] = array();

            if (isset($knownSerials[$dev['serial']])) { continue; }
            $knownSerials[$dev['serial']] = true;

            yield $dev;
        }

		return [];
	}

	public function getDeviceData(&$dev) {
        $xml = $dev['datasource']['xml'];
		unset($dev['datasource']['xml']);

		$device = $dev['datasource']['device'];
		unset($dev['datasource']['device']);

        if (!isset($xml->device->serviceList->service)) { return; }

        $calls = array();

        foreach ($xml->device->serviceList->service as $service) {
            if (!isset($service->serviceType) || !isset($service->controlURL)) { continue; }

            $url = \phpUri::parse($device['location'])->join($service->controlURL);
            $serviceType = (string)$service->serviceType;
            $dev['services'][$serviceType] = $url;
            $soapParams = ['location' => $url, 'uri' => $serviceType];

            if ($serviceType == $this->insightService) {
                $soap = new \SoapClient(null, $soapParams);

                $calls['insightParams'] = [$soap, 'GetInsightParams'];
                $calls['instantPower'] = [$soap, 'GetPower'];
                $calls['todayKWH'] = [$soap, 'GetTodayKWH'];
                $calls['powerThreshold'] = [$soap, 'GetPowerThreshold'];
                $calls['insightInfo'] = [$soap, 'GetInsightInfo'];
                $calls['onFor'] = [$soap, 'GetONFor'];
                $calls['inSBYSince'] = [$soap, 'GetInSBYSince'];
                $calls['todayONTime'] = [$soap, 'GetTodayONTime'];
                $calls['todaySBYTime'] = [$soap, 'GetTodaySBYTime'];
            }

            /* if ($serviceType == $this->metainfoService) {
                $soap = new \SoapClient(null, $soapParams);

                $calls['extMetaInfo'] = [$soap, 'GetExtMetaInfo'];
                $calls['metaInfo'] = [$soap, 'GetMetaInfo'];
            } */
        }

        if (!empty($calls)) {
            foreach ($calls as $k => [$soap, $f]) {
                try {
                    $dev['data'][$k] = $soap->__soapCall($f, array());
                } catch (\Exception $e) { }
            }

            // Newwer firmware doesn't seem to like the answering to
            // all of the above functions all of the time.
            //
            // However, it does seem to always answer insightParams.
            //
            // So now we parse insightParams...
            //
            // Based on http://ouimeaux.readthedocs.io/en/latest/_modules/ouimeaux/device/insight.html
            // also http://home.stockmopar.com/wemo-insight-hacking/
            // and https://github.com/openhab/openhab/blob/master/bundles/binding/org.openhab.binding.wemo/src/main/java/org/openhab/binding/wemo/internal/WemoBinding.java
            if (isset($dev['data']['insightParams'])) {
                $bits = explode('|', $dev['data']['insightParams']);
                $dev['data']['insightParams_state'] = $bits[0];
                $dev['data']['insightParams_lastChange'] = $bits[1];
                $dev['data']['insightParams_onFor'] = $bits[2];
                $dev['data']['insightParams_onToday'] = $bits[3];
                $dev['data']['insightParams_onTotal'] = $bits[4];
                $dev['data']['insightParams_timeperiod'] = $bits[5];
                $dev['data']['insightParams_averagePower'] = $bits[6];
                $dev['data']['insightParams_currentMW'] = $bits[7];
                $dev['data']['insightParams_todayMW'] = $bits[8];
                $dev['data']['insightParams_totalMW'] = $bits[9];
                $dev['data']['insightParams_threshold'] = $bits[10];
            }

            // And then where we didn't get anything from the real
            // function calls, and there is an appropriate entry in
            // insightParams, we'll simulate that instead... Stupid.
            $map = array();
            $map['instantPower'] = 'insightParams_currentMW';
            $map['powerThreshold'] = 'insightParams_threshold';
            $map['onFor'] = 'insightParams_onFor';
            $map['todayONTime'] = 'insightParams_onToday';

            foreach ($map as $k => $v) {
                if (!isset($dev['data'][$k]) && isset($dev['data'][$v])) {
                    $dev['data'][$k] = $dev['data'][$v];
                }
            }
        }
	}

	public function getDataSource($sensor) {
		return $sensor['datasource']['type'] . ' - ' . $sensor['datasource']['ip'] . ':' . $sensor['datasource']['port'];
	}
}
