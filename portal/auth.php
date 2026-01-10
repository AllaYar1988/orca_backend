<?php
session_start();

function requireUserLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function getCurrentPortalUser() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['user_username'],
            'name' => $_SESSION['user_name'],
            'company_id' => $_SESSION['user_company_id'],
            'company_name' => $_SESSION['user_company_name']
        ];
    }
    return null;
}

function isUserLoggedIn() {
    return isset($_SESSION['user_id']);
}
