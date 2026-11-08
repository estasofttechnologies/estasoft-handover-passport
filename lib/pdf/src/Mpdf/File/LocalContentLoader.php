<?php

namespace Estasoft\HandoverPassport\Vendor\Mpdf\File;

class LocalContentLoader implements \Estasoft\HandoverPassport\Vendor\Mpdf\File\LocalContentLoaderInterface
{

	public function load($path)
	{
		return file_get_contents($path);
	}

}
