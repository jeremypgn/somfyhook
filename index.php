<?php

require 'vendor/autoload.php';

header('Content-Type: application/json');

$redis = new Redis();
$redis->connect('127.0.0.1');

require 'config.dist.php';

$client = new \GuzzleHttp\Client([
    'base_uri' => 'https://sso.myfox.io/oauth/oauth/v2/',
    'timeout' => 2.0,
    'http_errors' => false
]);

if ($redis->get('myfox_access_token') && time() + $redis->get('myfox_expires_in') < time()) {
    // Renew access token
    $response = $client->post('token', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'form_params' => [
            'grant_type' => 'refresh_token',
            'client_id' => MYFOX_CLIENT_ID,
            'client_secret' => MYFOX_CLIENT_SECRET,
            'refresh_token' => $redis->get('myfox_refresh_token')
        ]
    ]);

    if ($response->getStatusCode() != 200) {
        echo json_encode(['status' => 'error', 'message' => 'Error renewing access token']);
        die;
    }

    $data = json_decode($response->getBody()->getContents());

    $redis->set('myfox_access_token', $data->access_token);
    $redis->set('myfox_refresh_token', $data->refresh_token);
    $redis->set('myfox_expires_in', $data->expires_in);
} else {
    // Get access token
    $response = $client->post('token', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'form_params' => [
            'grant_type' => 'password',
            'client_id' => MYFOX_CLIENT_ID,
            'client_secret' => MYFOX_CLIENT_SECRET,
            'username' => MYFOX_USERNAME,
            'password' => MYFOX_PASSWORD
        ]
    ]);

    if ($response->getStatusCode() != 200) {
        echo json_encode(['status' => 'error', 'message' => 'Error getting access token']);
        die;
    }

    $data = json_decode($response->getBody()->getContents());

    $redis->set('myfox_access_token', $data->access_token);
    $redis->set('myfox_refresh_token', $data->refresh_token);
    $redis->set('myfox_expires_in', $data->expires_in);
}

$client = new \GuzzleHttp\Client([
    'base_uri' => 'https://api.myfox.io/v3/',
    'headers' => [
        'Authorization' => 'Bearer ' . $redis->get('myfox_access_token')
    ],
    'timeout' => 2.0,
    'http_errors' => false
]);

if (!isset($_GET['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'No action specified']);
    die;
}

switch ($_GET['action']) {
    case "getSites":
        $request = $client->get('site');
        if ($request->getStatusCode() != 200) {
            echo json_encode(['status' => 'error', 'message' => 'Error getting sites']);
            die;
        }
        echo $request->getBody()->getContents();
        die;
    case "changeSecurity":
        $siteId = isset($_GET['site_id']) ? $_GET['site_id'] : MYFOX_SITE_ID;
        if (!isset($_GET['mode']) || !in_array($_GET['mode'], ['disarmed', 'armed', 'partial'])) {
            echo json_encode(['status' => 'error', 'message' => 'No mode specified, accepted: disarmed, armed, partial']);
            die;
        }
        $request = $client->put('site/' . $siteId . '/security', [
            'json' => [
                'status' => $_GET['mode']
            ]
        ]);
        if ($request->getStatusCode() != 200) {
            echo json_encode(['status' => 'error', 'message' => 'Error changing security mode']);
            die;
        }

        echo json_encode(['status' => 'success', 'message' => 'Security mode changed']);
        die;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        die;
}

