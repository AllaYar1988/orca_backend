<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Orca IoT Admin'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --header-height: 60px;
            --primary-color: #0d6efd;
            --sidebar-bg: #1e293b;
            --sidebar-hover: #334155;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f1f5f9;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            color: #fff;
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-brand i {
            color: var(--primary-color);
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-section {
            padding: 0.5rem 1.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.05em;
            margin-top: 1rem;
        }

        .nav-section:first-child {
            margin-top: 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #94a3b8;
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .nav-link.active {
            background: var(--sidebar-hover);
            color: #fff;
            border-left-color: var(--primary-color);
        }

        .nav-link i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.1rem;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .top-header {
            height: var(--header-height);
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }

        .content-wrapper {
            padding: 1.5rem;
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

        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }

        .table th {
            font-weight: 600;
            color: #475569;
            border-top: none;
        }

        .badge-active {
            background: #10b981;
        }

        .badge-inactive {
            background: #ef4444;
        }

        .stats-card {
            background: #fff;
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .stats-card .icon {
            width: 48px;
            height: 48px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stats-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
        }

        .stats-card .label {
            color: #64748b;
            font-size: 0.875rem;
        }

        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar-toggle {
                display: block !important;
            }
        }

        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #1e293b;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-brand">
                <i class="bi bi-cpu"></i>
                Orca IoT
            </a>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section">Dashboard</div>
            <a href="index.php" class="nav-link <?php echo ($currentPage ?? '') === 'dashboard' ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i>
                Dashboard
            </a>

            <div class="nav-section">Companies</div>
            <a href="company_create.php" class="nav-link <?php echo ($currentPage ?? '') === 'company_create' ? 'active' : ''; ?>">
                <i class="bi bi-plus-circle"></i>
                Create Company
            </a>
            <a href="companies.php" class="nav-link <?php echo ($currentPage ?? '') === 'companies' ? 'active' : ''; ?>">
                <i class="bi bi-building"></i>
                Company List
            </a>

            <div class="nav-section">Devices</div>
            <a href="device_create.php" class="nav-link <?php echo ($currentPage ?? '') === 'device_create' ? 'active' : ''; ?>">
                <i class="bi bi-plus-circle"></i>
                Create Device
            </a>
            <a href="devices.php" class="nav-link <?php echo ($currentPage ?? '') === 'devices' ? 'active' : ''; ?>">
                <i class="bi bi-hdd-stack"></i>
                Device List
            </a>
            <a href="virtual_devices.php" class="nav-link <?php echo ($currentPage ?? '') === 'virtual_devices' ? 'active' : ''; ?>">
                <i class="bi bi-diagram-3"></i>
                Virtual Devices
            </a>

            <div class="nav-section">Users</div>
            <a href="user_create.php" class="nav-link <?php echo ($currentPage ?? '') === 'user_create' ? 'active' : ''; ?>">
                <i class="bi bi-person-plus"></i>
                Create User
            </a>
            <a href="users.php" class="nav-link <?php echo ($currentPage ?? '') === 'users' ? 'active' : ''; ?>">
                <i class="bi bi-people"></i>
                User List
            </a>

            <div class="nav-section">Monitoring</div>
            <a href="logs.php" class="nav-link <?php echo ($currentPage ?? '') === 'logs' ? 'active' : ''; ?>">
                <i class="bi bi-journal-text"></i>
                Logs
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <header class="top-header">
            <div class="d-flex align-items-center gap-3">
                <button class="sidebar-toggle" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="page-title"><?php echo $pageTitle ?? 'Dashboard'; ?></h1>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted me-3"><?php echo date('Y-m-d H:i'); ?></span>
                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        <?php echo htmlspecialchars($currentUser['username'] ?? 'Admin'); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <div class="content-wrapper">
