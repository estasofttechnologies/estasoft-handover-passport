<?php
/**
 * Runbooks: library, step editor, templates and versions (RUN-1, RUN-2, RUN-3, RUN-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Data\RunbookRepository;
use Estasoft\HandoverPassport\Domain\RunbookSteps;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes runbooks. Content is kept per version; the runbook row holds what does not change between versions.
 */
final class RunbookService {

	public const STATUSES = array( 'draft', 'published' );
	public const SCOPES   = array( '', 'site', 'database', 'files' );

	/**
	 * Constructor.
	 *
	 * @param RunbookRepository $runbooks Runbooks.
	 * @param SecretGuard       $guard    Secret guard.
	 * @param ChangeLog         $log      Change log.
	 */
	public function __construct( private RunbookRepository $runbooks, private SecretGuard $guard, private ChangeLog $log ) {}

	/**
	 * Shipped templates by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function templates(): array {
		static $cache = null;
		if ( null === $cache ) {
			$file  = ESTASOFT_HP_DIR . 'data/runbook-templates.php';
			$cache = is_readable( $file ) ? (array) include $file : array();
		}
		return $cache;
	}

	/**
	 * Roles that can own a runbook or a step.
	 *
	 * @return array<string, string>
	 */
	public static function roles(): array {
		return PeopleService::roles();
	}

	/**
	 * Live runbooks with their current content.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return array_map( fn( array $row ): array => $this->hydrate( $row ), $this->runbooks->allLive() );
	}

	/**
	 * One runbook with its current content, or null.
	 *
	 * @param int  $id           Runbook ID.
	 * @param bool $with_deleted Whether a deleted runbook counts.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id, bool $with_deleted = false ): ?array {
		$row = $this->runbooks->find( $id );
		if ( null === $row || ( ! $with_deleted && null !== $row['deleted_at'] ) ) {
			return null;
		}
		return $this->hydrate( $row );
	}

	/**
	 * Runbooks deleted within the retention window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function deleted(): array {
		return $this->runbooks->query( array(), 'title', 'ASC', 0, 0, 'deleted_at IS NOT NULL' );
	}

	/**
	 * Earlier versions of a runbook, newest first.
	 *
	 * @param int $id Runbook ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function versions( int $id ): array {
		return $this->runbooks->versions( $id );
	}

	/**
	 * Make a runbook from a shipped template.
	 *
	 * @param string $key Template key.
	 * @return array<string, mixed>
	 * @throws ValidationError When the template does not exist.
	 */
	public function createFromTemplate( string $key ): array {
		$template = self::templates()[ $key ] ?? null;
		if ( null === $template ) {
			throw new ValidationError( __( 'Template', 'estasoft-handover-passport' ), __( 'That template does not exist.', 'estasoft-handover-passport' ) );
		}
		$input                  = $template;
		$input['template_key']  = $key;
		$input['status']        = 'draft';
		$input['steps']         = array_map(
			static fn( array $step ): array => $step + array( 'remove' => false, 'duplicate' => false ),
			(array) $template['steps']
		);
		$input['prerequisites'] = (array) $template['prerequisites'];
		$input['verification']  = implode( "\n", (array) $template['verification'] );
		$input['rollback_text'] = (string) $template['rollback'];
		return $this->create( $input, 'screen' );
	}

	/**
	 * Create a runbook.
	 *
	 * @param array<string, mixed> $input   Posted values.
	 * @param string               $channel Where it came from.
	 * @return array<string, mixed>
	 * @throws ValidationError            When a value is invalid.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	public function create( array $input, string $channel = 'screen' ): array {
		$clean = $this->clean( $input, null );
		$now   = Clock::sql();
		$id    = $this->runbooks->insert(
			$clean['row'] + array(
				'current_version' => 1,
				'last_reviewed'   => null,
				'last_rehearsed'  => null,
				'created_at'      => $now,
				'updated_at'      => $now,
				'deleted_at'      => null,
			)
		);
		if ( 0 === $id ) {
			throw new \RuntimeException( 'estasoft_hp_db_insert' );
		}
		$this->runbooks->addVersion( array( 'runbook_id' => $id, 'version' => 1, 'created_at' => $now, 'created_by' => get_current_user_id() ) + $clean['version'] );
		$this->log->add( 'runbook_added', 'runbook', $id, (string) $clean['row']['title'], array(), array(), '', $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'runbook', array(), array( 'id' => $id ) );
		return (array) $this->get( $id );
	}

	/**
	 * Edit a runbook. A change to the content becomes a new version when the runbook is published; a draft is changed in place.
	 *
	 * @param int                  $id      Runbook ID.
	 * @param array<string, mixed> $input   Posted values.
	 * @param string               $channel Where it came from.
	 * @return array<string, mixed>
	 * @throws ValidationError            When a value is invalid or the runbook is gone.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	public function update( int $id, array $input, string $channel = 'screen' ): array {
		$existing = $this->get( $id );
		if ( null === $existing ) {
			throw new ValidationError( __( 'Runbook', 'estasoft-handover-passport' ), __( 'That runbook no longer exists.', 'estasoft-handover-passport' ) );
		}
		$clean   = $this->clean( $input, $existing );
		$changed = $this->contentChanged( $existing, $clean['version'] );
		$version = (int) $existing['current_version'];
		$now     = Clock::sql();

		if ( $changed ) {
			if ( 'published' === $existing['status'] ) {
				++$version;
				$this->runbooks->addVersion( array( 'runbook_id' => $id, 'version' => $version, 'created_at' => $now, 'created_by' => get_current_user_id() ) + $clean['version'] );
			} else {
				$this->runbooks->updateVersion( $id, $version, $clean['version'] );
			}
		}
		$this->runbooks->update( $id, $clean['row'] + array( 'current_version' => $version, 'updated_at' => $now ) );
		$this->log->add( 'runbook_edited', 'runbook', $id, (string) $clean['row']['title'], array(), array(), $changed ? (string) $clean['version']['change_note'] : '', $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'runbook', array(), array( 'id' => $id ) );
		return (array) $this->get( $id );
	}

	/**
	 * Stamp a runbook as reviewed today.
	 *
	 * @param int    $id      Runbook ID.
	 * @param string $channel Origin for the log.
	 */
	public function markReviewed( int $id, string $channel = 'wordpress' ): void {
		$this->stamp( $id, 'last_reviewed', 'runbook_reviewed', $channel );
	}

	/**
	 * Stamp a runbook as rehearsed today.
	 *
	 * @param int    $id      Runbook ID.
	 * @param string $channel Origin for the log.
	 */
	public function markRehearsed( int $id, string $channel = 'wordpress' ): void {
		$this->stamp( $id, 'last_rehearsed', 'runbook_rehearsed', $channel );
	}

	/**
	 * Delete a runbook; it can be restored.
	 *
	 * @param int    $id      Runbook ID.
	 * @param string $channel Origin for the log.
	 */
	public function delete( int $id, string $channel = 'wordpress' ): void {
		$row = $this->runbooks->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			return;
		}
		$this->runbooks->softDelete( $id );
		$this->log->add( 'runbook_deleted', 'runbook', $id, (string) $row['title'], source: $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'runbook', array(), array() );
	}

	/**
	 * Restore a deleted runbook.
	 *
	 * @param int $id Runbook ID.
	 */
	public function undelete( int $id ): void {
		$row = $this->runbooks->find( $id );
		if ( null === $row || null === $row['deleted_at'] ) {
			return;
		}
		$this->runbooks->restore( $id );
		$this->log->add( 'runbook_restored', 'runbook', $id, (string) $row['title'] );
		do_action( 'estasoft_hp_record_saved', $id, 'runbook', array(), array() );
	}

	/**
	 * Underlying repository.
	 */
	public function repo(): RunbookRepository {
		return $this->runbooks;
	}

	/**
	 * Set a date column to now and log it.
	 *
	 * @param int    $id     Runbook ID.
	 * @param string $column last_reviewed or last_rehearsed.
	 * @param string $kind    Change kind.
	 * @param string $channel Origin for the log.
	 */
	private function stamp( int $id, string $column, string $kind, string $channel = 'wordpress' ): void {
		$row = $this->runbooks->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			return;
		}
		$this->runbooks->update( $id, array( $column => Clock::sql(), 'updated_at' => Clock::sql() ) );
		$this->log->add( $kind, 'runbook', $id, (string) $row['title'], source: $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'runbook', array(), array() );
	}

	/**
	 * Runbook row plus its current version.
	 *
	 * @param array<string, mixed> $row Runbook row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$version = $this->runbooks->version( (int) $row['id'], (int) $row['current_version'] ) ?? array();
		return $row + array(
			'steps'         => (array) ( $version['steps'] ?? array() ),
			'prerequisites' => (array) ( $version['prerequisites'] ?? array() ),
			'verification'  => (array) ( $version['verification'] ?? array() ),
			'rollback_text' => (string) ( $version['rollback_text'] ?? '' ),
			'change_note'   => (string) ( $version['change_note'] ?? '' ),
		);
	}

	/**
	 * Whether the content differs from the stored current version.
	 *
	 * @param array<string, mixed> $existing Hydrated runbook.
	 * @param array<string, mixed> $version  Cleaned content.
	 */
	private function contentChanged( array $existing, array $version ): bool {
		foreach ( array( 'steps', 'prerequisites', 'verification', 'rollback_text' ) as $key ) {
			if ( wp_json_encode( $existing[ $key ] ) !== wp_json_encode( $version[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate input and split it into runbook columns and version content.
	 *
	 * @param array<string, mixed>      $input    Posted values.
	 * @param array<string, mixed>|null $existing Hydrated runbook or null on create.
	 * @return array{row: array<string, mixed>, version: array<string, mixed>}
	 * @throws ValidationError When invalid.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	private function clean( array $input, ?array $existing ): array {
		$text = static fn( string $key, int $max ): string => mb_substr( trim( sanitize_textarea_field( is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : (string) ( $existing[ $key ] ?? '' ) ) ), 0, $max );

		$title = trim( sanitize_text_field( is_scalar( $input['title'] ?? null ) ? (string) $input['title'] : (string) ( $existing['title'] ?? '' ) ) );
		if ( '' === $title ) {
			throw new ValidationError( __( 'Title', 'estasoft-handover-passport' ), __( 'This field is required.', 'estasoft-handover-passport' ) );
		}
		$title = mb_substr( $title, 0, 255 );

		$roles = self::roles();
		$owner = sanitize_key( is_scalar( $input['owner_role'] ?? null ) ? (string) $input['owner_role'] : (string) ( $existing['owner_role'] ?? '' ) );
		$scope = sanitize_key( is_scalar( $input['restore_scope'] ?? null ) ? (string) $input['restore_scope'] : (string) ( $existing['restore_scope'] ?? '' ) );
		$state = sanitize_key( is_scalar( $input['status'] ?? null ) ? (string) $input['status'] : (string) ( $existing['status'] ?? 'draft' ) );

		$steps = array_key_exists( 'steps', $input ) ? RunbookSteps::clean( (array) $input['steps'], $roles ) : (array) ( $existing['steps'] ?? array() );

		$prerequisites = array();
		$posted_pre    = array_key_exists( 'prerequisites', $input ) ? (array) $input['prerequisites'] : (array) ( $existing['prerequisites'] ?? array() );
		foreach ( $posted_pre as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$what = mb_substr( trim( sanitize_textarea_field( is_scalar( $row['what'] ?? null ) ? (string) $row['what'] : '' ) ), 0, 500 );
			$who  = mb_substr( trim( sanitize_text_field( is_scalar( $row['who'] ?? null ) ? (string) $row['who'] : '' ) ), 0, 190 );
			if ( '' !== $what && empty( $row['remove'] ) ) {
				$prerequisites[] = array( 'what' => $what, 'who' => $who );
			}
		}

		$verification = array();
		$posted_ver   = $input['verification'] ?? ( $existing['verification'] ?? array() );
		$ver_lines    = is_array( $posted_ver ) ? $posted_ver : (array) preg_split( '/\R/', is_scalar( $posted_ver ) ? sanitize_textarea_field( (string) $posted_ver ) : '' );
		foreach ( $ver_lines as $line ) {
			$line = mb_substr( trim( is_scalar( $line ) ? (string) $line : '' ), 0, 500 );
			if ( '' !== $line ) {
				$verification[] = $line;
			}
		}

		$review = $input['review_days'] ?? ( $existing['review_days'] ?? null );
		$review = is_scalar( $review ) && (int) $review > 0 ? min( 3650, (int) $review ) : null;

		$row = array(
			'title'             => $title,
			'purpose'           => $text( 'purpose', 2000 ),
			'when_to_use'       => $text( 'when_to_use', 2000 ),
			'owner_role'        => array_key_exists( $owner, $roles ) ? $owner : '',
			'estimated_minutes' => RunbookSteps::minutes( $steps ),
			'status'            => in_array( $state, self::STATUSES, true ) ? $state : 'draft',
			'template_key'      => sanitize_key( is_scalar( $input['template_key'] ?? null ) ? (string) $input['template_key'] : (string) ( $existing['template_key'] ?? '' ) ),
			'restore_scope'     => in_array( $scope, self::SCOPES, true ) ? $scope : '',
			'critical'          => (int) ( ! empty( $input['critical'] ) && '0' !== (string) $input['critical'] ),
			'review_days'       => $review,
		);
		if ( ! array_key_exists( 'critical', $input ) && null !== $existing ) {
			$row['critical'] = (int) $existing['critical'];
		}
		$version = array(
			'steps'         => $steps,
			'prerequisites' => $prerequisites,
			'verification'  => $verification,
			'rollback_text' => $text( 'rollback_text', 2000 ),
			'change_note'   => $text( 'change_note', 500 ),
		);

		// Runbooks hold pointers, never secrets (SEC-6). The message names the field in words.
		$labels = array(
			'title'         => __( 'Title', 'estasoft-handover-passport' ),
			'purpose'       => __( 'Purpose', 'estasoft-handover-passport' ),
			'when_to_use'   => __( 'When to use it', 'estasoft-handover-passport' ),
			'steps'         => __( 'Steps', 'estasoft-handover-passport' ),
			'prerequisites' => __( 'Before you start', 'estasoft-handover-passport' ),
			'verification'  => __( 'How to check it worked', 'estasoft-handover-passport' ),
			'rollback_text' => __( 'If it goes wrong', 'estasoft-handover-passport' ),
			'change_note'   => __( 'What changed', 'estasoft-handover-passport' ),
		);
		foreach ( $row + $version as $key => $value ) {
			$this->guard->assertClean( $value, $labels[ $key ] ?? (string) $key );
		}
		return array( 'row' => $row, 'version' => $version );
	}
}
