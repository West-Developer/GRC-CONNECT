<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Your GRC queue number</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Arial, Helvetica, sans-serif;">
    <span style="display:none; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">
        {{ $previewText ?? 'Your queue number at the Cashier is '.$ticketNumber.'. Open the link to watch the live queue.' }}
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
                                Enrollment payment
                            </p>
                            <h1 style="margin:0 0 20px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:22px; font-weight:bold; line-height:1.3;">
                                Your queue number, {{ $studentName }}
                            </h1>
                            <p style="margin:0 0 16px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6;">
                                {{ $introText ?? 'Admission has recorded your requirements. Please go to the Cashier for your enrollment payment. Your queue number is:' }}
                            </p>
                        </td>
                    </tr>

                    <!-- Queue number -->
                    <tr>
                        <td style="padding:0 40px 8px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px;">
                                <tr>
                                    <td align="center" style="padding:20px;">
                                        <span style="font-family:Arial, Helvetica, sans-serif; font-size:40px; font-weight:bold; color:#c8102e; letter-spacing:2px;">{{ $ticketNumber }}</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- CTA button -->
                    <tr>
                        <td style="padding:20px 40px 28px 40px;">
                            <p style="margin:0 0 12px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.6;">
                                Open the live queue to see your own number and which number the Cashier is calling. You do not need to sign in.
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#c8102e" style="border-radius:6px; background-color:#c8102e;">
                                        <a href="{{ $liveUrl }}" target="_blank" rel="noopener" style="display:block; padding:13px 28px; color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; text-decoration:none; text-align:center; border-radius:6px;">
                                            View the live queue
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:16px 0 0 0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.6;">
                                If the button does not open, copy and paste this link into your browser:<br>
                                <a href="{{ $liveUrl }}" target="_blank" rel="noopener" style="color:#c8102e; word-break:break-all;">{{ $liveUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    <!-- Note -->
                    <tr>
                        <td style="padding:0 40px 32px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff7ed; border:1px solid #fde3cb; border-radius:6px;">
                                <tr>
                                    <td style="padding:14px 18px; color:#9a5b13; font-family:Arial, Helvetica, sans-serif; font-size:13px; line-height:1.6;">
                                        {{ $noteText ?? 'Your student account is set up after the Cashier confirms your payment; you will get another email then. Keep this link to yourself. If you did not apply to GRC, you can safely ignore this email.' }}
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
