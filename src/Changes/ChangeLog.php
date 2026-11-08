<?php
/**
 * Append-only change log (CHG-1, CHG-3, CHG-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Changes;

use Estasoft\HandoverPassport\Data\ChangeRepository;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Writes entries. Nothing here updates or deletes an entry (retention aside).
 */
class ChangeLog {

	public const SYSTEM = 'System scan';

	/**
	 * Current batch ID, set while an import runs (IMP-3).
	 *
	 * @var string
	 */
	private string $batch = '';

	/**
	 * Constructor.
	 *
	 * @param ChangeRepository $repo Change repository.
	 */
	public function __construct( private ChangeRepository $repo ) {}

	/**
	 * Group the entries written inside a callback under one batch ID.
	 *
	 * @param callable(string): mixed $callback Receives the batch ID.
	 * @return mixed Whatever the callback returns.
	 */
	public function inBatch( callable $callback ) {
		$previous    = $this->batch;
		$this->batch = wp_generate_uuid4();
		try {
			return $callback( $this->batch );
		} finally {
			$this->batch = $previous;
		}
	}

	/**
	 * Write one entry.
	 *
	 * @param string               $kind          Kind, for example "plugin_updated".
	 * @param string               $subject_type  Subject type ("plugin", "item", "person", ...).
	 * @param int                  $subject_id    Subject ID or 0.
	 * @param string               $label         Human label of the subject.
	 * @param array<string, mixed> $before        Values before (already free of Sensitive values).
	 * @param array<string, mixed> $after         Values after.
	 * @param string               $note          Optional text.
	 * @param string               $source        "wordpress", "scan", "cli", "rest", "import".
	 * @param string|null          $actor_label   Override the acting person label.
	 * @param int|null             $actor_id      Override the acting user ID.
	 */
	public function add( string $kind, string $subject_type, int $subject_id, string $label, array $before = array(), array $after = array(), string $note = '', string $source = 'wordpress', ?string $actor_label = null, ?int $actor_id = null ): int {
		$user = wp_get_current_user();
		if ( null === $actor_id ) {
			$actor_id = (int) $user->ID;
		}
		if ( null === $actor_label ) {
			$actor_label = $actor_id > 0 ? (string) $user->display_name : self::SYSTEM;
		}
		return $this->repo->insert(
			array(
				'changed_at'    => Clock::sql(),
				'kind'          => $kind,
				'subject_type'  => $subject_type,
				'subject_id'    => $subject_id,
				'subject_label' => mb_substr( $label, 0, 255 ),
				'before_data'   => $before,
				'after_data'    => $after,
				'actor_id'      => $actor_id,
				'actor_label'   => mb_substr( $actor_label, 0, 190 ),
				'source'        => $source,
				'batch_id'      => $this->batch,
				'note'          => $note,
			)
		);
	}

	/**
	 * Underlying repository (for screens and exports).
	 */
	public function repo(): ChangeRepository {
		return $this->repo;
	}
}
