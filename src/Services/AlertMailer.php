<?php
declare(strict_types=1);

namespace App\Services;

class AlertMailer {
    private string $adminEmail;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $fromEmail;

    public function __construct() {
        $this->adminEmail = getenv('ADMIN_ALERT_EMAIL') ?: 'alerts@yourcentraldomain.com';
        $this->smtpHost   = getenv('SMTP_HOST') ?: 'smtp.mailgun.org';
        $this->smtpPort   = (int)(getenv('SMTP_PORT') ?: 587);
        $this->smtpUser   = getenv('SMTP_USER') ?: '';
        $this->smtpPass   = getenv('SMTP_PASS') ?: '';
        $this->fromEmail  = getenv('SMTP_FROM') ?: 'no-reply@yourcentraldomain.com';
    }

    public function sendTicketAlert(array $ticket, array $deployment): bool {
        $rawSubject = sprintf(
            "[%s TICKET] %s - %s",
            strtoupper($ticket['priority'] ?? 'MEDIUM'),
            $deployment['client_name'] ?? 'Client',
            $ticket['subject'] ?? 'New Support Request'
        );

        // Strip carriage returns and line feeds to prevent header injection (H8)
        $subject = str_replace(["\r", "\n"], '', $rawSubject);
        $replyTo = !empty($ticket['requester_email'])
            ? str_replace(["\r", "\n"], '', (string)$ticket['requester_email'])
            : $this->fromEmail;

        $badgeColor = match (strtolower($ticket['priority'] ?? 'medium')) {
            'urgent', 'critical' => '#dc2626',
            'high'               => '#ea580c',
            'medium'             => '#0284c7',
            default              => '#16a34a'
        };

        $htmlBody = "
        <div style='font-family: Arial, sans-serif; background: #f8fafc; padding: 24px; color: #1e293b;'>
            <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0;'>
                <div style='background: #091830; padding: 18px 24px; color: #ffffff;'>
                    <span style='background: {$badgeColor}; color: #ffffff; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase;'>
                        " . htmlspecialchars($ticket['priority'] ?? 'MEDIUM') . "
                    </span>
                    <h2 style='margin: 10px 0 0 0; font-size: 18px; color: #0dc0e7;'>New Support Ticket Submitted</h2>
                </div>
                <div style='padding: 24px;'>
                    <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;'>
                        <tr><td style='padding: 6px 0; color: #64748b;'><strong>Reference:</strong></td><td><code>" . htmlspecialchars($ticket['reference'] ?? 'N/A') . "</code></td></tr>
                        <tr><td style='padding: 6px 0; color: #64748b;'><strong>Client / School:</strong></td><td>" . htmlspecialchars($deployment['client_name'] ?? '') . " (" . htmlspecialchars($deployment['deployment_domain'] ?? '') . ")</td></tr>
                        <tr><td style='padding: 6px 0; color: #64748b;'><strong>Product:</strong></td><td>" . htmlspecialchars($deployment['product_name'] ?? 'Prism EDU') . "</td></tr>
                        <tr><td style='padding: 6px 0; color: #64748b;'><strong>Requester:</strong></td><td>" . htmlspecialchars($ticket['requester_name'] ?? 'Staff') . " &lt;" . htmlspecialchars($ticket['requester_email'] ?? 'N/A') . "&gt; (" . htmlspecialchars($ticket['requester_role'] ?? 'Staff') . ")</td></tr>
                        <tr><td style='padding: 6px 0; color: #64748b;'><strong>Category:</strong></td><td>" . htmlspecialchars($ticket['category'] ?? 'General') . "</td></tr>
                    </table>
                    <div style='background: #f1f5f9; padding: 14px; border-radius: 8px; border-left: 4px solid #0dc0e7; margin-bottom: 24px;'>
                        <strong style='display: block; margin-bottom: 6px; color: #0f172a;'>Message:</strong>
                        <p style='margin: 0; line-height: 1.5; white-space: pre-wrap; font-size: 14px;'>" . htmlspecialchars($ticket['message'] ?? '') . "</p>
                    </div>
                    <a href='https://support.yourcentraldomain.com/dashboard.php' style='display: inline-block; background: #0dc0e7; color: #091830; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 14px;'>
                        Open Fleet Support Hub →
                    </a>
                </div>
            </div>
        </div>";

        // Fast fallback to PHP native mail() with HTML headers if SMTP credentials are empty
        if (empty($this->smtpUser) || empty($this->smtpPass)) {
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: Prism Hub <{$this->fromEmail}>\r\n";
            $headers .= "Reply-To: <{$replyTo}>\r\n";
            return @mail($this->adminEmail, $subject, $htmlBody, $headers);
        }

        // Direct Socket SMTP Handshake
        return $this->sendRawSmtp($this->adminEmail, $subject, $htmlBody, $replyTo);
    }

    private function sendRawSmtp(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool {
        $subject = str_replace(["\r", "\n"], '', $subject);
        if ($replyTo !== null) {
            $replyTo = str_replace(["\r", "\n"], '', $replyTo);
        }

        $timeout = 5;
        $socket = @fsockopen(
            ($this->smtpPort === 465 ? 'ssl://' : '') . $this->smtpHost,
            $this->smtpPort,
            $errno,
            $errstr,
            $timeout
        );

        if (!$socket) return false;

        $readResponse = function() use ($socket): string {
            $response = '';
            while (!feof($socket)) {
                $line = fgets($socket, 512);
                if ($line === false) break;
                $response .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') break;
                if (strlen($line) >= 3 && strlen(rtrim($line)) === 3) break;
            }
            return $response;
        };

        $checkCode = function(string $resp, array $expectedCodes): bool {
            $code = substr(trim($resp), 0, 3);
            return in_array($code, $expectedCodes, true);
        };

        $write = fn(string $cmd) => fputs($socket, $cmd . "\r\n");

        // Initial greeting
        $initResp = $readResponse();
        if (!$checkCode($initResp, ['220'])) {
            fclose($socket);
            return false;
        }

        $write("EHLO " . gethostname());
        $ehloResp = $readResponse();
        if (!$checkCode($ehloResp, ['250'])) {
            fclose($socket);
            return false;
        }

        if ($this->smtpPort === 587) {
            $write("STARTTLS");
            $tlsResp = $readResponse();
            if (!$checkCode($tlsResp, ['220'])) {
                fclose($socket);
                return false;
            }

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return false;
            }

            $write("EHLO " . gethostname());
            $ehloTlsResp = $readResponse();
            if (!$checkCode($ehloTlsResp, ['250'])) {
                fclose($socket);
                return false;
            }
        }

        $write("AUTH LOGIN");
        $authResp = $readResponse();
        if (!$checkCode($authResp, ['334'])) {
            fclose($socket);
            return false;
        }

        $write(base64_encode($this->smtpUser));
        $userResp = $readResponse();
        if (!$checkCode($userResp, ['334'])) {
            fclose($socket);
            return false;
        }

        $write(base64_encode($this->smtpPass));
        $passResp = $readResponse();
        if (!$checkCode($passResp, ['235'])) {
            fclose($socket);
            return false;
        }

        $write("MAIL FROM: <{$this->fromEmail}>");
        $mailResp = $readResponse();
        if (!$checkCode($mailResp, ['250'])) {
            fclose($socket);
            return false;
        }

        $write("RCPT TO: <{$to}>");
        $rcptResp = $readResponse();
        if (!$checkCode($rcptResp, ['250', '251'])) {
            fclose($socket);
            return false;
        }

        $write("DATA");
        $dataResp = $readResponse();
        if (!$checkCode($dataResp, ['354'])) {
            fclose($socket);
            return false;
        }

        $headers  = "From: Prism Fleet Hub <{$this->fromEmail}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: <{$replyTo}>\r\n";
        }
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";

        $write($headers . $htmlBody . "\r\n.");
        $sendResp = $readResponse();
        if (!$checkCode($sendResp, ['250'])) {
            fclose($socket);
            return false;
        }

        $write("QUIT");
        fclose($socket);

        return true;
    }
}
