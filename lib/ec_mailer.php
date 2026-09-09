<?php
/** Email Campaign Manager — mailer: build MIME + deliverability headers, send via ec_smtp. */
require_once __DIR__ . '/ec_core.php';
require_once __DIR__ . '/ec_smtp.php';

/** Decrypted settings singleton (row id=1). */
function ec_settings(): array {
    $c = ec_db();
    $row = $c->query("SELECT * FROM ten_ec_settings WHERE id=1")->fetch_assoc() ?: [];
    if (!empty($row['smtp_pass'])) $row['smtp_pass_plain'] = ec_decrypt($row['smtp_pass']);
    if (!empty($row['imap_pass'])) $row['imap_pass_plain'] = ec_decrypt($row['imap_pass']);
    return $row;
}

/** Encode a header value as RFC2047 if it contains non-ASCII. */
function ec_enc_header(string $v): string {
    return preg_match('/[^\x20-\x7e]/', $v) ? ('=?UTF-8?B?' . base64_encode($v) . '?=') : $v;
}

/**
 * Send one message.
 * @param array  $to   ['email'=>..., 'name'=>...]
 * @param array  $opts from_name, from_email, reply_to, message_id, return_path, list_unsub_url, extra_headers[]
 * @return array{ok:bool,message_id:string,error:string}
 */
function ec_send_message(array $to, string $subject, string $html, string $text, array $opts = []): array {
    $s = ec_settings();
    $fromEmail = $opts['from_email'] ?? '';
    if ($fromEmail === '') $fromEmail = $s['default_from_email'] ?? '';
    if ($fromEmail === '') return ['ok'=>false,'message_id'=>'','error'=>'No from address configured (Settings)'];
    $fromName = $opts['from_name'] ?? ($s['default_from_name'] ?? '');
    $replyTo  = $opts['reply_to'] ?? ($s['default_reply_to'] ?? $fromEmail);
    $domain = substr(strrchr($fromEmail, '@'), 1) ?: 'theeyenewspapers.com';

    $toEmail = $to['email']; $toName = $to['name'] ?? '';
    $messageId = $opts['message_id'] ?? ('<' . ec_token(16) . '@' . $domain . '>');
    $returnPath = $opts['return_path'] ?? $fromEmail;

    if ($text === '') $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    $boundary = 'ec_' . bin2hex(random_bytes(12));
    $H = [];
    $H[] = 'Date: ' . date('r');
    $H[] = 'From: ' . ($fromName !== '' ? ec_enc_header($fromName) . ' ' : '') . '<' . $fromEmail . '>';
    $H[] = 'To: ' . ($toName !== '' ? ec_enc_header($toName) . ' ' : '') . '<' . $toEmail . '>';
    $H[] = 'Reply-To: <' . $replyTo . '>';
    $H[] = 'Subject: ' . ec_enc_header($subject);
    $H[] = 'Message-ID: ' . $messageId;
    $H[] = 'MIME-Version: 1.0';
    if (!empty($opts['list_unsub_url'])) {
        $H[] = 'List-Unsubscribe: <' . $opts['list_unsub_url'] . '>';
        $H[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }
    $H[] = 'X-Mailer: TEN-EC';
    foreach (($opts['extra_headers'] ?? []) as $eh) $H[] = $eh;
    $H[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $B  = '--' . $boundary . "\r\n";
    $B .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $B .= chunk_split(base64_encode($text)) . "\r\n";
    $B .= '--' . $boundary . "\r\n";
    $B .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $B .= chunk_split(base64_encode($html)) . "\r\n";
    $B .= '--' . $boundary . "--\r\n";

    $raw = implode("\r\n", $H) . "\r\n\r\n" . $B;

    $res = ec_smtp_transmit([
        'host'=>$s['smtp_host']??'', 'port'=>$s['smtp_port']??587, 'security'=>$s['smtp_security']??'tls',
        'user'=>$s['smtp_user']??'', 'pass'=>$s['smtp_pass_plain']??'',
    ], $returnPath, $toEmail, $raw);

    return ['ok'=>$res['ok'],'message_id'=>$messageId,'error'=>$res['error']];
}
