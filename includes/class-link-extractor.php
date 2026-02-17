<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daily_Bananas_Link_Extractor {

	/**
	 * Extract URLs from HTML content, filtering out ignored domains.
	 *
	 * @param string $html            Post content HTML.
	 * @param array  $ignored_domains List of domains to exclude.
	 * @return array Unique absolute HTTP/HTTPS URLs.
	 */
	public static function extract( string $html, array $ignored_domains = [] ): array {
		if ( empty( trim( $html ) ) ) {
			return [];
		}

		$urls = self::parse_links( $html );
		$urls = self::filter_valid_urls( $urls );
		$urls = self::filter_ignored_domains( $urls, $ignored_domains );

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Parse all <a href> values from HTML using DOMDocument.
	 */
	private static function parse_links( string $html ): array {
		$urls = [];

		libxml_use_internal_errors( true );

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$dom->loadHTML(
			'<html><head><meta charset="UTF-8"/></head><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();

		$anchors = $dom->getElementsByTagName( 'a' );
		foreach ( $anchors as $anchor ) {
			$href = trim( $anchor->getAttribute( 'href' ) );
			if ( ! empty( $href ) ) {
				$urls[] = $href;
			}
		}

		return $urls;
	}

	/**
	 * Keep only absolute http/https URLs.
	 */
	private static function filter_valid_urls( array $urls ): array {
		return array_filter( $urls, function ( $url ) {
			$parsed = wp_parse_url( $url );
			if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
				return false;
			}
			return in_array( strtolower( $parsed['scheme'] ), [ 'http', 'https' ], true );
		} );
	}

	/**
	 * Remove URLs whose domains match the ignored list.
	 */
	private static function filter_ignored_domains( array $urls, array $ignored_domains ): array {
		if ( empty( $ignored_domains ) ) {
			return $urls;
		}

		$ignored_domains = array_filter( array_map( 'trim', $ignored_domains ) );
		if ( empty( $ignored_domains ) ) {
			return $urls;
		}

		return array_filter( $urls, function ( $url ) use ( $ignored_domains ) {
			return ! self::is_ignored( $url, $ignored_domains );
		} );
	}

	/**
	 * Check if a URL's host matches any ignored domain (including subdomains).
	 */
	private static function is_ignored( string $url, array $ignored_domains ): bool {
		$host = strtolower( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );
		if ( empty( $host ) ) {
			return false;
		}
		$host_bare = preg_replace( '/^www\./i', '', $host );

		foreach ( $ignored_domains as $raw ) {
			$domain = strtolower( trim( $raw ) );
			$domain = preg_replace( '/^www\./i', '', $domain );
			if ( empty( $domain ) ) {
				continue;
			}
			// Exact match or subdomain match
			if ( $host_bare === $domain ) {
				return true;
			}
			$suffix = '.' . $domain;
			if ( substr( $host_bare, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
