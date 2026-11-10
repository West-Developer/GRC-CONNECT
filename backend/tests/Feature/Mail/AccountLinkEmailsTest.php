<?php

namespace Tests\Feature\Mail;

use App\Domain\Identity\UserRole;
use App\Mail\FacultyAccountSetupMail;
use App\Mail\PasswordResetMail;
use App\Mail\StaffAccountSetupMail;
use App\Mail\StudentAccountSetupMail;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

/**
 * The account-setup / reset emails must be usable from a phone: the button is a
 * full-size block link to a complete URL, and the same link is also printed as
 * plain text so a mail client that does not make the button tappable (iOS Gmail
 * was reported) still auto-links it.
 */
final class AccountLinkEmailsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Mailable, 1: string}>
     */
    public static function mails(): iterable
    {
        $base = 'https://www.grc-enrollment.tech';

        yield 'student' => [new StudentAccountSetupMail('Denmar E. Curtivo', $base.'/account-setup', 'ABC123', 'denmar@grc.test'), $base.'/account-setup'];
        yield 'faculty' => [new FacultyAccountSetupMail($base.'/faculty-account-setup', 'ABC123', 'denmar@grc.test'), $base.'/faculty-account-setup'];
        yield 'staff' => [new StaffAccountSetupMail(UserRole::Faculty, $base.'/staff-account-setup', 'ABC123', 'denmar@grc.test'), $base.'/staff-account-setup'];
        yield 'password reset' => [new PasswordResetMail('Denmar', $base.'/reset-password', 'ABC123', 'denmar@grc.test'), $base.'/reset-password'];
    }

    /**
     * @dataProvider mails
     */
    public function test_the_link_is_a_block_button_and_is_repeated_as_a_plain_text_link(Mailable $mail, string $target): void
    {
        $html = $mail->render();
        $link = $target.'?email=denmar%40grc.test&amp;code=ABC123';

        // The button: a real, complete https link that fills its whole cell.
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote($link, '/').'"[^>]*display:block/', $html);
        // The fallback: the same link as visible text, linked too.
        $this->assertStringContainsString('copy and paste this link', $html);
        $this->assertStringContainsString('>'.$link.'</a>', $html);
        $this->assertSame(2, substr_count($html, 'href="'.$link.'"'));
    }
}
