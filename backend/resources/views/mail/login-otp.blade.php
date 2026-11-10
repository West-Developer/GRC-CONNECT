<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Your GRC sign-in verification code</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Arial, Helvetica, sans-serif;">
    <span style="display:none; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">
        Enter this one-time code on the sign-in page you already have open to finish signing in to your GRC account.
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
                                Sign-in verification
                            </p>
                            <h1 style="margin:0 0 20px 0; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:22px; font-weight:bold; line-height:1.3;">
                                Verify it&rsquo;s you, {{ $name }}
                            </h1>
                            <p style="margin:0 0 16px 0; color:#374151; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6;">
                                Someone is signing in to your GRC account. Enter the code below on the sign-in page you already have open to finish signing in.
                            </p>
                        </td>
                    </tr>

                    <!-- One-time code -->
                    <tr>
                        <td style="padding:0 40px 8px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:6px;">
                                <tr>
                                    <td align="center" style="padding:20px;">
                                        <span style="font-family:'Courier New', Courier, monospace; font-size:28px; font-weight:bold; color:#111827; letter-spacing:6px;">{{ $code }}</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Security note -->
                    <tr>
                        <td style="padding:20px 40px 32px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff7ed; border:1px solid #fde3cb; border-radius:6px;">
                                <tr>
                                    <td style="padding:14px 18px; color:#9a5b13; font-family:Arial, Helvetica, sans-serif; font-size:13px; line-height:1.6;">
                                        This code expires in {{ (int) config('auth.login_otp.challenge_ttl_minutes') }} minute(s) and can be used only once. If you did not just try to sign in, change your password and contact the Registrar's Office.
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
