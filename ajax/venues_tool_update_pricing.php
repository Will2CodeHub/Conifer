<?php
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$baseFee = isset($_POST['base_monthly_fee']) ? floatval($_POST['base_monthly_fee']) : 19.99;
$costPerBrand = isset($_POST['cost_per_brand']) ? floatval($_POST['cost_per_brand']) : 8.00;
$costPerVenue = isset($_POST['cost_per_venue']) ? floatval($_POST['cost_per_venue']) : 5.00;
$costPerSubcompany = isset($_POST['cost_per_subcompany']) ? floatval($_POST['cost_per_subcompany']) : 10.00;
$freeBrandPages = isset($_POST['free_brand_pages']) ? intval($_POST['free_brand_pages']) : 1;
$freeVenuePages = isset($_POST['free_venue_pages']) ? intval($_POST['free_venue_pages']) : 2;

if ($baseFee < 0 || $costPerBrand < 0 || $costPerVenue < 0 || $costPerSubcompany < 0) {
    echo json_encode(['success' => false, 'message' => 'Costs cannot be negative']);
    exit();
}

if ($freeBrandPages < 0 || $freeVenuePages < 0) {
    echo json_encode(['success' => false, 'message' => 'Free allowances cannot be negative']);
    exit();
}

$conn = getDBConnection();

// Check if pricing package exists
$checkQuery = "SELECT id FROM pricing_packages WHERE is_active = 1 LIMIT 1";
$result = $conn->query($checkQuery);

if ($result->num_rows > 0) {
    // Update existing
    $packageId = $result->fetch_assoc()['id'];
    $updateQuery = "UPDATE pricing_packages SET 
                   base_monthly_fee_eur = ?,
                   cost_per_extra_brand_page_eur = ?,
                   cost_per_extra_venue_page_eur = ?,
                   cost_per_subcompany_eur = ?,
                   free_brand_pages = ?,
                   free_venue_pages = ?
                   WHERE id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ddddiiii", $baseFee, $costPerBrand, $costPerVenue, $costPerSubcompany, 
                     $freeBrandPages, $freeVenuePages, $packageId);
} else {
    // Insert new
    $insertQuery = "INSERT INTO pricing_packages 
                   (package_name, base_monthly_fee_eur, cost_per_extra_brand_page_eur, 
                    cost_per_extra_venue_page_eur, cost_per_subcompany_eur, 
                    free_brand_pages, free_venue_pages, is_active) 
                   VALUES ('Standard Access', ?, ?, ?, ?, ?, ?, 1)";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("ddddii", $baseFee, $costPerBrand, $costPerVenue, $costPerSubcompany, 
                     $freeBrandPages, $freeVenuePages);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Pricing updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update pricing']);
}

$stmt->close();
$conn->close();
