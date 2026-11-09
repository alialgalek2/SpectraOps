<?php
/**
 * Core IT Support & Ticketing Engine
 * 
 * Manages ticket creation, system diagnostics capture, automated telemetry log
 * extraction, and detailed multipart MIME email dispatch to algaletech@yahoo.com.
 */

class SupportTicket {

    const DEFAULT_RECIPIENT = 'algaletech@yahoo.com';

    /**
     * Generate a unique, human-readable ticket tracking number
     * Example: TICK-20260909-A4F8
     */
    public static function generateTicketNumber(): string {
        $prefix = 'TICK-' . date('Ymd') . '-';
        $random = strtoupper(bin2hex(random_bytes(2)));
        return $prefix . $random;
    }

    /**
     * Capture comprehensive server and client diagnostic telemetry
     */
    public static function getSystemDiagnostics(): array {
        global $pdo, $global_branding;

        $versionInfo = class_exists('SystemUpdater') ? SystemUpdater::getCurrentVersion() : ['version_number' => 'v2.5.0', 'build_number' => '2500'];
        $healthMetrics = class_exists('Telemetry') ? Telemetry::getHealthMetrics() : ['health_score' => 99.0, 'status_label' => 'Optimal'];

        $dbVersion = 'Unknown';
        if ($pdo) {
            try {
                $dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn() ?: 'MySQL';
            } catch (Throwable $e) {}
        }

        return [
            'app_name'          => $global_branding['school_name'] ?? 'EduMaster School System',
            'system_version'    => ($versionInfo['version_number'] ?? 'v2.5.0') . ' (Build ' . ($versionInfo['build_number'] ?? '2500') . ')',
            'reliability_score' => ($healthMetrics['health_score'] ?? 99.0) . '% (' . ($healthMetrics['status_label'] ?? 'Optimal') . ')',
            'php_version'       => PHP_VERSION,
            'os_family'         => PHP_OS_FAMILY . ' (' . php_uname('s') . ' ' . php_uname('r') . ')',
            'server_software'   => $_SERVER['SERVER_SOFTWARE'] ?? 'Localhost / Apache',
            'client_ip'         => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown User Agent',
            'timestamp'         => date('Y-m-d H:i:s T'),
            'request_uri'       => $_SERVER['REQUEST_URI'] ?? '/support.php',
            'database_version'  => $dbVersion
        ];
    }

    /**
     * Extract recent system error logs from telemetry table for diagnostic attachment
     */
    public static function getRecentErrorLogs(int $limit = 25): string {
        global $pdo;
        if (!$pdo) {
            return "No database connection available to extract telemetry logs.\n";
        }

        try {
            $stmt = $pdo->prepare("SELECT log_id, error_code, error_level, module_name, sanitized_message, file_path, line_number, occurrence_count, sync_status, last_seen_at FROM system_telemetry_logs ORDER BY last_seen_at DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($logs)) {
                return "=== SYSTEM TELEMETRY LOG DUMP ===\nTimestamp: " . date('Y-m-d H:i:s T') . "\nStatus: Clean (Zero active or staged error logs recorded).\n";
            }

            $out = "=== SYSTEM TELEMETRY LOG DUMP (ZERO-PII SCRUBBED) ===\n";
            $out .= "Generated At: " . date('Y-m-d H:i:s T') . "\n";
            $out .= "Total Entries Extracted: " . count($logs) . "\n";
            $out .= str_repeat("=", 70) . "\n\n";

            foreach ($logs as $i => $log) {
                $idx = $i + 1;
                $out .= "[$idx] CODE: {$log['error_code']} | LEVEL: " . strtoupper($log['error_level']) . " | MODULE: {$log['module_name']}\n";
                $out .= "    Occurrences: {$log['occurrence_count']} | Last Seen: {$log['last_seen_at']} | Status: {$log['sync_status']}\n";
                $out .= "    File: {$log['file_path']} (Line {$log['line_number']})\n";
                $out .= "    Message: {$log['sanitized_message']}\n";
                $out .= str_repeat("-", 60) . "\n";
            }

            return $out;
        } catch (Throwable $e) {
            return "Failed to query system_telemetry_logs: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Create ticket in database, gather diagnostics, process attachments, and dispatch email
     */
    public static function createTicket(array $data, ?array $file = null): array {
        global $pdo;

        // 1. Validation
        $submitterName  = trim($data['submitter_name'] ?? '');
        $submitterEmail = trim($data['submitter_email'] ?? '');
        $submitterPhone = trim($data['submitter_phone'] ?? '');
        $userRole       = trim($data['user_role'] ?? 'guest');
        $subject        = trim($data['subject'] ?? '');
        $category       = trim($data['category'] ?? 'Technical Bug / Error');
        $priority       = in_array($data['priority'] ?? '', ['low', 'medium', 'high', 'critical']) ? $data['priority'] : 'medium';
        $moduleAffected = trim($data['module_affected'] ?? 'General / Other');
        $description    = trim($data['description'] ?? '');
        $errorMessage   = trim($data['error_message'] ?? '');
        $attachLogs     = !empty($data['attach_logs']);
        $recipient      = self::DEFAULT_RECIPIENT;

        if (empty($submitterName)) {
            return ['success' => false, 'error' => 'Please provide your full name.'];
        }
        if (empty($submitterEmail) || !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please provide a valid email address.'];
        }
        if (empty($subject)) {
            return ['success' => false, 'error' => 'Please provide an issue subject / summary.'];
        }
        if (empty($description)) {
            return ['success' => false, 'error' => 'Please describe the problem or issue encountered.'];
        }

        // 2. Generate Ticket Identifier
        $ticketNumber = self::generateTicketNumber();
        $diagnostics = self::getSystemDiagnostics();

        // 3. Handle File Upload (Screenshots / Log Files)
        $attachmentPath = null;
        $attachmentName = null;

        if ($file && isset($file['error']) && $file['error'] === UPLOAD_ERR_OK) {
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'log', 'txt', 'json'];
            $origName = basename($file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExtensions)) {
                return ['success' => false, 'error' => 'Unsupported attachment file type. Allowed: jpg, png, pdf, log, txt, json.'];
            }

            if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
                return ['success' => false, 'error' => 'Attachment file exceeds maximum size limit of 10MB.'];
            }

            $uploadDir = BASE_PATH . '/uploads/support_attachments';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $safeFileName = 'ticket_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $ticketNumber) . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $safeFileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $attachmentPath = 'uploads/support_attachments/' . $safeFileName;
                $attachmentName = $origName;
            }
        }

        // 4. Save to Database
        $userId = Auth::check() ? (Auth::id() ?: null) : null;
        $ticketId = null;

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("INSERT INTO support_tickets (
                    ticket_number, user_id, submitter_name, submitter_email, submitter_phone,
                    user_role, subject, category, priority, module_affected, description,
                    error_message, system_diagnostics, error_logs_attached, attachment_path,
                    attachment_name, email_recipient, email_status, status
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, 'spooled', 'open'
                )");

                $stmt->execute([
                    $ticketNumber,
                    $userId,
                    $submitterName,
                    $submitterEmail,
                    $submitterPhone ?: null,
                    $userRole,
                    $subject,
                    $category,
                    $priority,
                    $moduleAffected ?: null,
                    $description,
                    $errorMessage ?: null,
                    json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    $attachLogs ? 1 : 0,
                    $attachmentPath,
                    $attachmentName,
                    $recipient
                ]);

                $ticketId = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                // If DB fails, still attempt to dispatch email
                error_log("Failed to insert support ticket into DB: " . $e->getMessage());
            }
        }

        // 5. Construct and Send Detailed Email
        $ticketRecord = [
            'ticket_id'      => $ticketId,
            'ticket_number'  => $ticketNumber,
            'submitter_name' => $submitterName,
            'submitter_email'=> $submitterEmail,
            'submitter_phone'=> $submitterPhone,
            'user_role'      => $userRole,
            'subject'        => $subject,
            'category'       => $category,
            'priority'       => $priority,
            'module_affected'=> $moduleAffected,
            'description'    => $description,
            'error_message'  => $errorMessage,
            'diagnostics'    => $diagnostics,
            'recipient'      => $recipient
        ];

        $uploadedFullFilePath = $attachmentPath ? (BASE_PATH . '/' . $attachmentPath) : null;
        $emailResult = self::sendTicketEmail($ticketRecord, $uploadedFullFilePath, $attachmentName, $attachLogs);

        // 5.5 Dispatch to Central Spectra Ops Hub
        $fleetSynced = false;
        try {
            $hubClient = class_exists('\App\Services\HubClient') ? \App\Services\HubClient::get() : null;
            if ($hubClient) {
                // Map category to central_support_tickets ENUM
                $catLower = strtolower($category);
                $fleetCat = match (true) {
                    str_contains($catLower, 'bug') || str_contains($catLower, 'error') || str_contains($catLower, 'glitch') => 'bug',
                    str_contains($catLower, 'feature') || str_contains($catLower, 'enhancement') => 'feature_request',
                    str_contains($catLower, 'billing') || str_contains($catLower, 'account') => 'billing',
                    str_contains($catLower, 'outage') || str_contains($catLower, 'critical') || str_contains($catLower, 'down') => 'critical_outage',
                    default => 'general'
                };

                // Map priority to central_support_tickets ENUM: ('low','medium','high','urgent')
                $priLower = strtolower($priority);
                $fleetPri = match ($priLower) {
                    'low' => 'low',
                    'high' => 'high',
                    'critical' => 'urgent',
                    default => 'medium'
                };

                $fleetSynced = $hubClient->submitSupportTicket([
                    'reference'       => $ticketNumber,
                    'requester_name'  => $submitterName,
                    'requester_email' => $submitterEmail,
                    'requester_role'  => $userRole,
                    'subject'         => "[{$moduleAffected}] {$subject}",
                    'category'        => $fleetCat,
                    'priority'        => $fleetPri,
                    'message'         => $description . (!empty($errorMessage) ? "\n\nError Details / Trace:\n" . $errorMessage : ''),
                    'snapshot'        => $diagnostics
                ]);
            }
        } catch (Throwable $fe) {
            error_log("Spectra Ops ticket dispatch error: " . $fe->getMessage());
        }

        // 6. Update Status in DB
        if ($ticketId && $pdo) {
            $statusVal = $emailResult['sent'] ? 'sent' : 'spooled';
            try {
                $uStmt = $pdo->prepare("UPDATE support_tickets SET email_status = ?, email_sent_at = NOW(), fleet_synced = ?, fleet_synced_at = NOW() WHERE id = ?");
                $uStmt->execute([$statusVal, $fleetSynced ? 1 : 0, $ticketId]);
            } catch (Throwable $e) {
                // In case fleet_synced column has not been added yet
                try {
                    $pdo->exec("ALTER TABLE support_tickets ADD COLUMN fleet_synced TINYINT(1) DEFAULT 0, ADD COLUMN fleet_synced_at DATETIME NULL");
                    $uStmt = $pdo->prepare("UPDATE support_tickets SET email_status = ?, email_sent_at = NOW(), fleet_synced = ?, fleet_synced_at = NOW() WHERE id = ?");
                    $uStmt->execute([$statusVal, $fleetSynced ? 1 : 0, $ticketId]);
                } catch (Throwable $e2) {}
            }
        }

        return [
            'success'        => true,
            'ticket_number'  => $ticketNumber,
            'ticket_id'      => $ticketId,
            'email_sent'     => $emailResult['sent'],
            'email_spooled'  => $emailResult['spooled'],
            'fleet_synced'   => $fleetSynced,
            'recipient'      => $recipient,
            'message'        => 'Your support ticket has been registered and dispatched to technical engineering and Spectra Ops.'
        ];
    }

    /**
     * Compose and dispatch multipart/mixed MIME email to algaletech@yahoo.com
     */
    public static function sendTicketEmail(array $ticket, ?string $uploadedFilePath, ?string $uploadedFileName, bool $attachLogs): array {
        $to = $ticket['recipient'] ?? self::DEFAULT_RECIPIENT;
        $ticketNumber = $ticket['ticket_number'];
        $priorityStr = strtoupper($ticket['priority']);
        $subjectLine = "[Support Ticket #{$ticketNumber}] [{$priorityStr}] {$ticket['subject']}";

        $boundary = "==Multipart_Boundary_x" . md5(uniqid(time())) . "x";

        // Headers
        $headers = [];
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "From: \"EduMaster IT Helpdesk\" <support@school.local>";
        $headers[] = "Reply-To: \"{$ticket['submitter_name']}\" <{$ticket['submitter_email']}>";
        $headers[] = "X-Priority: " . ($ticket['priority'] === 'critical' ? '1' : ($ticket['priority'] === 'high' ? '2' : '3'));
        $headers[] = "X-Ticket-ID: {$ticketNumber}";
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        // 1. Build HTML Body
        $htmlContent = self::buildHtmlEmailBody($ticket);

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlContent . "\r\n\r\n";

        // 2. Attach Telemetry Error Logs if requested
        if ($attachLogs) {
            $logsText = self::getRecentErrorLogs(30);
            $logFileName = "telemetry_logs_{$ticketNumber}.txt";

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; name=\"{$logFileName}\"\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$logFileName}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($logsText)) . "\r\n\r\n";
        }

        // 3. Attach User Uploaded File (if present)
        if ($uploadedFilePath && file_exists($uploadedFilePath)) {
            $fileData = file_get_contents($uploadedFilePath);
            $cleanName = $uploadedFileName ?: basename($uploadedFilePath);
            $mimeType = mime_content_type($uploadedFilePath) ?: 'application/octet-stream';

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$mimeType}; name=\"{$cleanName}\"\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$cleanName}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($fileData)) . "\r\n\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        // 4. Dispatch Email via PHP mail()
        $headersStr = implode("\r\n", $headers);
        $mailSent = false;

        try {
            // Use @ to suppress Windows local SMTP warnings if local sendmail is unconfigured
            $mailSent = @mail($to, $subjectLine, $body, $headersStr);
        } catch (Throwable $e) {
            $mailSent = false;
        }

        // 5. Always persist exact EML to Outgoing Spool on Disk for Guaranteed Reliability & Audit
        $spoolDir = BASE_PATH . '/uploads/support_spool';
        if (!is_dir($spoolDir)) {
            @mkdir($spoolDir, 0755, true);
        }

        $spoolFile = $spoolDir . '/' . $ticketNumber . '.eml';
        $emlContent = "To: {$to}\r\nSubject: {$subjectLine}\r\n{$headersStr}\r\n\r\n{$body}";
        @file_put_contents($spoolFile, $emlContent);

        return [
            'sent'       => (bool)$mailSent,
            'spooled'    => true,
            'spool_file' => $spoolFile
        ];
    }

    /**
     * Construct rich, responsive HTML email template
     */
    private static function buildHtmlEmailBody(array $ticket): string {
        $diag = $ticket['diagnostics'] ?? [];
        $priority = strtolower($ticket['priority']);

        $badgeColor = match($priority) {
            'critical' => '#dc2626',
            'high'     => '#ea580c',
            'medium'   => '#2563eb',
            default    => '#16a34a'
        };

        $badgeText = strtoupper($priority);
        $appName   = htmlspecialchars($diag['app_name'] ?? 'EduMaster School Management System');
        $submitter = htmlspecialchars($ticket['submitter_name']);
        $email     = htmlspecialchars($ticket['submitter_email']);
        $phone     = htmlspecialchars($ticket['submitter_phone'] ?: 'N/A');
        $role      = htmlspecialchars(ucfirst($ticket['user_role']));
        $category  = htmlspecialchars($ticket['category']);
        $module    = htmlspecialchars($ticket['module_affected'] ?: 'General');
        $subject   = htmlspecialchars($ticket['subject']);
        $descHtml  = nl2br(htmlspecialchars($ticket['description']));

        $errorBlock = '';
        if (!empty($ticket['error_message'])) {
            $errHtml = htmlspecialchars($ticket['error_message']);
            $errorBlock = <<<HTML
            <div style="margin-top: 25px; border: 1px solid #f87171; background-color: #0f172a; border-radius: 8px; padding: 18px; color: #fca5a5;">
                <div style="color: #ef4444; font-weight: bold; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">
                    &#9888; Reported Error Message &amp; Stack Trace
                </div>
                <pre style="margin: 0; font-family: Consolas, 'Courier New', Courier, monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; color: #fecaca; line-height: 1.5;">{$errHtml}</pre>
            </div>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 20px; color: #1e293b; }
    .container { max-width: 680px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .header { background: #1e1b4b; padding: 24px 30px; color: #ffffff; }
    .header h1 { margin: 0 0 6px 0; font-size: 20px; font-weight: 700; }
    .header p { margin: 0; font-size: 13px; color: #c7d2fe; }
    .badge-pill { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: bold; color: #ffffff; background: {$badgeColor}; }
    .content { padding: 28px 30px; }
    .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 22px; font-size: 13px; }
    .meta-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    .meta-table td.label { font-weight: 600; color: #64748b; width: 140px; }
    .meta-table td.val { color: #0f172a; font-weight: 500; }
    .section-title { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #4338ca; border-bottom: 2px solid #e0e7ff; padding-bottom: 6px; margin: 24px 0 14px 0; }
    .description-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; font-size: 14px; line-height: 1.6; color: #334155; }
    .diagnostics-table { width: 100%; font-size: 12px; border-collapse: collapse; background: #f8fafc; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
    .diagnostics-table td { padding: 6px 12px; border-bottom: 1px solid #e2e8f0; }
    .diagnostics-table td.d-label { font-weight: 600; color: #64748b; width: 160px; }
    .diagnostics-table td.d-val { font-family: Consolas, monospace; color: #0f172a; }
    .footer { background: #f8fafc; padding: 18px 30px; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; text-align: center; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div style="float: right;">
            <span class="badge-pill">{$badgeText} PRIORITY</span>
        </div>
        <h1>IT Support Dispatch &bull; #{$ticket['ticket_number']}</h1>
        <p>{$appName} Automated Helpdesk Gateway</p>
    </div>

    <div class="content">
        <table class="meta-table">
            <tr>
                <td class="label">Issue Subject</td>
                <td class="val" style="font-size: 15px; font-weight: 700; color: #1e1b4b;">{$subject}</td>
            </tr>
            <tr>
                <td class="label">Category</td>
                <td class="val">{$category}</td>
            </tr>
            <tr>
                <td class="label">Affected Module</td>
                <td class="val">{$module}</td>
            </tr>
            <tr>
                <td class="label">Submitter</td>
                <td class="val"><strong>{$submitter}</strong> ({$role}) &bull; <a href="mailto:{$email}" style="color: #4338ca;">{$email}</a></td>
            </tr>
            <tr>
                <td class="label">Contact Phone</td>
                <td class="val">{$phone}</td>
            </tr>
            <tr>
                <td class="label">Logged At</td>
                <td class="val">{$diag['timestamp']}</td>
            </tr>
        </table>

        <div class="section-title">Detailed Problem Description</div>
        <div class="description-box">
            {$descHtml}
        </div>

        {$errorBlock}

        <div class="section-title">System Telemetry &amp; Environment Diagnostics</div>
        <table class="diagnostics-table">
            <tr>
                <td class="d-label">System Version</td>
                <td class="d-val">{$diag['system_version']}</td>
            </tr>
            <tr>
                <td class="d-label">Reliability Score</td>
                <td class="d-val" style="color: #16a34a; font-weight: bold;">{$diag['reliability_score']}</td>
            </tr>
            <tr>
                <td class="d-label">PHP Version</td>
                <td class="d-val">{$diag['php_version']}</td>
            </tr>
            <tr>
                <td class="d-label">Operating System</td>
                <td class="d-val">{$diag['os_family']}</td>
            </tr>
            <tr>
                <td class="d-label">Web Server</td>
                <td class="d-val">{$diag['server_software']}</td>
            </tr>
            <tr>
                <td class="d-label">Database Engine</td>
                <td class="d-val">{$diag['database_version']}</td>
            </tr>
            <tr>
                <td class="d-label">Client User Agent</td>
                <td class="d-val" style="font-size: 11px;">{$diag['client_user_agent']}</td>
            </tr>
            <tr>
                <td class="d-label">Client IP Address</td>
                <td class="d-val">{$diag['client_ip']}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        This automated IT ticket was submitted through the EduMaster Support Center and dispatched to <strong>{$ticket['recipient']}</strong>.<br>
        Ticket ID: <code>{$ticket['ticket_number']}</code> &bull; All error logs are scrubbed under Zero-PII compliance standards.
    </div>
</div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Synchronize pending local tickets with Spectra Ops status (e.g. resolved / closed)
     */
    public static function syncTicketStatusesWithFleet(): void {
        global $pdo;
        if (!$pdo) return;

        try {
            // Find recent tickets that are not closed or resolved
            $stmt = $pdo->query("SELECT ticket_number, status FROM support_tickets WHERE status IN ('open', 'in_progress') ORDER BY id DESC LIMIT 15");
            $openTickets = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if (empty($openTickets)) {
                return;
            }

            $pdoCentral = new PDO("mysql:host=127.0.0.1;dbname=central_fleet_db;charset=utf8mb4", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_TIMEOUT => 1
            ]);

            $refs = array_column($openTickets, 'ticket_number');
            $inClause = implode(',', array_fill(0, count($refs), '?'));
            $cStmt = $pdoCentral->prepare("SELECT ticket_reference, status FROM central_support_tickets WHERE ticket_reference IN ($inClause)");
            $cStmt->execute($refs);
            $centralStatuses = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if (!empty($centralStatuses)) {
                $updStmt = $pdo->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE ticket_number = ?");
                foreach ($centralStatuses as $ref => $cStatus) {
                    if (in_array($cStatus, ['resolved', 'closed'], true)) {
                        $updStmt->execute([$cStatus, $ref]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail silently so page rendering never breaks
        }
    }

    /**
     * Retrieve tickets submitted by a specific user or recent global tickets for admin
     */
    public static function getRecentTickets(?int $userId = null, int $limit = 20): array {
        global $pdo;
        if (!$pdo) return [];

        // Auto-sync status of any pending tickets with Spectra Ops Hub
        self::syncTicketStatusesWithFleet();

        try {
            if ($userId !== null && !Auth::hasRole('admin') && !Auth::hasRole('school_admin')) {
                $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
                $stmt->bindValue(1, $userId, PDO::PARAM_INT);
                $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            } else {
                $stmt = $pdo->prepare("SELECT * FROM support_tickets ORDER BY created_at DESC LIMIT ?");
                $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
