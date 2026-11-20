<?php

declare(strict_types=1);

namespace SeoLab\Capture;

/**
 * Risolutore di sistema per la produzione. IPv4 tramite gethostbynamel (stesso percorso del resolver di sistema,
 * quindi rispetta /etc/hosts). IPv6 solo tramite dns_get_record: il percorso AAAA non legge /etc/hosts,
 * divergenza documentata nei rischi residui di S-002.
 */
final class SystemAddressResolver implements AddressResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $addresses[] = (string) $ip;
            }
        }
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        return $addresses;
    }
}
