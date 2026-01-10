<?php
require_once __DIR__ . '/auth.php';
requireUserLogin();
$currentUser = getCurrentPortalUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Orca IoT Portal'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0d6efd;
            --header-bg: #1e293b;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f1f5f9;
            min-height: 100vh;
        }

        .navbar {
            background: var(--header-bg);
            padding: 0.75rem 1.5rem;
        }

        .navbar-brand {
            font-size: 1.25rem;
            font-weight: 700;
            color: #fff !important;
        }

        .navbar-brand i {
            color: var(--primary-color);
        }

        .nav-link {
            color: rgba(255,255,255,0.8) !important;
            padding: 0.5rem 1rem !important;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }

        .nav-link:hover, .nav-link.active {
            color: #fff !important;
            background: rgba(255,255,255,0.1);
        }

        .user-dropdown .btn {
            color: #fff;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .user-dropdown .btn:hover {
            background: rgba(255,255,255,0.1);
        }

        .content-wrapper {
            padding: 1.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .card {
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-radius: 0.5rem;
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
        }

        .device-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .device-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .device-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.5rem;
        }

        .badge-online {
            background: #10b981;
        }

        .badge-offline {
            background: #94a3b8;
        }

        .table th {
            font-weight: 600;
            color: #475569;
            border-top: none;
        }

        .log-value {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (max-width: 767.98px) {
            .content-wrapper {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-cpu me-2"></i>Orca IoT
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($currentPage ?? '') === 'dashboard' ? 'active' : ''; ?>" href="index.php">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($currentPage ?? '') === 'devices' ? 'active' : ''; ?>" href="devices.php">
                            <i class="bi bi-hdd-stack me-1"></i>My Devices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($currentPage ?? '') === 'logs' ? 'active' : ''; ?>" href="logs.php">
                            <i class="bi bi-journal-text me-1"></i>Logs
                        </a>
                    </li>
                </ul>
                <div class="user-dropdown dropdown">
                    <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        <?php echo htmlspecialchars($currentUser['name'] ? $currentUser['name'] : $currentUser['username']); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?php echo htmlspecialchars($currentUser['company_name']); ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="content-wrapper">
