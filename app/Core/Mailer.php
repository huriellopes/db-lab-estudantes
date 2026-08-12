<?php

declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envio de e-mail via SMTP (PHPMailer) — configurado por env (MAIL_HOST etc., ver
 * .env.example). Sem MAIL_HOST configurado, não envia nada e só registra no log — não
 * derruba a aplicação: dev/instâncias novas funcionam sem SMTP configurado, só o fluxo de
 * "esqueci minha senha" fica inoperante até alguém preencher isso.
 */
final class Mailer
{
    public static function send(string $toEmail, string $toName, string $subject, string $textBody): bool
    {
        $host = Config::get('MAIL_HOST');
        if ($host === null || $host === '') {
            error_log("Mailer: MAIL_HOST não configurada — e-mail para {$toEmail} (\"{$subject}\") não foi enviado.");

            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) Config::get('MAIL_PORT', '587');
            $mail->SMTPAuth = true;
            $mail->Username = Config::get('MAIL_USERNAME', '');
            $mail->Password = Config::get('MAIL_PASSWORD', '');
            $mail->SMTPSecure = Config::get('MAIL_ENCRYPTION', 'tls') === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(
                Config::get('MAIL_FROM_ADDRESS', $mail->Username) ?? $mail->Username,
                Config::get('MAIL_FROM_NAME', 'DB Lab Estudantes') ?? 'DB Lab Estudantes',
            );
            $mail->addAddress($toEmail, $toName);

            $mail->Subject = $subject;
            $mail->isHTML(false);
            $mail->Body = $textBody;

            return $mail->send();
        } catch (PHPMailerException $e) {
            error_log("Mailer: falha ao enviar pra {$toEmail}: {$e->getMessage()}");

            return false;
        }
    }
}
