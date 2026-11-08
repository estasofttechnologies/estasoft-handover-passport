<?php
/**
 * An HTTP client for the PDF library that never makes a request (SEC-12).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render\Pdf;

use Estasoft\HandoverPassport\Vendor\Mpdf\Http\ClientInterface;
use Estasoft\HandoverPassport\Vendor\Mpdf\PsrHttpMessageShim\Response;
use Estasoft\HandoverPassport\Vendor\Psr\Http\Message\RequestInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The pack holds no remote address, so the library should never ask for one. If text slipped through that made it try,
 * the answer is always "forbidden" and nothing leaves the server.
 */
final class NoNetwork implements ClientInterface {

	/**
	 * Refuse every request.
	 *
	 * @param RequestInterface $request Request the library wanted to make.
	 */
	public function sendRequest( RequestInterface $request ) {
		return new Response( 403, array(), '', '1.1', 'Not allowed' );
	}
}
