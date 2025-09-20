<div style="font-family: Arial, sans-serif; padding:20px; color:#333;">
    <h2 style="color:#0d6efd;">Fuoday Password Reset</h2>
    <p>Dear {{ $user->first_name }} {{ $user->last_name }},</p>
    <p>We received a request to reset your password. Please use the following One-Time Password (OTP) to proceed:</p>
    <div style="font-size:22px; font-weight:bold; margin:20px 0; padding:10px; border:1px dashed #0d6efd; display:inline-block;">
        {{ $otp }}
    </div>
    <p>This OTP will expire in <strong>2 minutes</strong>. Do not share this code with anyone.</p>
    <p>If you did not request a password reset, please ignore this email or contact our support team immediately.</p>
    <br>
    <p>Thanks & Regards,<br>
    <strong>Fuoday Support Team</strong></p>
    <hr style="margin-top:20px;">
    <p style="font-size:12px; color:#777;">This is an automated email, please do not reply.</p>
</div>
