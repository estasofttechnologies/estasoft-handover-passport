<?php
/**
 * Search, order and page a list of rows for a REST collection (API-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Works on plain arrays so it can be tested without WordPress.
 */
final class Pager {

	public const DEFAULT_PER_PAGE = 20;
	public const MAX_PER_PAGE     = 100;

	/**
	 * Apply search, order and paging.
	 *
	 * @param list<array<string, mixed>>                       $rows       Rows, already limited to what the caller may see.
	 * @param array{search?: string, orderby?: string, order?: string, page?: int, per_page?: int} $args Request values.
	 * @param callable(array<string, mixed>): string           $haystack   Text a search looks in.
	 * @param array<string, callable(array<string, mixed>): (int|float|string)> $sorters Sort key by orderby name.
	 * @param string                                           $default    Orderby used when the request names none or an unknown one.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int, page: int, per_page: int}
	 */
	public static function apply( array $rows, array $args, callable $haystack, array $sorters, string $default ): array {
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$needle = mb_strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool => false !== mb_strpos( mb_strtolower( $haystack( $row ) ), $needle )
				)
			);
		}

		$orderby = (string) ( $args['orderby'] ?? $default );
		if ( ! isset( $sorters[ $orderby ] ) ) {
			$orderby = $default;
		}
		$sign = 'desc' === strtolower( (string) ( $args['order'] ?? 'asc' ) ) ? -1 : 1;
		$sort = $sorters[ $orderby ];
		usort(
			$rows,
			static function ( array $a, array $b ) use ( $sort, $sign ): int {
				$left  = $sort( $a );
				$right = $sort( $b );
				if ( is_string( $left ) && is_string( $right ) ) {
					return $sign * strcmp( mb_strtolower( $left ), mb_strtolower( $right ) );
				}
				return $sign * ( $left <=> $right );
			}
		);

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$total    = count( $rows );
		$pages    = (int) max( 1, ceil( $total / $per_page ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		return array(
			'items'    => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total'    => $total,
			'pages'    => 0 === $total ? 0 : $pages,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}
}
