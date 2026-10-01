<?php
/**
 * Metadata rows whose parent object no longer exists.
 *
 * @package FanxieLab\Warden
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\DatabaseMaintenance\Cleanup;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Removes post/user/term/comment meta rows that point at a missing parent.
 */
final class OrphanedMetaTask implements CleanupTask {

	/**
	 * Supported meta types.
	 *
	 * @var list<string>
	 */
	public const TYPES = [ 'post', 'user', 'term', 'comment' ];

	/**
	 * Constructor.
	 *
	 * @param string $type One of self::TYPES.
	 * @throws InvalidArgumentException When the type is unknown.
	 */
	public function __construct( private readonly string $type ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new InvalidArgumentException( 'Unknown meta type.' );
		}
	}

	/**
	 * Task identifier.
	 */
	public function id(): string {
		return 'orphaned-' . $this->type . 'meta';
	}

	/**
	 * Task label.
	 */
	public function label(): string {
		return match ( $this->type ) {
			'post'    => __( 'Orphaned post meta', 'fanxie-warden' ),
			'user'    => __( 'Orphaned user meta', 'fanxie-warden' ),
			'term'    => __( 'Orphaned term meta', 'fanxie-warden' ),
			default   => __( 'Orphaned comment meta', 'fanxie-warden' ),
		};
	}

	/**
	 * Table/column names for the configured type.
	 *
	 * @return array{meta: string, mid: string, fk: string, parent: string, pk: string}
	 */
	private function schema(): array {
		global $wpdb;

		return match ( $this->type ) {
			'post'    => [
				'meta'   => $wpdb->postmeta,
				'mid'    => 'meta_id',
				'fk'     => 'post_id',
				'parent' => $wpdb->posts,
				'pk'     => 'ID',
			],
			'user'    => [
				'meta'   => $wpdb->usermeta,
				'mid'    => 'umeta_id',
				'fk'     => 'user_id',
				'parent' => $wpdb->users,
				'pk'     => 'ID',
			],
			'term'    => [
				'meta'   => $wpdb->termmeta,
				'mid'    => 'meta_id',
				'fk'     => 'term_id',
				'parent' => $wpdb->terms,
				'pk'     => 'term_id',
			],
			default   => [
				'meta'   => $wpdb->commentmeta,
				'mid'    => 'meta_id',
				'fk'     => 'comment_id',
				'parent' => $wpdb->comments,
				'pk'     => 'comment_ID',
			],
		};
	}

	/**
	 * FROM/JOIN/WHERE clause shared by every query. Identifiers only, no input.
	 */
	private function from_clause(): string {
		$s = $this->schema();
		return "FROM {$s['meta']} m LEFT JOIN {$s['parent']} p ON p.{$s['pk']} = m.{$s['fk']} WHERE p.{$s['pk']} IS NULL";
	}

	/**
	 * Number of orphaned rows.
	 */
	public function count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- identifiers only; live count.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $this->from_clause() );
	}

	/**
	 * Approximate bytes held by orphaned rows.
	 */
	public function estimate_bytes(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- identifiers only; live estimate.
		return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(LENGTH(m.meta_key) + LENGTH(m.meta_value)), 0) ' . $this->from_clause() );
	}

	/**
	 * Sample of orphaned rows.
	 *
	 * @param int $n Maximum rows.
	 * @return list<array{label: string, detail: string, date: string|null}>
	 */
	public function sample( int $n ): array {
		global $wpdb;
		$s = $this->schema();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- identifiers only; limit is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_key, m.{$s['fk']} AS parent " . $this->from_clause() . ' LIMIT %d', $n ), ARRAY_A );

		return array_values(
			array_map(
				static fn ( array $r ): array => [
					'label'  => (string) $r['meta_key'],
					/* translators: %d: ID of the deleted parent object */
					'detail' => sprintf( __( 'Parent #%d no longer exists', 'fanxie-warden' ), (int) $r['parent'] ),
					'date'   => null,
				],
				(array) $rows
			)
		);
	}

	/**
	 * Delete one batch of orphaned rows.
	 *
	 * @param int              $limit   Batch size.
	 * @param list<int|string> $exclude Meta IDs that previously failed.
	 */
	public function purge_batch( int $limit, array $exclude = [] ): BatchResult {
		global $wpdb;
		$s = $this->schema();

		[ $not_in, $args ] = SqlHelpers::not_in( "m.{$s['mid']}", array_map( 'intval', $exclude ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- identifiers + placeholders only.
		$mids = $wpdb->get_col( $wpdb->prepare( "SELECT m.{$s['mid']} " . $this->from_clause() . $not_in . ' LIMIT %d', array_merge( $args, [ $limit ] ) ) );

		$deleted = 0;
		$failed  = [];

		foreach ( array_map( 'intval', (array) $mids ) as $mid ) {
			if ( delete_metadata_by_mid( $this->type, $mid ) ) {
				++$deleted;
			} else {
				$failed[] = $mid;
			}
		}

		return new BatchResult( $deleted, $failed, count( (array) $mids ) < $limit );
	}
}
