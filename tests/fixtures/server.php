<?php

// Router for PHP's built-in server (`php -S`), used by the transport and
// integration tests. State lives in the directory PHOTOS_TEST_STATE_DIR.

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$state = (string) getenv('PHOTOS_TEST_STATE_DIR');
$body = (string) file_get_contents('php://input');

$json = static function (int $status, array $data, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($data);
};

switch ($path) {
    case '/echo':
        $files = [];
        foreach ($_FILES as $name => $file) {
            $files[$name] = [
                'name' => $file['name'],
                'sha1' => sha1((string) file_get_contents($file['tmp_name'])),
            ];
        }
        $json(200, [
            'method' => $_SERVER['REQUEST_METHOD'],
            'query' => $_SERVER['QUERY_STRING'] ?? '',
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'body_sha1' => sha1($body),
            'post' => $_POST,
            'files' => $files,
        ], ['X-Test' => 'one']);
        break;

    case '/v1/account':
        // Busy on the first call per counter, fine afterwards.
        $counter = $state . '/account-calls';
        $calls = is_file($counter) ? (int) file_get_contents($counter) : 0;
        file_put_contents($counter, (string) ($calls + 1));
        if ($calls === 0) {
            $json(503, ['error' => ['code' => 'busy', 'message' => 'all workers are busy; retry shortly']], ['Retry-After' => '0']);
        } else {
            $json(200, ['account' => 'local', 'unlimited' => true, 'calls' => $calls + 1]);
        }
        break;

    case '/v1/presets':
        usleep(600_000);
        $json(200, []);
        break;

    case '/image.png':
        header('Content-Type: image/png');
        readfile(__DIR__ . '/product.png');
        break;

    case '/hook':
        file_put_contents($state . '/hook.json', json_encode([
            'signature' => $_SERVER['HTTP_PHOTOS_SIGNATURE'] ?? '',
            'event' => $_SERVER['HTTP_PHOTOS_EVENT'] ?? '',
            'body' => base64_encode($body),
        ]), LOCK_EX);
        $json(200, ['received' => true]);
        break;

    default:
        http_response_code(404);
}
