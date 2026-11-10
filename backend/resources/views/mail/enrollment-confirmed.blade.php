<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Your GRC enrollment is confirmed</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Arial, Helvetica, sans-serif;">
    <span style="display:none; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">
        Your payment has been confirmed and your Certificate of Registration ({{ $documentNumber }}) is ready to view.
    </span>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:100%; background-color:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:#c8102e; padding:28px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="width:44px; vertical-align:middle;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:40px; height:40px; background-color:#ffffff; border-radius:6px;">
                                            <tr>
                                                <td align="center" valign="middle" style="width:40px; height:40px; color:#c8102e; font-family:Arial, Helvetica, sans-serif; font-size:13px; font-weight:bold; letter-spacing:0.5px;">
                                                    GRC
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td style="vertical-align:middle; padding-left:12px;">
                                        <span style="color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; line-height:1.3;">
                                            Global Reciprocal Colleges
                                        </span><br>
                                        <span style="color:#f6c9d1; font-family:Arial, Helvetica, sans-serif; font-size:12px;">
                                            Automated Enrollment System
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:40px 40px 8px 40px;">
                            <p style="margin:0 0 4px 0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:13px; letter-spacing:0.5px; text-transform:uppercase;">
                                Enrollment confirmed
                            </p>
                            <h1 style="margin:0 0 20px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:22px; font-weight:bold; line-height:1.3;">
                                You're enrolled, {{ $name }}
                            </h1>
                            <p style="margin:0 0 16px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6;">
                                Your payment has been confirmed for School Year {{ $snapshot['term']['school_year'] ?? '' }}, {{ $snapshot['term']['semester'] ?? '' }} Semester. Your Certificate of Registration is ready to view.
                            </p>
                        </td>
                    </tr>

                    <!-- COR number -->
                    <tr>
                        <td style="padding:0 40px 24px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 2px 0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:12px;">
                                            Certificate of Registration No.
                                        </p>
                                        <span style="font-family:'Courier New', Courier, monospace; font-size:16px; color:#111827; letter-spacing:0.5px;">{{ $documentNumber }}</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Subjects -->
                    @if (! empty($snapshot['subjects']))
                    <tr>
                        <td style="padding:0 40px 8px 40px;">
                            <p style="margin:0 0 10px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:bold;">
                                Subjects enrolled
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e7eb; border-radius:6px; overflow:hidden;">
                                @foreach ($snapshot['subjects'] as $subject)
                                <tr>
                                    <td style="padding:10px 16px; border-bottom:1px solid #f0f0f0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:13px;">
                                        <strong>{{ $subject['code'] }}</strong> &middot; {{ $subject['title'] }}
                                    </td>
                                    <td align="right" style="padding:10px 16px; border-bottom:1px solid #f0f0f0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:13px; white-space:nowrap;">
                                        {{ $subject['units'] }} units
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            <p style="margin:10px 0 0 0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:13px;">
                                Total units: {{ $snapshot['total_units'] ?? '0.00' }}
                            </p>
                        </td>
                    </tr>
                    @endif

                    <!-- Payment summary -->
                    <tr>
                        <td style="padding:24px 40px 8px 40px;">
                            <p style="margin:0 0 10px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:bold;">
                                Payment summary
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:4px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:13px;">Total assessment</td>
                                    <td align="right" style="padding:4px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:13px;">{{ $snapshot['fees']['currency'] ?? 'PHP' }} {{ $snapshot['fees']['grand_total'] ?? '0.00' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:13px;">Amount paid</td>
                                    <td align="right" style="padding:4px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:13px;">{{ $snapshot['fees']['currency'] ?? 'PHP' }} {{ $snapshot['fees']['amount_paid'] ?? '0.00' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:13px; font-weight:bold;">Remaining balance</td>
                                    <td align="right" style="padding:4px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:13px; font-weight:bold;">{{ $snapshot['fees']['currency'] ?? 'PHP' }} {{ $snapshot['fees']['remaining_balance'] ?? '0.00' }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- CTA button -->
                    <tr>
                        <td style="padding:20px 40px 32px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-radius:6px; background-color:#c8102e;">
                                        <a href="{{ $portalUrl }}" style="display:inline-block; padding:13px 28px; color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; text-decoration:none; border-radius:6px;">
                                            View your enrollment
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 40px; border-top:1px solid #e5e7eb; background-color:#fafafa;">
                            <p style="margin:0 0 4px 0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.6;">
                                This is an automated message from the Global Reciprocal Colleges Automated Enrollment System. Please do not reply directly to this email.
                            </p>
                            <p style="margin:0; color:#9ca3af; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.6;">
                                Global Reciprocal Colleges &middot; Caloocan City
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
