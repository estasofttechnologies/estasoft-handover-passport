<?php
/**
 * Plugin folder name => service kind and service name (SCN-5).
 *
 * The table is filterable with "estasoft_hp_service_map". Its accuracy is a best effort:
 * a match only creates a suggestion that a person accepts, edits or dismisses.
 *
 * @package Estasoft\HandoverPassport
 */

defined( 'ABSPATH' ) || exit;

return array(
	// Payments.
	'woocommerce-gateway-stripe'          => array( 'payments', 'Stripe' ),
	'woocommerce-paypal-payments'         => array( 'payments', 'PayPal' ),
	'woocommerce-square'                  => array( 'payments', 'Square' ),
	'woocommerce-payments'                => array( 'payments', 'WooPayments' ),
	'mollie-payments-for-woocommerce'     => array( 'payments', 'Mollie' ),
	'stripe-payments'                     => array( 'payments', 'Stripe' ),
	'wp-simple-pay'                       => array( 'payments', 'Stripe' ),
	// Email sending.
	'wp-mail-smtp'                        => array( 'email_sending', 'SMTP provider' ),
	'post-smtp'                           => array( 'email_sending', 'SMTP provider' ),
	'fluent-smtp'                         => array( 'email_sending', 'SMTP provider' ),
	'mailgun'                             => array( 'email_sending', 'Mailgun' ),
	'sendgrid-email-delivery-simplified'  => array( 'email_sending', 'SendGrid' ),
	'wp-ses'                              => array( 'email_sending', 'Amazon SES' ),
	'postmark-approved-wordpress-plugin'  => array( 'email_sending', 'Postmark' ),
	// CDN.
	'cloudflare'                          => array( 'cdn', 'Cloudflare' ),
	'bunnycdn'                            => array( 'cdn', 'Bunny CDN' ),
	'cdn-enabler'                         => array( 'cdn', 'CDN' ),
	'sucuri-scanner'                      => array( 'security', 'Sucuri' ),
	// CAPTCHA.
	'recaptcha-for-woocommerce'           => array( 'captcha', 'reCAPTCHA' ),
	'advanced-nocaptcha-recaptcha'        => array( 'captcha', 'reCAPTCHA' ),
	'simple-cloudflare-turnstile'         => array( 'captcha', 'Cloudflare Turnstile' ),
	'hcaptcha-for-forms-and-more'         => array( 'captcha', 'hCaptcha' ),
	// Analytics and tags.
	'google-site-kit'                     => array( 'analytics', 'Google Analytics and Search Console' ),
	'ga-google-analytics'                 => array( 'analytics', 'Google Analytics' ),
	'monsterinsights'                     => array( 'analytics', 'Google Analytics' ),
	'google-analytics-for-wordpress'      => array( 'analytics', 'Google Analytics' ),
	'matomo'                              => array( 'analytics', 'Matomo' ),
	'duracelltomi-google-tag-manager'     => array( 'tag_manager', 'Google Tag Manager' ),
	'gtm4wp'                              => array( 'tag_manager', 'Google Tag Manager' ),
	'pixelyoursite'                       => array( 'analytics', 'Meta Pixel' ),
	'facebook-for-woocommerce'            => array( 'analytics', 'Meta' ),
	// Backups.
	'updraftplus'                         => array( 'backups', 'UpdraftPlus' ),
	'backwpup'                            => array( 'backups', 'BackWPup' ),
	'duplicator'                          => array( 'backups', 'Duplicator' ),
	'all-in-one-wp-migration'             => array( 'backups', 'All-in-One WP Migration' ),
	'wpvivid-backuprestore'               => array( 'backups', 'WPvivid' ),
	'jetpack'                             => array( 'backups', 'Jetpack' ),
	'blogvault-real-time-backup'          => array( 'backups', 'BlogVault' ),
	'backupbuddy'                         => array( 'backups', 'BackupBuddy' ),
	// Security.
	'wordfence'                           => array( 'security', 'Wordfence' ),
	'better-wp-security'                  => array( 'security', 'Solid Security' ),
	'all-in-one-wp-security-and-firewall' => array( 'security', 'All-In-One Security' ),
	'wp-cerber'                           => array( 'security', 'WP Cerber' ),
	// SEO.
	'wordpress-seo'                       => array( 'seo', 'Yoast SEO' ),
	'seo-by-rank-math'                    => array( 'seo', 'Rank Math' ),
	'all-in-one-seo-pack'                 => array( 'seo', 'All in One SEO' ),
	'wp-seopress'                         => array( 'seo', 'SEOPress' ),
	// Forms and CRM.
	'contact-form-7'                      => array( 'forms', 'Contact Form 7' ),
	'wpforms-lite'                        => array( 'forms', 'WPForms' ),
	'wpforms'                             => array( 'forms', 'WPForms' ),
	'gravityforms'                        => array( 'forms', 'Gravity Forms' ),
	'ninja-forms'                         => array( 'forms', 'Ninja Forms' ),
	'fluentform'                          => array( 'forms', 'Fluent Forms' ),
	'hubspot'                             => array( 'crm', 'HubSpot' ),
	'leadin'                              => array( 'crm', 'HubSpot' ),
	'mailchimp-for-wp'                    => array( 'newsletters', 'Mailchimp' ),
	'mailchimp-for-woocommerce'           => array( 'newsletters', 'Mailchimp' ),
	'newsletter'                          => array( 'newsletters', 'Newsletter' ),
	// Caching.
	'wp-rocket'                           => array( 'caching', 'WP Rocket' ),
	'w3-total-cache'                      => array( 'caching', 'W3 Total Cache' ),
	'wp-super-cache'                      => array( 'caching', 'WP Super Cache' ),
	'litespeed-cache'                     => array( 'caching', 'LiteSpeed Cache' ),
	'wp-fastest-cache'                    => array( 'caching', 'WP Fastest Cache' ),
	'redis-cache'                         => array( 'caching', 'Redis' ),
	// Multilingual.
	'sitepress-multilingual-cms'          => array( 'multilingual', 'WPML' ),
	'polylang'                            => array( 'multilingual', 'Polylang' ),
	'translatepress-multilingual'         => array( 'multilingual', 'TranslatePress' ),
	// Search, maps, chat, errors.
	'elasticpress'                        => array( 'search', 'Elasticsearch' ),
	'relevanssi'                          => array( 'search', 'Relevanssi' ),
	'wp-google-maps'                      => array( 'maps', 'Google Maps' ),
	'sentry'                              => array( 'error_tracking', 'Sentry' ),
	'wp-sentry-integration'               => array( 'error_tracking', 'Sentry' ),
	'tawkto-live-chat'                    => array( 'chat', 'Tawk.to' ),
	'nextend-social-login'                => array( 'social_login', 'Social login' ),
);
