<?php
/**
 * EmailService
 *
 * Handles email notifications for alarm events
 * Supports SMTP configuration via environment variables
 *
 * Environment variables required:
 *   MAIL_HOST - SMTP server hostname
 *   MAIL_PORT - SMTP port (587 for TLS, 465 for SSL)
 *   MAIL_USERNAME - SMTP username
 *   MAIL_PASSWORD - SMTP password
 *   MAIL_FROM_ADDRESS - From email address
 *   MAIL_FROM_NAME - From name
 *   MAIL_ENCRYPTION - tls or ssl (optional, default tls)
 */

require_once __DIR__ . '/../config/env.php';

class EmailService {
    private $host;
    private $port;
    private $username;
    private $password;
    private $fromAddress;
    private $fromName;
    private $encryption;
    private $enabled;

    public function __construct() {
        $this->host = env('MAIL_HOST', '');
        $this->port = (int)env('MAIL_PORT', 587);
        $this->username = env('MAIL_USERNAME', '');
        $this->password = env('MAIL_PASSWORD', '');
        $this->fromAddress = env('MAIL_FROM_ADDRESS', 'noreply@orca.local');
        $this->fromName = env('MAIL_FROM_NAME', 'Orca IoT Platform');
        $this->encryption = env('MAIL_ENCRYPTION', 'tls');
        $this->enabled = env('MAIL_ENABLED', false);
    }

    /**
     * Check if email service is configured and enabled
     */
    public function isEnabled() {
        return $this->enabled && !empty($this->host) && !empty($this->username);
    }

    /**
     * Send an email using PHP's mail() function or SMTP
     *
     * @param string|array $to - Recipient email(s)
     * @param string $subject - Email subject
     * @param string $body - Email body (HTML supported)
     * @param array $options - Additional options (cc, bcc, headers)
     * @return array - ['success' => bool, 'message' => string]
     */
    public function send($to, $subject, $body, $options = []) {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'message' => 'Email service is not configured or disabled'
            ];
        }

        // Convert to array if string
        $recipients = is_array($to) ? $to : [$to];

        try {
            // Use PHP mail() for simple setup, or SMTP socket for full control
            if ($this->useSimpleMail()) {
                return $this->sendWithMail($recipients, $subject, $body, $options);
            } else {
                return $this->sendWithSMTP($recipients, $subject, $body, $options);
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Email error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check if we should use simple mail() vs SMTP
     */
    private function useSimpleMail() {
        return env('MAIL_USE_SIMPLE', false);
    }

    /**
     * Send using PHP's mail() function
     */
    private function sendWithMail($recipients, $subject, $body, $options) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            "From: {$this->fromName} <{$this->fromAddress}>",
            "Reply-To: {$this->fromAddress}"
        ];

        if (!empty($options['cc'])) {
            $headers[] = 'Cc: ' . (is_array($options['cc']) ? implode(',', $options['cc']) : $options['cc']);
        }

        if (!empty($options['bcc'])) {
            $headers[] = 'Bcc: ' . (is_array($options['bcc']) ? implode(',', $options['bcc']) : $options['bcc']);
        }

        $headerString = implode("\r\n", $headers);
        $toList = implode(',', $recipients);

        $result = mail($toList, $subject, $body, $headerString);

        return [
            'success' => $result,
            'message' => $result ? 'Email sent successfully' : 'Failed to send email'
        ];
    }

    /**
     * Send using SMTP socket connection
     */
    private function sendWithSMTP($recipients, $subject, $body, $options) {
        $socket = null;

        try {
            // Connect to SMTP server
            $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
            $socket = fsockopen($prefix . $this->host, $this->port, $errno, $errstr, 30);

            if (!$socket) {
                throw new Exception("Could not connect to SMTP server: $errstr ($errno)");
            }

            // Set timeout
            stream_set_timeout($socket, 30);

            // Read greeting
            $this->smtpRead($socket);

            // EHLO
            $this->smtpSend($socket, "EHLO " . gethostname());

            // Start TLS if needed
            if ($this->encryption === 'tls') {
                $this->smtpSend($socket, "STARTTLS");
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("Failed to enable TLS");
                }
                $this->smtpSend($socket, "EHLO " . gethostname());
            }

            // Authenticate
            $this->smtpSend($socket, "AUTH LOGIN");
            $this->smtpSend($socket, base64_encode($this->username));
            $this->smtpSend($socket, base64_encode($this->password));

            // Set envelope
            $this->smtpSend($socket, "MAIL FROM:<{$this->fromAddress}>");

            foreach ($recipients as $recipient) {
                $this->smtpSend($socket, "RCPT TO:<$recipient>");
            }

            // Send data
            $this->smtpSend($socket, "DATA");

            // Build message
            $message = "From: {$this->fromName} <{$this->fromAddress}>\r\n";
            $message .= "To: " . implode(', ', $recipients) . "\r\n";
            $message .= "Subject: $subject\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Date: " . date('r') . "\r\n";
            $message .= "\r\n";
            $message .= $body;
            $message .= "\r\n.";

            $this->smtpSend($socket, $message);

            // Quit
            $this->smtpSend($socket, "QUIT");

            fclose($socket);

            return [
                'success' => true,
                'message' => 'Email sent successfully via SMTP'
            ];

        } catch (Exception $e) {
            if ($socket) {
                fclose($socket);
            }
            throw $e;
        }
    }

    /**
     * Send SMTP command and read response
     */
    private function smtpSend($socket, $command) {
        fwrite($socket, $command . "\r\n");
        return $this->smtpRead($socket);
    }

    /**
     * Read SMTP response
     */
    private function smtpRead($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            // Check if this is the last line (4th char is space)
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        // Check for error codes (4xx or 5xx)
        $code = (int)substr($response, 0, 3);
        if ($code >= 400) {
            throw new Exception("SMTP Error: $response");
        }

        return $response;
    }

    /**
     * Send alarm notification email
     *
     * @param array $device - Device info (name, serial_number)
     * @param array $alarm - Alarm info (key, value, type, message, status)
     * @param string|array $recipients - Email recipient(s)
     * @return array
     */
    public function sendAlarmNotification($device, $alarm, $recipients) {
        $subject = "[Orca Alert] {$alarm['type']} alarm on {$device['name']}";

        $statusClass = $alarm['status'] === 'critical' ? 'color: #dc2626;' : 'color: #d97706;';
        $statusBg = $alarm['status'] === 'critical' ? 'background-color: #fef2f2;' : 'background-color: #fffbeb;';

        $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Alarm Notification</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="$statusBg border-radius: 8px; padding: 20px; margin-bottom: 20px;">
        <h1 style="$statusClass margin: 0 0 10px 0; font-size: 24px;">
            {$alarm['status']} Alarm
        </h1>
        <p style="margin: 0; font-size: 16px;">
            {$alarm['message']}
        </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-weight: bold; width: 140px;">Device:</td>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">{$device['name']}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-weight: bold;">Serial Number:</td>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">{$device['serial_number']}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-weight: bold;">Sensor:</td>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">{$alarm['key']}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-weight: bold;">Value:</td>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">{$alarm['value']}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-weight: bold;">Alarm Type:</td>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">{$alarm['type']}</td>
        </tr>
        <tr>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-weight: bold;">Status:</td>
            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; $statusClass font-weight: bold; text-transform: uppercase;">
                {$alarm['status']}
            </td>
        </tr>
        <tr>
            <td style="padding: 10px; font-weight: bold;">Time:</td>
            <td style="padding: 10px;">{$alarm['timestamp']}</td>
        </tr>
    </table>

    <p style="color: #6b7280; font-size: 14px;">
        This is an automated notification from the Orca IoT Platform.
        Please do not reply to this email.
    </p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;">

    <p style="color: #9ca3af; font-size: 12px; text-align: center;">
        Orca IoT Platform - Sensor Monitoring & Alerting
    </p>
</body>
</html>
HTML;

        return $this->send($recipients, $subject, $body);
    }

    /**
     * Send daily summary email
     *
     * @param array $summary - Summary data
     * @param string|array $recipients - Email recipient(s)
     * @return array
     */
    public function sendDailySummary($summary, $recipients) {
        $subject = "[Orca] Daily Summary - {$summary['date']}";

        $statusColor = $summary['status'] === 'critical' ? '#dc2626' :
                      ($summary['status'] === 'warning' ? '#d97706' : '#059669');

        $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Daily Summary</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h1 style="color: #1f2937; margin: 0 0 20px 0;">Daily Summary</h1>
    <p style="color: #6b7280; margin: 0 0 20px 0;">{$summary['date']}</p>

    <div style="background-color: #f9fafb; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
        <h2 style="margin: 0 0 15px 0; font-size: 18px; color: #374151;">Overview</h2>
        <table style="width: 100%;">
            <tr>
                <td style="padding: 5px 0;">Overall Status:</td>
                <td style="padding: 5px 0; font-weight: bold; color: {$statusColor}; text-transform: uppercase;">
                    {$summary['status']}
                </td>
            </tr>
            <tr>
                <td style="padding: 5px 0;">Total Devices:</td>
                <td style="padding: 5px 0; font-weight: bold;">{$summary['device_count']}</td>
            </tr>
            <tr>
                <td style="padding: 5px 0;">Devices Online:</td>
                <td style="padding: 5px 0; font-weight: bold;">{$summary['online_count']}</td>
            </tr>
            <tr>
                <td style="padding: 5px 0;">Warning Alerts:</td>
                <td style="padding: 5px 0; font-weight: bold; color: #d97706;">{$summary['warning_count']}</td>
            </tr>
            <tr>
                <td style="padding: 5px 0;">Critical Alerts:</td>
                <td style="padding: 5px 0; font-weight: bold; color: #dc2626;">{$summary['critical_count']}</td>
            </tr>
        </table>
    </div>

    <p style="color: #6b7280; font-size: 14px;">
        This is an automated daily summary from the Orca IoT Platform.
    </p>

    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;">

    <p style="color: #9ca3af; font-size: 12px; text-align: center;">
        Orca IoT Platform - Sensor Monitoring & Alerting
    </p>
</body>
</html>
HTML;

        return $this->send($recipients, $subject, $body);
    }
}
