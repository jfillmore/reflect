<?php

function dump($data) {
    echo "<pre>";
    echo print_r($data, true);
    echo "</pre>";
}

$body = stream_get_contents(fopen('php://input', 'r'));
if ($_SERVER['HTTP_ACCEPT'] == 'application/json') {
    $data = [
        'COOKIE' => $_COOKIE,
        'ENV' => $_ENV,
        'FILES' => $_FILES,
        'GET' => $_GET,
        'POST' => $_POST,
        'REQUEST' => $_REQUEST,
        'SERVER' => $_SERVER,
    ];
    if (isset($_SESSION)) {
        $data['SESSION'] = $_SESSION;
    }
    if ($body) {
        $data['body'] = $body;
    }
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);

} else {
    echo "<html><body><pre>";

    echo '<h2>$_GET</h2>';
    dump($_GET);

    echo '<h2>$_POST</h2>';
    dump($_POST);

    echo '<h2>$_SERVER</h2>';
    dump($_SERVER);

    echo '<h2>$_FILES</h2>';
    dump($_FILES);

    echo '<h2>$_COOKIE</h2>';
    dump($_COOKIE);

    echo '<h2>$_REQUEST</h2>';
    dump($_REQUEST);

    echo '<h2>$_ENV</h2>';
    dump($_ENV);

    if (isset($_SESSION)) {
        echo '<h2>$_SESSION</h2>';
        dump($_SESSION);
    }

    if ($body) {
        echo '<h2>Request Body<h2>';
        echo "<pre>$body</pre>";
    }

    echo "</pre></body></html";
}
