<?php
/**
 * Mail providers named from MX hosts (DOM-5).
 *
 * Each key is a host name suffix; the value is the provider as the provider names itself.
 * The match is by suffix of the MX host, so "aspmx.l.google.com" matches "google.com".
 * Extend through the "estasoft_hp_mail_providers" filter.
 *
 * @package Estasoft\HandoverPassport
 */

defined( 'ABSPATH' ) || exit;

return array(
	'google.com'             => 'Google Workspace',
	'googlemail.com'         => 'Google Workspace',
	'protection.outlook.com' => 'Microsoft 365',
	'outlook.com'            => 'Microsoft 365',
	'zoho.com'               => 'Zoho Mail',
	'zoho.eu'                => 'Zoho Mail',
	'zoho.in'                => 'Zoho Mail',
	'protonmail.ch'          => 'Proton Mail',
	'proton.me'              => 'Proton Mail',
	'messagingengine.com'    => 'Fastmail',
	'fastmail.com'           => 'Fastmail',
	'icloud.com'             => 'iCloud Mail',
	'yandex.net'             => 'Yandex Mail',
	'yandex.ru'              => 'Yandex Mail',
	'mimecast.com'           => 'Mimecast',
	'pphosted.com'           => 'Proofpoint',
	'mailgun.org'            => 'Mailgun',
	'mx.cloudflare.net'      => 'Cloudflare Email Routing',
	'amazonaws.com'          => 'Amazon SES',
	'secureserver.net'       => 'GoDaddy',
	'privateemail.com'       => 'Namecheap Private Email',
	'registrar-servers.com'  => 'Namecheap Email Forwarding',
	'ionos.com'              => 'IONOS',
	'kundenserver.de'        => 'IONOS',
	'hostinger.com'          => 'Hostinger',
	'ovh.net'                => 'OVHcloud',
	'migadu.com'             => 'Migadu',
	'forwardemail.net'       => 'Forward Email',
	'improvmx.com'           => 'ImprovMX',
	'titan.email'            => 'Titan Email',
);
