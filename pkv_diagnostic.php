<?php
/**
 * PKV Diagnostic Script
 * Checks database, permissions, and displays enquiry data
 * Access: https://yourdomain.com/management/pkv_diagnostic.php
 */

require_once 'config.php';
requireLogin();

$currentUser = getCurrentUser();
$conn = getDBConnection();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PKV Diagnostic Tool</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f3f4f6;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #111827;
            margin-bottom: 8px;
        }
        h2 {
            color: #374151;
            margin-top: 0;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 12px;
        }
        .status {
            padding: 8px 16px;
            border-radius: 6px;
            display: inline-block;
            font-weight: 600;
            font-size: 14px;
        }
        .status-success { background: #d1fae5; color: #065f46; }
        .status-error { background: #fee2e2; color: #991b1b; }
        .status-warning { background: #fef3c7; color: #92400e; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            font-size: 14px;
            color: #6b7280;
        }
        .code {
            background: #1f2937;
            color: #10b981;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 PKV Diagnostic Tool</h1>
        <p style="color: #6b7280; margin-bottom: 32px;">Checking PKV module configuration and data...</p>
        
        <!-- Current User Info -->
        <div class="card">
            <h2>👤 Current User Information</h2>
            <table>
                <tr>
                    <th>User ID</th>
                    <td><?php echo $currentUser['id']; ?></td>
                </tr>
                <tr>
                    <th>Username</th>
                    <td><?php echo htmlspecialchars($currentUser['username'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Full Name</th>
                    <td><?php echo htmlspecialchars($currentUser['full_name'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Email</th>
                    <td><?php echo htmlspecialchars($currentUser['email'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>Is Admin</th>
                    <td><?php echo $currentUser['is_admin'] ? '<span class="status status-success">YES</span>' : '<span class="status status-warning">NO</span>'; ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Permissions Check -->
        <div class="card">
            <h2>🔐 PKV Permissions</h2>
            <table>
                <tr>
                    <th>Permission</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>pkv_view</td>
                    <td><?php echo hasPermission('pkv_view') ? '<span class="status status-success">✓ HAS</span>' : '<span class="status status-error">✗ MISSING</span>'; ?></td>
                </tr>
                <tr>
                    <td>pkv_manage</td>
                    <td><?php echo hasPermission('pkv_manage') ? '<span class="status status-success">✓ HAS</span>' : '<span class="status status-error">✗ MISSING</span>'; ?></td>
                </tr>
                <tr>
                    <td>pkv_assign</td>
                    <td><?php echo hasPermission('pkv_assign') ? '<span class="status status-success">✓ HAS</span>' : '<span class="status status-error">✗ MISSING</span>'; ?></td>
                </tr>
                <tr>
                    <td>pkv_manage_all</td>
                    <td><?php echo hasPermission('pkv_manage_all') ? '<span class="status status-success">✓ HAS</span>' : '<span class="status status-error">✗ MISSING</span>'; ?></td>
                </tr>
                <tr>
                    <td>pkv_external_broker</td>
                    <td><?php echo hasPermission('pkv_external_broker') ? '<span class="status status-success">✓ HAS</span>' : '<span class="status status-error">✗ MISSING</span>'; ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Database Tables Check -->
        <div class="card">
            <h2>🗄️ Database Tables</h2>
            <table>
                <tr>
                    <th>Table</th>
                    <th>Exists</th>
                    <th>Row Count</th>
                </tr>
                <?php
                $tables = ['ten_pkv_enquiries', 'ten_pkv_brokers', 'ten_pkv_history', 'ten_pkv_note_templates', 'ten_pkv_email_templates'];
                foreach ($tables as $table) {
                    $exists = false;
                    $count = 0;
                    try {
                        $result = $conn->query("SELECT COUNT(*) as cnt FROM $table");
                        if ($result) {
                            $exists = true;
                            $count = $result->fetch_assoc()['cnt'];
                        }
                    } catch (Exception $e) {
                        // Table doesn't exist
                    }
                    echo "<tr>";
                    echo "<td><code>$table</code></td>";
                    echo "<td>" . ($exists ? '<span class="status status-success">✓ EXISTS</span>' : '<span class="status status-error">✗ MISSING</span>') . "</td>";
                    echo "<td>" . ($exists ? "<strong>$count</strong> rows" : '-') . "</td>";
                    echo "</tr>";
                }
                ?>
            </table>
        </div>
        
        <!-- Enquiries Data -->
        <div class="card">
            <h2>📋 PKV Enquiries Data</h2>
            <?php
            $isExternalBroker = hasPermission('pkv_external_broker');
            $enquiryQuery = "SELECT * FROM ten_pkv_enquiries ";
            if ($isExternalBroker) {
                $enquiryQuery .= "WHERE assigned_broker_id = ? ";
                $stmt = $conn->prepare($enquiryQuery);
                $stmt->bind_param("i", $currentUser['id']);
                $stmt->execute();
                $enquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                $enquiryQuery .= "ORDER BY created_at DESC LIMIT 20";
                $enquiries = $conn->query($enquiryQuery)->fetch_all(MYSQLI_ASSOC);
            }
            
            if (empty($enquiries)) {
                echo '<p style="color: #ef4444; font-weight: 600;">⚠️ NO ENQUIRIES FOUND!</p>';
                echo '<p>Possible reasons:</p>';
                echo '<ul>';
                echo '<li>Import has not been run</li>';
                echo '<li>You are an external broker with no assigned enquiries</li>';
                echo '<li>Database query issue</li>';
                echo '</ul>';
            } else {
                echo '<p><span class="status status-success">Found ' . count($enquiries) . ' enquiries</span></p>';
                echo '<table>';
                echo '<tr><th>ID</th><th>Client</th><th>Email</th><th>Phone</th><th>State</th><th>Source</th><th>Created</th></tr>';
                foreach ($enquiries as $enq) {
                    echo '<tr>';
                    echo '<td>#' . $enq['id'] . '</td>';
                    echo '<td>' . htmlspecialchars($enq['first_name'] . ' ' . $enq['last_name']) . '</td>';
                    echo '<td>' . htmlspecialchars($enq['email'] ?? '-') . '</td>';
                    echo '<td>' . htmlspecialchars($enq['phone'] ?? '-') . '</td>';
                    echo '<td><code>' . $enq['state'] . '</code></td>';
                    echo '<td>' . htmlspecialchars($enq['source'] ?? '-') . '</td>';
                    echo '<td>' . date('Y-m-d H:i', strtotime($enq['created_at'])) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
            ?>
        </div>
        
        <!-- State Counts -->
        <div class="card">
            <h2>📊 Enquiries by State</h2>
            <?php
            $stateCountsQuery = "SELECT state, COUNT(*) as count FROM ten_pkv_enquiries ";
            if ($isExternalBroker) {
                $stateCountsQuery .= "WHERE assigned_broker_id = ? ";
            }
            $stateCountsQuery .= "GROUP BY state ORDER BY count DESC";
            
            if ($isExternalBroker) {
                $stmt = $conn->prepare($stateCountsQuery);
                $stmt->bind_param("i", $currentUser['id']);
                $stmt->execute();
                $stateCounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            } else {
                $stateCounts = $conn->query($stateCountsQuery)->fetch_all(MYSQLI_ASSOC);
            }
            
            if (empty($stateCounts)) {
                echo '<p style="color: #ef4444;">No state data found</p>';
            } else {
                echo '<table>';
                echo '<tr><th>State</th><th>Count</th></tr>';
                foreach ($stateCounts as $sc) {
                    echo '<tr>';
                    echo '<td><code>' . $sc['state'] . '</code></td>';
                    echo '<td><strong>' . $sc['count'] . '</strong></td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
            ?>
        </div>
        
        <!-- AJAX Test -->
        <div class="card">
            <h2>🔌 AJAX Endpoint Test</h2>
            <p>Click the button to test if the AJAX endpoint is working:</p>
            <button class="btn" onclick="testAjax()">Test AJAX Get Enquiries</button>
            <div id="ajaxResult" style="margin-top: 16px;"></div>
        </div>
        
        <!-- SQL Query for Manual Check -->
        <div class="card">
            <h2>💻 Manual SQL Query</h2>
            <p>Run this query directly in phpMyAdmin or MySQL:</p>
            <div class="code">
SELECT COUNT(*) as total_enquiries FROM ten_pkv_enquiries;
<br>SELECT state, COUNT(*) as count FROM ten_pkv_enquiries GROUP BY state;
<br>SELECT * FROM ten_pkv_enquiries ORDER BY created_at DESC LIMIT 10;
            </div>
        </div>
        
        <!-- Actions -->
        <div class="card">
            <h2>🔧 Actions</h2>
            <a href="module-pkv.php" class="btn">Go to PKV Module</a>
            <a href="dashboard.php" class="btn" style="background: #6b7280; margin-left: 12px;">Back to Dashboard</a>
        </div>
    </div>
    
    <script>
        function testAjax() {
            const resultDiv = document.getElementById('ajaxResult');
            resultDiv.innerHTML = '<p style="color: #6b7280;">Testing...</p>';
            
            fetch('ajax/pkv_get_enquiries.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = `
                            <div class="status status-success">✓ AJAX Working!</div>
                            <p style="margin-top: 12px;">Found <strong>${data.enquiries.length}</strong> enquiries</p>
                            <pre style="background: #f9fafb; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 12px;">${JSON.stringify(data, null, 2)}</pre>
                        `;
                    } else {
                        resultDiv.innerHTML = `
                            <div class="status status-error">✗ AJAX Error</div>
                            <p style="margin-top: 12px; color: #ef4444;">${data.message || 'Unknown error'}</p>
                        `;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `
                        <div class="status status-error">✗ AJAX Failed</div>
                        <p style="margin-top: 12px; color: #ef4444;">Error: ${error.message}</p>
                        <p>Check that ajax/pkv_get_enquiries.php exists and is accessible.</p>
                    `;
                });
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>