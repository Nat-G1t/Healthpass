{{--
    FR-STU-12 (D-39) — appointment scheduling notice.

    Table-based inline-styled HTML, same shape as mail/otp-verification.blade.php
    (email clients ignore stylesheets and most modern CSS). Light palette only —
    dark mode is web-app only (D-38) and an email has no theme toggle.

    EVERY interpolation below uses {{ }}, never {!! !!}. `purposeText` and the
    college name are free text typed by a student or a College Admin, so raw
    output here would be a stored-XSS vector in whatever webmail renders it.

    SCHEDULING DATA ONLY — no clearance outcome, no Fit/Unfit, no vitals,
    no questionnaire answers (FR-STU-08).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HealthPass — Appointment Scheduled</title>
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

                        @if ($isBatch)
                            <p style="margin:0 0 24px;color:#6B7280;">
                                @if ($collegeName)
                                    <strong>{{ $collegeName }}</strong> has booked
                                @else
                                    Your college has booked
                                @endif
                                a clearance appointment for you at the university clinic.
                                You did not need to do anything — the details are below.
                            </p>
                        @else
                            <p style="margin:0 0 24px;color:#6B7280;">
                                Your clearance appointment is confirmed. Here are the details.
                            </p>
                        @endif

                        {{-- Reference block --}}
                        <div style="text-align:center;background:#FFF7F0;border-radius:10px;padding:20px 0;margin-bottom:24px;">
                            <p style="margin:0 0 6px;font-size:12px;color:#9CA3AF;letter-spacing:1px;text-transform:uppercase;">
                                Reference number
                            </p>
                            <p style="margin:0;font-size:24px;font-weight:700;letter-spacing:3px;color:#FF8C2A;">
                                {{ $appointment->reference_no }}
                            </p>
                        </div>

                        {{-- Detail table --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:28px;">
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;width:40%;">Service</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;">
                                    Medical Clearance
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">Date</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">
                                    {{ $appointment->scheduled_date->format('l, F j, Y') }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">Time slot</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">
                                    {{-- "—" on pre-D-37 appointments, which belong to no slot --}}
                                    {{ $timeRange }}
                                </td>
                            </tr>
                            @if ($purposeText)
                                <tr>
                                    <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">Purpose</td>
                                    <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">
                                        {{ $purposeText }}
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td style="padding:8px 0;color:#9CA3AF;border-top:1px solid #F3F4F6;">Booked by</td>
                                <td style="padding:8px 0;font-weight:600;color:#4B5563;border-top:1px solid #F3F4F6;">
                                    {{ $isBatch ? ($collegeName ? $collegeName.' (your college)' : 'Your college') : 'You' }}
                                </td>
                            </tr>
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
                                Your <strong>Student ID</strong> — you will scan it at the self-service
                                kiosk when you arrive to start your vitals and screening.
                                Please come a few minutes before your time slot.
                            </p>
                        </div>

                        {{-- Tutorial pointer. This link lands on the authenticated student
                             tutorial page; a recipient who is not signed in is sent to the
                             login page first. No data is exposed by the URL itself. --}}
                        <p style="margin:0 0 24px;font-size:13px;line-height:1.7;color:#6B7280;">
                            First time using the kiosk? Walk through it before you arrive:
                            <a href="{{ $tutorialUrl }}" style="color:#FF8C2A;font-weight:600;">
                                view the Kiosk Tutorial
                            </a>
                            (sign in to HealthPass to open it).
                        </p>

                        {{-- Cancellation guidance (FR-STU-06, D-39) --}}
                        <div style="border-top:1px solid #F3F4F6;padding-top:20px;">
                            <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#4B5563;">
                                Need to cancel?
                            </p>
                            @if ($isBatch)
                                <p style="margin:0;font-size:13px;line-height:1.7;color:#6B7280;">
                                    This appointment was booked for you as part of a group, so you
                                    cannot cancel it yourself. Please contact
                                    {{ $collegeName ? 'your college ('.$collegeName.')' : 'your college' }}
                                    administrator — they will withdraw it, which frees the slot
                                    for another student.
                                </p>
                            @else
                                <p style="margin:0;font-size:13px;line-height:1.7;color:#6B7280;">
                                    You can cancel from your HealthPass dashboard any time up to the
                                    day before your appointment; that frees the slot for another
                                    student. On or after the day itself, please contact the clinic
                                    directly.
                                </p>
                            @endif
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
