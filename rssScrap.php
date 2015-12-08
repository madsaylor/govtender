<?php

require_once __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Purl\Url;

date_default_timezone_set('Europe/Paris');
setlocale(LC_ALL, 'en_US.UTF-8');

function getXpathFromUrl($client, $url){
    $response = $client->request('GET', $url);
    $page = new DOMDocument();
    $page->loadHTML($response->getBody());
    return new DOMXPath($page);
}

function getXpathFromHtmlBody($body){
    $page = new DOMDocument();
    $page->loadHTML($body);
    return new DOMXPath($page);
}

function getFormFields($xpath, $formId){
    $nodeList = $xpath->query("//form[@id=\"{$formId}\"]//input");
    $formFields = [];
    foreach ($nodeList as $node){
        $formFields[$node->getAttribute('name')] = $node->getAttribute('value');
    }
    return $formFields;
}

function getFilenameFromResponse($response){
    $header = utf8_encode(urldecode($response->getHeaderLine('Content-Disposition')));
    $result = preg_match('/filename="(.*)"/u', $header, $matches);
    $filename = false;
    if ($result > 0) {
        $filename = $matches[1];
    }
    return $filename;
}

function extractZip($filename, $destination, $logger){
    $zip = new ZipArchive;
    if ($zip->open($filename) === TRUE){
        $pathinfo = pathinfo($filename);
        $finalDestination = "{$destination}/{$pathinfo['filename']}/";
        mkdir($finalDestination);

        $zip->extractTo($finalDestination);
        $zip->close();
        $filesNumber = count(scandir($finalDestination));
        unlink($filename);

        $directory = new \RecursiveDirectoryIterator($destination, \FilesystemIterator::FOLLOW_SYMLINKS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function ($current, $key, $iterator) {
            if ($iterator->hasChildren()){
                return true;
            }
            return strtolower($current->getExtension()) == 'zip';
        });

        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $fileObject) {
            extractZip($fileObject->getPathname(), $fileObject->getPath(), $logger);
        }

        $logger->addInfo("{$filesNumber} files from {$filename} extracted");
        return "Recurcive zip extraction complete";
    }
    else {
        return "Archive is not valid";
    }
}

function run()
{
//for running within Phar file
    if (!empty(Phar::running(false))) {
        $pathArr = explode('/', Phar::running(false));
        array_pop($pathArr);
        $currentDir = implode('/', $pathArr);
    } else {
        $currentDir = __DIR__;
    }

// create a log channel
    $logger = new Logger('scrape-process');
    $handler = new StreamHandler($currentDir.'/govtender.log', Logger::INFO);
    $logFormat = "[%datetime%] %message%\n";
    $formatter = new \Monolog\Formatter\LineFormatter($logFormat, null, false, true);
    $handler->setFormatter($formatter);
    $logger->pushHandler($handler);
//    $memusage = function(){        
//        return number_format(memory_get_usage(true));
//    };

    $feedFilename = $currentDir . '/tenderfeed.xml';

    $client = new Client();

//    if (!file_exists($feedFilename)) {
    $i = 0;
    $maxRetries = 5;
    $response = null;
    while ($i < $maxRetries) {
        try {
            $response = $client->request('GET', 'https://www.marches-publics.gouv.fr/rssTr.xml',
                ['sink' => $feedFilename]);
            break;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            unlink($feedFilename);
            $currentRetry = $i + 1;
            $logger->addInfo("Download error: {$e->getMessage()}. {$currentRetry} retries of {$maxRetries}");
            $i++;
        }
    }

    if ($response && $response->getStatusCode() == 200) {
        $logger->addInfo("File {$feedFilename} is downloaded");
    } else {
        $logger->addInfo("Feed download failed");
        die();
    }

    $xml = new SimpleXMLElement(file_get_contents($feedFilename));
    $tenderList = $xml->xpath('//channel/item');
    $pubDateElements = $xml->xpath('//channel/pubDate');
    $pubDate = new DateTime($pubDateElements[0]->__toString());

    $logger->addInfo("File published at {$pubDate->format('Y-m-d H:i:s')} and contains " .
        count($tenderList) . " tenders");

    $loginUrl = 'https://www.marches-publics.gouv.fr/index.php?page=entreprise.EntrepriseHome&goto=&lang=en';

    $client = new Client(['cookies' => true]);

    $xpath = getXpathFromUrl($client, $loginUrl);

    $formFields = getFormFields($xpath, 'ctl0_ctl1');

    $formFields['PRADO_POSTBACK_TARGET'] = 'ctl0$CONTENU_PAGE$authentificationButton';
    $formFields['ctl0$CONTENU_PAGE$login'] = 'lebowski';
    $formFields['ctl0$CONTENU_PAGE$password'] = 'PLvjWnSUl5';
    $formFields['ctl0$CONTENU_PAGE$authentificationButton_x'] = 14;
    $formFields['ctl0$CONTENU_PAGE$authentificationButton_y'] = 0;

    $response = $client->request('POST', $loginUrl, ['form_params' => $formFields]);
    $tenderRootDir = $currentDir . '/tenders';
    if (!file_exists($tenderRootDir)) {
        mkdir($tenderRootDir);
    }

    foreach ($tenderList as $tender) {
        $tenderDir = $tenderRootDir . "/{$tender->guid}";
        $pubDateFilePath = "{$tenderDir}/.tender_pub_date";
        if (file_exists($tenderDir) && file_exists($pubDateFilePath)) {
            $pubDate = file_get_contents($pubDateFilePath);
            if ($pubDate != $tender->pubDate) {
                file_put_contents($pubDateFilePath, $tender->pubDate);
                $logger->addInfo("Updating tender {$tender->guid}, new pubDate");
            } else {
                $logger->addInfo("Skipping tender {$tender->guid}, already processed");
                continue;
            }
        } else {
            mkdir($tenderDir);
            file_put_contents($pubDateFilePath, $tender->pubDate);
        }

        $detailsUrl = new Url($tender->link);
        $detailsUrl->query->set('page', 'entreprise.EntrepriseDetailsConsultation');
        $detailsUrl->query->remove('AllCons');

        $detailsXpath = getXpathFromUrl($client, $detailsUrl);
        $xpathQuery = '//li[not(contains(@style,"display:none"))]//a[contains(@id,"ctl0_CONTENU_PAGE_linkDownload")]';
        $result = $detailsXpath->query($xpathQuery);
        $logger->addInfo("Tender url {$tender->link}");

        $tenderDir = $tenderRootDir . "/{$tender->guid}";
        if ($result->length > 0) {
            $logger->addInfo("{$result->length} files found on tender {$tender->guid}");
            foreach ($result as $aElement) {
                if (strpos($aElement->getAttribute('href'), 'javascript:') !== false) {
                    $url = false;
                } else {
                    $url = new Url($aElement->getAttribute('href'));
                }

                if (!$url) {
                    $formFields = getFormFields($detailsXpath, 'ctl0_ctl1');
                    $formFields['PRADO_POSTBACK_TARGET'] = 'ctl0$CONTENU_PAGE$linkDownloadComplement';
                    $formFields['ongletActive'] = '1';

                    foreach ($formFields as $key => $value) {
                        if (!in_array($key, ['PRADO_PAGESTATE', 'PRADO_POSTBACK_TARGET'])) {
                            unset($formFields[$key]);
                        }
                    }

                    $tenderMoreInfoDir = $tenderDir . "/tender_more_info";
                    mkdir($tenderMoreInfoDir);

                    $filename = 'file.extension';
                    $tenderMoreInfoAbsPath = "$tenderMoreInfoDir/$filename";

                    $i = 0;
                    $maxRetries = 5;
                    $response = null;
                    while ($i < $maxRetries) {
                        try {
                            $response = $client->request('POST', $detailsUrl, [
                                'form_params' => $formFields,
                                'sink' => $tenderMoreInfoAbsPath
                            ]);
                            $filename = getFilenameFromResponse($response);
                            $path = "{$tenderMoreInfoDir}/{$filename}";
                            rename($tenderMoreInfoAbsPath, $path);
                            $tenderMoreInfoAbsPath = $path;
                            break;
                        } catch (\GuzzleHttp\Exception\RequestException $e) {
                            unlink($tenderMoreInfoAbsPath);
                            $currentRetry = $i + 1;
                            $logger->addInfo("Download error: {$e->getMessage()}. {$currentRetry} retries of {$maxRetries}");
                            $i++;
                        }
                    }

                    if ($response && $response->getStatusCode() == 200) {
                        $logger->addInfo("{$filename} downloaded");
                    } else {
                        $logger->addInfo("Download of {$filename} failed");
                    }

                    $pathInfo = pathinfo($tenderMoreInfoAbsPath);
                    if (isset($pathInfo['extension']) && $pathInfo['extension'] == 'zip') {
                        $message = extractZip($tenderMoreInfoAbsPath, $tenderMoreInfoDir, $logger);
                        $logger->addInfo($message);
                    }
                } elseif ($url->query->get('page') == 'entreprise.EntrepriseDownloadReglement') {
                    $baseUrl = new Url($detailsUrl);
                    $baseUrl->query->setData([]);
                    $url->join($baseUrl);

                    $tenderRulesDir = $tenderDir . "/tender_rules";
                    mkdir($tenderRulesDir);

                    //getting filename with preliminary HEAD request
                    $response = $client->head($url);
                    $filename = getFilenameFromResponse($response) ? getFilenameFromResponse($response) : 'tenderRules.file';

                    $tenderRulesAbsolutePath = $tenderRulesDir . "/{$filename}";

                    $i = 0;
                    $maxRetries = 5;
                    $response = null;
                    while ($i < $maxRetries) {
                        try {
                            $response = $client->request('GET', $url, ['sink' => $tenderRulesAbsolutePath]);
                            break;
                        } catch (\GuzzleHttp\Exception\RequestException $e) {
                            unlink($tenderRulesAbsolutePath);
                            $currentRetry = $i + 1;
                            $logger->addInfo("Download error: {$e->getMessage()}. {$currentRetry} retries of {$maxRetries}");
                            $i++;
                        }
                    }

                    if ($response && $response->getStatusCode() == 200) {
                        $logger->addInfo("{$filename} downloaded");
                    } else {
                        $logger->addInfo("Download of {$filename} failed");
                    }

                    $pathInfo = pathinfo($tenderRulesAbsolutePath);
                    if ($pathInfo['extension'] == 'zip') {
                        $message = extractZip($tenderRulesAbsolutePath, $tenderRulesDir, $logger);
                        $logger->addInfo($message);
                    }
                } elseif ($url->query->get('page') == 'entreprise.EntrepriseDemandeTelechargementDce') {
                    $baseUrl = new Url($detailsUrl);
                    $baseUrl->query->setData([]);
                    $url->join($baseUrl);

                    $tenderDocsDir = $tenderDir . "/tender_documents";
                    mkdir($tenderDocsDir);

                    $xpath = getXpathFromUrl($client, $url);
                    $formFields = getFormFields($xpath, 'ctl0_ctl1');
                    $formFields['ctl0$CONTENU_PAGE$EntrepriseFormulaireDemande$accepterConditions'] = 'on';
                    $formFields['ctl0$CONTENU_PAGE$EntrepriseFormulaireDemande$RadioGroup'] = 'ctl0$CONTENU_PAGE$EntrepriseFormulaireDemande$choixTelechargement';
                    $formFields['PRADO_POSTBACK_TARGET'] = 'ctl0$CONTENU_PAGE$validateButton';
                    $response = $client->request('POST', $url, ['form_params' => $formFields]);

                    $xpath = getXpathFromHtmlBody($response->getBody());
                    $formFields = getFormFields($xpath, 'ctl0_ctl1');
                    $formFields['PRADO_POSTBACK_TARGET'] = 'ctl0$CONTENU_PAGE$EntrepriseDownloadDce$completeDownload';

                    $archiveNameAbsPath = "{$tenderDocsDir}/docs.zip";

                    $i = 0;
                    $maxRetries = 5;
                    $response = null;
                    while ($i < $maxRetries) {
                        try {
                            $response = $client->request('POST', $url, [
                                'form_params' => $formFields,
                                'sink' => $archiveNameAbsPath
                            ]);
                            $filename = getFilenameFromResponse($response);
                            $path = "{$tenderDocsDir}/{$filename}";
                            rename($archiveNameAbsPath, $path);
                            $archiveNameAbsPath = $path;
                            break;
                        } catch (\GuzzleHttp\Exception\RequestException $e) {
                            unlink($archiveNameAbsPath);
                            $currentRetry = $i + 1;
                            $logger->addInfo("Download error: {$e->getMessage()}. {$currentRetry} retries of {$maxRetries}");
                            $i++;
                        }
                    }

                    if ($response && $response->getStatusCode() == 200) {
                        $logger->addInfo("{$filename} downloaded");
                    } else {
                        $logger->addInfo("Download of {$filename} failed");
                    }

                    $pathInfo = pathinfo($archiveNameAbsPath);
                    if ($pathInfo['extension'] == 'zip') {
                        $message = extractZip($archiveNameAbsPath, $tenderDocsDir, $logger);
                        $logger->addInfo($message);
                    }
                }
            }
        } else {
            $logger->addInfo("No files on tender {$tender->guid}");
        }
    }
}

while (true){
    run();
    sleep(60*15);
}