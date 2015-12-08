<?php

require_once __DIR__.'/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

date_default_timezone_set('Europe/Paris');
setlocale(LC_ALL, 'en_US.UTF-8');

function ocrPdfInFolder($folderPath, $logger){
    $directory = new \RecursiveDirectoryIterator($folderPath, \FilesystemIterator::FOLLOW_SYMLINKS);
    $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) use ($logger){
        if ($iterator->hasChildren()){
            return true;
        }

        if (strtolower($current->getExtension()) == 'pdf' &&
            strpos($current->getFilename(), '.OCR.pdf') === False){
            $ocrInfoFile = "{$current->getPath()}/.{$current->getFilename()}.OCR.info";
            $processedPdf = "{$current->getPath()}/{$current->getBasename('.pdf')}.OCR.pdf";

            if(!file_exists($processedPdf) && !file_exists($ocrInfoFile)){
                return true;
            }
            else{
                $logger->addInfo("Skipping file {$current->getFilename()}, OCR already done");
                return false;
            }
        }
    });

    $iterator = new \RecursiveIteratorIterator($filter);
    foreach ($iterator as $fileObject) {
        $filesize = number_format($fileObject->getSize());
        $logger->addInfo("Starting OCR for file {$fileObject->getFilename()}. Filesize: {$filesize}");
        chmod($fileObject->getPathname(),0666);
        $ocrFilename = "{$fileObject->getPath()}/{$fileObject->getBasename('.pdf')}.OCR.pdf";
        $ocrInfoFile = "{$fileObject->getPath()}/.{$fileObject->getFilename()}.OCR.info";
        $escapedOcrFilename = escapeshellarg($ocrFilename);
        $filename = escapeshellarg($fileObject->getPathname());
        $command = "ocrmypdf -s --jobs 4 {$filename} {$escapedOcrFilename}";
        exec($command);
        if (file_exists($ocrFilename)){
            $logger->addInfo("OCR is done for file {$fileObject->getPathname()}. \nSee {$ocrFilename}");
        }
        else{
            $logger->addInfo("File {$fileObject->getFilename()} dont need OCR");
            file_put_contents($ocrInfoFile, 'OCR not needed');
        }
    }
}

$logger = new Logger('scrape-process');
//$handler = new StreamHandler(__DIR__.'/ocr.log', Logger::INFO);
$handler = new \Monolog\Handler\ErrorLogHandler();
$logFormat = "[%datetime%] %message%\n";
$formatter = new \Monolog\Formatter\LineFormatter($logFormat, null, false, true);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

$logger->addInfo("OCR is started");

ocrPdfInFolder(__DIR__."/tenders/", $logger);