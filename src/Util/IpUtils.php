<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Util;

/**
 * Http utility functions.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class IpUtils
{
    /**
     * Checks if an IPv4 or IPv6 address is contained in the list of given IPs or subnets.
     *
     * @param string|array $ips List of IPs or subnets (can be a string if only a single one)
     *
     * @return bool
     */
    public static function checkIp(string $requestIp, array $ips)
    {
        $method = substr_count($requestIp, ':') > 1 ? 'checkIp6' : 'checkIp4';
        foreach ($ips as $ip) {
            if (self::$method($requestIp, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析客户端真实IP。
     * 仅当 REMOTE_ADDR 来自可信代理时，才解析转发头。
     *
     * @param array $server
     * @param array $trustedProxies
     * @return string
     */
    public static function resolveClientIp(array $server, array $trustedProxies = []): string
    {
        $remoteIp = $server['REMOTE_ADDR'] ?? '0.0.0.0';
        $clientIp = $remoteIp;

        if (self::isTrustedProxyRemoteIp($remoteIp, $trustedProxies)) {
            $forwardedIp = self::extractClientIpFromForwardedHeaders($server, true);
            if (!empty($forwardedIp)) {
                $clientIp = $forwardedIp;
            }
        }

        if (!self::isValidIp($clientIp)) {
            return '0.0.0.0';
        }

        return $clientIp;
    }

    /**
     * 请求是否来自可信代理
     *
     * @param string $remoteIp
     * @param array $trustedProxies
     * @return bool
     */
    public static function isTrustedProxyRemoteIp(string $remoteIp, array $trustedProxies = []): bool
    {
        if ($remoteIp === '' || empty($trustedProxies)) {
            return false;
        }

        return self::checkIp($remoteIp, $trustedProxies);
    }

    /**
     * 从转发头中提取客户端IP，默认仅接受公网IP
     *
     * @param array $server
     * @param bool $publicOnly
     * @return string|null
     */
    public static function extractClientIpFromForwardedHeaders(array $server, bool $publicOnly = true): ?string
    {
        $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwardedFor) && strcasecmp($forwardedFor, 'unknown') !== 0) {
            $ip = self::extractFirstValidIp($forwardedFor, $publicOnly);
            if (!empty($ip)) {
                return $ip;
            }
        }

        foreach (['HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $headerName) {
            $headerIp = $server[$headerName] ?? '';
            if (!is_string($headerIp)) {
                continue;
            }

            $headerIp = trim($headerIp);
            if ($headerIp === '' || strcasecmp($headerIp, 'unknown') === 0) {
                continue;
            }

            $isValid = $publicOnly ? self::isPublicIp($headerIp) : self::isValidIp($headerIp);
            if ($isValid) {
                return $headerIp;
            }
        }

        return null;
    }

    /**
     * 从逗号分隔IP串中提取第一个合法IP
     *
     * @param string $ipList
     * @param bool $publicOnly
     * @return string|null
     */
    public static function extractFirstValidIp(string $ipList, bool $publicOnly = false): ?string
    {
        $ips = explode(',', $ipList);
        foreach ($ips as $item) {
            $ip = trim($item);
            if ($ip === '' || strcasecmp($ip, 'unknown') === 0) {
                continue;
            }

            if (!self::isValidIp($ip)) {
                continue;
            }

            if ($publicOnly && !self::isPublicIp($ip)) {
                continue;
            }

            return $ip;
        }

        return null;
    }

    /**
     * @param string $ip
     * @return bool
     */
    public static function isValidIp(string $ip): bool
    {
        return filter_var($ip, \FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @param string $ip
     * @return bool
     */
    public static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Compares two IPv4 addresses.
     * In case a subnet is given, it checks if it contains the request IP.
     *
     * @param string $ip IPv4 address or subnet in CIDR notation
     *
     * @return bool Whether the request IP matches the IP, or whether the request IP is within the CIDR subnet
     */
    public static function checkIp4(?string $requestIp, string $ip)
    {
        if (!filter_var($requestIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return false;
        }

        if (str_contains($ip, '/')) {
            [$address, $netmask] = explode('/', $ip, 2);

            if ('0' === $netmask) {
                return false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4);
            }

            if ($netmask < 0 || $netmask > 32) {
                return false;
            }
        } else {
            $address = $ip;
            $netmask = 32;
        }

        if (false === ip2long($address)) {
            return false;
        }

        return 0 === substr_compare(sprintf('%032b', ip2long($requestIp)), sprintf('%032b', ip2long($address)), 0, $netmask);
    }

    /**
     * Compares two IPv6 addresses.
     * In case a subnet is given, it checks if it contains the request IP.
     *
     * @author David Soria Parra <dsp at php dot net>
     *
     * @see https://github.com/dsp/v6tools
     *
     * @param string $ip IPv6 address or subnet in CIDR notation
     *
     * @return bool
     *
     * @throws \RuntimeException When IPV6 support is not enabled
     */
    public static function checkIp6(?string $requestIp, string $ip)
    {
        if (!((\extension_loaded('sockets') && \defined('AF_INET6')) || @inet_pton('::1'))) {
            throw new \RuntimeException('Unable to check Ipv6. Check that PHP was not compiled with option "disable-ipv6".');
        }

        // Check to see if we were given a IP4 $requestIp or $ip by mistake
        if (!filter_var($requestIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return false;
        }

        if (str_contains($ip, '/')) {
            [$address, $netmask] = explode('/', $ip, 2);

            if (!filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return false;
            }

            if ('0' === $netmask) {
                return (bool) unpack('n*', @inet_pton($address));
            }

            if ($netmask < 1 || $netmask > 128) {
                return false;
            }
        } else {
            if (!filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return false;
            }

            $address = $ip;
            $netmask = 128;
        }

        $bytesAddr = unpack('n*', @inet_pton($address));
        $bytesTest = unpack('n*', @inet_pton($requestIp));

        if (!$bytesAddr || !$bytesTest) {
            return false;
        }

        for ($i = 1, $ceil = ceil($netmask / 16); $i <= $ceil; ++$i) {
            $left = $netmask - 16 * ($i - 1);
            $left = ($left <= 16) ? $left : 16;
            $mask = ~(0xFFFF >> $left) & 0xFFFF;
            if (($bytesAddr[$i] & $mask) != ($bytesTest[$i] & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Anonymizes an IP/IPv6.
     *
     * Removes the last byte for v4 and the last 8 bytes for v6 IPs
     */
    public static function anonymize(string $ip): string
    {
        $wrappedIPv6 = false;
        if ('[' === substr($ip, 0, 1) && ']' === substr($ip, -1, 1)) {
            $wrappedIPv6 = true;
            $ip = substr($ip, 1, -1);
        }

        $packedAddress = inet_pton($ip);
        if (4 === \strlen($packedAddress)) {
            $mask = '255.255.255.0';
        } elseif ($ip === inet_ntop($packedAddress & inet_pton('::ffff:ffff:ffff'))) {
            $mask = '::ffff:ffff:ff00';
        } elseif ($ip === inet_ntop($packedAddress & inet_pton('::ffff:ffff'))) {
            $mask = '::ffff:ff00';
        } else {
            $mask = 'ffff:ffff:ffff:ffff:0000:0000:0000:0000';
        }
        $ip = inet_ntop($packedAddress & inet_pton($mask));

        if ($wrappedIPv6) {
            $ip = '['.$ip.']';
        }

        return $ip;
    }
}
