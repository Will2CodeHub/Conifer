<?php
/**
 * PKV Import Script
 * Imports existing enquiries from health_insurance_enquiries table into ten_pkv_enquiries
 * Run this ONCE after initial PKV module installation
 */

require_once 'config.php';
requireAdmin(); // Only admins can run this

$conn = getDBConnection();

// State mapping from old to new
$stateMapping = [
    'New' => 'new',
    'Awaiting Client Response' => 'awaiting_client_response',
    'Awaiting Insurer Response' => 'awaiting_insurer_response',
    'Agreed Sale - Finalising Contract' => 'contract_preparation',
    'Closed - Failed' => 'closed_failed',
    'Contract Signed' => 'contract_signed',
    'On Hold' => 'on_hold'
];

// Publication mapping
$publicationMapping = [
    'tme' => 'TME',
    'tge' => 'TGE'
];

$imported = 0;
$skipped = 0;
$errors = [];

try {
    // Get all enquiries from old table
    $oldEnquiries = $conn->query("SELECT * FROM health_insurance_enquiries ORDER BY date ASC")->fetch_all(MYSQLI_ASSOC);
    
    foreach ($oldEnquiries as $old) {
        // Check if already imported (by email or combination of name+phone)
        $checkQuery = "SELECT id FROM ten_pkv_enquiries WHERE ";
        if ($old['email']) {
            $checkQuery .= "email = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("s", $old['email']);
        } else if ($old['phone']) {
            $checkQuery .= "first_name = ? AND last_name = ? AND phone = ?";
            $stmt = $conn->prepare($checkQuery);
            $stmt->bind_param("sss", $old['first_name'], $old['last_name'], $old['phone']);
        } else {
            $skipped++;
            $errors[] = "Skipped enquiry #" . $old['id'] . " - no email or phone";
            continue;
        }
        
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($existing) {
            $skipped++;
            continue;
        }
        
        // Map the state
        $newState = $stateMapping[$old['state']] ?? 'new';
        
        // Map the publication
        $source = $publicationMapping[$old['publication']] ?? strtoupper($old['publication']);
        
        // Combine messages into notes
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
        $combinedNotes = implode("\n\n", $notes);
        
        // Additional info
        $additionalInfo = "";
        if ($old['callback_request'] == '1') {
            $additionalInfo = "Client requested callback.";
        }
        
        // Insert into new table
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
            
            // Add history entry for import
            $historyStmt = $conn->prepare("INSERT INTO ten_pkv_history 
                                          (enquiry_id, user_id, action, note, new_state, created_at)
                                          VALUES (?, ?, 'Imported from old system', 'Original enquiry ID: " . $old['id'] . "', ?, ?)");
            $historyStmt->bind_param("iiss", $newEnquiryId, $_SESSION['ten_user_id'], $newState, $old['date']);
            $historyStmt->execute();
            $historyStmt->close();
            
            $imported++;
        } else {
            $errors[] = "Failed to import enquiry #" . $old['id'] . ": " . $stmt->error;
        }
        
        $stmt->close();
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PKV Import Results</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f3f4f6;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #111827;
            margin-bottom: 24px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #111827;
        }
        .stat-label {
            font-size: 14px;
            color: #6b7280;
            margin-top: 8px;
        }
        .success { color: #10b981; }
        .warning { color: #f59e0b; }
        .error { color: #ef4444; }
        .errors {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
        }
        .errors h3 {
            color: #dc2626;
            margin-top: 0;
        }
        .error-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .error-list li {
            padding: 8px 0;
            border-bottom: 1px solid #fecaca;
        }
        .error-list li:last-child {
            border-bottom: none;
        }
        .actions {
            margin-top: 32px;
            display: flex;
            gap: 12px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .warning-box {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .warning-box strong {
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>PKV Import Results</h1>
        
        <?php if ($imported + $skipped > 0): ?>
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-value success"><?php echo $imported; ?></div>
                    <div class="stat-label">Imported</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value warning"><?php echo $skipped; ?></div>
                    <div class="stat-label">Skipped</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value error"><?php echo count($errors); ?></div>
                    <div class="stat-label">Errors</div>
                </div>
            </div>
            
            <?php if ($imported > 0): ?>
                <div class="warning-box">
                    <strong>✓ Success!</strong> <?php echo $imported; ?> enquiries have been imported into the PKV system.
                </div>
            <?php endif; ?>
            
            <?php if (count($errors) > 0): ?>
                <div class="errors">
                    <h3>Errors & Warnings</h3>
                    <ul class="error-list">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="actions">
                <a href="module-pkv.php" class="btn btn-primary">Go to PKV Module</a>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
            
        <?php else: ?>
            <div class="warning-box">
                <strong>No enquiries found</strong> in the health_insurance_enquiries table, or all have already been imported.
            </div>
            <div class="actions">
                <a href="module-pkv.php" class="btn btn-primary">Go to PKV Module</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>