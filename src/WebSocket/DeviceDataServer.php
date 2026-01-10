<?php

namespace Orca\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * WebSocket server for real-time device data updates
 *
 * Handles:
 * - Client connections with authentication
 * - Device subscriptions (subscribe to specific device updates)
 * - Broadcasting new device logs to subscribed clients
 */
class DeviceDataServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage Connected clients */
    protected $clients;

    /** @var array Device subscriptions: deviceId => [connectionId => ConnectionInterface] */
    protected $deviceSubscriptions = [];

    /** @var array Connection to devices map: connectionId => [deviceIds] */
    protected $connectionDevices = [];

    /** @var array Connection authentication: connectionId => userId */
    protected $authenticatedConnections = [];

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        echo "WebSocket Server initialized\n";
    }

    /**
     * Handle new connection
     */
    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $connId = $conn->resourceId;
        $this->connectionDevices[$connId] = [];

        echo "New connection: {$connId}\n";

        // Send welcome message
        $conn->send(json_encode([
            'type' => 'connected',
            'connectionId' => $connId,
            'message' => 'Connected to ORCA WebSocket server'
        ]));
    }

    /**
     * Handle incoming messages
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $connId = $from->resourceId;
        $data = json_decode($msg, true);

        if (!$data || !isset($data['action'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Invalid message format'
            ]));
            return;
        }

        switch ($data['action']) {
            case 'authenticate':
                $this->handleAuthentication($from, $data);
                break;

            case 'subscribe':
                $this->handleSubscribe($from, $data);
                break;

            case 'unsubscribe':
                $this->handleUnsubscribe($from, $data);
                break;

            case 'ping':
                $from->send(json_encode(['type' => 'pong']));
                break;

            default:
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Unknown action: ' . $data['action']
                ]));
        }
    }

    /**
     * Handle authentication request
     */
    protected function handleAuthentication(ConnectionInterface $conn, array $data)
    {
        $connId = $conn->resourceId;
        $token = $data['token'] ?? null;

        if (!$token) {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Token required'
            ]));
            return;
        }

        // Validate token against database
        $userId = $this->validateToken($token);

        if ($userId) {
            $this->authenticatedConnections[$connId] = $userId;
            $conn->send(json_encode([
                'type' => 'authenticated',
                'userId' => $userId,
                'message' => 'Authentication successful'
            ]));
            echo "Connection {$connId} authenticated as user {$userId}\n";
        } else {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Invalid or expired token'
            ]));
        }
    }

    /**
     * Handle device subscription
     */
    protected function handleSubscribe(ConnectionInterface $conn, array $data)
    {
        $connId = $conn->resourceId;
        $deviceId = $data['deviceId'] ?? null;

        // Check authentication
        if (!isset($this->authenticatedConnections[$connId])) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Authentication required'
            ]));
            return;
        }

        if (!$deviceId) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Device ID required'
            ]));
            return;
        }

        // Verify user has access to device
        $userId = $this->authenticatedConnections[$connId];
        if (!$this->userHasDeviceAccess($userId, $deviceId)) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Access denied to device'
            ]));
            return;
        }

        // Add subscription
        if (!isset($this->deviceSubscriptions[$deviceId])) {
            $this->deviceSubscriptions[$deviceId] = [];
        }
        $this->deviceSubscriptions[$deviceId][$connId] = $conn;
        $this->connectionDevices[$connId][] = $deviceId;

        $conn->send(json_encode([
            'type' => 'subscribed',
            'deviceId' => $deviceId,
            'message' => "Subscribed to device {$deviceId}"
        ]));

        echo "Connection {$connId} subscribed to device {$deviceId}\n";
    }

    /**
     * Handle device unsubscription
     */
    protected function handleUnsubscribe(ConnectionInterface $conn, array $data)
    {
        $connId = $conn->resourceId;
        $deviceId = $data['deviceId'] ?? null;

        if ($deviceId && isset($this->deviceSubscriptions[$deviceId][$connId])) {
            unset($this->deviceSubscriptions[$deviceId][$connId]);
            $this->connectionDevices[$connId] = array_filter(
                $this->connectionDevices[$connId],
                fn($id) => $id !== $deviceId
            );

            $conn->send(json_encode([
                'type' => 'unsubscribed',
                'deviceId' => $deviceId
            ]));

            echo "Connection {$connId} unsubscribed from device {$deviceId}\n";
        }
    }

    /**
     * Handle connection close
     */
    public function onClose(ConnectionInterface $conn)
    {
        $connId = $conn->resourceId;

        // Remove from all device subscriptions
        if (isset($this->connectionDevices[$connId])) {
            foreach ($this->connectionDevices[$connId] as $deviceId) {
                if (isset($this->deviceSubscriptions[$deviceId][$connId])) {
                    unset($this->deviceSubscriptions[$deviceId][$connId]);
                }
            }
            unset($this->connectionDevices[$connId]);
        }

        // Remove authentication
        if (isset($this->authenticatedConnections[$connId])) {
            unset($this->authenticatedConnections[$connId]);
        }

        $this->clients->detach($conn);
        echo "Connection {$connId} closed\n";
    }

    /**
     * Handle errors
     */
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Broadcast new device log to all subscribed clients
     * Called externally when new data arrives
     */
    public function broadcastDeviceLog(int $deviceId, array $logData)
    {
        if (!isset($this->deviceSubscriptions[$deviceId])) {
            return 0;
        }

        $message = json_encode([
            'type' => 'device_log',
            'deviceId' => $deviceId,
            'data' => $logData
        ]);

        $count = 0;
        foreach ($this->deviceSubscriptions[$deviceId] as $conn) {
            $conn->send($message);
            $count++;
        }

        echo "Broadcasted log for device {$deviceId} to {$count} clients\n";
        return $count;
    }

    /**
     * Validate token and return user ID
     */
    protected function validateToken(string $token): ?int
    {
        try {
            // Load database configuration
            $configPath = __DIR__ . '/../../config/database.php';
            if (!file_exists($configPath)) {
                echo "Database config not found\n";
                return null;
            }

            require_once $configPath;
            $db = \Database::getInstance()->getConnection();

            $stmt = $db->prepare("
                SELECT ut.user_id
                FROM user_tokens ut
                JOIN users u ON ut.user_id = u.id
                WHERE ut.token = ?
                AND ut.expires_at > NOW()
                AND u.is_active = 1
            ");
            $stmt->execute([$token]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result ? (int)$result['user_id'] : null;
        } catch (\Exception $e) {
            echo "Token validation error: {$e->getMessage()}\n";
            return null;
        }
    }

    /**
     * Check if user has access to device
     */
    protected function userHasDeviceAccess(int $userId, int $deviceId): bool
    {
        try {
            require_once __DIR__ . '/../../config/database.php';
            $db = \Database::getInstance()->getConnection();

            // Check user_devices table
            $stmt = $db->prepare("
                SELECT 1 FROM user_devices
                WHERE user_id = ? AND device_id = ?
            ");
            $stmt->execute([$userId, $deviceId]);

            if ($stmt->fetch()) {
                return true;
            }

            // Check through company access
            $stmt = $db->prepare("
                SELECT 1 FROM user_companies uc
                JOIN devices d ON d.company_id = uc.company_id
                WHERE uc.user_id = ? AND d.id = ?
            ");
            $stmt->execute([$userId, $deviceId]);

            return (bool)$stmt->fetch();
        } catch (\Exception $e) {
            echo "Access check error: {$e->getMessage()}\n";
            return false;
        }
    }

    /**
     * Get server statistics
     */
    public function getStats(): array
    {
        return [
            'totalConnections' => count($this->clients),
            'authenticatedConnections' => count($this->authenticatedConnections),
            'deviceSubscriptions' => array_map(
                fn($subs) => count($subs),
                $this->deviceSubscriptions
            )
        ];
    }
}
