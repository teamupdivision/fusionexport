<?php

namespace FusionExport;

use FusionExport\Converters\NumberConverter;
use FusionExport\Converters\BooleanConverter;
use FusionExport\Converters\EnumConverter;
use FusionExport\Converters\ChartConfigConverter;
use FusionExport\Converters\ObjectConverter;
use FusionExport\Exceptions\InvalidConfigurationException;
use FusionExport\Exceptions\InvalidDataTypeException;
use PHPHtmlParser\Dom;
use mikehaertl\tmp\File as TmpFile;
use PDO;

require_once __DIR__ . './MinifyConfig.php';


class ResourcePathInfo
{
    public $internalPath;
    public $externalPath;
}

class ExportConfig
{
    protected $configs;

    public function __construct()
    {
        $this->typingsFile = __DIR__ . '/../config/fusionexport-typings.json';
        $this->configs = [];
        $this->formattedConfigs = [];

        $this->readTypingsConfig();
        $this->collectedResources = array();
    }

    public function set($name, $value)
    {
        $parsedValue = $this->parseConfig($name, $value);

        $this->configs[$name] = $parsedValue;

        return $this;
    }

    public function get($name)
    {
        return $this->configs[$name];
    }

    public function remove($name)
    {
        unset($this->configs[$name]);
        return $this;
    }

    public function has($name)
    {
        return array_key_exists($name, $this->configs);
    }

    public function clear()
    {
        $this->configs = [];
    }

    public function count()
    {
        return count($this->configs);
    }

    public function configNames()
    {
        return array_keys($this->configs);
    }

    public function configValues()
    {
        return array_values($this->configs);
    }

    public function cloneConfig()
    {
        $newExportConfig = new ExportConfig();

        foreach ($this->configs as $key => $value) {
            $newExportConfig->set($key, $value);
        }

        return $newExportConfig;
    }

    public function getFormattedConfigs()
    {
        $this->formatConfigs();
        return $this->formattedConfigs;
    }

    private function parseConfig($name, $value)
    {
        if (!property_exists($this->typings, $name)) {
            throw new InvalidConfigurationException($name);
        }

        $supportedTypes = $this->typings->$name->supportedTypes;

        $isSupported = false;
        foreach ($supportedTypes as $supportedType) {
            if (gettype($value) === $supportedType) {
                $isSupported = true;
                break;
            }
        }

        if (!$isSupported) {
            throw new InvalidDataTypeException($name, $value, $supportedTypes);
        }

        $parsedValue = $value;

        if (property_exists($this->typings->$name, 'converter')) {
            $converter = $this->typings->$name->converter;

            if ($converter === 'ChartConfigConverter') {
                $parsedValue = ChartConfigConverter::convert($value);
            } elseif ($converter === 'BooleanConverter') {
                $parsedValue = BooleanConverter::convert($value);
            } elseif ($converter === 'ObjectConverter') {
                $parsedValue = ObjectConverter::convert($value);
            } elseif ($converter === 'NumberConverter') {
                $parsedValue = NumberConverter::convert($value);
            } elseif ($converter === 'EnumConverter') {
                $dataset = $this->typings->$name->dataset;
                $parsedValue = EnumConverter::convert($value, $dataset);
            }
        }

        return $parsedValue;
    }

    private function formatConfigs()
    {
        if (isset($this->configs['templateFilePath']) && isset($this->configs['template'])) {
            print("Both 'templateFilePath' and 'template' is provided. 'templateFilePath' will be ignored.\n");
            unset($this->configs['templateFilePath']);
        }

        $zipBag = array();

        foreach ($this->configs as $key=> $value) {
            switch ($key) {
                case "chartConfig":
                    if (Helpers::endswith($this->configs['chartConfig'], '.json')) {
                        $this->formattedConfigs['chartConfig'] = Helpers::readFile($this->configs['chartConfig']);
                    } else {
                        $this->formattedConfigs['chartConfig'] = $this->configs['chartConfig'];
                    }
                    break;
                case "inputSVG":
                    $obj = new ResourcePathInfo;
                    $internalFilePath = "inputSVG.svg";
                    $obj->internalPath = $internalFilePath;
                    $obj->externalPath = $this->configs['inputSVG'];
                    $this->formattedConfigs['inputSVG'] = $internalFilePath;
                    array_push($zipBag, $obj);
                    break;
                case "callbackFilePath":
                    $obj = new ResourcePathInfo;
                    $internalFilePath = "callbackFile.js";
                    $this->formattedConfigs['callbackFilePath'] = $internalFilePath;
                    $obj->internalPath = $internalFilePath;
                    $obj->externalPath = $this->configs['callbackFilePath'];
                    array_push($zipBag, $obj);
                    break;
                case "dashboardLogo":
                    $obj = new ResourcePathInfo;
                    $internalFilePath = "logo." . pathinfo($this->configs['dashboardLogo'], PATHINFO_EXTENSION);
                    $obj->internalPath = $internalFilePath;
                    $obj->externalPath = $this->configs['dashboardLogo'];
                    $this->formattedConfigs['dashboardLogo'] = $internalFilePath;
                    array_push($zipBag, $obj);
                    break;
                case "templateFilePath":
                    $templatePathWithinZip = '';
                    $zipPaths = array();
                    $this->createTemplateZipPaths($zipPaths, $templatePathWithinZip);
                    $this->formattedConfigs['templateFilePath'] = $templatePathWithinZip;
                    foreach ($zipPaths as $path) {
                        array_push($zipBag, $path);
                    }
                    break;
                case "outputFileDefinition":
                    $this->formattedConfigs['outputFileDefinition'] = Helpers::readFile($this->configs['outputFileDefinition']);
                    break;
                case "asyncCapture":
                    if (empty($this->configs['asyncCapture']) < 1) {
                        if (strtolower($this->configs['asyncCapture']) == "true") {
                            $this->formattedConfigs['asyncCapture'] = "true";
                        } else {
                            $this->formattedConfigs['asyncCapture'] = "false";
                        }
                    }
                    break;
                default:
                    $this->formattedConfigs[$key] = $this->configs[$key];
            }
        }
        $isMinified = false;
        if (isset($this->configs['minifyResources'])) {
            $isMinified = $this->get('minifyResources');
        }
        
        if (count($zipBag) > 0) {
            $zipFile = $this->generateZip($zipBag,$isMinified);
            $this->formattedConfigs['payload'] = $zipFile;
        }
        
      
        $platform = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'win32' : PHP_OS;

        $this->formattedConfigs['platform'] = $platform;
        $this->formattedConfigs['clientName'] = 'PHP';
    }

    private function createTemplateZipPaths(&$outZipPaths, &$outTemplatePathWithinZip)
    {
        $templatePathWithinZip ='';
        $listExtractedPaths = array();
        $listExtractedPaths = $this->findResources();
        $listResourcePaths = array();
        $baseDirectoryPath = null;

        $isMinified = false;
        if (isset($this->configs['minifyResources'])) {
            $isMinified = $this->get('minifyResources');
        }

         $minifiedHash = '.min-fusionexport'.date("Y-m-d H:i:s");
         $minifiedExtension = $isMinified ?$minifiedHash :"";

        if (isset($this->configs['resourceFilePath'])) {
            Helpers::globResolve($listResourcePaths, $baseDirectoryPath, $this->configs[resourceFilePath]);
        }
        $templateFilePath = realpath($this->configs['templateFilePath']);
        if (!isset($baseDirectoryPath)) {
            array_push($listExtractedPaths, $templateFilePath);
            $commonDirectoryPath = Helpers::findCommonPath($listExtractedPaths);
            if (isset($commonDirectoryPath)) {
                $baseDirectoryPath = $commonDirectoryPath;
            }
            if (strlen($baseDirectoryPath) == 0) {
                $baseDirectoryPath = dirname($templateFilePath);
            }
        }
        $mapExtractedPathAbsToRel = array();
        foreach ($listExtractedPaths as $tmpPath) {
            $mapExtractedPathAbsToRel[$tmpPath] = Helpers::removeCommonPath($tmpPath, $baseDirectoryPath);
        }
        foreach ($listResourcePaths as $tmpPath) {
            $mapExtractedPathAbsToRel[$tmpPath] = Helpers::removeCommonPath($tmpPath, $baseDirectoryPath);
        }
        $templateFilePathWithinZipRel = Helpers::removeCommonPath($templateFilePath, $baseDirectoryPath);
        $mapExtractedPathAbsToRel[$templateFilePath] = $templateFilePathWithinZipRel;
        $zipPaths = array();
        
        $zipPaths = $this->generatePathForZip($mapExtractedPathAbsToRel, $baseDirectoryPath);
        $templatePathWithinZip = $templatePathWithinZip . DIRECTORY_SEPARATOR . $templateFilePathWithinZipRel;
        $outZipPaths = $zipPaths;
        $outTemplatePathWithinZip = $templatePathWithinZip;
    }

    private function findResources()
    {
        $dom = new Dom();
        $dom->setOptions([
            'removeScripts' => false,
        ]);

        @$dom->load(Helpers::readFile($this->configs['templateFilePath']));

        $links = @$dom->find('link')->toArray();
        $scripts = @$dom->find('script')->toArray();
        $imgs = @$dom->find('img')->toArray();

        $links = array_map(function ($link) {
            return $link->getAttribute('href');
        }, $links);

        $scripts = array_map(function ($script) {
            return $script->getAttribute('src');
        }, $scripts);

        $imgs = array_map(function ($img) {
            return $img->getAttribute('src');
        }, $imgs);

        $this->collectedResources = array_merge($links, $scripts, $imgs);

        $this->removeRemoteResources();

        $this->collectedResources = Helpers::resolvePaths(
            $this->collectedResources,
            dirname(realpath($this->configs['templateFilePath']))
        );

        $this->collectedResources = array_unique($this->collectedResources);

        return $this->collectedResources;
    }

    private function removeRemoteResources()
    {
        $this->collectedResources = array_filter(
            $this->collectedResources,
            function ($res) {
                if (Helpers::startsWith($res, 'http://')) {
                    return false;
                }

                if (Helpers::startsWith($res, 'https://')) {
                    return false;
                }

                if (Helpers::startsWith($res, 'file://')) {
                    return false;
                }

                return true;
            }
        );
    }

    private function generatePathForZip($listAllFilePaths, $baseDirectoryPath)
    {
        $listFilePath = array();
        foreach ($listAllFilePaths as $key => $value) {
            $obj = new ResourcePathInfo;
            $obj->internalPath = $value;
            $obj->externalPath = $key;
            array_push($listFilePath, $obj);
        }
        return $listFilePath;
    }

    private function generateZip($fileBag,$minify)
    {
        $tmpFile = new TmpFile('', '.zip');
        $a = new MinifyConfig();
        $isMinified = $minify===true;

        $tmpFile->delete = false;
        $fileName = $tmpFile->getFileName();

        $zipFile = new \ZipArchive();
        $zipFile->open($fileName, \ZipArchive::OVERWRITE);
        foreach ($fileBag as $files) {

            $files = $isMinified && $this->isHtmlJsCss($files) ?$a->minifyData($files):$files;
            
            if (strlen((string)$files->internalPath) > 0 && strlen((string)$files->externalPath) > 0) {
                $zipFile->addFile($files->externalPath, $files->internalPath);
            }
        }
        $zipFile->close();
        $isMinified && $this->isHtmlJsCss($files) ?file_put_contents($files->externalPath, $files->data_html):'';
        return $fileName;
    }

    private function isHtmlJsCss($files){
        $allowedExtensions= array('css', 'html', 'js');
        $ext= pathinfo($files->internalPath, PATHINFO_EXTENSION);
        return in_array($ext, $allowedExtensions);
    }

    private function readTypingsConfig()
    {
        $this->typings = json_decode(Helpers::readFile($this->typingsFile));
    }
}
