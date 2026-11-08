<?php

namespace Estasoft\HandoverPassport\Vendor\Mpdf\Http;

use Estasoft\HandoverPassport\Vendor\Psr\Http\Message\RequestInterface;

interface ClientInterface
{

	public function sendRequest(RequestInterface $request);

}
