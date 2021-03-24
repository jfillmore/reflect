<?php

// spill our guts to the world if something isn't right
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
xdebug_disable();  // too much noise by default


function getHeader($name, $default = null) {
    $serverKey = str_replace('-', '_', strtoupper($name));
    if (isset($_SERVER["HTTP_$serverKey"])) {
        return $_SERVER["HTTP_$serverKey"];
    }
    return $default;
}


function cleanTrace($stackTrace) {
    $stack = array();
    // hide internal frames
    array_pop($stackTrace);
    foreach ($stackTrace as $frame) {
        $line = '';
        if (isset($frame['file'])) {
            $line .= $frame['file'] . ' ';
        }
        if (isset($frame['line'])) {
            $line .= $frame['line'] . ' ';
        }
        if (isset($frame['class'])) {
            $line .= ' ' . $frame['class'] . $frame['type'];
        }
        $line .= $frame['function'] . '(';
        if (count($frame['args'])) {
            // don't return the actual args, but the types are nice
            $args = array();
            foreach ($frame['args'] as $arg) {
                $args[] = gettype($arg);
            }
            $line .= join(', ', $args);
        }
        $line .= ')';
        $stack[] = $line;
    }
    return $stack;
}

function fail($ex, $spillage = null) {
    $resp = array(
        'success' => false,
        'error' => $ex->getMessage()
    );
    if ($spillage !== null) {
        $resp['spillage'] = $spillage;
    }
    $resp['data'] = array(
        'backtrace' => cleanTrace($ex->getTrace())
    );
    echo json_encode($resp, JSON_PRETTY_PRINT);
    exit(1);
}


class HttpReflect {
    private $body = null;
    private $meta = [];

    public function __construct() {
        $this->body = stream_get_contents(fopen('php://input', 'r'));
        // TODO: use sorted copies
        ksort($_ENV);
        ksort($_GET);
        ksort($_POST);
        ksort($_REQUEST);
        ksort($_SERVER);
    }

    public function getStatusCode($default) {
        return getHeader('x-refl-status', $default);
    }

    public function sendSessionHeaders() {
        global $SESSION_STATUS;
        // e.g. a "slug", optionally preceeded with +- to customize behavior
        $sessionId = getHeader('x-refl-session', 'default');
        // assume new session by default
        if (!preg_match('/^\w/', $sessionId)) {
            $keyOp = substr($sessionId, 0, 1);
            $sessionId = substr($sessionId, 1);
        } else {
            $keyOp = '+';
        }
        session_name('session');
        session_id($sessionId);
        session_start();

        if ($keyOp == '-') {
            $sessionStatus = session_status();
            if (isset($_SESSION['id'])) {
                session_destroy();
                $this->meta['session'] = 'destroyed';
            } else {
                $this->meta['session'] = 'did not exist';
            }
        } else if ($keyOp == '?') {
            // just avoid doing anything
        } else if ($keyOp == '+') {
            $_SESSION['id'] = $sessionId;
            if (!isset($_SESSION['started_at'])) {
               $_SESSION['started_at'] = time();
            }
            if (!isset($_SESSION['counter'])) {
               $_SESSION['counter'] = 1;
            } else {
               $_SESSION['counter'] += 1;
            }
        } else {
            throw new Exception("Invalid session key: $sessionId");
        }
    }

    private function getRequestHeaders() {
        $headers = [];
        foreach ($_SERVER as $key => $val) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $nameParts = explode('_', substr($key, 5));
                // CGI QQ
                $headers[strtolower(implode('-', $nameParts))] = $val;
            }
        }
        return $headers;
    }

    public function processRequest() {
        // misc schenanigans
        $delaySecs = floatval(getHeader('x-refl-delay', 0));
        if ($delaySecs > 0) {
            $this->meta['delay'] = $delaySecs;
            usleep(intval($delaySecs * 1000000));
        }
    }

    public function getReflection($startTime) {
        $defaultData = [
            'cookies' => $_COOKIE,
            'files' => $_FILES,
            'meta' => $this->meta,
            'session' => $_SESSION,
            'headers' => $this->getRequestHeaders(),
            'body' => $this->body
        ];
        $resp = [];
        foreach ($defaultData as $key => $val) {
            if (!empty($val)) {
                $resp[$key] = $val;
            }
        }
        $resp['request'] = [
            'src_addr' => $_SERVER['REMOTE_ADDR'],
            'src_port' => $_SERVER['REMOTE_PORT'],
            'method' => $_SERVER['REQUEST_METHOD'],
            'url' => "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
            'duration_sec' => floatval(preg_replace(
                '/^(\d+\.[0]+[^0]).*/',
                '\1',
                microtime(true) - $startTime,
            ))
        ];
        return $resp;
    }
}


// == HEADERS =================================================================
if ($_SERVER['HTTP_ACCEPT'] == 'application/json') {
    header('Content-Type: application/json');
} else {
    header('Content-Type: text/plain');
}
ob_start();


// == EXTRA HEADERS + BODY ====================================================
$error = null;
$startTime = microtime(true);
try {
    $ref = new HttpReflect();
    http_response_code($ref->getStatusCode(200));
    $ref->sendSessionHeaders();
    $ref->processRequest();
    $response = $ref->getReflection($startTime);
} catch (Exception $e) {
    $error = $e;
}

$spillage = ob_get_contents();
ob_end_clean();
if ($spillage || $error) {
    if (!$error) {
        $error = new Exception('API Spillage');
    }
    fail($error, $spillage);
}

echo json_encode($response, JSON_PRETTY_PRINT);
