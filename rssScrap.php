<?php

use GuzzleHttp\Client;

$feedFilename = 'tenderfeed.xml';
if (!file_exists($feedFilename)){
    $client = new GuzzleHttp\Client();
    $response = $client->request('GET', 'https://www.marches-publics.gouv.fr/rssTr.xml', ['sink' => $feedFilename]);
}

$xml = new SimpleXMLElement(file_get_contents($feedFilename));
$tenderList = $xml->xpath('//channel/item');

$loginUrl = 'https://www.marches-publics.gouv.fr/index.php?page=entreprise.EntrepriseHome&goto=&lang=en';
$response = $client->request('GET', $loginUrl);

$loginPage = new DOMDocument();
$loginPage->loadHTML($response->body);

//foreach($tenderList as $tender){
//    $url = $tender->link;
//    $response = $client->request('GET', $url);
//}
