# Collector Probe

This repo contains tools for collecting data from various sensor types to submit to https://github.com/ShaneMcC/collector-web

This is an amalgamation of previous (now archived) repos:
 - wemo probe - https://github.com/ShaneMcC/wemo
 - energenie probe - https://github.com/ShaneMcC/energenie-listen
 - 1wire probe - https://github.com/ShaneMcC/1wire

The data is collected by probes and then sent to a central server for graphing purposes.

To prevent data-loss, if the central server is unavailable, data will collect on the probes and then be pushed as soon as it becomes available again.
