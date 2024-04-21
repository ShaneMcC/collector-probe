<?php

namespace CollectorProbe;

abstract class AbstractProbe {

    /**
     * Get an array of devices/sensors this probe knows about.
     *
     * Each item in the array should be an array of:
     * ['name' => 'Sensor Name', 'serial' => 'SensorSerialNumber', 'datasource' => ['type' => 'datasource name'], 'data' => []]
     *
     * The actual data array is optional at this point, and may instead be populated later by getDeviceData()
     *
     * @return array Array of devices/sensors known by this probe
     */
    abstract function getDevices();


    /**
     * Some devices are slow to retrieve data, so we might not populate the data immediately if it isn't required
     * (ie, in search mode)
     *
     * If sensor data is needed, this method will be called to attempt to populate it.
     *
     * The device array from getDevices() is passed in byref, the ['data'] attribute should be populated as desired.
     */
    public function getDeviceData(&$dev) { }

    /**
     * Return a string representing the datasource of the given sensor.
     */
    public function getDataSource($sensor) {
        return $sensor['datasource']['type'];
    }
}
