<?php
/**
 * Record kinds and their field schemas.
 *
 * One schema drives the forms, the REST API, the import tool, validation and the exports,
 * so a new field never needs a new table (spec section 14).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

use Estasoft\HandoverPassport\Data\LinkRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of the kinds stored in the items table.
 */
final class Kinds {

	public const LICENCE   = 'licence';
	public const DOMAIN    = 'domain';
	public const DNS       = 'dns_snapshot';
	public const ZONE      = 'zone';
	public const HOST      = 'host';
	public const ENV       = 'environment';
	public const LAYER     = 'layer';
	public const SERVICE   = 'service';
	public const BACKUP    = 'backup';
	public const ACCESS    = 'access';
	public const URL       = 'url';
	public const ASSET     = 'asset';
	public const STACKNOTE = 'stack_note';
	public const CHECKLIST = 'checklist';
	public const HANDSTATE = 'handover_state';

	/**
	 * Kinds that people add and edit in registers.
	 *
	 * @return list<string>
	 */
	public static function registerKinds(): array {
		return array( self::LICENCE, self::DOMAIN, self::ZONE, self::HOST, self::ENV, self::LAYER, self::SERVICE, self::BACKUP, self::ACCESS, self::URL, self::ASSET, self::STACKNOTE, self::CHECKLIST );
	}

	/**
	 * Schema of one kind, or null when unknown.
	 *
	 * @param string $kind Kind key.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $kind ): ?array {
		$all = self::all();
		return $all[ $kind ] ?? null;
	}

	/**
	 * Every schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		static $cache = null;
		if ( null === $cache ) {
			$cache = self::build();
		}
		return $cache;
	}

	/**
	 * Fields every record carries, in addition to its own (PRT-6).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function commonFields(): array {
		return array(
			'note'  => self::f( 'textarea', __( 'Note', 'estasoft-handover-passport' ), array( 'max' => 4000, 'help' => __( 'Plain text. Never paste a password or key here.', 'estasoft-handover-passport' ) ) ),
			'links' => self::f( 'links', __( 'Links', 'estasoft-handover-passport' ), array( 'max' => 10, 'help' => __( 'One address per line, up to 10.', 'estasoft-handover-passport' ) ) ),
		);
	}

	/**
	 * Ownership fields added to asset-like kinds (OWN-1, OWN-3).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function ownershipFields(): array {
		return array(
			'legal_owner'     => self::f( 'select', __( 'Legal owner', 'estasoft-handover-passport' ), array( 'options' => self::owners(), 'column' => 'legal_owner' ) ),
			'account_holder'  => self::f( 'people', __( 'Account holder', 'estasoft-handover-passport' ), array( 'relation' => LinkRepository::HOLDER, 'max' => 3 ) ),
			'payer'           => self::f( 'people', __( 'Who pays', 'estasoft-handover-passport' ), array( 'relation' => LinkRepository::PAYER, 'max' => 3 ) ),
			'administrators'  => self::f( 'people', __( 'Administrators', 'estasoft-handover-passport' ), array( 'relation' => LinkRepository::ADMIN, 'max' => 10 ) ),
			'transfer_status' => self::f( 'select', __( 'Transfer to the client', 'estasoft-handover-passport' ), array( 'options' => self::transferStates(), 'column' => 'status' ) ),
			'transfer_date'   => self::f( 'date', __( 'Transfer date', 'estasoft-handover-passport' ) ),
			'transfer_owner'  => self::f( 'text', __( 'Who handles the transfer', 'estasoft-handover-passport' ) ),
			'transfer_notes'  => self::f( 'textarea', __( 'Transfer notes', 'estasoft-handover-passport' ), array( 'max' => 1000 ) ),
		);
	}

	/**
	 * Legal owner choices.
	 *
	 * @return array<string, string>
	 */
	public static function owners(): array {
		return array(
			'client'      => __( 'Client', 'estasoft-handover-passport' ),
			'agency'      => __( 'Agency', 'estasoft-handover-passport' ),
			'third_party' => __( 'Third party', 'estasoft-handover-passport' ),
			'unknown'     => __( 'Unknown', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Transfer tracker states (OWN-3).
	 *
	 * @return array<string, string>
	 */
	public static function transferStates(): array {
		return array(
			'todo'        => __( 'To do', 'estasoft-handover-passport' ),
			'requested'   => __( 'Requested', 'estasoft-handover-passport' ),
			'in_progress' => __( 'In progress', 'estasoft-handover-passport' ),
			'done'        => __( 'Done', 'estasoft-handover-passport' ),
			'not_needed'  => __( 'Not needed', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Yes, No or Unknown.
	 *
	 * @return array<string, string>
	 */
	public static function yesNoUnknown(): array {
		return array(
			'yes'     => __( 'Yes', 'estasoft-handover-passport' ),
			'no'      => __( 'No', 'estasoft-handover-passport' ),
			'unknown' => __( 'Unknown', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Build a field definition.
	 *
	 * @param string               $type  Field type.
	 * @param string               $label Visible label.
	 * @param array<string, mixed> $extra Extra keys: options, max, required, sensitive, help, column, relation, default.
	 * @return array<string, mixed>
	 */
	private static function f( string $type, string $label, array $extra = array() ): array {
		return array_merge( array( 'type' => $type, 'label' => $label ), $extra );
	}

	/**
	 * Build every schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function build(): array {
		$own = self::ownershipFields();
		$yn  = self::yesNoUnknown();

		$kinds = array(
			self::LICENCE   => array(
				'label'      => __( 'Licence', 'estasoft-handover-passport' ),
				'plural'     => __( 'Licences', 'estasoft-handover-passport' ),
				'section'    => 'licences',
				'title'      => 'product',
				'subject'    => 'slug',
				'due'        => 'renews_on',
				'ownership'  => true,
				'vis'        => array( 1, 0 ),
				'columns'    => array( 'product', 'vendor', 'kind', 'renews_on', 'cost' ),
				'fields'     => array(
					'product'         => self::f( 'text', __( 'Product', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'vendor'          => self::f( 'text', __( 'Vendor', 'estasoft-handover-passport' ) ),
					'kind'            => self::f( 'select', __( 'Kind', 'estasoft-handover-passport' ), array( 'options' => array( 'plugin' => __( 'Plugin', 'estasoft-handover-passport' ), 'theme' => __( 'Theme', 'estasoft-handover-passport' ), 'service' => __( 'Service', 'estasoft-handover-passport' ), 'hosting' => __( 'Hosting', 'estasoft-handover-passport' ), 'domain' => __( 'Domain', 'estasoft-handover-passport' ), 'certificate' => __( 'Certificate', 'estasoft-handover-passport' ), 'other' => __( 'Other', 'estasoft-handover-passport' ) ) ) ),
					'slug'            => self::f( 'text', __( 'Plugin or theme folder name', 'estasoft-handover-passport' ), array( 'help' => __( 'Links this licence to a detected plugin or theme, for example "elementor-pro".', 'estasoft-handover-passport' ) ) ),
					'free'            => self::f( 'select', __( 'Free or GPL, no licence needed', 'estasoft-handover-passport' ), array( 'options' => array( 'no' => __( 'No', 'estasoft-handover-passport' ), 'yes' => __( 'Yes', 'estasoft-handover-passport' ) ) ) ),
					'plan'            => self::f( 'text', __( 'Plan', 'estasoft-handover-passport' ) ),
					'sites_allowed'   => self::f( 'int', __( 'Sites allowed', 'estasoft-handover-passport' ) ),
					'sites_used'      => self::f( 'int', __( 'Sites used', 'estasoft-handover-passport' ) ),
					'purchased_on'    => self::f( 'date', __( 'Purchased on', 'estasoft-handover-passport' ) ),
					'renews_on'       => self::f( 'date', __( 'Renews or expires on', 'estasoft-handover-passport' ) ),
					'auto_renew'      => self::f( 'select', __( 'Auto-renew', 'estasoft-handover-passport' ), array( 'options' => $yn ) ),
					'cancelled'       => self::f( 'select', __( 'Cancelled', 'estasoft-handover-passport' ), array( 'options' => array( 'no' => __( 'No', 'estasoft-handover-passport' ), 'yes' => __( 'Yes', 'estasoft-handover-passport' ) ) ) ),
					'cost'            => self::f( 'money', __( 'Cost', 'estasoft-handover-passport' ), array( 'help' => __( 'Amount per billing period. Stored in minor units (cents).', 'estasoft-handover-passport' ) ) ),
					'currency'        => self::f( 'text', __( 'Currency', 'estasoft-handover-passport' ), array( 'max' => 3, 'help' => __( 'Three letters, for example USD.', 'estasoft-handover-passport' ) ) ),
					'billing_period'  => self::f( 'select', __( 'Billing period', 'estasoft-handover-passport' ), array( 'options' => array( 'monthly' => __( 'Monthly', 'estasoft-handover-passport' ), 'yearly' => __( 'Yearly', 'estasoft-handover-passport' ), 'two_years' => __( 'Every two years', 'estasoft-handover-passport' ), 'three_years' => __( 'Every three years', 'estasoft-handover-passport' ), 'one_time' => __( 'One time', 'estasoft-handover-passport' ), 'other' => __( 'Other', 'estasoft-handover-passport' ) ) ) ),
					'account_email'   => self::f( 'email', __( 'Account email', 'estasoft-handover-passport' ), array( 'sensitive' => true ) ),
					'key_location'    => self::f( 'text', __( 'Where the key lives', 'estasoft-handover-passport' ), array( 'help' => __( 'The password-manager item name, never the key itself.', 'estasoft-handover-passport' ) ) ),
					'support_address' => self::f( 'text', __( 'Support address', 'estasoft-handover-passport' ) ),
					'renewal_address' => self::f( 'url', __( 'Renewal address', 'estasoft-handover-passport' ) ),
					'lapse_effect'    => self::f( 'select', __( 'If it lapses', 'estasoft-handover-passport' ), array( 'options' => array( 'updates_stop' => __( 'Updates stop', 'estasoft-handover-passport' ), 'feature_stops' => __( 'A feature stops', 'estasoft-handover-passport' ), 'site_breaks' => __( 'The site breaks', 'estasoft-handover-passport' ), 'nothing' => __( 'Nothing', 'estasoft-handover-passport' ) ) ) ),
				),
			),
			self::DOMAIN    => array(
				'label'     => __( 'Domain', 'estasoft-handover-passport' ),
				'plural'    => __( 'Domains', 'estasoft-handover-passport' ),
				'section'   => 'domains',
				'title'     => 'domain',
				'subject'   => 'domain',
				'due'       => 'expires_on',
				'ownership' => true,
				'vis'       => array( 1, 1 ),
				'columns'   => array( 'domain', 'role', 'registrar', 'expires_on' ),
				'fields'    => array(
					'domain'          => self::f( 'text', __( 'Domain', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'role'            => self::f( 'select', __( 'Role', 'estasoft-handover-passport' ), array( 'options' => array( 'primary' => __( 'Primary', 'estasoft-handover-passport' ), 'redirect' => __( 'Redirect', 'estasoft-handover-passport' ), 'alias' => __( 'Alias', 'estasoft-handover-passport' ), 'email_only' => __( 'Email only', 'estasoft-handover-passport' ), 'staging' => __( 'Staging', 'estasoft-handover-passport' ) ) ) ),
					'registrar'       => self::f( 'text', __( 'Registrar', 'estasoft-handover-passport' ) ),
					'registrant'      => self::f( 'text', __( 'Registrant, as entered', 'estasoft-handover-passport' ) ),
					'registered_on'   => self::f( 'date', __( 'Registered on', 'estasoft-handover-passport' ) ),
					'expires_on'      => self::f( 'date', __( 'Expires on', 'estasoft-handover-passport' ), array( 'help' => __( 'Entered by hand. An automatic lookup comes in a later version.', 'estasoft-handover-passport' ) ) ),
					'auto_renew'      => self::f( 'select', __( 'Auto-renew', 'estasoft-handover-passport' ), array( 'options' => $yn ) ),
					'registrar_lock'  => self::f( 'select', __( 'Registrar lock', 'estasoft-handover-passport' ), array( 'options' => $yn ) ),
					'privacy'         => self::f( 'select', __( 'Privacy protection', 'estasoft-handover-passport' ), array( 'options' => $yn ) ),
					'login_location'  => self::f( 'text', __( 'Where the registrar login lives', 'estasoft-handover-passport' ) ),
					'dkim_selector'   => self::f( 'text', __( 'DKIM selector', 'estasoft-handover-passport' ), array( 'help' => __( 'Entered by hand. Passport cannot read it from DNS without knowing the name.', 'estasoft-handover-passport' ) ) ),
				),
			),
			self::ZONE      => array(
				'label'     => __( 'Intended DNS zone', 'estasoft-handover-passport' ),
				'plural'    => __( 'Intended DNS zones', 'estasoft-handover-passport' ),
				'section'   => 'domains',
				'title'     => 'domain',
				'subject'   => 'domain',
				'due'       => null,
				'ownership' => false,
				'vis'       => array( 0, 0 ),
				'columns'   => array( 'domain' ),
				'fields'    => array(
					'domain' => self::f( 'text', __( 'Domain', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'rows'   => self::f( 'textarea', __( 'Records', 'estasoft-handover-passport' ), array( 'max' => 8000, 'help' => __( 'One record per line: type, name, value, TTL, purpose. For example: A @ 203.0.113.10 3600 website', 'estasoft-handover-passport' ) ) ),
				),
			),
			self::HOST      => array(
				'label'     => __( 'Hosting', 'estasoft-handover-passport' ),
				'plural'    => __( 'Hosting', 'estasoft-handover-passport' ),
				'section'   => 'hosting',
				'title'     => 'provider',
				'subject'   => null,
				'due'       => 'renews_on',
				'ownership' => true,
				'vis'       => array( 1, 1 ),
				'columns'   => array( 'provider', 'plan', 'kind', 'renews_on' ),
				'fields'    => array(
					'provider'       => self::f( 'text', __( 'Provider', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'plan'           => self::f( 'text', __( 'Plan', 'estasoft-handover-passport' ) ),
					'region'         => self::f( 'text', __( 'Region', 'estasoft-handover-passport' ) ),
					'kind'           => self::f( 'select', __( 'Kind', 'estasoft-handover-passport' ), array( 'options' => array( 'shared' => __( 'Shared', 'estasoft-handover-passport' ), 'vps' => __( 'VPS', 'estasoft-handover-passport' ), 'managed_wp' => __( 'Managed WordPress', 'estasoft-handover-passport' ), 'dedicated' => __( 'Dedicated', 'estasoft-handover-passport' ), 'serverless' => __( 'Serverless', 'estasoft-handover-passport' ), 'other' => __( 'Other', 'estasoft-handover-passport' ) ) ) ),
					'control_panel'  => self::f( 'text', __( 'Control panel', 'estasoft-handover-passport' ) ),
					'support_address' => self::f( 'text', __( 'Support address', 'estasoft-handover-passport' ), array( 'help' => __( 'Email, web address or phone number.', 'estasoft-handover-passport' ) ) ),
					'support_hours'  => self::f( 'text', __( 'Support hours', 'estasoft-handover-passport' ) ),
					'sla'            => self::f( 'text', __( 'Service level', 'estasoft-handover-passport' ) ),
					'access_methods' => self::f( 'lines', __( 'How to get in', 'estasoft-handover-passport' ), array( 'max' => 10, 'help' => __( 'Methods only, for example "SFTP, login in the password manager". Never the login itself.', 'estasoft-handover-passport' ) ) ),
					'renews_on'      => self::f( 'date', __( 'Renewal date', 'estasoft-handover-passport' ) ),
					'cron_server'    => self::f( 'select', __( 'A real server cron runs WordPress cron', 'estasoft-handover-passport' ), array( 'options' => $yn ) ),
					'cron_where'     => self::f( 'text', __( 'Where that cron is set', 'estasoft-handover-passport' ) ),
					'exit_notes'     => self::f( 'textarea', __( 'How to leave this host', 'estasoft-handover-passport' ), array( 'max' => 3000, 'help' => __( 'Export steps, DNS lowering plan and expected downtime.', 'estasoft-handover-passport' ) ) ),
				),
			),
			self::ENV       => array(
				'label'     => __( 'Environment', 'estasoft-handover-passport' ),
				'plural'    => __( 'Environments', 'estasoft-handover-passport' ),
				'section'   => 'hosting',
				'title'     => 'name',
				'subject'   => 'name',
				'due'       => null,
				'ownership' => false,
				'vis'       => array( 1, 0 ),
				'columns'   => array( 'name', 'address', 'host', 'deploy_method', 'last_deployed' ),
				'fields'    => array(
					'name'          => self::f( 'select', __( 'Environment', 'estasoft-handover-passport' ), array( 'required' => true, 'options' => array( 'production' => __( 'Production', 'estasoft-handover-passport' ), 'staging' => __( 'Staging', 'estasoft-handover-passport' ), 'development' => __( 'Development', 'estasoft-handover-passport' ) ) ) ),
					'address'       => self::f( 'url', __( 'Address', 'estasoft-handover-passport' ) ),
					'host'          => self::f( 'text', __( 'Host', 'estasoft-handover-passport' ) ),
					'deployer'      => self::f( 'text', __( 'Who deploys', 'estasoft-handover-passport' ) ),
					'deploy_method' => self::f( 'select', __( 'How', 'estasoft-handover-passport' ), array( 'options' => array( 'manual' => __( 'Manual upload', 'estasoft-handover-passport' ), 'git' => __( 'Git push', 'estasoft-handover-passport' ), 'ci' => __( 'CI', 'estasoft-handover-passport' ), 'host_tool' => __( 'Host staging tool', 'estasoft-handover-passport' ) ) ) ),
					'sync'          => self::f( 'text', __( 'What syncs, and in which direction', 'estasoft-handover-passport' ) ),
					'last_deployed' => self::f( 'date', __( 'Last deployed', 'estasoft-handover-passport' ) ),
				),
			),
			self::LAYER     => array(
				'label'     => __( 'Infrastructure layer', 'estasoft-handover-passport' ),
				'plural'    => __( 'Infrastructure layers', 'estasoft-handover-passport' ),
				'section'   => 'hosting',
				'title'     => 'layer',
				'subject'   => 'layer',
				'due'       => null,
				'ownership' => true,
				'vis'       => array( 1, 0 ),
				'columns'   => array( 'layer', 'provider' ),
				'fields'    => array(
					'layer'    => self::f( 'select', __( 'Layer', 'estasoft-handover-passport' ), array( 'required' => true, 'options' => array( 'cdn' => __( 'CDN or firewall', 'estasoft-handover-passport' ), 'dns' => __( 'DNS provider', 'estasoft-handover-passport' ), 'email' => __( 'Email sending', 'estasoft-handover-passport' ), 'object_cache' => __( 'Object cache', 'estasoft-handover-passport' ), 'page_cache' => __( 'Page cache', 'estasoft-handover-passport' ), 'images' => __( 'Image optimisation', 'estasoft-handover-passport' ), 'search' => __( 'Search', 'estasoft-handover-passport' ) ) ) ),
					'provider' => self::f( 'text', __( 'Provider', 'estasoft-handover-passport' ) ),
				),
			),
			self::SERVICE   => array(
				'label'     => __( 'Service', 'estasoft-handover-passport' ),
				'plural'    => __( 'Services', 'estasoft-handover-passport' ),
				'section'   => 'services',
				'title'     => 'service',
				'subject'   => 'service',
				'due'       => 'renews_on',
				'ownership' => true,
				'vis'       => array( 1, 0 ),
				'columns'   => array( 'service', 'kind', 'plan', 'renews_on' ),
				'fields'    => array(
					'service'       => self::f( 'text', __( 'Service', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'kind'          => self::f( 'select', __( 'Kind', 'estasoft-handover-passport' ), array( 'options' => self::serviceKinds() ) ),
					'purpose'       => self::f( 'text', __( 'Purpose', 'estasoft-handover-passport' ) ),
					'plan'          => self::f( 'text', __( 'Plan', 'estasoft-handover-passport' ) ),
					'data_kinds'    => self::f( 'lines', __( 'Kinds of data it handles', 'estasoft-handover-passport' ), array( 'max' => 10 ) ),
					'dashboard_url' => self::f( 'url', __( 'Dashboard address', 'estasoft-handover-passport' ) ),
					'key_location'  => self::f( 'text', __( 'Where the key lives', 'estasoft-handover-passport' ), array( 'help' => __( 'The password-manager item name, never the key itself.', 'estasoft-handover-passport' ) ) ),
					'webhooks'      => self::f( 'lines', __( 'Webhook or callback addresses on this site', 'estasoft-handover-passport' ), array( 'max' => 10 ) ),
					'renews_on'     => self::f( 'date', __( 'Renewal date', 'estasoft-handover-passport' ) ),
					'licence_id'    => self::f( 'int', __( 'Linked licence record', 'estasoft-handover-passport' ), array( 'help' => __( 'The licence record ID, if the fee is tracked there.', 'estasoft-handover-passport' ) ) ),
				),
			),
			self::BACKUP    => array(
				'label'     => __( 'Backup', 'estasoft-handover-passport' ),
				'plural'    => __( 'Backups', 'estasoft-handover-passport' ),
				'section'   => 'backups',
				'title'     => 'name',
				'subject'   => 'name',
				'due'       => null,
				'computed_due' => array( 'field' => 'last_confirmed', 'days' => 'backup_confirm' ),
				'ownership' => true,
				'vis'       => array( 1, 1 ),
				'columns'   => array( 'name', 'backup_kind', 'frequency_hours', 'offsite', 'last_confirmed' ),
				'fields'    => array(
					'name'            => self::f( 'text', __( 'Name', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'backup_kind'     => self::f( 'select', __( 'Kind', 'estasoft-handover-passport' ), array( 'options' => array( 'plugin' => __( 'Plugin', 'estasoft-handover-passport' ), 'host_panel' => __( 'Host panel', 'estasoft-handover-passport' ), 'server_snapshot' => __( 'Server snapshot', 'estasoft-handover-passport' ), 'managed' => __( 'Managed service', 'estasoft-handover-passport' ), 'manual' => __( 'Manual', 'estasoft-handover-passport' ) ) ) ),
					'covers'          => self::f( 'lines', __( 'What it covers', 'estasoft-handover-passport' ), array( 'max' => 5, 'help' => __( 'For example files, database, uploads.', 'estasoft-handover-passport' ) ) ),
					'frequency_hours' => self::f( 'int', __( 'Runs every (hours)', 'estasoft-handover-passport' ) ),
					'retention'       => self::f( 'text', __( 'Retention', 'estasoft-handover-passport' ) ),
					'stored_at'       => self::f( 'text', __( 'Where the copy is stored', 'estasoft-handover-passport' ) ),
					'offsite'         => self::f( 'select', __( 'Stored outside the hosting account', 'estasoft-handover-passport' ), array( 'options' => $yn ) ),
					'encrypted'       => self::f( 'select', __( 'Encrypted', 'estasoft-handover-passport' ), array( 'options' => $yn ) ),
					'can_restore'     => self::f( 'text', __( 'Who can restore it', 'estasoft-handover-passport' ) ),
					'licence_id'      => self::f( 'int', __( 'Linked licence record', 'estasoft-handover-passport' ), array( 'help' => __( 'The licence record ID, if the cost is tracked there.', 'estasoft-handover-passport' ) ) ),
					'last_confirmed'  => self::f( 'date', __( 'Last confirmed to have run', 'estasoft-handover-passport' ) ),
				),
			),
			self::ACCESS    => array(
				'label'     => __( 'Access entry', 'estasoft-handover-passport' ),
				'plural'    => __( 'Access', 'estasoft-handover-passport' ),
				'section'   => 'access',
				'title'     => 'system',
				'subject'   => null,
				'due'       => null,
				'computed_due' => array( 'field' => 'last_reviewed', 'days' => 'access_review_days' ),
				'ownership' => false,
				'vis'       => array( 0, 0 ),
				'columns'   => array( 'system', 'address', 'level', 'signin', 'last_reviewed' ),
				'fields'    => array(
					'system'          => self::f( 'select', __( 'System', 'estasoft-handover-passport' ), array( 'required' => true, 'options' => self::accessSystems() ) ),
					'address'         => self::f( 'text', __( 'Address', 'estasoft-handover-passport' ) ),
					'who_has_access'  => self::f( 'people', __( 'Who has access', 'estasoft-handover-passport' ), array( 'relation' => LinkRepository::ACCESS, 'max' => 20 ) ),
					'level'           => self::f( 'text', __( 'Access level', 'estasoft-handover-passport' ) ),
					'signin'          => self::f( 'select', __( 'Sign-in method', 'estasoft-handover-passport' ), array( 'options' => array( 'sso' => __( 'Single sign-on', 'estasoft-handover-passport' ), 'password_2fa' => __( 'Password with two-step', 'estasoft-handover-passport' ), 'password_only' => __( 'Password only', 'estasoft-handover-passport' ), 'key' => __( 'Key', 'estasoft-handover-passport' ), 'unknown' => __( 'Unknown', 'estasoft-handover-passport' ) ) ) ),
					'credentials_in'  => self::f( 'text', __( 'Where the credentials live', 'estasoft-handover-passport' ), array( 'help' => __( 'The tool and item name, for example "1Password, item Acme hosting". Never the credentials.', 'estasoft-handover-passport' ) ) ),
					'critical'        => self::f( 'select', __( 'A critical system', 'estasoft-handover-passport' ), array( 'options' => array( 'no' => __( 'No', 'estasoft-handover-passport' ), 'yes' => __( 'Yes', 'estasoft-handover-passport' ) ) ) ),
					'last_reviewed'   => self::f( 'date', __( 'Last reviewed', 'estasoft-handover-passport' ) ),
				),
			),
			self::URL       => array(
				'label'     => __( 'Critical URL', 'estasoft-handover-passport' ),
				'plural'    => __( 'Critical URLs', 'estasoft-handover-passport' ),
				'section'   => 'other',
				'title'     => 'name',
				'subject'   => null,
				'due'       => null,
				'ownership' => false,
				'vis'       => array( 1, 1 ),
				'columns'   => array( 'name', 'address', 'expected_status', 'last_status' ),
				'fields'    => array(
					'name'            => self::f( 'text', __( 'Name', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'address'         => self::f( 'url', __( 'Address', 'estasoft-handover-passport' ), array( 'required' => true, 'help' => __( 'An anonymous page on this site.', 'estasoft-handover-passport' ) ) ),
					'why'             => self::f( 'text', __( 'Why it matters', 'estasoft-handover-passport' ) ),
					'expected_status' => self::f( 'int', __( 'Expected status', 'estasoft-handover-passport' ), array( 'default' => 200 ) ),
					'expected_text'   => self::f( 'text', __( 'Expected text on the page', 'estasoft-handover-passport' ) ),
					'tester'          => self::f( 'text', __( 'Who tests it', 'estasoft-handover-passport' ) ),
				),
			),
			self::ASSET     => array(
				'label'     => __( 'Other asset', 'estasoft-handover-passport' ),
				'plural'    => __( 'Other assets', 'estasoft-handover-passport' ),
				'section'   => 'other',
				'title'     => 'name',
				'subject'   => null,
				'due'       => 'renews_on',
				'ownership' => true,
				'vis'       => array( 1, 0 ),
				'columns'   => array( 'name', 'asset_kind', 'renews_on' ),
				'fields'    => array(
					'name'       => self::f( 'text', __( 'Name', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'asset_kind' => self::f( 'select', __( 'Kind', 'estasoft-handover-passport' ), array( 'options' => array( 'certificate' => __( 'Certificate', 'estasoft-handover-passport' ), 'repository' => __( 'Repository', 'estasoft-handover-passport' ), 'analytics' => __( 'Analytics property', 'estasoft-handover-passport' ), 'other' => __( 'Other', 'estasoft-handover-passport' ) ) ) ),
					'address'    => self::f( 'text', __( 'Address', 'estasoft-handover-passport' ) ),
					'renews_on'  => self::f( 'date', __( 'Renews or expires on', 'estasoft-handover-passport' ) ),
				),
			),
			self::STACKNOTE => array(
				'label'     => __( 'Plugin or theme notes', 'estasoft-handover-passport' ),
				'plural'    => __( 'Plugin and theme notes', 'estasoft-handover-passport' ),
				'section'   => 'stack',
				'title'     => 'name',
				'subject'   => 'ref',
				'due'       => null,
				'ownership' => false,
				'vis'       => array( 1, 0 ),
				'columns'   => array( 'name', 'what_it_does', 'criticality' ),
				'fields'    => array(
					'ref'            => self::f( 'text', __( 'Item', 'estasoft-handover-passport' ), array( 'required' => true, 'help' => __( 'plugin:folder-name or theme:folder-name', 'estasoft-handover-passport' ) ) ),
					'name'           => self::f( 'text', __( 'Name', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'what_it_does'   => self::f( 'text', __( 'What it does here', 'estasoft-handover-passport' ) ),
					'why_installed'  => self::f( 'text', __( 'Why it is installed', 'estasoft-handover-passport' ) ),
					'criticality'    => self::f( 'select', __( 'Criticality', 'estasoft-handover-passport' ), array( 'options' => array( 'critical' => __( 'Critical', 'estasoft-handover-passport' ), 'important' => __( 'Important', 'estasoft-handover-passport' ), 'optional' => __( 'Optional', 'estasoft-handover-passport' ) ) ) ),
					'custom_code'    => self::f( 'select', __( 'Custom code', 'estasoft-handover-passport' ), array( 'options' => array( 'no' => __( 'No', 'estasoft-handover-passport' ), 'yes' => __( 'Yes', 'estasoft-handover-passport' ) ) ) ),
					'repo_address'   => self::f( 'text', __( 'Repository address', 'estasoft-handover-passport' ) ),
					'repo_branch'    => self::f( 'text', __( 'Branch', 'estasoft-handover-passport' ) ),
					'deployed_how'   => self::f( 'text', __( 'How it is deployed', 'estasoft-handover-passport' ) ),
					'hidden'         => self::f( 'select', __( 'Hide this item', 'estasoft-handover-passport' ), array( 'options' => array( 'no' => __( 'No', 'estasoft-handover-passport' ), 'yes' => __( 'Yes', 'estasoft-handover-passport' ) ) ) ),
					'hidden_reason'  => self::f( 'text', __( 'Reason for hiding', 'estasoft-handover-passport' ) ),
				),
			),
			self::CHECKLIST => array(
				'label'     => __( 'Handover checklist item', 'estasoft-handover-passport' ),
				'plural'    => __( 'Custom checklist items', 'estasoft-handover-passport' ),
				'section'   => 'other',
				'title'     => 'text',
				'subject'   => null,
				'due'       => null,
				'ownership' => false,
				'vis'       => array( 1, 0 ),
				'columns'   => array( 'text', 'owner', 'due' ),
				'fields'    => array(
					'text'  => self::f( 'text', __( 'Item', 'estasoft-handover-passport' ), array( 'required' => true ) ),
					'owner' => self::f( 'text', __( 'Owner', 'estasoft-handover-passport' ) ),
					'due'   => self::f( 'date', __( 'Due', 'estasoft-handover-passport' ) ),
					'state' => self::f( 'select', __( 'State', 'estasoft-handover-passport' ), array( 'options' => self::checklistStates() ) ),
				),
			),
			self::DNS       => array(
				'label'     => __( 'DNS snapshot', 'estasoft-handover-passport' ),
				'plural'    => __( 'DNS snapshots', 'estasoft-handover-passport' ),
				'section'   => 'domains',
				'title'     => 'domain',
				'subject'   => 'domain',
				'due'       => null,
				'ownership' => false,
				'system'    => true,
				'vis'       => array( 1, 0 ),
				'columns'   => array( 'domain', 'checked_at' ),
				'fields'    => array(),
			),
			self::HANDSTATE => array(
				'label'     => __( 'Checklist state', 'estasoft-handover-passport' ),
				'plural'    => __( 'Checklist states', 'estasoft-handover-passport' ),
				'section'   => 'other',
				'title'     => 'rule',
				'subject'   => 'rule',
				'due'       => null,
				'ownership' => false,
				'system'    => true,
				'vis'       => array( 0, 0 ),
				'columns'   => array( 'rule', 'state' ),
				'fields'    => array(),
			),
		);

		foreach ( $kinds as $key => $def ) {
			$def['key']    = $key;
			$def['fields'] = $def['fields'] + ( ! empty( $def['ownership'] ) ? $own : array() );
			if ( empty( $def['system'] ) ) {
				$def['fields'] += self::commonFields();
			}
			$kinds[ $key ] = $def;
		}
		return $kinds;
	}

	/**
	 * Third-party service kinds (SVC-1).
	 *
	 * @return array<string, string>
	 */
	public static function serviceKinds(): array {
		return array(
			'payments'       => __( 'Payments', 'estasoft-handover-passport' ),
			'email_sending'  => __( 'Email sending', 'estasoft-handover-passport' ),
			'cdn'            => __( 'CDN', 'estasoft-handover-passport' ),
			'captcha'        => __( 'CAPTCHA', 'estasoft-handover-passport' ),
			'analytics'      => __( 'Analytics', 'estasoft-handover-passport' ),
			'tag_manager'    => __( 'Tag manager', 'estasoft-handover-passport' ),
			'crm'            => __( 'CRM', 'estasoft-handover-passport' ),
			'forms'          => __( 'Forms', 'estasoft-handover-passport' ),
			'maps'           => __( 'Maps', 'estasoft-handover-passport' ),
			'chat'           => __( 'Chat', 'estasoft-handover-passport' ),
			'newsletters'    => __( 'Newsletters', 'estasoft-handover-passport' ),
			'social_login'   => __( 'Social login', 'estasoft-handover-passport' ),
			'search'         => __( 'Search', 'estasoft-handover-passport' ),
			'error_tracking' => __( 'Error tracking', 'estasoft-handover-passport' ),
			'backups'        => __( 'Backups', 'estasoft-handover-passport' ),
			'security'       => __( 'Security', 'estasoft-handover-passport' ),
			'seo'            => __( 'SEO', 'estasoft-handover-passport' ),
			'caching'        => __( 'Caching', 'estasoft-handover-passport' ),
			'multilingual'   => __( 'Multilingual', 'estasoft-handover-passport' ),
			'other'          => __( 'Other', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Systems an access entry can describe (ACC-1).
	 *
	 * @return array<string, string>
	 */
	public static function accessSystems(): array {
		return array(
			'wp_admin'      => __( 'WordPress admin', 'estasoft-handover-passport' ),
			'hosting_panel' => __( 'Hosting panel', 'estasoft-handover-passport' ),
			'ssh_sftp'      => __( 'SSH or SFTP', 'estasoft-handover-passport' ),
			'dns'           => __( 'DNS', 'estasoft-handover-passport' ),
			'registrar'     => __( 'Registrar', 'estasoft-handover-passport' ),
			'cdn'           => __( 'CDN', 'estasoft-handover-passport' ),
			'email'         => __( 'Email', 'estasoft-handover-passport' ),
			'analytics'     => __( 'Analytics', 'estasoft-handover-passport' ),
			'search_console' => __( 'Search Console', 'estasoft-handover-passport' ),
			'tag_manager'   => __( 'Tag manager', 'estasoft-handover-passport' ),
			'payments'      => __( 'Payment gateway', 'estasoft-handover-passport' ),
			'git'           => __( 'Git repository', 'estasoft-handover-passport' ),
			'ci'            => __( 'CI', 'estasoft-handover-passport' ),
			'backup_store'  => __( 'Backup storage', 'estasoft-handover-passport' ),
			'vault'         => __( 'Password-manager vault', 'estasoft-handover-passport' ),
			'other'         => __( 'Other', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Handover checklist states (HND-1).
	 *
	 * @return array<string, string>
	 */
	public static function checklistStates(): array {
		return array(
			'not_done' => __( 'Not done', 'estasoft-handover-passport' ),
			'done'     => __( 'Done', 'estasoft-handover-passport' ),
			'na'       => __( 'Not applicable', 'estasoft-handover-passport' ),
		);
	}
}
