<?php
/**
 * Thrown when a person may not make a pack (EXP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * The message is safe to show.
 */
final class ExportRefused extends RuntimeException {}
