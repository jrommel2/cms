<?php
/**
 * @link      http://buildwithcraft.com/
 * @copyright Copyright (c) 2015 Pixel & Tonic, Inc.
 * @license   http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\helpers\AppHelper;
use craft\app\helpers\ImageHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\StringHelper;
use craft\app\image\Raster;
use craft\app\image\Svg;
use craft\app\io\Image;
use yii\base\Component;

/**
 * Service for image operations.
 *
 * An instance of the Images service is globally accessible in Craft via [[Application::images `Craft::$app->getImages()`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Images extends Component
{
    // Properties
    // =========================================================================

    private $_isGd = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns whether image manipulations will be performed using GD or not.
     *
     * @return boolean|null
     */
    public function isGd()
    {
        if ($this->_isGd === null) {
            if (Craft::$app->getConfig()->get('imageDriver') == 'gd') {
                $this->_isGd = true;
            } else if (extension_loaded('imagick')) {
                // Taken from Imagick\Imagine() constructor.
                $imagick = new \Imagick();
                $v = $imagick->getVersion();
                list($version, $year, $month, $day, $q, $website) = sscanf($v['versionString'], 'ImageMagick %s %04d-%02d-%02d %s %s');

                // Update this if Imagine updates theirs.
                if (version_compare('6.2.9', $version) <= 0) {
                    $this->_isGd = false;
                } else {
                    $this->_isGd = true;
                }
            } else {
                $this->_isGd = true;
            }
        }

        return $this->_isGd;
    }

    /**
     * Returns whether image manipulations will be performed using Imagick or not.
     *
     * @return boolean
     */
    public function isImagick()
    {
        return !$this->isGd();
    }

    /**
     * Loads an image from a file system path.
     *
     * @param string $path
     * @param bool   $rasterize whether or not the image will be rasterized if it's an SVG
     * @param int    $svgSize   The size SVG should be scaled up to, if rasterized
     *
     * @throws \Exception
     * @return Image
     */
    public function loadImage($path, $rasterize = false, $svgSize = 1000)
    {
        if (StringHelper::toLowerCase(IOHelper::getExtension($path)) == 'svg')
        {
            $image = new Svg();
            $image->loadImage($path);

            if ($rasterize)
            {
                $image->scaleToFit($svgSize, $svgSize);
                $svgString = $image->getSvgString();
                $image = new Raster();
                $image->loadFromSVG($svgString);
            }
        }
        else
        {
            $image = new Raster();
            $image->loadImage($path);
        }


        return $image;
    }

    /**
     * Determines if there is enough memory to process this image.
     *
     * The code was adapted from http://www.php.net/manual/en/function.imagecreatefromjpeg.php#64155. It will first
     * attempt to do it with available memory. If that fails, Craft will bump the memory to amount defined by the
     * [phpMaxMemoryLimit](http://buildwithcraft.com/docs/config-settings#phpMaxMemoryLimit) config setting, then try again.
     *
     * @param string  $filePath The path to the image file.
     * @param boolean $toTheMax If set to true, will set the PHP memory to the config setting phpMaxMemoryLimit.
     *
     * @return boolean
     */
    public function checkMemoryForImage($filePath, $toTheMax = false)
    {
        if (StringHelper::toLowerCase(IOHelper::getExtension($filePath)) == 'svg')
        {
            return true;
        }

        if (!function_exists('memory_get_usage')) {
            return false;
        }

        if ($toTheMax) {
            // Turn it up to 11.
            Craft::$app->getConfig()->maxPowerCaptain();
        }

        // Find out how much memory this image is going to need.
        $imageInfo = getimagesize($filePath);
        $K64 = 65536;
        $tweakFactor = 1.7;
        $bits = isset($imageInfo['bits']) ? $imageInfo['bits'] : 8;
        $channels = isset($imageInfo['channels']) ? $imageInfo['channels'] : 4;
        $memoryNeeded = round(($imageInfo[0] * $imageInfo[1] * $bits * $channels / 8 + $K64) * $tweakFactor);

        $memoryLimit = AppHelper::getPhpConfigValueInBytes('memory_limit');

        if ($memoryLimit == -1 || memory_get_usage() + $memoryNeeded < $memoryLimit) {
            return true;
        }

        if (!$toTheMax) {
            return $this->checkMemoryForImage($filePath, true);
        }

        // Oh well, we tried.
        return false;
    }

    /**
     * Cleans an image by it's path, clearing embedded JS and PHP code.
     *
     * @param string $filePath
     *
     * @return boolean
     */
    public function cleanImage($filePath)
    {
        try {
            if (Craft::$app->getConfig()->get('rotateImagesOnUploadByExifData')) {
                $this->rotateImageByExifData($filePath);
            }

            $this->stripOrientationFromExifData($filePath);
        } catch (\Exception $e) {
            Craft::error('Tried to rotate or strip EXIF data from image and failed: '.$e->getMessage());
        }

        return $this->loadImage($filePath)->saveAs($filePath, true);
    }

    /**
     * Rotate image according to it's EXIF data.
     *
     * @param string $filePath
     *
     * @return mixed
     */
    public function rotateImageByExifData($filePath)
    {
        if (!ImageHelper::canHaveExifData($filePath)) {
            return null;
        }

        $exif = $this->getExifData($filePath);

        $degrees = 0;

        if (!empty($exif['ifd0.Orientation'])) {
            switch ($exif['ifd0.Orientation']) {
                case ImageHelper::EXIF_IFD0_ROTATE_180: {
                    $degrees = 180;
                    break;
                }
                case ImageHelper::EXIF_IFD0_ROTATE_90: {
                    $degrees = 90;
                    break;
                }
                case ImageHelper::EXIF_IFD0_ROTATE_270: {
                    $degrees = 270;
                    break;
                }
            }
        }

        $image = $this->loadImage($filePath)->rotate($degrees);

        return $image->saveAs($filePath, true);
    }

    /**
     * Get EXIF metadata for a file by it's path.
     *
     * @param $filePath
     *
     * @return array
     */
    public function getExifData($filePath)
    {
        if (!ImageHelper::canHaveExifData($filePath)) {
            return null;
        }

        $image = new Image();

        return $image->getExifMetadata($filePath);
    }

    /**
     * Strip orientation from EXIF data for an image at a path.
     *
     * @param $filePath
     *
     * @return boolean
     */
    public function stripOrientationFromExifData($filePath)
    {
        if (!ImageHelper::canHaveExifData($filePath)) {
            return null;
        }

        $data = new \PelDataWindow(IOHelper::getFileContents($filePath));

        // Is this a valid JPEG?
        if (\PelJpeg::isValid($data)) {
            $jpeg = $file = new \PelJpeg();
            $jpeg->load($data);
            $exif = $jpeg->getExif();

            if ($exif) {
                $tiff = $exif->getTiff();
                $ifd0 = $tiff->getIfd();

                // Delete the Orientation entry and re-save the file
                $ifd0->offsetUnset(\PelTag::ORIENTATION);
                $file->saveFile($filePath);
            }

            return true;
        } else {
            return false;
        }
    }
}