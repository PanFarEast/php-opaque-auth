<?php
require_once __DIR__ . '/../../php/Opaque.php';
// A line-oriented adapter for exercising the public facade in a long-lived PHP process.
while (($line = fgets(STDIN)) !== false) {
    try {
        $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $class = $request['class'] === 'client' ? Opaque\Client::class : Opaque\Server::class;
        $method = $request['method'];
        $args = $request['args'] ?? [];
        if ($request['class'] === 'server' && $method === 'getPublicKey') {
            $args = $args['serverSetup'];
        }
        if ($request['class'] === 'server' && $method === 'createSetup') {
            $result = $class::$method();
        } else {
            $result = $class::$method($args);
        }
        fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR) . "\n");
    } catch (Throwable $error) {
        // Deliberately omit exception text: protocol arguments can contain secrets.
        fwrite(STDOUT, "{\"ok\":false,\"error\":\"PHP operation failed\"}\n");
    }
}
