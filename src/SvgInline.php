<?php

declare(strict_types=1);

namespace YiiRocks\SvgInline;

use DOMDocument;
use DOMElement;
use Psr\Container\ContainerInterface;
use YiiRocks\SvgInline\Bootstrap\BootstrapIcon;
use YiiRocks\SvgInline\Bootstrap\SvgInlineBootstrapInterface;
use YiiRocks\SvgInline\FontAwesome\FontawesomeIcon;
use YiiRocks\SvgInline\FontAwesome\SvgInlineFontAwesomeInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Html\Html;

/**
 * Fontawesome provides a quick and easy way to access icons.
 */
class SvgInline implements SvgInlineInterface
{
    /** @var array Values for converting various units to pixels */
    private const PIXEL_MAP = [
        'px' => 1,
        'em' => 16,
        'ex' => 16 / 2,
        'pt' => 16 / 12,
        'pc' => 16,
        'in' => 16 * 6,
        'cm' => 16 / (2.54 / 6),
        'mm' => 16 / (25.4 / 6),
    ];

    /** @var Aliases Object used to resolve aliases */
    protected Aliases $aliases;

    /** @var array Class property */
    protected array $class;

    /** @var string Backup icon in case requested icon cannot be found */
    protected string $fallbackIcon;

    /** @var string Color of the icon. Set to empty string to disable this attribute */
    protected string $fill;

    /** @var array additional properties for the icon not set with Options */
    protected array $svgProperties;

    /** $var ContainerInterface $container */
    private ContainerInterface $container;

    /** @var Icon|BootstrapIcon|FontawesomeIcon icon properties */
    private Object $icon;

    /** @var DOMDocument SVG file */
    private DOMDocument $svg;

    /** @var DOMElement SVG */
    private DOMElement $svgElement;

    /**
     * @param Aliases $aliases
     * @return $this
     */
    public function __construct(Aliases $aliases, ContainerInterface $container)
    {
        $this->aliases = $aliases;
        $this->container = $container;
        return $this;
    }

    /**
     * Magic function, sets icon properties.
     *
     * Supported options are listed in @method, but
     * [no support](https://github.com/yiisoft/yii2-apidoc/issues/136) in the docs yet.
     *
     * @param string $name  property name
     * @param array  $value property value
     * @return self updated object
     */
    public function __call(string $name, $value): self
    {
        $function = 'set' . ucfirst($name);
        $this->icon->$function($value[0]);
        return $this;
    }
    
    /**
     * Magic function, returns the SVG string.
     *
     * @return string SVG data
     */
    public function __toString(): string
    {
        libxml_clear_errors();
        libxml_use_internal_errors(true);
        $this->svg = new DOMDocument();

        $this->loadSvg();
        $this->setSvgMeasurement();
        $this->setSvgProperties();
        $this->setSvgAttributes();

        return $this->svg->saveXML($this->svgElement);
    }

    /**
     * Sets the Bootstrap Icon
     *
     * @param string $name name of the icon
     * @return BootstrapIcon component object
     */
    public function bootstrap(string $name): SvgInlineBootstrapInterface
    {
        $bootstrap = $this->container->get(SvgInlineBootstrapInterface::class);
        $bootstrap->icon = $bootstrap->name($name);

        return $bootstrap;
    }

    /**
     * Sets the Font Awesome Icon
     *
     * @param string $name name of the icon
     * $param null|string $style style of the icon
     * @return FontawesomeIcon component object
     */
    public function fai(string $name, ?string $style = null): SvgInlineFontAwesomeInterface
    {
        $fai = $this->container->get(SvgInlineFontAwesomeInterface::class);
        $fai->icon = $fai->name($name, $style);

        return $fai;
    }

    /**
     * Sets the filename
     *
     * @param string $name  name of the icon, or filename
     * @return self component object
     */
    public function file(string $file): self
    {
        $this->icon = new Icon();
        $fileName = $this->aliases->get($file);
        $this->icon->setName($fileName);

        return $this;
    }

    /**
     * Load Font Awesome SVG file. Falls back to default if not found.
     *
     * @see $fallbackIcon
     */
    public function loadSvg(): void
    {
        $iconFile = $this->icon->getName();
        if (!$this->svg->load($iconFile)) {
            $this->svg->load($this->fallbackIcon);
        }

        $this->svgElement = $this->svg->getElementsByTagName('svg')->item(0);
    }

    /**
     * @see $fallbackIcon
     * @param string $value
     * @return void
     */
    public function setFallbackIcon(string $value): void
    {
        $this->fallbackIcon = $this->aliases->get($value);
    }

    /**
     * @see $fill
     * @param string|null $value
     * @return void
     */
    public function setFill(string $value): void
    {
        $this->fill = $value;
    }

    /**
     * Determines size of the SVG element.
     *
     * @return array Width & height
     */
    protected function getSvgSize(): array
    {
        $svgWidth = $this->getPixelValue($this->svgElement->getAttribute('width'));
        $svgHeight = $this->getPixelValue($this->svgElement->getAttribute('height'));

        if ($this->svgElement->hasAttribute('viewBox')) {
            [$xStart, $yStart, $xEnd, $yEnd] = explode(' ', $this->svgElement->getAttribute('viewBox'));
            $viewBoxWidth = isset($xStart, $xEnd) ? $xEnd - $xStart : 0;
            $viewBoxHeight = isset($yStart, $yEnd) ? $yEnd - $yStart : 0;

            if ($viewBoxWidth > 0 && $viewBoxHeight > 0) {
                $svgWidth = $viewBoxWidth;
                $svgHeight = $viewBoxHeight;
            }
        }

        return [$svgWidth ?? 1, $svgHeight ?? 1];
    }

    /**
     * Prepares either the size class (default) or the width/height if either of these is given manually.
     *
     * @return void
     */
    protected function setSvgMeasurement(): void
    {
        [$svgWidth, $svgHeight] = $this->getSvgSize();

        $width = $this->icon->get('width');
        $height = $this->icon->get('height');

        $this->class = ['class' => $this->icon->get('class')];
        if ($width || $height) {
            $this->svgProperties['width'] = $width ?? round($height * $svgWidth / $svgHeight);
            $this->svgProperties['height'] = $height ?? round($width * $svgHeight / $svgWidth);
        }
    }

    /**
     * Converts various sizes to pixels.
     *
     * @param string $size
     * @return int
     */
    private function getPixelValue(string $size): int
    {
        $size = trim($size);
        $value = substr($size, 0, -2);
        $unit = substr($size, -2);

        if (is_numeric($value) && isset(self::PIXEL_MAP[$unit])) {
            $size = $value * self::PIXEL_MAP[$unit];
        }

        return (int) round((float) $size);
    }

    /**
     * Adds the properties to the SVG.
     *
     * @return void
     */
    private function setSvgAttributes(): void
    {
        $title = $this->icon->get('title');
        if ($title) {
            $titleElement = $this->svg->createElement('title', $title);
            $this->svgElement->insertBefore($titleElement, $this->svgElement->firstChild);
        }

        foreach ($this->svgProperties as $key => $value) {
            $this->svgElement->removeAttribute($key);
            if (!empty($value)) {
                $this->svgElement->setAttribute($key, (string) $value);
            }
        }
    }

    /**
     * Prepares the values to be set on the SVG.
     *
     * @return void
     */
    private function setSvgProperties(): void
    {
        $this->svgProperties['aria-hidden'] = 'true';
        $this->svgProperties['role'] = 'img';
        $this->svgProperties['class'] = $this->class['class'];

        $css = $this->icon->get('css');
        if (is_array($css)) {
            $this->svgProperties['style'] = Html::cssStyleFromArray($css);
        }

        $this->svgProperties['fill'] = $this->icon->get('fill') ?? $this->fill;
    }
}
