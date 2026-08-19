{{--
    D-50 — College Admin transfer notice (FR-AUTH-10).

    Same table-based inline-styled shape as the other notices. Light palette only
    (D-38). Every interpolation uses {{ }}, never {!! !!}.

    Account scope only: which college this person now manages. No student data
    and nothing clinical.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — You now manage {{ $toCode }}</title>
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
                            The Clinic Director has moved your HealthPass account to a different
                            college. This takes effect immediately — the next time you sign in, every
                            page will show the new college's data.
                        </p>

                        {{-- From / to --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background:#F6F2ED;border-radius:8px;padding:20px;margin:0 0 24px;">
                            <tr>
                                <td style="padding:0 0 10px;font-size:13px;color:#9CA3AF;">Previously</td>
                                <td style="padding:0 0 10px;font-size:13px;text-align:right;">
                                    {{ $fromCollege }}
                                </td>
                            </tr>
                            <tr>
                                <td style="font-size:13px;color:#9CA3AF;">Now managing</td>
                                <td style="font-size:13px;font-weight:600;color:#FF8C2A;text-align:right;">
                                    {{ $toCollege }}
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 24px;color:#6B7280;">
                            From now on you will see {{ $toCode }}'s students, batch requests and
                            analytics. You no longer have access to {{ $fromCode }}'s records —
                            anything you submitted for them stays on file with that college.
                        </p>

                        {{-- Follows APP_URL, so no edit needed when the domain is set. --}}
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

                        <p style="margin:0;font-size:12px;color:#9CA3AF;border-top:1px solid #E5E7EB;padding-top:16px;">
                            Weren't expecting this change? Contact the University Clinic.
                            This is an automated message — please do not reply.
                        </p>
                    </td>
                </tr>
            </table>
        </td></tr>
    </table>

</body>
</html>
