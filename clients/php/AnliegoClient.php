<?php

/**
 * Anliego – PHP-Client für die Ticket-Schnittstelle (Einzeldatei, ohne Composer, PHP 7.4+, cURL).
 *
 * Einbinden:
 *   require_once __DIR__ . '/AnliegoClient.php';
 *   $anliego = new AnliegoClient('https://tickets.example.de', 'ank_…');
 *
 *   $ids = [];
 *   foreach ($_FILES['anhaenge']['tmp_name'] ?? [] as $i => $tmp) {
 *       $ids[] = $anliego->uploadAttachment($tmp, $_FILES['anhaenge']['name'][$i])['id'];
 *   }
 *
 *   $ticket = $anliego->createTicket([
 *       'subject'     => 'Beitrag wird falsch angezeigt',
 *       'description' => $text,
 *       'category'    => 'App-Fehler',
 *       'reporter'    => ['external_user_id' => (string) $userId, 'email' => $email, 'name' => $name],
 *       'context'     => ['url' => $seite, 'app_version' => APP_VERSION, 'browser' => $_SERVER['HTTP_USER_AGENT'] ?? ''],
 *       'attachment_ids' => $ids,
 *   ]);
 *   echo 'Ihre Meldung hat die Nummer ' . $ticket['reference'];
 *
 * Den API-Schlüssel nur auf dem Server speichern, nie im Browser oder in der Handy-App.
 */
class AnliegoException extends RuntimeException
{
    /** @var array<string, mixed>|null */
    public $response;

    public function __construct(string $message, int $code = 0, ?array $response = null)
    {
        parent::__construct($message, $code);
        $this->response = $response;
    }
}

class AnliegoClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var int */
    private $timeout;

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Legt ein Ticket an. Ohne eigenen Idempotency-Key wird einer erzeugt; bei einem Wiederholversuch
     * mit demselben Schlüssel entsteht kein zweites Ticket.
     *
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed> u. a. number, reference, status
     */
    public function createTicket(array $ticket, ?string $idempotencyKey = null): array
    {
        $idempotencyKey = $idempotencyKey ?: bin2hex(random_bytes(16));

        return $this->request('POST', '/api/v1/tickets', json_encode($ticket), [
            'Content-Type: application/json',
            'Idempotency-Key: '.$idempotencyKey,
        ]);
    }

    /**
     * Lädt eine Datei hoch (Screenshot, PDF, DOCX, XLSX, APK …) und liefert u. a. die 'id'.
     *
     * @return array<string, mixed>
     */
    public function uploadAttachment(string $path, ?string $filename = null, ?string $mimeType = null): array
    {
        if (! is_readable($path)) {
            throw new AnliegoException('Datei nicht lesbar: '.$path);
        }

        $file = new CURLFile($path, $mimeType ?: (mime_content_type($path) ?: 'application/octet-stream'), $filename ?: basename($path));

        return $this->request('POST', '/api/v1/attachments', ['file' => $file], []);
    }

    /**
     * Tickets eines Nutzers (für „Meine Meldungen“).
     *
     * @return list<array<string, mixed>>
     */
    public function ticketsOfUser(string $externalUserId): array
    {
        return $this->request('GET', '/api/v1/tickets?external_user_id='.rawurlencode($externalUserId))['data'];
    }

    /**
     * Ein Ticket mit Status und Nachrichten (ohne interne Notizen).
     *
     * @return array<string, mixed>
     */
    public function ticket(string $reference): array
    {
        return $this->request('GET', '/api/v1/tickets/'.rawurlencode($reference));
    }

    /**
     * Antwort des Nutzers auf sein Ticket. $reporter mit external_user_id und/oder email.
     * Liefert die Kurzform des Tickets; bei follow_up = true ist es ein neues Folgeticket.
     *
     * @param  array<string, string>  $reporter
     * @param  list<string>  $attachmentIds
     * @return array<string, mixed>
     */
    public function reply(string $reference, string $body, array $reporter, array $attachmentIds = []): array
    {
        return $this->request('POST', '/api/v1/tickets/'.rawurlencode($reference).'/messages', json_encode([
            'body' => $body,
            'reporter' => $reporter,
            'attachment_ids' => $attachmentIds,
        ]), ['Content-Type: application/json']);
    }

    /**
     * @param  string|array<string, mixed>|null  $body
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, $body = null, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl.$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => array_merge([
                'Authorization: Bearer '.$this->apiKey,
                'Accept: application/json',
            ], $headers),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new AnliegoException('Ticketsystem nicht erreichbar: '.$error);
        }

        $data = json_decode((string) $raw, true);

        if ($status >= 400 || ! is_array($data)) {
            $message = is_array($data) ? ($data['message'] ?? 'Fehler') : 'Unerwartete Antwort';
            throw new AnliegoException($message.' (HTTP '.$status.')', $status, is_array($data) ? $data : null);
        }

        return $data;
    }
}
