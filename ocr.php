<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

function ocrPdfInFolder($folderPath, $logger){
    $directory = new \RecursiveDirectoryIterator($folderPath, \FilesystemIterator::FOLLOW_SYMLINKS);
    $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) {
        if ($iterator->hasChildren()){
            return true;
        }
        return strtolower($current->getExtension()) == 'pdf';
    });

    $iterator = new \RecursiveIteratorIterator($filter);
    foreach ($iterator as $fileObject) {
        chmod($fileObject->getPathname(),0666);
        $ocrFilename = "{$fileObject->getPath()}/{$fileObject->getBasename('.pdf')}.OCR.pdf";
        $escapedOcrFilename = escapeshellarg($ocrFilename);
        $filename = escapeshellarg($fileObject->getPathname());
        $command = "ocrmypdf -s --jobs 4 {$filename} {$escapedOcrFilename}";
        exec($command);
        if (file_exists($ocrFilename)){
            $logger->addInfo("OCR is done for file {$fileObject->getPathname()}. \nSee {$ocrFilename}");
        }
        else{
            $logger->addInfo("File {$fileObject->getFilename()} dont need OCR");
        }
    }
}

$logger = new Logger('scrape-process');
$handler = new StreamHandler(__DIR__.'/ocr.log', Logger::INFO);
$logFormat = "[%datetime%] %message%\n";
$formatter = new \Monolog\Formatter\LineFormatter($logFormat, null, false, true);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

ocrPdfInFolder(__DIR__."/tenders/", $logger);