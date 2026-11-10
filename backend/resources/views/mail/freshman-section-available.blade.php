<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>A section is now open for you</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Arial, Helvetica, sans-serif;">
    <span style="display:none; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">
        A section is now open for your program and year. Open Enrollment to choose it.
    </span>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:100%; background-color:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb;">

                    <tr>
                        <td style="background-color:#c8102e; padding:28px 40px;">
                            <span style="color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; line-height:1.3;">
                                Global Reciprocal Colleges
                            </span><br>
                            <span style="color:#f6c9d1; font-family:Arial, Helvetica, sans-serif; font-size:12px;">
                                Automated Enrollment System
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:40px 40px 8px 40px;">
                            <p style="margin:0 0 4px 0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:13px; letter-spacing:0.5px; text-transform:uppercase;">
                                Enrollment
                            </p>
                            <h1 style="margin:0 0 20px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:22px; font-weight:bold; line-height:1.3;">
                                A section is now open, {{ $studentName }}
                            </h1>
                            <p style="margin:0 0 16px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6;">
                                Every section for your year level was full when you paid. Seats are now open in <strong>{{ $blockLabel }}</strong>. Open Enrollment, choose the section and submit. Once Registrar Staff approve it you are enrolled; your earlier payment is applied, so you do not pay again.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 40px 28px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#c8102e" style="border-radius:6px; background-color:#c8102e;">
                                        <a href="{{ $enrollmentUrl }}" target="_blank" rel="noopener" style="display:block; padding:13px 28px; color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; text-decoration:none; text-align:center; border-radius:6px;">
                                            Open Enrollment
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:16px 0 0 0; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.6;">
                                If the button does not open, copy and paste this link into your browser:<br>
                                <a href="{{ $enrollmentUrl }}" target="_blank" rel="noopener" style="color:#c8102e; word-break:break-all;">{{ $enrollmentUrl }}</a>
                            </p>
                        </td>
                    </tr>

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
