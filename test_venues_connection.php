<?php
require_once 'config.php';
require_once 'api/venues_db_helper.php';
requireLogin();

header('Content-Type: text/plain');

echo "=== TEN_Venues Database Connection Test ===\n\n";

$venuesConn = getVenuesDBConnection();

if (!$venuesConn) {
    echo "ERROR: Could not connect to TEN_Venues database\n";
    exit();
}

echo "✓ Successfully connected to TEN_Venues database\n\n";

// Test 1: Count companies
echo "Test 1: Count Companies\n";
$result = $venuesConn->query("SELECT COUNT(*) as total FROM companies WHERE type = 'Company' AND deleted_at IS NULL");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Total Companies: " . $row['total'] . "\n";
} else {
    echo "ERROR: " . $venuesConn->error . "\n";
}

// Test 2: List companies
echo "\nTest 2: List Companies\n";
$result = $venuesConn->query("SELECT id, name, type FROM companies WHERE type = 'Company' AND deleted_at IS NULL LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  - ID: {$row['id']}, Name: {$row['name']}, Type: {$row['type']}\n";
    }
} else {
    echo "ERROR: " . $venuesConn->error . "\n";
}

// Test 3: Count subscriptions
echo "\nTest 3: Count Subscriptions\n";
$result = $venuesConn->query("SELECT COUNT(*) as total FROM company_subscriptions");
if ($result) {
    $row = $result->fetch_assoc();
    echo "Total Subscriptions: " . $row['total'] . "\n";
} else {
    echo "ERROR: " . $venuesConn->error . "\n";
}

// Test 4: Check subscription statuses
echo "\nTest 4: Subscription Statuses\n";
$result = $venuesConn->query("SELECT subscription_status, COUNT(*) as count FROM company_subscriptions GROUP BY subscription_status");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  - Status: {$row['subscription_status']}, Count: {$row['count']}\n";
    }
} else {
    echo "ERROR: " . $venuesConn->error . "\n";
}

// Test 5: Check pricing packages
echo "\nTest 5: Pricing Packages\n";
$result = $venuesConn->query("SELECT * FROM pricing_packages WHERE is_active = 1");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "  - Package: {$row['package_name']}, Base Fee: €{$row['base_monthly_fee_eur']}\n";
    }
} else {
    echo "ERROR: " . $venuesConn->error . "\n";
}

$venuesConn->close();
echo "\n=== Test Complete ===\n";
