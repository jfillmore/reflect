<?php

// spill our guts to the world if something isn't right
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
// allow caller to customize the response content type
ini_set('default_charset', '');
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

    private function decodedBody() {
        $contentType = getHeader('content-type', '');
        $ctJson = 'application/json';
        $ctForm = 'application/x-www-form-urlencoded';
        if (substr($contentType, 0, strlen($ctJson)) == $ctJson) {
            return json_decode($this->body);
        }
        if (substr($contentType, 0, strlen($ctForm)) == $ctForm) {
            return urldecode($this->body);
        }
        return null;
    }

    public function getStatusCode($default) {
        return getHeader('x-refl-status', $default);
    }

    public function sendSessionHeaders() {
        global $SESSION_STATUS;
        // e.g. a "slug", optionally preceeded with +- to customize behavior
        $defSessionId = 'default';
        $sessionId = getHeader('x-refl-session', $defSessionId);
        // assume new session by default
        if (preg_match('/^\w/', $sessionId)) {
            $keyOp = '+';
        } else {
            $keyOp = substr($sessionId, 0, 1);
            $sessionId = substr($sessionId, 1);
            if (!$sessionId) {
                $sessionId = $defSessionId;
            }
        }
        session_name('session');
        session_id($sessionId);
        session_start();

        $this->meta['session'] = 'continue';
        $this->meta['session_op'] = $keyOp;
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
        $this->meta['delay'] = $delaySecs;
        if ($delaySecs > 0) {
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
            'body' => [
                'raw' => $this->body,
                'decoded' => $this->decodedBody(),
            ],
        ];
        $resp = [];
        foreach ($defaultData as $key => $val) {
            if (!empty($val)) {
                $resp[$key] = $val;
            }
        }
        $padding = getHeader('x-refl-padding');
        if (!empty($padding)) {
            $units =  ['B', 'K', 'M'];
            $unit = substr($padding, -1);
            if (is_numeric($unit)) {
                $scale = 1;
            } else {
                $unit = strtoupper($unit);
                if (!in_array($unit, $units)) {
                    throw new Exception("Unrecognized unit: $unit; try $units");
                }
                $padding = substr($padding, 0, strlen($padding) - 1);
                $scale = 1000 ** array_search($unit, $units);
            }
            $resp['padding'] = str_repeat('.', $padding * $scale);
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
$encoding = getHeader('x-refl-charset', 'UTF-8');
$charset = '';
if ($encoding) {
    $charset = ";charset=$encoding";
}
if ($_SERVER['HTTP_ACCEPT'] == 'application/json') {
    header("Content-Type: application/json$charset");
} else {
    header("Content-Type: text/plain$charset");
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
