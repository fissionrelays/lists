<?php

if (php_sapi_name() !== 'cli') die("cli only");

$output_dir = dirname(__DIR__) . "/public/tor";

// Relay must be seen within the last 3 hours.
$last_seen_window = 10800;

// Wait between 2 and almost 10 minutes to start at the top of the hour.
$sleep = false;
$sleep_min = 120;
$sleep_max = 590;
if ($sleep) sleep(rand($sleep_min, $sleep_max));

function fetchRelays() {
    $url = "https://onionoo.torproject.org/details";
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => "lists.fissionrelays.net (admin@fissionrelays.net)"
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);

    $response_raw = curl_exec($ch);
    $response = json_decode($response_raw, true);
    return $response['relays'];
}

function parseAddresses($relays, $last_seen_window) {
    $now = time();
    $addresses = [
        'ipv4' => [],
        'ipv6' => [],
        'ipv4_exit' => [],
        'ipv6_exit' => []
    ];

    foreach ($relays as $relay) {
        $relay_addresses = [
            'ipv4' => [],
            'ipv6' => [],
            'ipv4_exit' => [],
            'ipv6_exit' => []
        ];

        // Check if still up.
        if (strtotime($relay['last_seen']) < $now - $last_seen_window) continue;

        $is_exit = in_array("Exit", $relay['flags']);

        foreach ($relay['or_addresses'] as $or_address) {
            preg_match('/^\[?([0-9a-f:.]*)]?:\d+$/', $or_address, $or_address_matches);
            if (count($or_address_matches) === 2) {
                $address = $or_address_matches[1];
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $relay_addresses['ipv4'][] = $address;
                    if ($is_exit) $relay_addresses['ipv4_exit'] [] = $address;
                }
                elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $relay_addresses['ipv6'][] = $address;
                    if ($is_exit) $relay_addresses['ipv6_exit'] [] = $address;
                }
            }
        }

        if (array_key_exists('exit_addresses', $relay)) {
            foreach ($relay['exit_addresses'] as $exit_address) {
                if (filter_var($exit_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !in_array($exit_address, $relay_addresses['ipv4_exit'])) {
                    $relay_addresses['ipv4_exit'][] = $exit_address;
                }
                elseif (filter_var($exit_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && !in_array($exit_address, $relay_addresses['ipv6_exit'])) {
                    $relay_addresses['ipv6_exit'][] = $exit_address;
                }
            }
        }

        $addresses = array_merge_recursive($addresses, $relay_addresses);
    }

    return $addresses;
}

function sortAddresses($addresses) {
    $result = [];
    foreach ($addresses as $name => $list) {
        $temp = array_unique($addresses[$name]);
        natsort($temp);
        $result[$name] = $temp;
    }
    return $result;
}

function writeArrayToFile($filename, $array) {
    $f = fopen($filename, 'w');
    foreach ($array as $line) {
        fwrite($f, "{$line}\n");
    }
    fclose($f);
}

function writeStringToFile($filename, $string) {
    $f = fopen($filename, 'w');
    fwrite($f, "{$string}\n");
    fclose($f);
}

$relays = fetchRelays();
$addresses = parseAddresses($relays, $last_seen_window);
$addresses = sortAddresses($addresses);

writeArrayToFile("{$output_dir}/relays.txt", array_merge($addresses['ipv4'], $addresses['ipv6']));
writeArrayToFile("{$output_dir}/exits.txt", array_merge($addresses['ipv4_exit'], $addresses['ipv6_exit']));
writeArrayToFile("{$output_dir}/relays-ipv4.txt", $addresses['ipv4']);
writeArrayToFile("{$output_dir}/relays-ipv6.txt", $addresses['ipv6']);
writeArrayToFile("{$output_dir}/exits-ipv4.txt", $addresses['ipv4_exit']);
writeArrayToFile("{$output_dir}/exits-ipv6.txt", $addresses['ipv6_exit']);

writeStringToFile("{$output_dir}/updated.txt", gmdate('Y-m-d H:i:s'));
