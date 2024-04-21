<?php

namespace CollectorProbe;

class Hue extends AbstractProbe {
    private $device = '';
    private $options = [];

    public function __construct($device, $options = []) {
        $this->device = $device;
        $this->options = $options;
    }

    public function getDevices() {
        // All devices from either API version
        $possibleDevs = [];

        // Used to convert the data values to the same format as elsewhere.
        $v1DataValues = [];
        $v1DataValues['temperature'] = ['name' => 'temp', 'value' => function($v) { return $v * 10;} ];

        $v2DataValues = [];
        $v2DataValues['battery'] = ['type' => 'device_power', 'value' => function($v) { return $v[0]['power_state']['battery_level']; }];

        $v2DataValues['presence'] = ['type' => 'motion', 'value' => function($v) { return $v[0]['motion']['motion']; }];
        $v2DataValues['lightlevel'] = ['type' => 'light_level', 'value' => function($v) { return $v[0]['light']['light_level']; }];
        $v2DataValues['temp'] = ['type' => 'temperature', 'value' => function($v) { return intval($v[0]['temperature']['temperature'] * 100); }];
        // TODO: These are not yet migrated to the new API I think?
        // $v2DataValues['dark'] = ['type' => 'device_power', 'value' => function($v) { return $v[0]['power_state']['battery_level']; }];
        // $v2DataValues['daylight'] = ['type' => 'device_power', 'value' => function($v) { return $v[0]['power_state']['battery_level']; }];

        $v2DataValues['open'] = ['type' => 'contact', 'value' => function($v) { return $v[0]['contact_report']['state'] == 'no_contact'; }];
        $v2DataValues['tampered'] = ['type' => 'tamper', 'value' => function($v) {
            foreach ($v as $c) {
                foreach ($c['tamper_reports'] as $tr) {
                    if ($tr['state'] != 'not_tampered') { return true; }
                }
            }

            return false;
        }];

        $huedata = json_decode(@file_get_contents('http://' . $this->device . '/api/' . $this->options['apikey'] . '/sensors'), true);
        $hueSensorDevs = [];

        // Each physical device exposes multiple sensors that only contain partial information.
        // Put them all together here.
        foreach ($huedata as $sensor) {
            if ($sensor['type'] == 'CLIPGenericStatus') { continue; }
            if ($sensor['type'] == 'ZLLSwitch') { continue; }
            if (!isset($sensor['uniqueid'])) { continue; }
            if (!preg_match('#:#', $sensor['uniqueid'])) { continue; }

            if (preg_match('#^([0-9A-F:]+)-#i', $sensor['uniqueid'], $m)) {
                $serial = str_replace(':', '', $m[1]);
            }

            if (!isset($hueSensorDevs[$serial])) {
                $hueSensorDevs[$serial] = ['name' => 'Sensor', 'serial' => $serial, 'values' => []];
            }

            foreach ($sensor['state'] as $type => $value) {
                if ($type != 'lastupdated') {
                    $hueSensorDevs[$serial]['values'][$type] = $value;
                }
            }

            if (isset($sensor['config']['battery'])) {
                $hueSensorDevs[$serial]['values']['battery'] = $sensor['config']['battery'];
            }

            if (!preg_match('#^Hue .* sensor [0-9]+$#', $sensor['name'])) {
                $hueSensorDevs[$serial]['name'] = $sensor['name'];
            }
        }

        // Now group them
        foreach ($hueSensorDevs as $sensor) {
            $dev = [];
            $dev['name'] = $sensor['name'];
            $dev['serial'] = $sensor['serial'];
            $dev['datasource'] = ['type' => 'hue', 'bridge' => $this->device, 'version' => 1];
            $dev['data'] = [];

            foreach ($sensor['values'] as $dName => $dValue) {
                $thisModifiers = isset($v1DataValues[$dName]) ? $v1DataValues[$dName] : [];
                if (isset($thisModifiers['name'])) { $dName = $thisModifiers['name']; }
                if (isset($thisModifiers['value'])) { $dValue = $thisModifiers['value']($dValue); }

                $dev['data'][$dName] = $dValue;
            }

            $possibleDevs[$dev['serial']] = $dev;
        }


        // Now lets try version 2 for some new things...
        $opts = ["http" => ["method" => "GET", "header" => "hue-application-key: " . $this->options['apikey']], "ssl" => ["verify_peer" => false, "verify_peer_name" => false]];
        $huedata_v2 = json_decode(@file_get_contents('https://' . $this->device . '/clip/v2/resource', false, stream_context_create($opts)), true);

        $hueSensorDevsV2 = [];

        // Find all devices.
        if (isset($huedata_v2['data'])) {
            foreach ($huedata_v2['data'] as $sensor) {
                if ($sensor['type'] != 'device') { continue; }

                $hueSensorDevsV2[$sensor['id']] = [];
                $hueSensorDevsV2[$sensor['id']]['name'] = $sensor['metadata']['name'];
                $hueSensorDevsV2[$sensor['id']]['children'] = [];
            }

            // Find all resources that are related to a device.
            foreach ($huedata_v2['data'] as $sensor) {
                if (isset($sensor['owner']['rid']) && isset($hueSensorDevsV2[$sensor['owner']['rid']])) {
                    if (!isset($hueSensorDevsV2[$sensor['owner']['rid']]['children'][$sensor['type']])) {
                        $hueSensorDevsV2[$sensor['owner']['rid']]['children'][$sensor['type']] = [];
                    }
                    $hueSensorDevsV2[$sensor['owner']['rid']]['children'][$sensor['type']][] = $sensor;
                }
            }
        }

        // Now extract the required data from each device+resource
        foreach ($hueSensorDevsV2 as $sensor) {
            if (!isset($sensor['children']['zigbee_connectivity'][0]['mac_address'])) { continue; }
            $serial = str_replace(':', '', $sensor['children']['zigbee_connectivity'][0]['mac_address']);

            if (isset($possibleDevs[$serial])) {
                $dev = $possibleDevs[$serial];
            } else {
                $dev = [];
                $dev['name'] = $sensor['name'];
                $dev['serial'] = $serial;
                $dev['serial'] = str_replace(':', '', $dev['serial']);
                $dev['datasource'] = ['type' => 'hue', 'bridge' => $this->device, 'version' => 2];
                $dev['data'] = [];
            }

            foreach ($v2DataValues as $key => $keyInfo) {
                // Don't override v1 data.
                if (isset($dev['data'][$key])) { continue; }

                if (isset($sensor['children'][$keyInfo['type']])) {
                    $dev['data'][$key] = call_user_func($keyInfo['value'], $sensor['children'][$keyInfo['type']]);
                }
            }

            yield $dev;
        }

        return [];
    }

    public function getDataSource($sensor) {
        return $sensor['datasource']['type'] . ' - ' . $sensor['datasource']['bridge'];
    }
}
