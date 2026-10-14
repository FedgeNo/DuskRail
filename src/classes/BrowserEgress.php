<?php

declare(strict_types=1);

final class BrowserEgress {
    public static function addresses(string $setting): array {
        if (trim($setting) === '') return [];
        $addresses = array_map('trim', explode(',', $setting));
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('BROWSER_OUTBOUND_IPS must contain public IPv4 addresses separated by commas.');
            }
        }
        if (count(array_unique($addresses)) !== count($addresses)) throw new InvalidArgumentException('BROWSER_OUTBOUND_IPS contains duplicate addresses.');
        return $addresses;
    }

    public static function nextAddress(string $setting, string $state_file): ?string {
        $addresses = self::addresses($setting);
        if ($addresses === []) return null;
        $state = fopen($state_file, 'c+');
        if ($state === false) throw new RuntimeException('Could not open the browser egress rotation state.');
        try {
            if (!flock($state, LOCK_EX)) throw new RuntimeException('Could not lock the browser egress rotation state.');
            $value = trim(stream_get_contents($state));
            if ($value !== '' && !ctype_digit($value)) throw new RuntimeException('Invalid browser egress rotation state.');
            $index = (int) $value % count($addresses);
            $next = (string) (($index + 1) % count($addresses));
            if (!rewind($state) || fwrite($state, $next) !== strlen($next) || !ftruncate($state, strlen($next)) || !fflush($state)) {
                throw new RuntimeException('Could not save the browser egress rotation state.');
            }
            return $addresses[$index];
        } finally {
            flock($state, LOCK_UN);
            fclose($state);
        }
    }
}
