{{--
    D-50 — staff account welcome notice (FR-AUTH-10).

    Same table-based inline-styled shape as mail/appointment-scheduled.blade.php.
    Light palette only — dark mode is web-app only (D-38) and email has no toggle.

    EVERY interpolation uses {{ }}, never {!! !!}: the name and college are
    admin-entered text and must not reach a webmail client as live markup.

    NO PASSWORD APPEARS HERE, and the body says so on purpose (D-35 / D-47). The
    one-time password is shown to the Director once, on screen, and handed over
    in person. Stating the rule in the message is what makes a future "HealthPass
    needs your password" mail obviously fake.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — Your staff account is ready</title>
</head>
<body style="margin:0;padding:0;background:#F6F2ED;font-family:sans-serif;color:#4B5563;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#F6F2ED;padding:40px 16px;">
        <tr><td align="center">
            <table width="520" cellpadding="0" cellspacing="0"
                   style="background:#FFFFFF;border-radius:12px;padding:40px;max-width:520px;">
                <tr>
                    <td>
                        {{-- Logo row --}}
                        <p style="margin:0 0 4px;font-size:22px;font-weight:700;color:#FF8C2A;">HealthPass</p>
                        <p style="margin:0 0 32px;font-size:12px;color:#9CA3AF;">
                            Pampanga State University — Medical Clearance System
                        </p>

                        <p style="margin:0 0 8px;">Hi {{ $staffName }},</p>

                        <p style="margin:0 0 24px;color:#6B7280;">
                            The University Clinic has created a HealthPass staff account for you.
                            You can sign in as soon as you have the one-time password the Clinic
                            Director gave you.
                        </p>

                        {{-- Account summary --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background:#F6F2ED;border-radius:8px;padding:20px;margin:0 0 24px;">
                            <tr>
                                <td style="padding:0 0 10px;font-size:13px;color:#9CA3AF;">Role</td>
                                <td style="padding:0 0 10px;font-size:13px;font-weight:600;text-align:right;">
                                    {{ $roleLabel }}
                                </td>
                            </tr>
                            @if ($collegeName)
                                <tr>
                                    <td style="padding:0 0 10px;font-size:13px;color:#9CA3AF;">College</td>
                                    <td style="padding:0 0 10px;font-size:13px;font-weight:600;text-align:right;">
                                        {{ $collegeName }}
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td style="font-size:13px;color:#9CA3AF;">Sign in with</td>
                                <td style="font-size:13px;font-weight:600;text-align:right;word-break:break-all;">
                                    {{ $staff->email }}
                                </td>
                            </tr>
                        </table>

                        {{-- Sign-in button. Follows APP_URL, so it needs no edit
                             of its own once the real domain is configured. --}}
                        <table cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                            <tr>
                                <td style="background:#FF8C2A;border-radius:999px;">
                                    <a href="{{ $loginUrl }}"
                                       style="display:inline-block;padding:12px 28px;font-size:14px;font-weight:600;color:#FFFFFF;text-decoration:none;">
                                        Sign in to HealthPass
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 24px;font-size:12px;color:#9CA3AF;word-break:break-all;">
                            Or paste this into your browser: {{ $loginUrl }}
                        </p>

                        {{-- The security note. Not boilerplate: it is what makes a
                             later "send us your password" mail recognisably fake. --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background:#FFF7ED;border-radius:8px;padding:16px;margin:0 0 24px;">
                            <tr>
                                <td style="font-size:13px;color:#6B7280;">
                                    <strong style="color:#4B5563;">About your password.</strong>
                                    HealthPass never sends passwords by email — not in this message and
                                    not in any other. Yours was handed to you directly by the Clinic
                                    Director, and you will be asked to replace it with one only you know
                                    the first time you sign in. If an email ever asks you for your
                                    HealthPass password, it did not come from us.
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0;font-size:12px;color:#9CA3AF;border-top:1px solid #E5E7EB;padding-top:16px;">
                            Didn't expect this? Contact the University Clinic before signing in.
                            This is an automated message — please do not reply.
                        </p>
                    </td>
                </tr>
            </table>
        </td></tr>
    </table>

</body>
</html>
