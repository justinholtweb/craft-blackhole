<?php

namespace justinholtweb\blackhole\helpers;

/**
 * Address matching for the whitelist.
 *
 * Three notations, because all three are things people actually write down:
 *
 *   - an exact address:   `203.0.113.7`, `2001:db8::1`
 *   - a dotted prefix:    `203.0.113.` — everything starting with those bytes
 *   - a CIDR range:       `203.0.113.0/24`, `2001:db8::/32`
 *
 * Comparison is done on packed bytes rather than on strings, so `2001:db8::1`,
 * `2001:0db8:0000:0000:0000:0000:0000:0001` and `2001:db8:0:0:0:0:0:1` are the one address they
 * all are — a string comparison gets that wrong three times out of three.
 */
class Ip
{
    /**
     * Whether an address matches any pattern in a list.
     *
     * @param string[] $patterns
     */
    public static function matchesAny(?string $ip, array $patterns): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (self::matches($ip, (string)$pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        if (str_contains($pattern, '/')) {
            return self::inRange($ip, $pattern);
        }

        // A trailing dot or colon is the "everything under here" shorthand. It is not a netmask
        // and cannot be turned into one — `10.` is not a /8 unless you assume it is — so it stays
        // a string prefix, which is exactly what the person writing it meant.
        if (str_ends_with($pattern, '.') || str_ends_with($pattern, ':')) {
            return stripos($ip, $pattern) === 0;
        }

        $left = self::pack($ip);
        $right = self::pack($pattern);

        if ($left === null || $right === null) {
            // Not an address on one side or the other — fall back to comparing what was typed,
            // so a malformed entry fails closed rather than matching everything.
            return strcasecmp($ip, $pattern) === 0;
        }

        return $left === $right;
    }

    /**
     * Whether an address falls inside a CIDR range.
     */
    public static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        $address = self::pack($ip);
        $network = self::pack((string)$subnet);

        if ($address === null || $network === null) {
            return false;
        }

        // Mixing families is not an error, it is simply never a match: no IPv4 address is inside
        // an IPv6 range, and the packed lengths say so without any special-casing.
        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $maxBits = strlen($address) * 8;
        $bits = $bits === null || $bits === '' ? $maxBits : (int)$bits;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }

    /**
     * An address in its packed binary form, or null if it is not an address.
     */
    public static function pack(string $ip): ?string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return null;
        }

        // A bracketed literal (`[::1]`) and a port on the end are both things that turn up in
        // forwarded headers.
        $ip = trim($ip, '[]');

        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    public static function isValid(string $ip): bool
    {
        return self::pack($ip) !== null;
    }

    /**
     * An address in a single canonical spelling, so the unique key on the bots table means what
     * it looks like it means.
     */
    public static function normalize(string $ip): string
    {
        $packed = self::pack($ip);

        if ($packed === null) {
            return trim($ip);
        }

        $printable = @inet_ntop($packed);

        return $printable === false ? trim($ip) : $printable;
    }

    /**
     * Whether the address Craft resolved for this request is only *claimed* — taken from a
     * forwarding header the site has not said to trust — rather than the address actually
     * connecting.
     *
     * Craft's default `trustedHosts` is "any", under which `getUserIP()` believes whatever
     * `X-Forwarded-For` (or `ipHeaders`) says. That is fine for deciding *who to let in*, and
     * fatal for deciding *who to ban*: anybody could name a victim's address and have it blocked.
     * So a claimed address never springs the trap. Behind a real proxy, set `trustedHosts` to it
     * and the forwarded address becomes a fact.
     */
    public static function isOnlyClaimed(\craft\web\Request $request): bool
    {
        $trusted = array_values(array_filter((array)$request->trustedHosts));
        $trustsProxies = $trusted !== [] && !in_array('any', $trusted, true);

        if ($trustsProxies) {
            return false;
        }

        $remote = self::normalize((string)$request->getRemoteIP());
        $resolved = self::normalize((string)$request->getUserIP());

        return $resolved !== '' && $remote !== '' && $resolved !== $remote;
    }
}
