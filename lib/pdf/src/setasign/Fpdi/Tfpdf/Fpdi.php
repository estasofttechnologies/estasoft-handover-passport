<?php

/**
 * This file is part of FPDI
 *
 * @package   Estasoft\HandoverPassport\Vendor\setasign\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace Estasoft\HandoverPassport\Vendor\setasign\Fpdi\Tfpdf;

use Estasoft\HandoverPassport\Vendor\setasign\Fpdi\FpdfTrait;
use Estasoft\HandoverPassport\Vendor\setasign\Fpdi\FpdiTrait;

/**
 * Class Fpdi
 *
 * This class let you import pages of existing PDF documents into a reusable structure for tFPDF.
 */
class Fpdi extends FpdfTpl
{
    use FpdiTrait;
    use FpdfTrait;

    /**
     * FPDI version
     *
     * @string
     */
    const VERSION = '2.6.9';
}
