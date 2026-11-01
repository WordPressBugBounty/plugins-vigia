<?php
/**
 * Client IP class
 *
 * The one place where VigIA works out the address of the visitor, for the
 * block list and for the visit log.
 *
 * @package VigIA
 * @since 2.8.2
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Client IP class
 *
 * Until 2.8.1 three copies of the same function took the first valid address
 * of CF-Connecting-IP, X-Forwarded-For (its first entry), X-Real-IP and
 * REMOTE_ADDR, in that order. Every one of those headers is written by the
 * client unless a proxy in front of the site overwrites it, so a visitor
 * blocked by IP got the page back by sending X-Forwarded-For with any other
 * address, and could write any address into the log. Reported against 2.8.0.
 *
 * Now the address is the connection, REMOTE_ADDR, unless that connection
 * comes from a proxy there is reason to trust:
 *
 * - CF-Connecting-IP only when REMOTE_ADDR is one of Cloudflare's published
 *   ranges, or a proxy declared with the vigia_trusted_proxies filter;
 * - X-Forwarded-For and X-Real-IP only when REMOTE_ADDR is a declared proxy
 *   or, with none declared, an address of the site's own network (a web
 *   server or a load balancer in front of PHP on the same machine or LAN),
 *   which a visitor reaching a public site directly never is.
 *
 * Same rules as Vigilant's Vigilante_IP_Utils, without its settings screen:
 * VigIA has no proxy setting, so what Vigilant asks the administrator is
 * worked out here from the connection.
 */
class VigIA_Client_IP {

    /**
     * Address used when the request carries none (WP-CLI, a broken server).
     */
    const NONE = '0.0.0.0';

    /**
     * Address of the current visitor.
     *
     * Reads only the four keys it needs, each sanitized where it is read. The
     * three headers are chosen by the client and decide nothing by themselves:
     * resolve() believes them only from a trusted connection.
     *
     * @return string Valid IP address, or self::NONE.
     */
    public static function get() {
        $server = array();

        if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $server['REMOTE_ADDR'] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $server['HTTP_CF_CONNECTING_IP'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        }
        if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $server['HTTP_X_FORWARDED_FOR'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
        }
        if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $server['HTTP_X_REAL_IP'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
        }

        return self::resolve( $server, self::trusted_proxies() );
    }

    /**
     * Address of the visitor of a $_SERVER-like array.
     *
     * Public and free of globals so a harness can call it with any request.
     *
     * @param array    $server          REMOTE_ADDR and the proxy headers.
     * @param string[] $trusted_proxies Declared proxy addresses or CIDR ranges.
     * @return string Valid IP address, or self::NONE.
     */
    public static function resolve( $server, $trusted_proxies = array() ) {
        $remote = isset( $server['REMOTE_ADDR'] ) ? self::unmap_ipv4( trim( (string) $server['REMOTE_ADDR'] ) ) : '';

        if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
            return self::NONE;
        }

        $declared = self::in_ranges( $remote, $trusted_proxies );

        // Only Cloudflare sends this header, and its edge connects from its
        // own ranges. A web server of the site's own network in front of PHP
        // passes the header through as the visitor wrote it, so from there
        // it is not believed: X-Forwarded-For below covers that case.
        if ( ! empty( $server['HTTP_CF_CONNECTING_IP'] ) && ( $declared || self::in_ranges( $remote, self::cloudflare_ranges() ) ) ) {
            $address = self::unmap_ipv4( trim( (string) $server['HTTP_CF_CONNECTING_IP'] ) );
            if ( filter_var( $address, FILTER_VALIDATE_IP ) ) {
                return $address;
            }
        }

        if ( ! $declared && ! ( empty( $trusted_proxies ) && self::is_own_network( $remote ) ) ) {
            return $remote;
        }

        if ( ! empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
            $address = self::client_from_chain( (string) $server['HTTP_X_FORWARDED_FOR'], $trusted_proxies );
            if ( '' !== $address ) {
                return $address;
            }
        }

        if ( ! empty( $server['HTTP_X_REAL_IP'] ) ) {
            $address = self::unmap_ipv4( self::strip_port( (string) $server['HTTP_X_REAL_IP'] ) );
            if ( filter_var( $address, FILTER_VALIDATE_IP ) ) {
                return $address;
            }
        }

        return $remote;
    }

    /**
     * The visitor in an X-Forwarded-For chain, read from the proxy's end.
     *
     * Each proxy adds the address it was connected from to the END of the
     * header and keeps whatever came before, so in "a, b, c" only c was
     * written by the proxy the site trusts and a is what the visitor chose.
     * Read from the right, addresses of the own network, of Cloudflare and of
     * the declared proxies are passed over as one more hop, and the first
     * one outside them is the visitor. Cloudflare appends the visitor and a
     * web server in front of PHP appends Cloudflare, so this also finds the
     * visitor behind Cloudflare plus a local proxy. An entry that is not an
     * address stops the reading: nothing to its left can be told apart from
     * what the visitor wrote. A port written by the proxy is dropped first
     * (see strip_port()).
     *
     * @param string   $value           Header value.
     * @param string[] $trusted_proxies Declared proxy addresses or CIDR ranges.
     * @return string Address, or '' when there is none to trust.
     */
    public static function client_from_chain( $value, $trusted_proxies = array() ) {
        $entries = array_reverse( array_map( 'trim', explode( ',', (string) $value ) ) );
        $nearest = '';

        foreach ( $entries as $entry ) {
            $address = self::unmap_ipv4( self::strip_port( $entry ) );

            if ( ! filter_var( $address, FILTER_VALIDATE_IP ) ) {
                break;
            }

            if ( ! self::is_own_network( $address )
                && ! self::in_ranges( $address, self::cloudflare_ranges() )
                && ! self::in_ranges( $address, $trusted_proxies )
            ) {
                return $address;
            }

            if ( '' === $nearest ) {
                $nearest = $address;
            }
        }

        return $nearest;
    }

    /**
     * An address written by a proxy with its port, without the port.
     *
     * Some proxies append the port they were connected from (Azure App
     * Service writes "203.0.113.7:51234" and "[2001:db8::1]:443"), and until
     * 2.8.2 such an entry stopped the reading, so on those hosts the visitor
     * was the proxy. Only two shapes are taken apart: an IPv4 address with a
     * port, and an IPv6 address in brackets with or without one. A bare IPv6
     * address also has colons, so anything that already is an address comes
     * back as it was. Anything else comes back as it was too, and fails the
     * address check of the caller.
     *
     * @since 2.8.3
     * @param string $entry One address as written in a proxy header.
     * @return string
     */
    public static function strip_port( $entry ) {
        $entry = trim( (string) $entry );

        if ( filter_var( $entry, FILTER_VALIDATE_IP ) ) {
            return $entry;
        }

        if ( preg_match( '/^\[([0-9a-fA-F:.]+)\](?::(\d{1,5}))?$/', $entry, $m ) ) {
            $address = $m[1];
            $port    = isset( $m[2] ) ? $m[2] : '';
            if ( filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && self::is_port( $port, true ) ) {
                return $address;
            }
            return $entry;
        }

        if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):(\d{1,5})$/', $entry, $m ) ) {
            if ( filter_var( $m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && self::is_port( $m[2], false ) ) {
                return $m[1];
            }
        }

        return $entry;
    }

    /**
     * Whether a written port is one, 1 to 65535.
     *
     * @param string $port       Digits after the colon.
     * @param bool   $allow_none Whether no port at all is fine.
     * @return bool
     */
    private static function is_port( $port, $allow_none ) {
        if ( '' === $port ) {
            return $allow_none;
        }

        return ( (int) $port >= 1 && (int) $port <= 65535 );
    }

    /**
     * Proxies declared by the site, for a load balancer with a public address.
     *
     * The filter vigia_trusted_proxies takes an array of exact addresses or
     * CIDR ranges. It must be added from a plugin or a must-use plugin: the
     * block list runs on plugins_loaded, before the theme is loaded. Wildcards
     * and ranges wide enough to cover the internet are dropped, because one
     * of them would make every visitor a trusted proxy.
     *
     * @return string[]
     */
    public static function trusted_proxies() {
        $list = apply_filters( 'vigia_trusted_proxies', array() );

        if ( ! is_array( $list ) ) {
            return array();
        }

        $valid = array();
        foreach ( $list as $entry ) {
            if ( is_string( $entry ) && self::is_valid_proxy( trim( $entry ) ) ) {
                $valid[] = trim( $entry );
            }
        }

        return $valid;
    }

    /**
     * Whether two written addresses are the same address.
     *
     * IPv6 has many spellings of one address (2001:DB8::1, 2001:db8::1,
     * 2001:0db8:0:0:0:0:0:1), and an IPv4 address can come written as IPv6.
     *
     * @param string $a First address.
     * @param string $b Second address.
     * @return bool
     */
    public static function same_address( $a, $b ) {
        $a = self::unmap_ipv4( trim( (string) $a ) );
        $b = self::unmap_ipv4( trim( (string) $b ) );

        if ( $a === $b ) {
            return true;
        }

        if ( ! filter_var( $a, FILTER_VALIDATE_IP ) || ! filter_var( $b, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        return inet_pton( $a ) === inet_pton( $b );
    }

    /**
     * Cloudflare's published edge ranges, from https://www.cloudflare.com/ips/
     *
     * They change rarely. If they do before VigIA is updated, the new ones
     * can be declared with the vigia_trusted_proxies filter.
     *
     * @return string[]
     */
    public static function cloudflare_ranges() {
        return array(
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        );
    }

    /**
     * Whether an address belongs to a network no visitor comes from.
     *
     * Private, loopback, link-local and carrier-grade NAT, for IPv4 and IPv6.
     * Written out instead of using the FILTER_FLAG_NO_PRIV_RANGE and
     * FILTER_FLAG_NO_RES_RANGE flags, whose ranges change between PHP
     * versions (from 8.3 an IPv4 address written as IPv6 counts as reserved).
     *
     * @param string $address Valid IP address, IPv4 written as IPv4.
     * @return bool
     */
    public static function is_own_network( $address ) {
        return self::in_ranges(
            $address,
            array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16', '100.64.0.0/10', '::1/128', 'fc00::/7', 'fe80::/10' )
        );
    }

    /**
     * An IPv4 address written as IPv6 (::ffff:203.0.113.7), as IPv4.
     *
     * A dual-stack server or proxy writes it that way. Anything else comes
     * back as it was.
     *
     * @param string $address Address as written.
     * @return string
     */
    public static function unmap_ipv4( $address ) {
        if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            return $address;
        }

        $packed = inet_pton( $address );

        if ( false !== $packed && 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
            $ipv4 = inet_ntop( substr( $packed, 12 ) );
            return false === $ipv4 ? $address : $ipv4;
        }

        return $address;
    }

    /**
     * Whether an address is one of a list of exact addresses or CIDR ranges.
     *
     * @param string   $address Valid IP address.
     * @param string[] $ranges  Addresses or CIDR ranges.
     * @return bool
     */
    private static function in_ranges( $address, $ranges ) {
        if ( empty( $ranges ) || ! is_array( $ranges ) ) {
            return false;
        }

        foreach ( $ranges as $range ) {
            $range = trim( (string) $range );

            if ( false === strpos( $range, '/' ) ) {
                if ( self::same_address( $address, $range ) ) {
                    return true;
                }
            } elseif ( self::cidr_match( $address, $range ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a declared proxy is an exact address or a narrow enough range.
     *
     * The floors are /8 for IPv4 and /7 for IPv6 (fc00::/7 is the whole IPv6
     * private space), so 0.0.0.0/0, 0.0.0.0/1 or 2000::/3, which would trust
     * every visitor, are refused. Every range the big CDNs publish passes.
     *
     * @param string $entry Candidate entry.
     * @return bool
     */
    private static function is_valid_proxy( $entry ) {
        if ( false === strpos( $entry, '/' ) ) {
            return (bool) filter_var( $entry, FILTER_VALIDATE_IP );
        }

        $parts = explode( '/', $entry, 2 );
        $bits  = trim( $parts[1] );

        if ( ! filter_var( trim( $parts[0] ), FILTER_VALIDATE_IP ) || '' === $bits || ! ctype_digit( $bits ) ) {
            return false;
        }

        $packed = inet_pton( trim( $parts[0] ) );
        $max    = 8 * strlen( $packed );
        $floor  = ( 4 === strlen( $packed ) ) ? 8 : 7;

        return ( (int) $bits >= $floor && (int) $bits <= $max );
    }

    /**
     * Match an address against a CIDR range, IPv4 or IPv6.
     *
     * @param string $address IP address.
     * @param string $cidr    CIDR range.
     * @return bool
     */
    private static function cidr_match( $address, $cidr ) {
        $parts = explode( '/', $cidr, 2 );
        $bits  = trim( $parts[1] );

        if ( '' === $bits || ! ctype_digit( $bits ) ) {
            return false;
        }

        if ( ! filter_var( $address, FILTER_VALIDATE_IP ) || ! filter_var( trim( $parts[0] ), FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $address_packed = inet_pton( $address );
        $subnet_packed  = inet_pton( trim( $parts[0] ) );

        if ( strlen( $address_packed ) !== strlen( $subnet_packed ) ) {
            return false;
        }

        $bits = (int) $bits;
        if ( $bits > 8 * strlen( $address_packed ) ) {
            return false;
        }

        $whole = intdiv( $bits, 8 );
        if ( $whole > 0 && substr( $address_packed, 0, $whole ) !== substr( $subnet_packed, 0, $whole ) ) {
            return false;
        }

        $rest = $bits % 8;
        if ( $rest > 0 ) {
            $mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
            if ( ( ord( $address_packed[ $whole ] ) & $mask ) !== ( ord( $subnet_packed[ $whole ] ) & $mask ) ) {
                return false;
            }
        }

        return true;
    }
}
