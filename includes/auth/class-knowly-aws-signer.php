<?php
/**
 * Knowly_AWS_Signer — Minimal AWS Signature Version 4 implementation.
 *
 * Produces signed request headers for AWS API calls without requiring
 * the AWS SDK or any Composer dependency.
 *
 * @package KnowlyAPI
 */

defined( 'ABSPATH' ) || exit;

class Knowly_AWS_Signer {

    private string $access_key;
    private string $secret_key;
    private string $region;
    private string $service;

    public function __construct( string $access_key, string $secret_key, string $region, string $service ) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->region     = $region;
        $this->service    = $service;
    }

    /**
     * Build and return headers array to pass to wp_remote_get / wp_remote_post.
     *
     * Adds Authorization, x-amz-date, and x-amz-content-sha256.
     * The Host header is included in the signature but NOT returned
     * (wp_remote_* adds it automatically).
     *
     * @param  string $method  HTTP method (GET, POST, PUT …)
     * @param  string $url     Full request URL (may include query string)
     * @param  array  $headers Additional headers to sign (e.g. Content-Type)
     * @param  string $body    Raw request body — empty string for GET
     * @return array           Signed headers ready for wp_remote_*
     */
    public function get_signed_headers(
        string $method,
        string $url,
        array  $headers = [],
        string $body    = ''
    ): array {
        $parsed       = parse_url( $url );
        $host         = $parsed['host'];
        $path         = isset( $parsed['path'] ) ? $this->url_encode_path( $parsed['path'] ) : '/';
        $query        = $parsed['query'] ?? '';
        $datetime     = gmdate( 'Ymd\THis\Z' );
        $date         = substr( $datetime, 0, 8 );
        $payload_hash = hash( 'sha256', $body );

        // Merge signing-required headers
        $all = array_merge( $headers, [
            'Host'                 => $host,
            'x-amz-date'           => $datetime,
            'x-amz-content-sha256' => $payload_hash,
        ] );

        // Lowercase keys, sorted — SigV4 requirement
        $lower = [];
        foreach ( $all as $k => $v ) {
            $lower[ strtolower( $k ) ] = trim( (string) $v );
        }
        ksort( $lower );

        $canonical_headers = '';
        $signed_keys       = [];
        foreach ( $lower as $k => $v ) {
            $canonical_headers .= $k . ':' . $v . "\n";
            $signed_keys[]      = $k;
        }
        $signed_headers_str = implode( ';', $signed_keys );

        // Canonical query string — params must be sorted and URI-encoded
        $canonical_query = $this->canonical_query( $query );

        // Canonical request
        $canonical_request = implode( "\n", [
            strtoupper( $method ),
            $path,
            $canonical_query,
            $canonical_headers,
            $signed_headers_str,
            $payload_hash,
        ] );

        // String to sign
        $algorithm        = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $string_to_sign   = implode( "\n", [
            $algorithm,
            $datetime,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ] );

        // Signing key and final signature
        $signing_key = $this->derive_signing_key( $date );
        $signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        // Build return-headers (without Host — wp adds it)
        $return = $headers;
        $return['x-amz-date']           = $datetime;
        $return['x-amz-content-sha256'] = $payload_hash;
        $return['Authorization']        =
            "{$algorithm} Credential={$this->access_key}/{$credential_scope}, "
            . "SignedHeaders={$signed_headers_str}, Signature={$signature}";

        return $return;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function derive_signing_key( string $date ): string {
        $k_date    = hash_hmac( 'sha256', $date,            'AWS4' . $this->secret_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region,    $k_date,                   true );
        $k_service = hash_hmac( 'sha256', $this->service,   $k_region,                 true );
        return       hash_hmac( 'sha256', 'aws4_request',   $k_service,                true );
    }

    private function canonical_query( string $query ): string {
        if ( ! $query ) return '';

        parse_str( $query, $params );

        $encoded = [];
        foreach ( $params as $k => $v ) {
            $encoded[ rawurlencode( $k ) ] = rawurlencode( (string) $v );
        }
        ksort( $encoded );

        $pairs = [];
        foreach ( $encoded as $k => $v ) {
            $pairs[] = $k . '=' . $v;
        }
        return implode( '&', $pairs );
    }

    private function url_encode_path( string $path ): string {
        $segments = explode( '/', $path );
        $encoded  = array_map( 'rawurlencode', $segments );
        // rawurlencode encodes '/' inside segments — we re-join with '/' separator
        return implode( '/', $encoded );
    }
}
