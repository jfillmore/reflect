<?php

//  ____________________                               ____________________
//  \*/-\*/-\*/-\*/-\*/-\  DO NOT EXPOSE EXTERNALLY!  /.\*/-\*/-\*/-\*/-\*/
//   ~~~~~~~~~~~~~~~~~~~~                             ~~~~~~~~~~~~~~~~~~~~


// spill our guts to the world if something isn't right; this isn't a prod service!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
xdebug_disable();  // too much noise by default
// ensure we have fine-tune control over responses
ob_implicit_flush(true);


// == HELPERS ==================================================================

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

function parseUnit($val) {
    $units =  ['B', 'K', 'M'];
    $unit = substr($val, -1);
    if (is_numeric($unit)) {
        $scale = 1;
    } else {
        $unit = strtoupper($unit);
        if (!in_array($unit, $units)) {
            throw new Exception("Unrecognized unit: $unit; try $units");
        }
        $val = intval(substr($val, 0, strlen($val) - 1));
        $scale = 1000 ** array_search($unit, $units);
    }

    return [$val, $scale];
}


// == APP LOGIC ================================================================

class HttpReflect {
    public $request_body = null;
    public $meta = [];

    public function __construct() {
        $this->request_body = stream_get_contents(fopen('php://input', 'r'));
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
            'body' => $this->request_body
        ];
        $resp = [];
        foreach ($defaultData as $key => $val) {
            if (!empty($val)) {
                $resp[$key] = $val;
            }
        }
        $padding = getHeader('x-refl-padding');
        if (!empty($padding)) {
            $unit = parseUnit($padding);
            $resp['padding'] = str_repeat('.', $unit[0] * $unit[1]);
        }
        $resp['request'] = [
            'src_addr' => @$_SERVER['REMOTE_ADDR'],
            'src_port' => @$_SERVER['REMOTE_PORT'],
            'method' => @$_SERVER['REQUEST_METHOD'],
            'url' => "http://" . @$_SERVER['HTTP_HOST'] . @$_SERVER['REQUEST_URI'],
            'duration_sec' => floatval(preg_replace(
                '/^(\d+\.[0]+[^0]).*/',
                '\1',
                microtime(true) - $startTime,
            )),
        ];
        return $resp;
    }
}


// == HEADERS + MAGIC ==========================================================

$reflectBody = intval(getHeader('x-refl-body', 0)) == 1;
$reflectUrl = getHeader('x-refl-proxy');

$error = null;
$response  = null;

try {
    if ($reflectBody && $reflectUrl) {
        throw new Exception("Cannot use x-refl-body and x-refl-proxy together.");
    }

    $startTime = microtime(true);
    $ref = new HttpReflect();
    http_response_code($ref->getStatusCode(200));
    $ref->sendSessionHeaders();
    $ref->processRequest();
    $contentType = 'text/plain';
    if ($reflectBody) {
        $contentType = @$_SERVER['HTTP_CONTENT_TYPE'];
        $response = $ref->request_body;
    } else if ($reflectUrl) {
        // so much hackz :-(
        $urlRes = fopen($reflectUrl, 'r');
        $urlMeta = stream_get_meta_data($urlRes)['wrapper_data'];
        $response = stream_get_contents($urlRes);
        foreach ($urlMeta as $header) {
            if (substr(strtolower($header), 0, 13) == 'content-type:') {
                $contentType = trim(substr($header, 13));
                break;
            }
        }

    } else {
        if (@$_SERVER['HTTP_ACCEPT'] == 'application/json') {
            $contentType = 'application/json';
        }
        $response = json_encode(
            $ref->getReflection($startTime),
            JSON_PRETTY_PRINT
        );
    }
    header("Content-Type: $contentType");

} catch (Exception $e) {
    $error = $e;
}


// == RESPONSE BODY ============================================================

$out = fopen('php://output', 'w');

$stream = getHeader('x-refl-stream');
if (empty($stream)) {
    header('Content-Length: ' . strlen($response));
    fwrite($out, $response);
} else {
    $delaySecs = floatval(getHeader('x-refl-stream-delay', 0));
    $unit = parseUnit($stream);
    $chunkSize = $unit[0] * $unit[1];
    if ($chunkSize < 1) {
        throw new Exception("Invalid chunk size: $chunkSize.");
    }
    header('Transfer-Encoding: chunked');
    $offset = 0;
    $eol = "\r\n";
    while ($offset < strlen($response)) {
        $chunk = substr($response, $offset, $chunkSize);
        fwrite($out, dechex(strlen($chunk)) . $eol);
        fwrite($out, $chunk . $eol);
        fflush($out);
        if ($delaySecs) {
            usleep(intval($delaySecs * 1000000));
        }
        $offset += $chunkSize;
    }
    fwrite($out, "0$eol$eol");
    fflush($out);
}

fclose($out);
