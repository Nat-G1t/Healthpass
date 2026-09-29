{{--
    D-92 — approved-batch cancellation notice.

    Same table-based inline-styled shape as mail/appointment-scheduled.blade.php
    (email clients ignore stylesheets and most modern CSS). Light palette only.

    EVERY interpolation uses {{ }}, never {!! !!}. The reason and the college
    name are typed by a College Admin, so raw output here would be a stored-XSS
    vector in whatever webmail renders it.

    SCHEDULING DATA ONLY — no clearance outcome, no Fit/Unfit, no vitals,
    no questionnaire answers (FR-STU-08).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — Appointment Cancelled</title>
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
                            @if ($collegeName)
                                <strong>{{ $collegeName }}</strong> has cancelled
                            @else
                                Your college has cancelled
                            @endif
                            the batch your clinic appointment was booked in.
                            <strong>Your approved schedule is cancelled — you no longer need
                            to attend</strong> on the date below.
                        </p>

                        {{-- Cancelled banner. Slate rather than orange: this is
                             not a success moment and should not look like one. --}}
                        <div style="background:#F6F2ED;border-left:4px solid #9CA3AF;border-radius:8px;padding:18px 20px;margin-bottom:24px;">
                            <p style="margin:0 0 4px;font-size:11px;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;">
                                Cancelled appointment
                            </p>
                            <p style="margin:0;font-size:15px;font-weight:600;color:#4B5563;text-decoration:line-through;">
                                {{ $appointment->scheduled_date->format('l, F j, Y') }} · {{ $timeRange }}
                            </p>
                            <p style="margin:6px 0 0;font-size:12px;color:#9CA3AF;">
                                {{ $formLabel }} · Ref. {{ $appointment->reference_no }}
                            </p>
                        </div>

                        {{-- Detail table: the Batch ID and the college's reason --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:28px;">
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;width:40%;vertical-align:top;">Batch ID</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;">
                                    {{ $batchRef ?? '—' }}
                                </td>
                            </tr>
                            @if ($reason)
                                <tr>
                                    <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;vertical-align:top;">Reason</td>
                                    <td style="padding:8px 0;color:#4B5563;border-top:1px solid #F3F4F6;white-space:pre-line;">{{ $reason }}</td>
                                </tr>
                            @endif
                        </table>

                        {{-- D-61: clinic schedules come only from college batch
                             requests, so there is nothing to book — the way back
                             is through the college. --}}
                        <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#4B5563;">
                            Still need your clearance?
                        </p>
                        <p style="margin:0 0 24px;font-size:13px;line-height:1.7;color:#6B7280;">
                            Clinic schedules are arranged through your college. Ask
                            {{ $collegeName ? 'your college ('.$collegeName.')' : 'your college' }}
                            office to include you in a new batch request.
                        </p>

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
