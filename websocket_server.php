<?php
/**
 * ORCA WebSocket Server
 *
 * Starts a WebSocket server for real-time device data updates.
 *
 * Usage:
 *   php websocket_server.php
 *
 * Configuration (environment variables):
 *   WS_PORT - WebSocket port (default: 8080)
 *   WS_PUSH_PORT - Internal push endpoint port (default: 8081)
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/WebSocket/DeviceDataServer.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Orca\WebSocket\DeviceDataServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;

// Configuration
$wsPort = getenv('WS_PORT') ?: 8080;
$pushPort = getenv('WS_PUSH_PORT') ?: 8081;

echo "========================================\n";
echo "       ORCA WebSocket Server\n";
echo "========================================\n";
echo "WebSocket Port: {$wsPort}\n";
echo "Push Endpoint Port: {$pushPort}\n";
echo "========================================\n\n";

// Create the main device data server
$deviceServer = new DeviceDataServer();

// Create the event loop
$loop = Loop::get();

// Create WebSocket server
$webSocket = new SocketServer("0.0.0.0:{$wsPort}", [], $loop);
$wsServer = new IoServer(
    new HttpServer(
        new WsServer($deviceServer)
    ),
    $webSocket,
    $loop
);

echo "WebSocket server started on ws://0.0.0.0:{$wsPort}\n";

// Create internal HTTP server for receiving broadcasts from API
$pushSocket = new SocketServer("127.0.0.1:{$pushPort}", [], $loop);
$pushSocket->on('connection', function ($conn) use ($deviceServer) {
    $buffer = '';

    $conn->on('data', function ($data) use ($conn, $deviceServer, &$buffer) {
        $buffer .= $data;

        // Check if we have a complete HTTP request
        if (strpos($buffer, "\r\n\r\n") === false) {
            return;
        }

        // Parse HTTP request
        $parts = explode("\r\n\r\n", $buffer, 2);
        $headers = $parts[0];
        $body = $parts[1] ?? '';

        // Extract content length
        preg_match('/Content-Length: (\d+)/i', $headers, $matches);
        $contentLength = isset($matches[1]) ? (int)$matches[1] : 0;

        // Wait for full body
        if (strlen($body) < $contentLength) {
            return;
        }

        // Parse JSON body
        $payload = json_decode($body, true);

        if ($payload && isset($payload['type']) && $payload['type'] === 'device_log') {
            $deviceId = $payload['deviceId'] ?? 0;
            $logData = $payload['data'] ?? [];

            if ($deviceId > 0) {
                $count = $deviceServer->broadcastDeviceLog($deviceId, $logData);
                $response = json_encode(['success' => true, 'clients' => $count]);
            } else {
                $response = json_encode(['success' => false, 'error' => 'Invalid device ID']);
            }
        } else {
            $response = json_encode(['success' => false, 'error' => 'Invalid payload']);
        }

        // Send HTTP response
        $httpResponse = "HTTP/1.1 200 OK\r\n";
        $httpResponse .= "Content-Type: application/json\r\n";
        $httpResponse .= "Content-Length: " . strlen($response) . "\r\n";
        $httpResponse .= "Connection: close\r\n";
        $httpResponse .= "\r\n";
        $httpResponse .= $response;

        $conn->write($httpResponse);
        $conn->end();

        $buffer = '';
    });
});

echo "Push endpoint started on http://127.0.0.1:{$pushPort}/broadcast\n";

// Periodic stats output
$loop->addPeriodicTimer(60, function () use ($deviceServer) {
    $stats = $deviceServer->getStats();
    echo sprintf(
        "[%s] Connections: %d | Authenticated: %d | Subscriptions: %s\n",
        date('Y-m-d H:i:s'),
        $stats['totalConnections'],
        $stats['authenticatedConnections'],
        json_encode($stats['deviceSubscriptions'])
    );
});

echo "\nServer is running. Press Ctrl+C to stop.\n\n";

// Run the event loop
$loop->run();
