<?php
declare(strict_types=1);

require_once __DIR__ . '/SpectraTelemetryGateway.php';

/**
 * SpectraSupportTicket — Canonical Support Ticket Dispatcher for SpectraOps Clients
 *
 * Queues support tickets into SpectraTelemetryGateway's outbox and immediately
 * flushes via POST /api/v1/sync with idempotent local_ticket_id / event_id keys.
 */
class SpectraSupportTicket
{
    /**
     * Dispatch a support ticket to SpectraOps.
     *
     * @param array{
     *   local_ticket_id?: string|int,
     *   subject: string,
     *   message: string,
     *   priority?: string,
     *   category?: string,
     *   submitted_by?: string,
     *   requester_name?: string,
     *   requester_email?: string,
     *   requester_role?: string,
     *   attachments?: array,
     *   system_snapshot?: array|string
     * } $ticketData
     */
    public static function dispatch(array $ticketData, ?SpectraTelemetryGateway $gateway = null): array
    {
        $subject = trim((string)($ticketData['subject'] ?? $ticketData['title'] ?? ''));
        $message = trim((string)($ticketData['message'] ?? $ticketData['body'] ?? $ticketData['description'] ?? ''));

        if ($subject === '' || $message === '') {
            return [
                'ok'      => false,
                'success' => false,
                'error'   => 'Ticket subject and message are required.',
            ];
        }

        $gw = $gateway ?? SpectraTelemetryGateway::fromConfig();

        $localId = (string)($ticketData['local_ticket_id'] ?? $ticketData['ticket_reference'] ?? ('TKT-' . strtoupper(bin2hex(random_bytes(4)))));
        $submittedBy = trim((string)($ticketData['submitted_by'] ?? $ticketData['requester_email'] ?? 'admin@client.local'));

        $payload = [
            'local_ticket_id'  => $localId,
            'subject'          => $subject,
            'message'          => $message,
            'priority'         => strtolower(trim((string)($ticketData['priority'] ?? 'medium'))),
            'category'         => strtolower(trim((string)($ticketData['category'] ?? 'general'))),
            'submitted_by'     => $submittedBy,
            'requester_name'   => trim((string)($ticketData['requester_name'] ?? $submittedBy)),
            'requester_email'  => trim((string)($ticketData['requester_email'] ?? $submittedBy)),
            'requester_role'   => trim((string)($ticketData['requester_role'] ?? 'Staff')),
            'attachments'      => is_array($ticketData['attachments'] ?? null) ? $ticketData['attachments'] : [],
            'system_snapshot'  => $ticketData['system_snapshot'] ?? null,
        ];

        $eventId = $gw->queueEvent('ticket', $payload, 'tkt_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $localId));
        $syncResult = $gw->sync([], true);

        return [
            'ok'              => true,
            'success'         => true,
            'event_id'        => $eventId,
            'local_ticket_id' => $localId,
            'synced'          => (bool)($syncResult['synced'] ?? false),
            'queued'          => empty($syncResult['synced']),
            'sync_response'   => $syncResult,
        ];
    }
}

if (!function_exists('spectra_send_support_ticket')) {
    /**
     * Procedural helper for sending a support ticket to SpectraOps.
     */
    function spectra_send_support_ticket(array $ticketData, ?SpectraTelemetryGateway $gateway = null): array
    {
        return SpectraSupportTicket::dispatch($ticketData, $gateway);
    }
}
