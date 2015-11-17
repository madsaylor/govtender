<?php

require_once __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Purl\Url;

// create a log channel
$logger = new Logger('scrape-process');
//$logger->pushHandler(new StreamHandler(__DIR__.'/scrape.log', Logger::INFO));
$logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

$feedFilename = __DIR__.'/tenderfeed.xml';

$client = new Client();

if (!file_exists($feedFilename)){
    $response = $client->request('GET', 'https://www.marches-publics.gouv.fr/rssTr.xml',
        ['sink' => $feedFilename]);
    if ($response->getStatusCode() == 200){
        $logger->addInfo("File {$feedFilename} is downloaded");
    }
}
else{
    $logger->addInfo("Using existing file {$feedFilename}");
}

$xml = new SimpleXMLElement(file_get_contents($feedFilename));
$tenderList = $xml->xpath('//channel/item');
$pubDateElements = $xml->xpath('//channel/pubDate');
$pubDate = new DateTime($pubDateElements[0]->__toString());

$logger->addInfo("File published at {$pubDate->format('Y-m-d H:i:s')} and contains ".count($tenderList)." tenders");

$loginUrl = 'https://www.marches-publics.gouv.fr/index.php?page=entreprise.EntrepriseHome&goto=&lang=en';

$client = new Client(['cookies' => true]);
$response = $client->request('GET', $loginUrl);

$loginPage = new DOMDocument();
$loginPage->loadHTML($response->getBody());
$xpath = new DOMXPath($loginPage);
$nodeList = $xpath->query('//form[@id="ctl0_ctl1"]//input');
$formFields = [];
foreach ($nodeList as $node){
    $formFields[$node->getAttribute('name')] = $node->getAttribute('value');
}

$formFields['PRADO_POSTBACK_TARGET'] = 'ctl0$CONTENU_PAGE$authentificationButton';
$formFields['ctl0$CONTENU_PAGE$login'] = 'lebowski';
$formFields['ctl0$CONTENU_PAGE$password'] = 'PLvjWnSUl5';
$formFields['ctl0$CONTENU_PAGE$authentificationButton_x'] = 14;
$formFields['ctl0$CONTENU_PAGE$authentificationButton_y'] = 0;

$response = $client->request('POST', $loginUrl, ['form_params' => $formFields]);

foreach($tenderList as $tender){
    $detailsUrl = new Url($tender->link);
    $detailsUrl->query->set('page', 'entreprise.EntrepriseDetailConsultation');
    $response = $client->request('GET', $detailsUrl);

    $tenderPage = new DOMDocument();
    $tenderPage->loadHTML($response->getBody());
    $xpath = new DOMXPath($tenderPage);
    $xpathQuery = '//li[not(contains(@style,"display:none"))]//a[contains(@id,"ctl0_CONTENU_PAGE_linkDownload")]';
    $result = $xpath->query($xpathQuery);
//    $logger->addInfo("{$detailsUrl} {$result->length}");
    if ($result->length > 0){
        foreach($result as $aElement){
            $url = new Url($aElement->getAttribute('href'));
            $baseUrl = clone $detailsUrl;
            $baseUrl->setQuery('');
            $url->join($baseUrl);
            print_r($url);
            if($url->query->get('page') == 'entreprise.EntrepriseDownloadReglement'){
                $response = $client->request('GET', $url);
                print_r($response);
                break;
            }
        }
    }
}


