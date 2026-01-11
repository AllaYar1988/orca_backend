<?php
/**
 * Warning Summary API
 * Returns warning/critical status counts at different hierarchy levels
 *
 * GET /api/warning_summary.php?level=installation
 * GET /api/warning_summary.php?level=company&company_id=1
 * GET /api/warning_summary.php?level=device&device_id=1
 * Headers: Authorization: Bearer <token>
 *
 * Levels:
 *   - installation: Summary across all user's companies
 *   - company: Summary for a specific company (all devices)
 *   - device: Summary for a specific device (all sensors)
 *
 * Response:
 *   - success: boolean
 *   - level: string (installation|company|device)
 *   - summary: object with warning counts
 *   - items: array of items with their status (companies, devices, or sensors)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Authenticate user via token
require_once __DIR__ . '/auth_middleware.php';
// $authUser is now available with user data

require_once __DIR__ . '/../config/database.php';

$level = isset($_GET['level']) ? $_GET['level'] : 'installation';
$companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
// Time window for "recent" status - default 24 hours
$hoursBack = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;

$userModel = new User();
$db = Database::getInstance()->getConnection();

/**
 * Get worst status from an array of status values
 * Priority: critical > warning > normal
 */
function getWorstStatus($statuses) {
    if (in_array('critical', $statuses)) {
        return 'critical';
    }
    if (in_array('warning', $statuses)) {
        return 'warning';
    }
    return 'normal';
}

/**
 * Get most recent log status for a device/sensor
 */
function getLatestStatus($db, $deviceId, $logKey = null, $hoursBack = 24) {
    $params = [':device_id' => $deviceId, ':hours_back' => $hoursBack];
    $sql = "SELECT status FROM device_logs
            WHERE device_id = :device_id
            AND logged_at >= UTC_TIMESTAMP() - INTERVAL :hours_back HOUR";

    if ($logKey !== null) {
        $sql .= " AND log_key = :log_key";
        $params[':log_key'] = $logKey;
    }

    $sql .= " ORDER BY logged_at DESC LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchColumn();

    return $result ?: 'normal';
}

/**
 * Count status occurrences for a device within time window
 */
function getStatusCounts($db, $deviceId, $logKey = null, $hoursBack = 24) {
    $params = [':device_id' => $deviceId, ':hours_back' => $hoursBack];
    $sql = "SELECT status, COUNT(*) as count FROM device_logs
            WHERE device_id = :device_id
            AND logged_at >= UTC_TIMESTAMP() - INTERVAL :hours_back HOUR";

    if ($logKey !== null) {
        $sql .= " AND log_key = :log_key";
        $params[':log_key'] = $logKey;
    }

    $sql .= " GROUP BY status";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $counts = ['normal' => 0, 'warning' => 0, 'critical' => 0];
    foreach ($results as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int)$row['count'];
        }
    }

    return $counts;
}

switch ($level) {
    case 'installation':
        // Level 0: Installation-wide summary across all user's companies
        $companies = $userModel->getAssignedCompanies($authUser['id']);

        $totalWarning = 0;
        $totalCritical = 0;
        $companySummaries = [];

        foreach ($companies as $company) {
            // Get all devices in this company that user has access to
            $devices = $userModel->getCompanyDevices($authUser['id'], $company['id']);
            $deviceIds = array_column($devices, 'id');

            if (empty($deviceIds)) {
                $companySummaries[] = [
                    'id' => (int)$company['id'],
                    'name' => $company['name'],
                    'status' => 'normal',
                    'warning_count' => 0,
                    'critical_count' => 0,
                    'device_count' => 0
                ];
                continue;
            }

            // Get status counts for all devices in this company
            $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
            $stmt = $db->prepare("
                SELECT
                    SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_count,
                    SUM(CASE WHEN status = 'critical' THEN 1 ELSE 0 END) as critical_count
                FROM device_logs
                WHERE device_id IN ($placeholders)
                AND logged_at >= UTC_TIMESTAMP() - INTERVAL ? HOUR
            ");
            $params = array_merge($deviceIds, [$hoursBack]);
            $stmt->execute($params);
            $counts = $stmt->fetch();

            $warningCount = (int)($counts['warning_count'] ?? 0);
            $criticalCount = (int)($counts['critical_count'] ?? 0);

            // Determine company status based on worst status of its devices
            $companyStatus = 'normal';
            if ($criticalCount > 0) {
                $companyStatus = 'critical';
            } elseif ($warningCount > 0) {
                $companyStatus = 'warning';
            }

            $totalWarning += $warningCount;
            $totalCritical += $criticalCount;

            $companySummaries[] = [
                'id' => (int)$company['id'],
                'name' => $company['name'],
                'status' => $companyStatus,
                'warning_count' => $warningCount,
                'critical_count' => $criticalCount,
                'device_count' => count($deviceIds)
            ];
        }

        // Overall installation status
        $installationStatus = 'normal';
        if ($totalCritical > 0) {
            $installationStatus = 'critical';
        } elseif ($totalWarning > 0) {
            $installationStatus = 'warning';
        }

        echo json_encode([
            'success' => true,
            'level' => 'installation',
            'summary' => [
                'status' => $installationStatus,
                'total_warning' => $totalWarning,
                'total_critical' => $totalCritical,
                'company_count' => count($companies)
            ],
            'companies' => $companySummaries,
            'hours_back' => $hoursBack
        ]);
        break;

    case 'company':
        // Level 1: Company-level summary
        if (!$companyId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'company_id is required']);
            exit;
        }

        // Check access
        if (!$userModel->hasAccessToCompany($authUser['id'], $companyId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied to this company']);
            exit;
        }

        // Get devices for this company
        $devices = $userModel->getCompanyDevices($authUser['id'], $companyId);

        $totalWarning = 0;
        $totalCritical = 0;
        $deviceSummaries = [];

        foreach ($devices as $device) {
            $counts = getStatusCounts($db, $device['id'], null, $hoursBack);

            $warningCount = $counts['warning'];
            $criticalCount = $counts['critical'];

            // Determine device status
            $deviceStatus = 'normal';
            if ($criticalCount > 0) {
                $deviceStatus = 'critical';
            } elseif ($warningCount > 0) {
                $deviceStatus = 'warning';
            }

            $totalWarning += $warningCount;
            $totalCritical += $criticalCount;

            $deviceSummaries[] = [
                'id' => (int)$device['id'],
                'name' => $device['name'],
                'serial_number' => $device['serial_number'],
                'status' => $deviceStatus,
                'warning_count' => $warningCount,
                'critical_count' => $criticalCount,
                'is_online' => (bool)$device['is_online'],
                'seconds_ago' => isset($device['seconds_ago']) ? (int)$device['seconds_ago'] : null
            ];
        }

        // Overall company status
        $companyStatus = 'normal';
        if ($totalCritical > 0) {
            $companyStatus = 'critical';
        } elseif ($totalWarning > 0) {
            $companyStatus = 'warning';
        }

        echo json_encode([
            'success' => true,
            'level' => 'company',
            'company_id' => $companyId,
            'summary' => [
                'status' => $companyStatus,
                'total_warning' => $totalWarning,
                'total_critical' => $totalCritical,
                'device_count' => count($devices)
            ],
            'devices' => $deviceSummaries,
            'hours_back' => $hoursBack
        ]);
        break;

    case 'device':
        // Level 2: Device-level summary (by sensor)
        if (!$deviceId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'device_id is required']);
            exit;
        }

        // Check access
        if (!$userModel->hasAccessToDevice($authUser['id'], $deviceId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied to this device']);
            exit;
        }

        // Check for sensor-level restrictions
        $allowedSensors = $userModel->getAllowedSensors($authUser['id'], $deviceId);
        $hasSensorRestrictions = !empty($allowedSensors);

        // Get unique sensors for this device
        $sensorClause = '';
        $sensorParams = [':device_id' => $deviceId, ':hours_back' => $hoursBack];

        if ($hasSensorRestrictions) {
            $placeholders = [];
            foreach ($allowedSensors as $i => $sensor) {
                $placeholders[] = ":sensor_$i";
                $sensorParams[":sensor_$i"] = $sensor;
            }
            $sensorClause = " AND log_key IN (" . implode(',', $placeholders) . ")";
        }

        // Get sensor summaries
        $sql = "SELECT
                    log_key,
                    SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_count,
                    SUM(CASE WHEN status = 'critical' THEN 1 ELSE 0 END) as critical_count,
                    MAX(logged_at) as last_logged
                FROM device_logs
                WHERE device_id = :device_id
                AND logged_at >= UTC_TIMESTAMP() - INTERVAL :hours_back HOUR
                AND log_key IS NOT NULL AND log_key != ''
                $sensorClause
                GROUP BY log_key
                ORDER BY log_key";

        $stmt = $db->prepare($sql);
        $stmt->execute($sensorParams);
        $sensors = $stmt->fetchAll();

        $totalWarning = 0;
        $totalCritical = 0;
        $sensorSummaries = [];

        foreach ($sensors as $sensor) {
            $warningCount = (int)$sensor['warning_count'];
            $criticalCount = (int)$sensor['critical_count'];

            // Get latest status for this sensor
            $latestStatus = getLatestStatus($db, $deviceId, $sensor['log_key'], $hoursBack);

            $totalWarning += $warningCount;
            $totalCritical += $criticalCount;

            $sensorSummaries[] = [
                'log_key' => $sensor['log_key'],
                'status' => $latestStatus,
                'warning_count' => $warningCount,
                'critical_count' => $criticalCount,
                'last_logged' => $sensor['last_logged']
            ];
        }

        // Overall device status
        $deviceStatus = 'normal';
        if ($totalCritical > 0) {
            $deviceStatus = 'critical';
        } elseif ($totalWarning > 0) {
            $deviceStatus = 'warning';
        }

        echo json_encode([
            'success' => true,
            'level' => 'device',
            'device_id' => $deviceId,
            'summary' => [
                'status' => $deviceStatus,
                'total_warning' => $totalWarning,
                'total_critical' => $totalCritical,
                'sensor_count' => count($sensorSummaries)
            ],
            'sensors' => $sensorSummaries,
            'hours_back' => $hoursBack
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid level. Use: installation, company, or device']);
}
