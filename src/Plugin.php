<?php
/**
 * Plugin container and start-up.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport;

use Estasoft\HandoverPassport\Admin\Admin;
use Estasoft\HandoverPassport\Admin\SiteHealth;
use Estasoft\HandoverPassport\Changes\ChangeCapture;
use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Checks\WeeklyUrlCheck;
use Estasoft\HandoverPassport\Cli\ExportCommand;
use Estasoft\HandoverPassport\Cli\FindingsCommand;
use Estasoft\HandoverPassport\Cli\RecordCommand;
use Estasoft\HandoverPassport\Cli\RemindersCommand;
use Estasoft\HandoverPassport\Cli\RestoreTestCommand;
use Estasoft\HandoverPassport\Cli\ScanCommand;
use Estasoft\HandoverPassport\Cli\SnapshotCommand;
use Estasoft\HandoverPassport\Cli\StatusCommand;
use Estasoft\HandoverPassport\Data\ChangeRepository;
use Estasoft\HandoverPassport\Data\ExportRepository;
use Estasoft\HandoverPassport\Data\FindingRepository;
use Estasoft\HandoverPassport\Data\ItemRepository;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Data\ReminderRepository;
use Estasoft\HandoverPassport\Data\RunbookRepository;
use Estasoft\HandoverPassport\Data\SnapshotRepository;
use Estasoft\HandoverPassport\Data\ScanRepository;
use Estasoft\HandoverPassport\Data\TestRepository;
use Estasoft\HandoverPassport\Export\EmailedCopy;
use Estasoft\HandoverPassport\Export\ExportService;
use Estasoft\HandoverPassport\Export\PackCopies;
use Estasoft\HandoverPassport\Findings\FindingService;
use Estasoft\HandoverPassport\Handover\HandoverChecklist;
use Estasoft\HandoverPassport\Handover\SnapshotService;
use Estasoft\HandoverPassport\Handover\StatusService;
use Estasoft\HandoverPassport\Handover\SuccessorNotes;
use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Install\Upgrader;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Privacy\PersonalData;
use Estasoft\HandoverPassport\Rest\Routes;
use Estasoft\HandoverPassport\Scan\Collector;
use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Security\CopyGuard;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Services\CheckService;
use Estasoft\HandoverPassport\Services\DataCheck;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Services\ReminderService;
use Estasoft\HandoverPassport\Services\RecordService;
use Estasoft\HandoverPassport\Services\DrillService;
use Estasoft\HandoverPassport\Services\RestoreChecklist;
use Estasoft\HandoverPassport\Services\RestoreTestService;
use Estasoft\HandoverPassport\Services\RunbookPresenter;
use Estasoft\HandoverPassport\Services\RunbookService;
use Estasoft\HandoverPassport\Services\Retention;
use Estasoft\HandoverPassport\Settings\Settings;
use Estasoft\HandoverPassport\Setup\SetupService;

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
		add_action( Schedule::URLS, array( $this, 'runWeeklyUrls' ) );
		add_action( 'estasoft_hp_snapshot_created', array( $this, 'onSnapshotCreated' ) );
		add_action( 'estasoft_hp_scan_completed', array( $this->copyGuard(), 'check' ), 5, 0 );
		add_action( 'estasoft_hp_record_saved', array( $this, 'queueFindings' ), 20, 0 );
		add_action( 'estasoft_hp_scan_completed', array( $this, 'queueFindings' ), 20, 0 );

		if ( is_admin() ) {
			( new Admin( $this ) )->register();
		}

		( new SiteHealth( $this ) )->register();
		( new PersonalData( $this ) )->register();

		add_action( 'rest_api_init', array( new Routes( $this ), 'register' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'estasoft-passport scan', new ScanCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport reminders', new RemindersCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport status', new StatusCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport findings', new FindingsCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport record', new RecordCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport snapshot', new SnapshotCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport export', new ExportCommand( $this ) );
			\WP_CLI::add_command( 'estasoft-passport restore-test', new RestoreTestCommand( $this ) );
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
		( new Retention( $this->settings(), new ItemRepository(), new PeopleRepository(), new LinkRepository(), new ChangeRepository(), new FindingRepository(), new ReminderRepository(), new ExportRepository() ) )->run();
	}

	/**
	 * Cron callback for the daily reminders. Paused by the copy guard on a copy of the site.
	 */
	public function runReminders(): void {
		$this->reminders()->run();
		$this->findings()->run();
		$this->emailedCopy()->daily();
	}

	/**
	 * A snapshot was made: email the Emergency sheet if copies are on.
	 */
	public function onSnapshotCreated(): void {
		$this->emailedCopy()->onSnapshot();
	}

	/**
	 * The optional emailed copy of the Emergency sheet.
	 */
	public function emailedCopy(): EmailedCopy {
		return $this->shared( 'emailed_copy', fn(): EmailedCopy => new EmailedCopy( $this ) );
	}

	/**
	 * Cron callback for the weekly summary.
	 */
	public function runDigest(): void {
		$this->reminders()->digest();
	}

	/**
	 * Cron callback for the weekly address check.
	 */
	public function runWeeklyUrls(): void {
		$this->weeklyUrls()->run();
	}

	/**
	 * The data check in Settings.
	 */
	public function dataCheck(): DataCheck {
		return $this->shared( 'data_check', fn(): DataCheck => new DataCheck( $this, $this->records()->items(), new PeopleRepository(), $this->records()->links() ) );
	}

	/**
	 * The optional weekly address check.
	 */
	public function weeklyUrls(): WeeklyUrlCheck {
		return $this->shared( 'weekly_urls', fn(): WeeklyUrlCheck => new WeeklyUrlCheck( $this ) );
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
	 * Runbooks as text for reading, with the placeholders filled (RUN-4).
	 */
	public function runbookPresenter(): RunbookPresenter {
		return $this->shared( 'runbook_presenter', fn(): RunbookPresenter => new RunbookPresenter( $this ) );
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
	 * The checks to run after a restore: the critical addresses and the things only a person can check (URL-4).
	 */
	public function restoreChecklist(): RestoreChecklist {
		return $this->shared( 'restore_checklist', fn(): RestoreChecklist => new RestoreChecklist( $this ) );
	}

	/**
	 * Drills of runbooks (RUN-5).
	 */
	public function drills(): DrillService {
		return $this->shared( 'drills', fn(): DrillService => new DrillService( new TestRepository(), $this->runbooks(), $this->runbookPresenter(), $this->restoreChecklist(), $this->restoreTests(), $this->secretGuard(), $this->changeLog() ) );
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
	 * The handover checklist.
	 */
	public function handover(): HandoverChecklist {
		return $this->shared( 'handover', fn(): HandoverChecklist => new HandoverChecklist( $this ) );
	}

	/**
	 * Snapshots of the Passport.
	 */
	public function snapshots(): SnapshotService {
		return $this->shared( 'snapshots', fn(): SnapshotService => new SnapshotService( $this, new SnapshotRepository() ) );
	}

	/**
	 * The notes to the successor.
	 */
	public function successorNotes(): SuccessorNotes {
		return $this->shared( 'successor_notes', fn(): SuccessorNotes => new SuccessorNotes( $this ) );
	}

	/**
	 * Copies of export profiles.
	 */
	public function packCopies(): PackCopies {
		return $this->shared( 'pack_copies', fn(): PackCopies => new PackCopies( $this ) );
	}

	/**
	 * Makes packs in every format and keeps the export log.
	 */
	public function exports(): ExportService {
		return $this->shared( 'exports', fn(): ExportService => new ExportService( $this, new ExportRepository() ) );
	}

	/**
	 * Status of the Passport.
	 */
	public function handoverStatus(): StatusService {
		return $this->shared( 'handover_status', fn(): StatusService => new StatusService( $this->passport(), $this->changeLog() ) );
	}

	/**
	 * The setup guide.
	 */
	public function setup(): SetupService {
		return $this->shared( 'setup', fn(): SetupService => new SetupService( $this ) );
	}

	/**
	 * Build a service once.
	 *
	 * @template T of object
	 * @param string        $id      Service ID.
	 * @param callable(): T $factory Builds the service.
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
