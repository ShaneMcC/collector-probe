<?php

class SSDP {

	private $discoveryIPs = array('239.255.255.250');

	public function __construct($discoveryIPs = null) {
		if ($discoveryIPs !== null) {
			if (!is_array($discoveryIPs)) { $discoveryIPs = array($discoveryIPs); }
			$this->discoveryIPs = $discoveryIPs;
		}
	}

	public function search($st = 'ssdp:all', $timeout = 2, $allowUnicastDiscovery = true) {
		$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, true);
		foreach ($this->discoveryIPs as $sendIP) {
			$search = array();
			$search[] = 'M-SEARCH * HTTP/1.1';
			if ($allowUnicastDiscovery) {
				$search[] = 'Host: ' . $sendIP . ':1900';
			} else {
				$search[] = 'Host: 239.255.255.250:1900';
			}
			$search[] = 'Man: "ssdp:discover"';
			$search[] = 'ST: ' . $st;
			if (!$allowUnicastDiscovery || $sendIP == '239.255.255.250') {
				$search[] = 'MX: ' . $timeout;
			}
			$search = implode("\r\n", $search) . "\r\n\r\n";

			socket_sendto($sock, $search, strlen($search), 0, $sendIP, 1900);
			socket_sendto($sock, $search, strlen($search), 0, $sendIP, 1900);
			socket_sendto($sock, $search, strlen($search), 0, $sendIP, 1900);
		}
		socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array('sec' => ($timeout + 1), 'usec'=>'0'));

		$result = array();
		while (true) {
			$input = null;
			$from = null;
			$port = null;
			socket_recvfrom($sock, $input, 1024, MSG_WAITALL, $from, $port);
			if ($input !== null) {
		   		foreach (explode("\r\n\r\n", trim($input)) as $reply) {
					$headers = $this->parseHeaders($reply);
					if (isset($headers['usn'])) {
						$result[$headers['usn']] = $headers;
						$result[$headers['usn']]['__IP'] = $from;
						$result[$headers['usn']]['__PORT'] = $port;
					}
				}
			} else {
				break;
			}
		}
		socket_close($sock);

		return $result;
	}

	function parseHeaders($raw) {
		$result = array();
		foreach (explode("\n", $raw) as $header) {
			$bits = explode(':', $header, 2);
			if (isset($bits[1])) {
				$bits[0] = strtolower($bits[0]);
				if (!isset($result[$bits[0]])) { $result[$bits[0]] = array(); }
				$result[$bits[0]][] = trim($bits[1]);
			}
		}
		// Flatten any single-entry headers.
		foreach ($result as &$r) { if (count($r) == 1) { $r = $r[0]; } }
		return $result;
	}
}
