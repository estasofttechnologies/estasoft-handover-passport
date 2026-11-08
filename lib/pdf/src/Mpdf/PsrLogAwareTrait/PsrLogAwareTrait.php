<?php

namespace Estasoft\HandoverPassport\Vendor\Mpdf\PsrLogAwareTrait;

use Estasoft\HandoverPassport\Vendor\Psr\Log\LoggerInterface;

trait PsrLogAwareTrait 
{

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Psr\Log\LoggerInterface
	 */
	protected $logger;

	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}
	
}
