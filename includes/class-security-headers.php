<?php
declare(strict_types=1);

namespace WSSF;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Security Headers: X-Content-Type-Options, X-Frame-Options,
 * Referrer-Policy, Permissions-Policy + Content-Security-Policy.
 *
 * CSP режимы: off / report_only / enforce.
 * Report-only — безопасно для первого включения: не блокирует, но пишет
 * нарушения в консоль.
 */
final class Security_Headers {

    public function register(): void {
        if ( is_admin() ) { return; }
        add_action( 'send_headers', [ $this, 'send_headers' ], 5 );
    }

    public function send_headers(): void {
        if ( ! (bool) get_option( 'wssf_hdr_enabled', 1 ) ) { return; }
        if ( headers_sent() ) { return; }

        if ( (bool) get_option( 'wssf_hdr_xcto', 1 ) ) {
            header( 'X-Content-Type-Options: nosniff' );
        }
        if ( (bool) get_option( 'wssf_hdr_xfo', 1 ) ) {
            header( 'X-Frame-Options: SAMEORIGIN' );
        }
        if ( (bool) get_option( 'wssf_hdr_referrer', 1 ) ) {
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        }
        if ( (bool) get_option( 'wssf_hdr_permissions', 1 ) ) {
            header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()' );
        }

        $mode = $this->get_mode();
        if ( $mode === 'off' ) { return; }

        $csp = $this->build_csp();
        if ( $csp === '' ) { return; }

        // The header value is assembled from options, and sanitize_textarea_field()
        // keeps line breaks; a CR/LF must never reach header().
        $csp = (string) preg_replace( '/[\r\n]+/', ' ', $csp );

        if ( $mode === 'report_only' ) {
            header( 'Content-Security-Policy-Report-Only: ' . $csp ); // phpcs:ignore -- CR/LF stripped above; value comes from options, not from the request.
        } else {
            header( 'Content-Security-Policy: ' . $csp ); // phpcs:ignore -- CR/LF stripped above; value comes from options, not from the request.
        }
    }

    private function get_mode(): string {
        $m = (string) get_option( 'wssf_csp_mode', 'off' );
        return in_array( $m, [ 'off', 'report_only', 'enforce' ], true ) ? $m : 'off';
    }

    private function build_csp(): string {
        $extra_script  = $this->get_extra_sources( 'wssf_csp_script_src' );
        $extra_style   = $this->get_extra_sources( 'wssf_csp_style_src' );
        $extra_img     = $this->get_extra_sources( 'wssf_csp_img_src' );
        $extra_connect = $this->get_extra_sources( 'wssf_csp_connect_src' );

        $parts = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' " . $extra_script,
            "style-src 'self' 'unsafe-inline' " . $extra_style,
            "img-src 'self' data: " . $extra_img,
            "font-src 'self' data:",
            "connect-src 'self' " . $extra_connect,
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ];
        $parts = array_map( 'trim', $parts );
        return implode( '; ', $parts );
    }

    private function get_extra_sources( string $option ): string {
        $raw = (string) get_option( $option, '' );
        if ( $raw === '' ) { return ''; }

        $lines = preg_split( '/[\r\n,]+/u', $raw );
        if ( ! is_array( $lines ) ) { return ''; }

        $out = [];
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( $line === '' ) { continue; }
            if ( ! preg_match( '/^(https?:\/\/|\*|data:|[a-z0-9.\-]+\.[a-z]{2,})/i', $line ) ) {
                continue;
            }
            $out[] = $line;
        }
        return implode( ' ', array_unique( $out ) );
    }
}