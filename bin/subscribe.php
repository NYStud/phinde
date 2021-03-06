#!/usr/bin/env php
<?php
namespace phinde;
/**
 * Subscribe with PubSubHubbub to an URL
 */
require_once __DIR__ . '/../src/init.php';

$cc = new \Console_CommandLine();
$cc->description = 'Subscribe to URL updates';
$cc->version = '0.0.1';
$cc->addArgument(
    'url',
    array(
        'description' => 'URL to process',
        'multiple'    => false
    )
);
try {
    $res = $cc->parse();
} catch (\Exception $e) {
    $cc->displayError($e->getMessage());
}

$subDb = new Subscriptions();

$url = $res->args['url'];
$url = Helper::addSchema($url);
$urlObj = new \Net_URL2($url);
$url = $urlObj->getNormalizedURL();
if (!Helper::isUrlAllowed($url)) {
    Log::error("Domain is not allowed; not subscribing");
    $subDb->remove($url);
    exit(2);
}

list($topic, $hub) = $subDb->detectHub($url);
if ($hub === null) {
    Log::error('No hub URL found for topic');
    exit(10);
}
if ($topic != $url) {
    Log::info('Topic URL differs from URL: ' . $topic);
}

$sub = $subDb->get($topic);
if ($sub === false) {
    $subDb->create($topic, $hub);
} else {
    Log::info(
        'Topic exists already in subscription table with status '
        . $sub->sub_status
    );
    Log::info('Renewing subscription...');
    $subDb->renew($sub->sub_id);
    $hub = $sub->sub_hub;
}
$sub = $subDb->get($topic);

$callbackUrl = $GLOBALS['phinde']['baseurl'] . 'push-subscription.php'
    . '?hub.topic=' . urlencode($topic)
    . '&capkey=' . urlencode($sub->sub_capkey);
$req = new HttpRequest($hub, 'POST');
$req->addPostParameter('hub.callback', $callbackUrl);
$req->addPostParameter('hub.mode', 'subscribe');
$req->addPostParameter('hub.topic', $topic);
$req->addPostParameter('hub.lease_seconds', $sub->sub_lease_seconds);
$req->addPostParameter('hub.secret', $sub->sub_secret);
$res = $req->send();

if (intval($res->getStatus()) == 202) {
    Log::info('Subscription initiated');
    exit(0);
}

Log::error(
    'Error: Subscription response status code was not 202 but '
    . $res->getStatus()
);
Log::error($res->getBody());
?>
