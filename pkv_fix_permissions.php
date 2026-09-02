<?php
/**
 * PKV Fix - Remove External Broker Permission from Admin
 * This fixes the issue where admin users can't see enquiries because
 * they have pkv_external_broker permission but no broker account
 */

require_once 'config.php';
requireAdmin();

$conn = getDBConnection();
$userId = $_SESSION['ten_user_id'];

// Get the external broker permission ID
$stmt = $conn->prepare("SELECT id FROM ten_permissions WHERE permission_key = 'pkv_external_broker'");
$stmt->execute();
$result = $stmt->get_result();
$externalBrokerPerm = $result->fetch_assoc();
$stmt->close();

if (!$externalBrokerPerm) {
    die("External broker permission not found in database.");
}

$permId = $externalBrokerPerm['id'];

// Get user's roles
$stmt = $conn->prepare("SELECT role_id FROM ten_user_roles WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$userRoles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$removed = 0;

// Remove external broker permission from all user's roles
foreach ($userRoles as $role) {
    $roleId = $role['role_id'];
    
    $stmt = $conn->prepare("DELETE FROM ten_role_permissions WHERE role_id = ? AND permission_id = ?");
    $stmt->bind_param("ii", $roleId, $permId);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $removed++;
    }
    $stmt->close();
}

$conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PKV Permission Fixed</title>
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
        .success {
            background: #d1fae5;
            border: 2px solid #10b981;
            color: #065f46;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 600;
        }
        .info {
            background: #dbeafe;
            border: 1px solid #3b82f6;
            color: #1e40af;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        .btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-right: 12px;
        }
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ PKV Permission Fixed!</h1>
        
        <div class="success">
            ✓ The <code>pkv_external_broker</code> permission has been removed from your roles.
        </div>
        
        <div class="info">
            <strong>What was the problem?</strong><br>
            You had the <code>pkv_external_broker</code> permission, which restricts users to only see enquiries assigned to their broker account. Since you're an admin without a broker account, you couldn't see ANY enquiries.
            <br><br>
            <strong>What was fixed?</strong><br>
            Removed <code>pkv_external_broker</code> permission from <?php echo $removed; ?> of your roles. You now have full access to all PKV enquiries.
        </div>
        
        <h2>Next Steps:</h2>
        <ol>
            <li>Click "Go to PKV Module" button below</li>
            <li>You should now see all 271 enquiries</li>
            <li>Stats should show correct counts</li>
            <li>You can now manage all enquiries</li>
        </ol>
        
        <p style="margin-top: 32px;">
            <a href="module-pkv.php" class="btn">Go to PKV Module</a>
            <a href="pkv_diagnostic.php" class="btn" style="background: #6b7280;">Run Diagnostic Again</a>
        </p>
        
        <hr style="margin: 32px 0; border: none; border-top: 1px solid #e5e7eb;">
        
        <h3>About External Broker Permission</h3>
        <p>The <code>pkv_external_broker</code> permission should ONLY be assigned to:</p>
        <ul>
            <li>External broker users (not your staff)</li>
            <li>Users who should only see THEIR assigned clients</li>
            <li>Partner companies managing a subset of enquiries</li>
        </ul>
        <p><strong>Admin users and internal staff should NOT have this permission.</strong></p>
    </div>
</body>
</html>
