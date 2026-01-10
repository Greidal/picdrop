<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'libs/PHPMailer/src/Exception.php';
require 'libs/PHPMailer/src/PHPMailer.php';
require 'libs/PHPMailer/src/SMTP.php';
require 'config.php';

function sendVerificationMail($toEmail, $token)
{
    $mail = setupMailer();
    if (!$mail) return false;

    try {
        $mail->addAddress($toEmail);

        $baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
        $verifyLink = $baseUrl . "/verify.php?token=" . $token;

        $mail->Subject = 'Bitte bestätige deinen Kamerarsch Account 📸';
        $mail->Body    = "
            <h1>Willkommen zur Party! 🥳</h1>
            <p>Bitte klicke auf den Link unten, um deinen Account zu aktivieren:</p>
            <p><a href='$verifyLink' style='background:#ff0055; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Account bestätigen</a></p>
            <p>Oder Link kopieren: <br>$verifyLink</p>
        ";
        $mail->AltBody = "Link zum Bestätigen: $verifyLink";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sendEventAccessMail($toEmail, $eventName, $eventUuid)
{
    $mail = setupMailer();
    if (!$mail) return false;

    try {
        $mail->addAddress($toEmail);
        $baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
        $link = $baseUrl . "/manage_event.php?event=" . $eventUuid;

        $mail->Subject = "Einladung: Du hast Zugriff auf '$eventName'! 📸";
        $mail->Body    = "
            <h2>Hallo! 👋</h2>
            <p>Du wurdest für das Event <b>$eventName</b> freigeschaltet.</p>
            <p><a href='$link' style='background:#ff0055; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Zum Event</a></p>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sendInviteMail($toEmail, $eventName, $inviteToken)
{
    $mail = setupMailer();
    if (!$mail) return false;

    try {
        $mail->addAddress($toEmail);
        $baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
        $link = $baseUrl . "/register.php?invite=" . $inviteToken;

        $mail->Subject = "Einladung zu '$eventName' 📸";
        $mail->Body    = "
            <h2>Du wurdest eingeladen! 🥳</h2>
            <p>Jemand möchte, dass du beim Event <b>$eventName</b> die Fotos mitverwaltest.</p>
            <p>Du hast aber noch keinen Account. Klicke hier, um dich zu registrieren:</p>
            <p><a href='$link' style='background:#00ff88; color:#002200; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>Account erstellen & teilnehmen</a></p>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function setupMailer()
{
    $mail = new PHPMailer(true);
    try {
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
        return $mail;
    } catch (Exception $e) {
        return null;
    }
}
