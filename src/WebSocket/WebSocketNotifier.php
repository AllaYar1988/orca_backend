<?php
// WebSocket Notifier - no namespace for easy integration

/**
 * WebSocket Notifier
 *
 * Sends notifications to the WebSocket server when new device logs arrive.
 * Uses a simple HTTP push to the internal WebSocket broadcast endpoint.
 */
class WebSocketNotifier
{
    /** @var string WebSocket server internal endpoint */
    private $pushEndpoint;

    /** @var bool Enable/disable notifications */
    private $enabled;

    /** @var int Connection timeout in seconds */
    private $timeout;

    public function __construct()
    {
        // Load configuration
        $this->pushEndpoint = getenv('WS_PUSH_ENDPOINT') ?: 'http://127.0.0.1:8081/broadcast';
        $this->enabled = getenv('WS_ENABLED') !== 'false';
        $this->timeout = 1; // 1 second timeout to not block the API
    }

    /**
     * Notify WebSocket server of new device log
     *
     * @param int $deviceId Device ID
     * @param array $logData Log data to broadcast
     * @return bool Success status
     */
    public function notifyDeviceLog(int $deviceId, array $logData): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $payload = json_encode([
                'type' => 'device_log',
                'deviceId' => $deviceId,
                'data' => $logData
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true
                ]
            ]);

            // Fire and forget - don't wait for response
            @file_get_contents($this->pushEndpoint, false, $context);

            return true;
        } catch (\Exception $e) {
            // Silently fail - WebSocket is optional enhancement
            error_log("WebSocket notification failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Static helper for quick notifications
     */
    public static function broadcast(int $deviceId, array $logData): bool
    {
        $notifier = new self();
        return $notifier->notifyDeviceLog($deviceId, $logData);
    }
}
