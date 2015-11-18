<?php

require_once __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Purl\Url;
use Psr\Http\Message\ResponseInterface;

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

$xpath = getXpathFromUrl($client, $loginUrl);

$formFields = getFormFields($xpath, 'ctl0_ctl1');

$formFields['PRADO_POSTBACK_TARGET'] = 'ctl0$CONTENU_PAGE$authentificationButton';
$formFields['ctl0$CONTENU_PAGE$login'] = 'lebowski';
$formFields['ctl0$CONTENU_PAGE$password'] = 'PLvjWnSUl5';
$formFields['ctl0$CONTENU_PAGE$authentificationButton_x'] = 14;
$formFields['ctl0$CONTENU_PAGE$authentificationButton_y'] = 0;

$response = $client->request('POST', $loginUrl, ['form_params' => $formFields]);
$tenderRootDir = __DIR__.'/tenders';
mkdir($tenderRootDir);

foreach($tenderList as $tender){
    $detailsUrl = new Url($tender->link);
    $detailsUrl->query->set('page', 'entreprise.EntrepriseDetailConsultation');
    $xpath = getXpathFromUrl($client, $detailsUrl);
    $xpathQuery = '//li[not(contains(@style,"display:none"))]//a[contains(@id,"ctl0_CONTENU_PAGE_linkDownload")]';
    $result = $xpath->query($xpathQuery);
    $logger->addInfo("Tender url {$tender->link}");

    if ($result->length > 0) {
        $tenderDir = $tenderRootDir . "/{$tender->guid}";
        mkdir($tenderDir);
        $logger->addInfo("{$result->length} files found on tender {$tender->guid}");
        foreach ($result as $aElement) {
            $url = new Url($aElement->getAttribute('href'));
            $baseUrl = clone $detailsUrl;
            $baseUrl->query->setData([]);
            $url->join($baseUrl);
            $logger->addInfo("{$url->query->get('page')}");
            if ($url->query->get('page') == 'entreprise.EntrepriseDownloadReglement') {
                $tenderRulesDir = $tenderDir . "/tender_rules";
                mkdir($tenderRulesDir);

                $filename = 'tenderRules.file';
                //getting filename with preliminary HEAD request
                $response = $client->head($url);
                $header = utf8_encode(urldecode($response->getHeaderLine('Content-Disposition')));
                $result = preg_match('/filename="(.*)"/u', $header, $matches);
                if ($result > 0) {
                    $filename = $matches[1];
                }
                $response = $client->request('GET', $url, ['sink' => $tenderRulesDir . "/{$filename}"]);
                if ($response->getStatusCode() == 200) {
                    $logger->addInfo("{$filename} downloaded");
                }
            }
            elseif ($url->query->get('page') == 'entreprise.EntrepriseDemandeTelechargementDce') {
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
                $response = $client->request('POST', $url, [
                    'form_params' => $formFields,
                    'sink' => "{$tenderDocsDir}/docs.zip"
                ]);
            }
        }
    } else {
        $logger->addInfo("No files on tender {$tender->guid}");
    }
}


