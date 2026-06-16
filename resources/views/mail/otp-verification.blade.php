<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — Email Verification</title>
</head>
<body style="margin:0;padding:0;background:#F6F2ED;font-family:sans-serif;color:#4B5563;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#F6F2ED;padding:40px 16px;">
        <tr><td align="center">
            <table width="480" cellpadding="0" cellspacing="0"
                   style="background:#FFFFFF;border-radius:12px;padding:40px;max-width:480px;">
                <tr>
                    <td>
                        {{-- Logo row --}}
                        <p style="margin:0 0 4px;font-size:22px;font-weight:700;color:#FF8C2A;">HealthPass</p>
                        <p style="margin:0 0 32px;font-size:12px;color:#9CA3AF;">
                            Pampanga State University — Medical Clearance System
                        </p>

                        <p style="margin:0 0 8px;">Hi {{ $firstName }},</p>
                        <p style="margin:0 0 24px;color:#6B7280;">
                            Use the code below to verify your email address. It expires in
                            <strong>10 minutes</strong>.
                        </p>

                        {{-- OTP block --}}
                        <div style="text-align:center;background:#FFF7F0;border-radius:10px;padding:24px 0;margin-bottom:24px;">
                            <p style="margin:0 0 6px;font-size:12px;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;">
                                Your verification code
                            </p>
                            <p style="margin:0;font-size:40px;font-weight:700;letter-spacing:16px;color:#FF8C2A;">
                                {{ $otp }}
                            </p>
                        </div>

                        <p style="margin:0 0 8px;font-size:13px;color:#6B7280;">
                            Enter this code in the HealthPass registration page to continue.
                        </p>
                        <p style="margin:0;font-size:12px;color:#9CA3AF;">
                            If you did not request this, you can safely ignore this email.
                            Do not share this code with anyone.
                        </p>
                    </td>
                </tr>
            </table>

            {{-- Footer --}}
            <p style="margin:20px 0 0;font-size:11px;color:#9CA3AF;text-align:center;">
                Protected under the Data Privacy Act of 2012 (RA 10173)
            </p>
        </td></tr>
    </table>

</body>
</html>
