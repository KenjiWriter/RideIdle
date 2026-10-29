<?php

declare(strict_types=1);

namespace App\Services;

use Closure;

/**
 * Whether the domain of a game points at this server, which has to be so before a certificate can be asked for. It compares what the domain resolves to with the public
 * addresses of the server: the ones the configuration names, or else the ones that the host of the web app resolves to (the first game is served from here too).
 */
class DomainChecker
{
    /** A host that a certificate can be asked for: at least two labels of letters, digits and hyphens (not at the ends), each up to 63 characters, 253 in all. */
    public const HOST = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    public const OK = 'ok';

    /** The domain resolves, but to other addresses than the ones of this server. */
    public const WRONG = 'wrong';

    /** The domain does not resolve at all (no record yet, or it has not spread). */
    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    /** The addresses of this server are not known, so there is nothing to compare with. */
    public const UNKNOWN = 'unknown';

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param Closure(string): list<string>|null $resolver the addresses that a host resolves to (the DNS of the machine by default) */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::dns(...);
    }

    public static function isValidHost(string $host): bool
    {
        return preg_match(self::HOST, $host) === 1;
    }

    /**
     * What the domain resolves to against what the server answers on.
     *
     * @return array{status: string, found: list<string>, expected: list<string>}
     */
    public function check(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $expected = $this->serverAddresses();

        if (! self::isValidHost($domain)) {
            return ['status' => self::INVALID, 'found' => [], 'expected' => $expected];
        }

        $found = ($this->resolver)($domain);

        $status = match (true) {
            $found === [] => self::MISSING,
            $expected === [] => self::UNKNOWN,
            array_intersect($found, $expected) !== [] => self::OK,
            default => self::WRONG,
        };

        return ['status' => $status, 'found' => $found, 'expected' => $expected];
    }

    /** @return list<string> the public addresses of this server */
    public function serverAddresses(): array
    {
        $configured = (array) config('ride.network.server_ips', []);

        if ($configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        $host = parse_url((string) config('ride.web_url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? ($this->resolver)(strtolower($host)) : [];
    }

    /** @return list<string> */
    private static function dns(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = strtolower($address);
            }
        }

        return array_values(array_unique($addresses));
    }
}
