<?php

require_once __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// create a log channel
$logger = new Logger('scrape-process');
$logger->pushHandler(new StreamHandler(__DIR__.'/scrape.log', Logger::INFO));

$feedFilename = __DIR__.'/tenderfeed.xml';

$client = new GuzzleHttp\Client();

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

//$loginUrl = 'https://www.marches-publics.gouv.fr/index.php?page=entreprise.EntrepriseHome&goto=&lang=en';
//$response = $client->request('GET', $loginUrl);
//
//$loginPage = new DOMDocument();
//$loginPage->loadHTML($response->getBody());
//$xpath = new DOMXPath($loginPage);
//
//$inputList = $xpath->query('//form[@id="ctl0_ctl1"]//input');
//$logger->addInfo("File published at {$pubDate->item(0)} and contains ".count($tenderList)." tenders");
//
//$loginUrl = 'https://www.marches-publics.gouv.fr/index.php?page=entreprise.EntrepriseHome&goto=&lang=en';
//$response = $client->request('GET', $loginUrl);
//
//$loginPage = new DOMDocument();
//$loginPage->loadHTML($response->getBody());
//$xpath = new DOMXPath($loginPage);
//
//$inputList = $xpath->query('//form[@id="ctl0_ctl1"]//input');

//foreach ($inputList->item(0) as $input){
//    echo $input->attributes['name'];
//}


//foreach($tenderList as $tender){
//    $url = $tender->link;
//    $response = $client->request('GET', $url);
//}
