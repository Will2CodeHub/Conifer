<?php
require_once 'config.php';
require_once 'email-helpers.php';

header('Content-Type: text/plain');

echo "EMAIL SENDING TEST\n";
echo "==================\n\n";

// Test 1: Check if functions exist
echo "1. Function checks:\n";
echo "   - sendEmail exists: " . (function_exists('sendEmail') ? "YES" : "NO") . "\n";
echo "   - getEmailTemplate exists: " . (function_exists('getEmailTemplate') ? "YES" : "NO") . "\n\n";

// Test 2: Try sending simple email
echo "2. Sending test email...\n";
$testEmail = "info@theeyenewspapers.com"; // Change to your email
$subject = "TEN Venues Test Email";
$content = "<h1>Test Email</h1><p>This is a test email from TEN Venues Management.</p>";

$htmlContent = getEmailTemplate($content, $subject);
$result = sendEmail($testEmail, $subject, $htmlContent, 'TEN Management');

echo "   Result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
echo "   Email sent to: {$testEmail}\n\n";

// Test 3: Check PHP mail configuration
echo "3. PHP mail() function available: " . (function_exists('mail') ? "YES" : "NO") . "\n";
echo "   sendmail_path: " . ini_get('sendmail_path') . "\n";
echo "   SMTP: " . ini_get('SMTP') . "\n";
echo "   smtp_port: " . ini_get('smtp_port') . "\n\n";

// Test 4: Check error log
echo "4. Check your error log for detailed email sending info\n";
echo "   Error log location: " . ini_get('error_log') . "\n\n";

echo "TEST COMPLETE\n";
