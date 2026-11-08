<?php

/**
 * This file is part of FPDI
 *
 * @package   Estasoft\HandoverPassport\Vendor\setasign\Fpdi
 * @copyright Copyright (c) 2026 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace Estasoft\HandoverPassport\Vendor\setasign\Fpdi\PdfParser\Filter;

use Estasoft\HandoverPassport\Vendor\setasign\Fpdi\PdfParser\PdfParserException;

/**
 * Exception for filters
 */
class FilterException extends PdfParserException
{
    const UNSUPPORTED_FILTER = 0x0201;

    const NOT_IMPLEMENTED = 0x0202;
}
