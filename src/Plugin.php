<?php
/**
 * Plugin container and start-up.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport;

use Estasoft\HandoverPassport\Admin\Admin;
use Estasoft\HandoverPassport\Changes\ChangeCapture;
use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Cli\RemindersCommand;
use Estasoft\HandoverPassport\Cli\ScanCommand;
use Estasoft\HandoverPassport\Data\ChangeRepository;
use Estasoft\HandoverPassport\Data\FindingRepository;
use Estasoft\HandoverPassport\Data\ItemRepository;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Data\ReminderRepository;
use Estasoft\HandoverPassport\Data\RunbookRepository;
use Estasoft\HandoverPassport\Data\ScanRepository;
use Estasoft\HandoverPassport\Data\TestRepository;
use Estasoft\HandoverPassport\Findings\FindingService;
use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Install\Upgrader;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Rest\Routes;
use Estasoft\HandoverPassport\Scan\Collector;
use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Security\CopyGuard;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Services\CheckService;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Services\ReminderService;
use Estasoft\HandoverPassport\Services\RecordService;
use Estasoft\HandoverPassport\Services\RestoreTestService;
use Estasoft\HandoverPassport\Services\RunbookService;
use Estasoft\HandoverPassport\Services\Retention;
use Estasoft\HandoverPassport\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Holds one instance of each service and attaches the plugin to WordPress.
 */
final class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Services built so far.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Attach to WordPress. Nothing here touches the front end (WPI-10).
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'plugins_loaded', array( Upgrader::class, 'maybeUpgrade' ), 5 );

		( new ChangeCapture( $this->changeLog() ) )->register();

		add_action( Schedule::SCAN, array( $this, 'runScheduledScan' ) );
		add_action( Schedule::EVENT_SCAN, array( $this, 'runEventScan' ) );
		add_action( Schedule::RETENTION, array( $this, 'runRetention' ) );
		add_action( Schedule::REMINDERS, array( $this, 'runReminders' ) );
		add_action( Schedule::DIGEST, array( $this, 'runDigest' ) );
		add_action( 'estasoft_hp_scan_completed', array( $this->copyGuard(), 'check' ), 5, 0 );
		add_action( 'estasoft_hp_record_saved', array( $this, 'queueFindings' ), 20, 0 );
		add_action( 'estasoft_hp_scan_completed', array( $this, 'queueFindings' ), 20, 0 );

		if ( is_admin() ) {
			( new Admin( $this ) )->register();
		}

		add_action( 'rest_api_init', array( new Routes( $this ), 'register' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'estasoft-passport scan', new ScanCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport reminders', new RemindersCommand( $this ) );
		}
	}

	/**
	 * Ask for the rules to run once at the end of this request (FND-3).
	 */
	public function queueFindings(): void {
		$this->findings()->queue();
	}

	/**
	 * Cron callback for the daily scan. The rules and the snapshot hashes are checked right after it.
	 */
	public function runScheduledScan(): void {
		$this->scanService()->run( 'scheduled' );
		$this->findings()->run( true );
	}

	/**
	 * Cron callback for a scan asked for by an event such as a plugin update.
	 */
	public function runEventScan(): void {
		$this->scanService()->run( 'event' );
	}

	/**
	 * Cron callback for the daily clean-up.
	 */
	public function runRetention(): void {
		( new Retention( $this->settings(), new ItemRepository(), new PeopleRepository(), new LinkRepository(), new ChangeRepository(), new FindingRepository(), new ReminderRepository() ) )->run();
	}

	/**
	 * Cron callback for the daily reminders. Paused by the copy guard on a copy of the site.
	 */
	public function runReminders(): void {
		$this->reminders()->run();
		$this->findings()->run();
	}

	/**
	 * Cron callback for the weekly summary.
	 */
	public function runDigest(): void {
		$this->reminders()->digest();
	}

	/**
	 * Settings.
	 */
	public function settings(): Settings {
		return $this->shared( 'settings', static fn(): Settings => new Settings() );
	}

	/**
	 * Passport record.
	 */
	public function passport(): Passport {
		return $this->shared( 'passport', static fn(): Passport => new Passport() );
	}

	/**
	 * Secret guard.
	 */
	public function secretGuard(): SecretGuard {
		return $this->shared( 'secret_guard', static fn(): SecretGuard => new SecretGuard() );
	}

	/**
	 * Change log.
	 */
	public function changeLog(): ChangeLog {
		return $this->shared( 'change_log', static fn(): ChangeLog => new ChangeLog( new ChangeRepository(), new ItemRepository() ) );
	}

	/**
	 * Records service.
	 */
	public function records(): RecordService {
		return $this->shared(
			'records',
			fn(): RecordService => new RecordService( new ItemRepository(), new LinkRepository(), $this->secretGuard(), $this->changeLog(), $this->settings() )
		);
	}

	/**
	 * People service.
	 */
	public function people(): PeopleService {
		return $this->shared(
			'people',
			fn(): PeopleService => new PeopleService( new PeopleRepository(), new LinkRepository(), $this->secretGuard(), $this->changeLog(), $this->settings() )
		);
	}

	/**
	 * Findings service.
	 */
	public function findings(): FindingService {
		return $this->shared( 'findings', fn(): FindingService => new FindingService( $this, new FindingRepository(), $this->changeLog() ) );
	}

	/**
	 * Runbooks.
	 */
	public function runbooks(): RunbookService {
		return $this->shared( 'runbooks', fn(): RunbookService => new RunbookService( new RunbookRepository(), $this->secretGuard(), $this->changeLog() ) );
	}

	/**
	 * Restore tests.
	 */
	public function restoreTests(): RestoreTestService {
		return $this->shared( 'restore_tests', fn(): RestoreTestService => new RestoreTestService( new TestRepository(), new RunbookRepository(), $this->secretGuard(), $this->changeLog() ) );
	}

	/**
	 * Reminder emails, the weekly summary and the delivery check.
	 */
	public function reminders(): ReminderService {
		return $this->shared( 'reminders', fn(): ReminderService => new ReminderService( $this, new ReminderRepository(), $this->copyGuard() ) );
	}

	/**
	 * Copy guard.
	 */
	public function copyGuard(): CopyGuard {
		return $this->shared( 'copy_guard', static fn(): CopyGuard => new CopyGuard() );
	}

	/**
	 * Checks of DNS, certificates and critical addresses.
	 */
	public function checks(): CheckService {
		return $this->shared( 'checks', fn(): CheckService => new CheckService( $this ) );
	}

	/**
	 * Scan service.
	 */
	public function scanService(): ScanService {
		return $this->shared(
			'scan',
			fn(): ScanService => new ScanService( new Collector(), new ScanRepository(), $this->changeLog(), $this->records(), $this->settings() )
		);
	}

	/**
	 * Build a service once.
	 *
	 * @template T of object
	 * @param string          $id      Service ID.
	 * @param callable(): T   $factory Builds the service.
	 * @return T
	 */
	private function shared( string $id, callable $factory ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}

	/**
	 * Forget built services. Used by tests.
	 */
	public function reset(): void {
		$this->services = array();
	}
}
