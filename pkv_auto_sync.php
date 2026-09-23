<?php
/**
 * PKV Auto-Sync Script
 * Automatically syncs new enquiries from health_insurance_enquiries to ten_pkv_enquiries
 * Run this as a cron job every 5-15 minutes
 * Example crontab: */5 * * * * php /path/to/pkv_auto_sync.php >> /path/to/logs/pkv_sync.log 2>&1
 */

require_once 'config.php';

// For cron jobs, we need to handle authentication differently
// This script should run without user session
$CRON_MODE = true;

$conn = getDBConnection();

// State mapping
$stateMapping = [
    'New' => 'new',
    'Awaiting Client Response' => 'awaiting_client_response',
    'Awaiting Insurer Response' => 'awaiting_insurer_response',
    'Agreed Sale - Finalising Contract' => 'contract_preparation',
    'Closed - Failed' => 'closed_failed',
    'Contract Signed' => 'contract_signed',
    'On Hold' => 'on_hold'
];

$publicationMapping = [
    'tme' => 'TME',
    'tge' => 'TGE'
];

$imported = 0;
$updated = 0;
$errors = [];
$systemUserId = 1; // System user ID for automated imports

try {
    // Get the last sync time
    $lastSyncQuery = "SELECT MAX(created_at) as last_sync FROM ten_pkv_enquiries WHERE source IN ('TME', 'TGE', 'FORM_IMPORT')";
    $lastSyncResult = $conn->query($lastSyncQuery)->fetch_assoc();
    $lastSync = $lastSyncResult['last_sync'] ?? '2000-01-01 00:00:00';
    
    // Get new or updated enquiries since last sync
    $stmt = $conn->prepare("SELECT * FROM health_insurance_enquiries WHERE date >= ? ORDER BY date ASC");
    $stmt->bind_param("s", $lastSync);
    $stmt->execute();
    $newEnquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo "[" . date('Y-m-d H:i:s') . "] PKV Auto-Sync started. Found " . count($newEnquiries) . " enquiries to process.\n";
    
    foreach ($newEnquiries as $old) {
        // Skip if no contact info
        if (!$old['email'] && !$old['phone']) {
            continue;
        }
        
        // Check if already exists
        $checkQuery = "SELECT id FROM ten_pkv_enquiries WHERE ";
        if ($old['email']) {
            $checkQuery .= "email = ? AND created_at >= ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("ss", $old['email'], $lastSync);
        } else {
            $checkQuery .= "first_name = ? AND last_name = ? AND phone = ? AND created_at >= ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("ssss", $old['first_name'], $old['last_name'], $old['phone'], $lastSync);
        }
        
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($existing) {
            continue; // Already imported
        }
        
        // Map state
        $newState = $stateMapping[$old['state']] ?? 'new';
        
        // Map source
        $source = $publicationMapping[$old['publication']] ?? 'FORM_IMPORT';
        
        // Combine messages
        $notes = [];
        if ($old['client_message']) {
            $notes[] = "CLIENT MESSAGE:\n" . $old['client_message'];
        }
        if ($old['insurer_message']) {
            $notes[] = "INSURER MESSAGE:\n" . $old['insurer_message'];
        }
        if ($old['ten_message']) {
            $notes[] = "TEN MESSAGE:\n" . $old['ten_message'];
        }
        $combinedNotes = implode("\n\n---\n\n", $notes);
        
        // Additional info
        $additionalInfo = "";
        if ($old['callback_request'] == '1') {
            $additionalInfo = "Client requested callback.";
        }
        
        // Insert new enquiry
        $insertQuery = "INSERT INTO ten_pkv_enquiries 
                       (first_name, last_name, email, phone, source, state, notes, additional_info, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("ssssssssss", 
            $old['first_name'],
            $old['last_name'],
            $old['email'],
            $old['phone'],
            $source,
            $newState,
            $combinedNotes,
            $additionalInfo,
            $old['date'],
            $old['date']
        );
        
        if ($stmt->execute()) {
            $newEnquiryId = $conn->insert_id;
            
            // Add history
            $historyStmt = $conn->prepare("INSERT INTO ten_pkv_history 
                                          (enquiry_id, user_id, action, note, new_state, created_at)
                                          VALUES (?, ?, 'Auto-imported from form', 'Original ID: " . $old['id'] . " from " . $old['publication'] . "', ?, ?)");
            $historyStmt->bind_param("iiss", $newEnquiryId, $systemUserId, $newState, $old['date']);
            $historyStmt->execute();
            $historyStmt->close();
            
            // Send notification to admins
            $adminQuery = "SELECT DISTINCT u.email 
                          FROM ten_users u
                          LEFT JOIN ten_user_roles ur ON u.id = ur.user_id
                          LEFT JOIN ten_role_permissions rp ON ur.role_id = rp.role_id
                          LEFT JOIN ten_permissions p ON rp.permission_id = p.id
                          WHERE (u.is_admin = 1 OR p.permission_key IN ('pkv_manage_all', 'pkv_manage'))
                          AND u.email IS NOT NULL";
            $adminEmails = $conn->query($adminQuery)->fetch_all(MYSQLI_ASSOC);
            
            foreach ($adminEmails as $admin) {
                $subject = "New PKV Enquiry from " . strtoupper($old['publication']) . " - " . $old['first_name'] . " " . $old['last_name'];
                $body = "A new PKV enquiry has been received:\n\n";
                $body .= "Enquiry ID: #$newEnquiryId\n";
                $body .= "Client: " . $old['first_name'] . " " . $old['last_name'] . "\n";
                $body .= "Email: " . ($old['email'] ?: 'Not provided') . "\n";
                $body .= "Phone: " . ($old['phone'] ?: 'Not provided') . "\n";
                $body .= "Source: $source\n";
                $body .= "Callback Requested: " . ($old['callback_request'] == '1' ? 'Yes' : 'No') . "\n\n";
                if ($old['client_message']) {
                    $body .= "Message:\n" . substr($old['client_message'], 0, 200) . (strlen($old['client_message']) > 200 ? '...' : '') . "\n\n";
                }
                $body .= "View in TEN Management: " . SITE_URL . "/module-pkv.php\n";
                
                sendEmail($admin['email'], $subject, nl2br($body));
            }
            
            $imported++;
            echo "[" . date('Y-m-d H:i:s') . "] Imported: " . $old['first_name'] . " " . $old['last_name'] . " (ID: $newEnquiryId)\n";
        } else {
            $errors[] = "Failed to import enquiry #" . $old['id'];
            echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to import enquiry #" . $old['id'] . "\n";
        }
        
        $stmt->close();
    }
    
    $conn->close();
    
    echo "[" . date('Y-m-d H:i:s') . "] PKV Auto-Sync completed. Imported: $imported, Errors: " . count($errors) . "\n";
    
    // Log to activity table if any imports
    if ($imported > 0) {
        logActivity('pkv_auto_sync', 'pkv_system', null, "Auto-synced $imported new enquiries from forms");
    }
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage() . "\n";
    
    // Send error notification to admin
    sendEmail(ADMIN_EMAIL, "PKV Auto-Sync Error", "PKV auto-sync encountered an error:\n\n" . $e->getMessage());
}