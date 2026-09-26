<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

function sendVerificationMail(string $toEmail, string $token): bool
{
    $verifyLink = e(appBaseUrl() . "/verify.php?token=" . urlencode($token));

    return sendMail(
        $toEmail,
        'Bitte bestätige deinen ' . PAGE_TITLE . ' Account 📸',
        "
            <h1>Willkommen zur Party! 🥳</h1>
            <p>Bitte klicke auf den Link unten, um deinen Account zu aktivieren:</p>
            <p><a href='$verifyLink' style='background:#ff0055; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Account bestätigen</a></p>
            <p>Oder Link kopieren: <br>$verifyLink</p>
        ",
        "Link zum Bestätigen: " . html_entity_decode($verifyLink)
    );
}

function sendEventAccessMail(string $toEmail, string $eventName, string $eventUuid): bool
{
    $link = e(appBaseUrl() . "/manage_event.php?event=" . urlencode($eventUuid));
    $name = e($eventName);

    return sendMail(
        $toEmail,
        "Einladung: Du hast Zugriff auf '$eventName'! 📸",
        "
            <h2>Hallo! 👋</h2>
            <p>Du wurdest für das Event <b>$name</b> freigeschaltet.</p>
            <p><a href='$link' style='background:#ff0055; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Zum Event</a></p>
        "
    );
}

function sendInviteMail(string $toEmail, string $eventName, string $inviteToken): bool
{
    $link = e(appBaseUrl() . "/register.php?invite=" . urlencode($inviteToken));
    $name = e($eventName);

    return sendMail(
        $toEmail,
        "Einladung zu '$eventName' 📸",
        "
            <h2>Du wurdest eingeladen! 🥳</h2>
            <p>Jemand möchte, dass du beim Event <b>$name</b> die Fotos mitverwaltest.</p>
            <p>Du hast aber noch keinen Account. Klicke hier, um dich zu registrieren:</p>
            <p><a href='$link' style='background:#00ff88; color:#002200; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>Account erstellen & teilnehmen</a></p>
        "
    );
}

function sendMail(string $toEmail, string $subject, string $htmlBody, ?string $altBody = null): bool
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->isHTML(true);

        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        if ($altBody !== null) {
            $mail->AltBody = $altBody;
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PicDrop: sending mail failed: ' . $e->getMessage());
        return false;
    }
}
