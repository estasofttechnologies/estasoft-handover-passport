<?php

namespace Estasoft\HandoverPassport\Vendor\Mpdf\Tag;

use Estasoft\HandoverPassport\Vendor\Mpdf\Strict;

use Estasoft\HandoverPassport\Vendor\Mpdf\Cache;
use Estasoft\HandoverPassport\Vendor\Mpdf\Color\ColorConverter;
use Estasoft\HandoverPassport\Vendor\Mpdf\CssManager;
use Estasoft\HandoverPassport\Vendor\Mpdf\Form;
use Estasoft\HandoverPassport\Vendor\Mpdf\Image\ImageProcessor;
use Estasoft\HandoverPassport\Vendor\Mpdf\Language\LanguageToFontInterface;
use Estasoft\HandoverPassport\Vendor\Mpdf\Mpdf;
use Estasoft\HandoverPassport\Vendor\Mpdf\Otl;
use Estasoft\HandoverPassport\Vendor\Mpdf\SizeConverter;
use Estasoft\HandoverPassport\Vendor\Mpdf\TableOfContents;

abstract class Tag
{

	use Strict;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\Mpdf
	 */
	protected $mpdf;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\Cache
	 */
	protected $cache;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\CssManager
	 */
	protected $cssManager;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\Form
	 */
	protected $form;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\Otl
	 */
	protected $otl;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\TableOfContents
	 */
	protected $tableOfContents;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\SizeConverter
	 */
	protected $sizeConverter;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\Color\ColorConverter
	 */
	protected $colorConverter;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\Image\ImageProcessor
	 */
	protected $imageProcessor;

	/**
	 * @var \Estasoft\HandoverPassport\Vendor\Mpdf\Language\LanguageToFontInterface
	 */
	protected $languageToFont;

	const ALIGN = [
		'left' => 'L',
		'center' => 'C',
		'right' => 'R',
		'top' => 'T',
		'text-top' => 'TT',
		'middle' => 'M',
		'baseline' => 'BS',
		'bottom' => 'B',
		'text-bottom' => 'TB',
		'justify' => 'J'
	];

	public function __construct(
		Mpdf $mpdf,
		Cache $cache,
		CssManager $cssManager,
		Form $form,
		Otl $otl,
		TableOfContents $tableOfContents,
		SizeConverter $sizeConverter,
		ColorConverter $colorConverter,
		ImageProcessor $imageProcessor,
		LanguageToFontInterface $languageToFont
	) {

		$this->mpdf = $mpdf;
		$this->cache = $cache;
		$this->cssManager = $cssManager;
		$this->form = $form;
		$this->otl = $otl;
		$this->tableOfContents = $tableOfContents;
		$this->sizeConverter = $sizeConverter;
		$this->colorConverter = $colorConverter;
		$this->imageProcessor = $imageProcessor;
		$this->languageToFont = $languageToFont;
	}

	public function getTagName()
	{
		$tag = get_class($this);
		return strtoupper(str_replace('Estasoft\HandoverPassport\Vendor\Mpdf\Tag\\', '', $tag));
	}

	protected function getAlign($property)
	{
		$property = strtolower($property);
		return array_key_exists($property, self::ALIGN) ? self::ALIGN[$property] : '';
	}

	abstract public function open($attr, &$ahtml, &$ihtml);

	abstract public function close(&$ahtml, &$ihtml);

}
