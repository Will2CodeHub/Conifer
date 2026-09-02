<?php
/**
 * Email Helper Functions for TEN Venues Management
 * Handles email sending for verification, password reset, welcome emails, etc.
 * All functions wrapped in function_exists() to prevent conflicts
 */

/**
 * Send email using PHP mail() function
 * You can replace this with SMTP later if needed
 */
if (!function_exists('sendEmail')) {
    function sendEmail($to, $subject, $htmlContent, $fromName = 'TEN Management') {
        $fromEmail = 'noreply@theeyenewspapers.com';
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>" . "\r\n";
        $headers .= "Reply-To: {$fromEmail}" . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        error_log("Attempting to send email to: {$to}, Subject: {$subject}");
        $result = mail($to, $subject, $htmlContent, $headers);
        error_log("Email send result: " . ($result ? "SUCCESS" : "FAILED"));
        
        return $result;
    }
}

/**
 * Get email template wrapper
 */
if (!function_exists('getEmailTemplate')) {
    function getEmailTemplate($content, $title = '') {
        $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
        
        return "
        <!DOCTYPE html>
        <html lang='{$lang}'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$title}</title>
            <style>
                body {
                    font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    background-color: #f4f4f4;
                    margin: 0;
                    padding: 0;
                }
                .email-container {
                    max-width: 600px;
                    margin: 40px auto;
                    background: white;
                    overflow: hidden;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                }
                .email-header {
                    background: #2563eb;
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                .email-header h1 {
                    margin: 0;
                    font-size: 28px;
                    font-weight: 600;
                }
                .email-body {
                    padding: 40px 30px;
                }
                .email-footer {
                    background: #f8f9fa;
                    padding: 20px 30px;
                    text-align: center;
                    color: #6c757d;
                    font-size: 14px;
                    border-top: 2px solid #e5e7eb;
                }
                .button {
                    display: inline-block;
                    padding: 14px 32px;
                    background: #2563eb;
                    color: white !important;
                    text-decoration: none;
                    font-weight: 600;
                    margin: 20px 0;
                    text-align: center;
                }
                .button:hover {
                    background: #1d4ed8;
                }
                .info-box {
                    background: #f9fafb;
                    border-left: 4px solid #2563eb;
                    padding: 15px;
                    margin: 20px 0;
                }
                .text-muted {
                    color: #6c757d;
                    font-size: 14px;
                }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='email-header'>
                    <h1>TEN Venues Management</h1>
                </div>
                <div class='email-body'>
                    {$content}
                </div>
                <div class='email-footer'>
                    <p>&copy; " . date('Y') . " TEN Venues Management. All rights reserved.</p>
                    <p class='text-muted'>If you did not request this email, please ignore it.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}

/**
 * Send verification email
 */
if (!function_exists('sendVerificationEmail')) {
    function sendVerificationEmail($userId, $email, $name, $token) {
        $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://your-domain.com';
        $verificationUrl = $baseUrl . "/verify-email.php?token=" . urlencode($token);
        
        $subject = 'Verify Your Email Address';
        
        $content = "
            <h2>Hello {$name}!</h2>
            <p>Welcome to TEN Venues Management! We're excited to have you on board.</p>
            <p>To complete your registration and start building your venue presence, please verify your email address by clicking the button below:</p>
            
            <div style='text-align: center;'>
                <a href='{$verificationUrl}' class='button'>Verify Email Address</a>
            </div>
            
            <div class='info-box'>
                <p><strong>Alternative method:</strong></p>
                <p class='text-muted'>If the button doesn't work, copy and paste this link into your browser:</p>
                <p style='word-break: break-all; color: #2563eb;'>{$verificationUrl}</p>
            </div>
            
            <p class='text-muted'>This verification link will expire in 24 hours.</p>
            <p class='text-muted'>If you didn't create an account, you can safely ignore this email.</p>
        ";
        
        $htmlContent = getEmailTemplate($content, $subject);
        
        return sendEmail($email, $subject, $htmlContent, 'TEN Venues Management');
    }
}

/**
 * Send password reset email
 */
if (!function_exists('sendPasswordResetEmail')) {
    function sendPasswordResetEmail($email, $name, $token) {
        $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://your-domain.com';
        $resetUrl = $baseUrl . "/reset-password.php?token=" . urlencode($token);
        
        $subject = 'Reset Your Password';
        
        $content = "
            <h2>Hello {$name}!</h2>
            <p>We received a request to reset your password. Click the button below to create a new password:</p>
            
            <div style='text-align: center;'>
                <a href='{$resetUrl}' class='button'>Reset Password</a>
            </div>
            
            <div class='info-box'>
                <p><strong>Alternative method:</strong></p>
                <p class='text-muted'>If the button doesn't work, copy and paste this link into your browser:</p>
                <p style='word-break: break-all; color: #2563eb;'>{$resetUrl}</p>
            </div>
            
            <p class='text-muted'>This password reset link will expire in 1 hour.</p>
            <p class='text-muted'>If you didn't request a password reset, please ignore this email or contact support if you have concerns.</p>
        ";
        
        $htmlContent = getEmailTemplate($content, $subject);
        
        return sendEmail($email, $subject, $htmlContent, 'TEN Venues Management');
    }
}

/**
 * Send resend verification email
 */
if (!function_exists('sendResendVerificationEmail')) {
    function sendResendVerificationEmail($userId, $email, $name) {
        // Generate new token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Update database - only if getDBConnection exists
        if (function_exists('getDBConnection')) {
            $conn = getDBConnection();
            $stmt = $conn->prepare("UPDATE users SET verification_token = ?, verification_expires = ? WHERE id = ?");
            $stmt->bind_param("ssi", $token, $expiresAt, $userId);
            $stmt->execute();
            $stmt->close();
            
            // Also insert into email_verifications table if it exists
            $stmt = $conn->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $userId, $token, $expiresAt);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }
        
        // Send email
        return sendVerificationEmail($userId, $email, $name, $token);
    }
}

/**
 * Send welcome email with login credentials
 * Used when TEN Management creates a new company account
 */
if (!function_exists('sendWelcomeEmail')) {
    function sendWelcomeEmail($email, $name, $companyName, $password) {
        $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
        $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://your-domain.com';
        $loginUrl = $baseUrl . "/";
        
        $subject = 'Welcome to TEN Venues Management';
        
        $content = "
            <h2>Welcome {$name}!</h2>
            <p>Your company account for <strong>{$companyName}</strong> has been created in the TEN Venues Management system.</p>
            
            <div class='info-box'>
                <p><strong>Your Login Credentials:</strong></p>
                <p><strong>Email:</strong> {$email}</p>
                <p><strong>Password:</strong> <span style='font-size: 16px; font-weight: 600; color: #2563eb; letter-spacing: 1px;'>{$password}</span></p>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$loginUrl}' class='button'>Login to Your Account</a>
            </div>
            
            <p><strong>Important:</strong> For security reasons, we recommend changing your password immediately after your first login.</p>
            
            <p>With TEN Venues Management, you can:</p>
            <ul style='line-height: 2;'>
                <li>Manage your venues and brands</li>
                <li>Create and promote events</li>
                <li>Publish articles across TEN news portals</li>
                <li>Manage your team and permissions</li>
                <li>Track your subscription and billing</li>
            </ul>
            
            <p class='text-muted'>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
        ";
        
        $htmlContent = getEmailTemplate($content, $subject);
        
        return sendEmail($email, $subject, $htmlContent, 'TEN Management');
    }
}
