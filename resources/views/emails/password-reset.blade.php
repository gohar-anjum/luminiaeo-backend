<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 30px; border-radius: 8px;">
        <h1 style="color: #333; margin-top: 0;">Reset Your Password</h1>
        
        <p>Hello,</p>
        
        <p>You are receiving this email because we received a password reset request for your account.</p>
        
        <p>Click the button below to reset your password:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $resetUrl }}" style="background-color: #007bff; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Reset Password</a>
        </div>
        
        <p>Or copy and paste this URL into your browser:</p>
        <p style="word-break: break-all; color: #007bff;">{{ $resetUrl }}</p>
        
        <p style="margin-top: 30px; color: #666; font-size: 14px;">
            This password reset link will expire in 60 minutes.
        </p>
        
        <p style="margin-top: 20px; color: #666; font-size: 14px;">
            If you did not request a password reset, no further action is required.
        </p>
        
        <p style="margin-top: 30px; color: #999; font-size: 12px; border-top: 1px solid #eee; padding-top: 20px;">
            If you're having trouble clicking the button, copy and paste the URL above into your web browser.
        </p>
    </div>
</body>
</html>

