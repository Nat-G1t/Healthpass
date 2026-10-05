{{--
    D-110 — the 2nd-chance notice: the student missed their clinic day and the
    8 PM job booked them one new day, on the same batch.

    Same table-based, inline-styled layout as mail/appointment-scheduled.blade.php
    (email clients ignore stylesheets and most modern CSS). The red box is the
    `danger` badge's colours (D-107), the one red the app uses.

    EVERY interpolation uses {{ }}, never {!! !!}: the student's name is free
    text, and raw output would be a stored-XSS vector in webmail.

    SCHEDULING DATA ONLY — no clearance outcome, no Fit/Unfit, no vitals, no
    questionnaire answers (FR-STU-08).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — Your 2nd Chance</title>
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

                        <p style="margin:0 0 8px;">Hi {{ $studentName }},</p>

                        <p style="margin:0 0 24px;color:#6B7280;">
                            You missed your {{ $formName }} at the university clinic
                            @if ($missedDate)
                                on <strong>{{ $missedDate->format('l, F j, Y') }}</strong>.
                            @else
                                on your scheduled day.
                            @endif
                            HealthPass has booked you one more day — the details are below.
                        </p>

                        {{-- Last chance --}}
                        <div style="background:#FEE2E2;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
                            <p style="margin:0;font-size:14px;font-weight:700;color:#B91C1C;">
                                This is your last chance.
                            </p>
                            <p style="margin:6px 0 0;font-size:13px;line-height:1.6;color:#B91C1C;">
                                If you miss this day too, you will not be scheduled again.
                            </p>
                        </div>

                        {{-- Batch ID block (D-101 — never the APT reference) --}}
                        <div style="text-align:center;background:#FFF7F0;border-radius:10px;padding:20px 0;margin-bottom:24px;">
                            <p style="margin:0 0 6px;font-size:12px;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;">
                                Batch ID
                            </p>
                            <p style="margin:0;font-size:24px;font-weight:700;letter-spacing:3px;color:#FF8C2A;">
                                {{ $batchRef ?? '—' }}
                            </p>
                        </div>

                        {{-- Detail table --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:28px;">
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;width:40%;">Form</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;">
                                    {{ $formName }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">New date</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">
                                    {{ $appointment->scheduled_date->format('l, F j, Y') }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">Time slot</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">
                                    {{ $timeRange }}
                                </td>
                            </tr>
                            @if ($arrivalTime)
                                <tr>
                                    <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">Arrive at</td>
                                    <td style="padding:8px 0;font-weight:700;color:#FF8C2A;border-top:1px solid #F3F4F6;">
                                        {{ $arrivalTime }}
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">Where</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">
                                    {{ $clinicLocation }}
                                </td>
                            </tr>
                        </table>

                        {{-- What to bring --}}
                        <div style="background:#F6F2ED;border-radius:10px;padding:18px 20px;margin-bottom:24px;">
                            <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#4B5563;">
                                On the day, bring
                            </p>
                            <p style="margin:0;font-size:13px;line-height:1.7;color:#6B7280;">
                                Your <strong>Student ID</strong> or your <strong>HealthPass QR code</strong> —
                                you will scan it at the self-service kiosk when you arrive.
                                @if ($arrivalTime)
                                    Please arrive at <strong>{{ $arrivalTime }}</strong>.
                                @else
                                    Please come a few minutes before your time slot.
                                @endif
                            </p>
                        </div>

                        <p style="margin:24px 0 0;font-size:12px;color:#9CA3AF;">
                            This is an automated scheduling notice — please do not reply to it.
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
