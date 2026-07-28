{{--
    FR-STU-13 (D-41) — appointment withdrawal notice.

    Same table-based inline-styled shape as mail/appointment-scheduled.blade.php.
    Light palette only — dark mode is web-app only (D-38) and email has no toggle.

    EVERY interpolation uses {{ }}, never {!! !!}. The college name is
    admin-managed text and must not reach a webmail client as live markup.

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
                            the clearance appointment it booked for you.
                            <strong>You no longer need to attend</strong> on the date below.
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
                                {{ $appointment->service_type === 'medical' ? 'Medical Clearance' : 'Dental Check' }}
                                · Ref. {{ $appointment->reference_no }}
                            </p>
                        </div>

                        @if ($canRebook)
                            <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#4B5563;">
                                Still need your clearance?
                            </p>
                            <p style="margin:0 0 24px;font-size:13px;line-height:1.7;color:#6B7280;">
                                You can book your own appointment at any time — you do not have to
                                wait for your college to arrange another batch.
                                <a href="{{ $bookingUrl }}" style="color:#FF8C2A;font-weight:600;">
                                    Book an appointment in HealthPass
                                </a>
                                (sign in first), and pick whichever date and hour suits you.
                            </p>
                        @endif

                        {{-- Wrong-cancellation path --}}
                        <div style="border-top:1px solid #F3F4F6;padding-top:20px;">
                            <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#4B5563;">
                                Think this is a mistake?
                            </p>
                            <p style="margin:0;font-size:13px;line-height:1.7;color:#6B7280;">
                                Contact
                                {{ $collegeName ? 'your college ('.$collegeName.')' : 'your college' }}
                                administrator — they arranged this booking and can add you to
                                another batch.
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
