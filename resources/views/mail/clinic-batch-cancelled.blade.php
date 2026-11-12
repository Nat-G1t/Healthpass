{{--
    D-137 — "an approved batch was cancelled", to each active nurse and
    physician (App\Mail\ClinicBatchCancelledMail). D-129: the Director can
    cancel too, so the opening line names who did.

    Same table-based inline-styled shape as the student notices. Light
    palette only.

    EVERY interpolation uses {{ }}, never {!! !!}: the reason and the college
    name are typed by people, so raw output here would be a stored-XSS vector
    in whatever webmail renders it.

    A COUNT of students, never their names. No link — a cancelled batch has
    no page on the Clinic Dashboard.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — Batch Cancelled</title>
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
                            @if ($cancelledByDirector)
                                The Clinic Director has cancelled
                            @else
                                <strong>{{ $collegeName }}</strong> has cancelled
                            @endif
                            an approved batch. <strong>Its students will no longer come</strong>
                            on the date below, and their seats are free again.
                        </p>

                        {{-- Cancelled banner. Slate rather than orange: this is
                             not a success moment and should not look like one. --}}
                        <div style="background:#F6F2ED;border-left:4px solid #9CA3AF;border-radius:8px;padding:18px 20px;margin-bottom:24px;">
                            <p style="margin:0 0 4px;font-size:11px;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;">
                                Cancelled batch
                            </p>
                            <p style="margin:0;font-size:15px;font-weight:600;color:#4B5563;text-decoration:line-through;">
                                {{ $dateLabel }} · {{ $hoursLabel }}
                            </p>
                            <p style="margin:6px 0 0;font-size:12px;color:#9CA3AF;">
                                {{ $formLabel }} · Batch {{ $batchRef }}
                            </p>
                        </div>

                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:28px;">
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;width:40%;vertical-align:top;">Batch ID</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;">{{ $batchRef }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;vertical-align:top;">College</td>
                                <td style="padding:8px 0;color:#4B5563;border-top:1px solid #F3F4F6;">{{ $collegeName }} ({{ $collegeCode }})</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;vertical-align:top;">Form</td>
                                <td style="padding:8px 0;color:#4B5563;border-top:1px solid #F3F4F6;">{{ $formLabel }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;vertical-align:top;">Purpose</td>
                                <td style="padding:8px 0;color:#4B5563;border-top:1px solid #F3F4F6;">{{ $purpose }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;vertical-align:top;">Students not coming</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">{{ $studentsLabel }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;vertical-align:top;">Cancelled by</td>
                                <td style="padding:8px 0;color:#4B5563;border-top:1px solid #F3F4F6;">{{ $cancellerLabel }}</td>
                            </tr>
                            @if ($reason)
                                <tr>
                                    <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;vertical-align:top;">Reason</td>
                                    <td style="padding:8px 0;color:#4B5563;border-top:1px solid #F3F4F6;white-space:pre-line;">{{ $reason }}</td>
                                </tr>
                            @endif
                        </table>

                        <p style="margin:24px 0 0;font-size:12px;color:#9CA3AF;">
                            You are receiving this because you are clinic staff on HealthPass.
                            This is an automated notice — please do not reply to it.
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
