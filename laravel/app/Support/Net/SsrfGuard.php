<?php

declare(strict_types=1);

namespace App\Support\Net;

/**
 * Blocks outbound requests to dangerous network targets (SSRF egress denylist).
 *
 * Policy mirrors the Python agent's net guard: link-local (cloud metadata at
 * 169.254.169.254 and fe80::/10), multicast, reserved/future and the
 * unspecified address are blocked. Private and loopback ranges are intentionally
 * allowed so admin-defined connectors can reach internal APIs.
 *
 * A host that cannot be resolved here is allowed: the request would fail to
 * connect anyway, and unit tests use non-resolvable hostnames. The residual
 * DNS-rebinding gap (resolve here, reconnect elsewhere) is accepted; literal and
 * resolved metadata addresses — the high-value target — are still blocked.
 */
final class SsrfGuard
{
    /**
     * @var list<string>
     */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8',       // this-host / unspecified
        '169.254.0.0/16',  // link-local (incl. cloud metadata 169.254.169.254)
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // reserved / future
        '::/128',          // unspecified
        'fe80::/10',       // link-local
        'ff00::/8',        // multicast
    ];

    public function hostIsBlocked(string $host): bool
    {
        foreach ($this->resolve($host) as $ip) {
            if ($this->ipIsBlocked($ip)) {
                return true;
            }
        }

        return false;
    }

    public function ipIsBlocked(string $ip): bool
    {
        $ip = $this->unwrapIpv4Mapped($ip);

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    private function unwrapIpv4Mapped(string $ip): string
    {
        $binary = @inet_pton($ip);
        if ($binary !== false && strlen($binary) === 16 && str_starts_with($binary, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $mapped = @inet_ntop(substr($binary, 12, 4));
            if ($mapped !== false) {
                return $mapped;
            }
        }

        return $ip;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = explode('/', $cidr);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bitsRaw;
        $wholeBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainderBits !== 0) {
            $mask = 0xFF << (8 - $remainderBits) & 0xFF;
            if ((ord($ipBin[$wholeBytes]) & $mask) !== (ord($subnetBin[$wholeBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
