<?php
/**
 * Minimal, self-contained authenticated SMTP client (one recipient per call).
 * Supports implicit SSL (port 465), STARTTLS (587) and plain; AUTH LOGIN/PLAIN.
 * ec_mailer.php builds the raw MIME; this only transmits it.
 */

/** Read a full (possibly multi-line) SMTP reply. Returns [code, text]. */
function ec_smtp_read($fp): array {
    $data = '';
    while (($line = fgets($fp, 4096)) !== false) {
        $data .= $line;
        // continuation lines have a '-' as the 4th char: "250-..." ; final: "250 ..."
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    $code = (int)substr($data, 0, 3);
    return [$code, trim($data)];
}

function ec_smtp_cmd($fp, string $cmd): array {
    fwrite($fp, $cmd . "\r\n");
    return ec_smtp_read($fp);
}

/**
 * Transmit one message.
 * @param array $s settings: host,port,security('ssl'|'tls'|'none'),user,pass
 * @param string $envelopeFrom  MAIL FROM address (return-path)
 * @param string $rcpt          RCPT TO address
 * @param string $rawData       full RFC822 message (headers + CRLF CRLF + body)
 * @return array{ok:bool,error:string}
 */
function ec_smtp_transmit(array $s, string $envelopeFrom, string $rcpt, string $rawData): array {
    $host = $s['host'] ?? ''; $port = (int)($s['port'] ?? 587);
    $sec = $s['security'] ?? 'tls';
    if ($host === '') return ['ok'=>false,'error'=>'SMTP host not configured'];

    $transport = ($sec === 'ssl') ? "ssl://$host:$port" : "tcp://$host:$port";
    $ctx = stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);
    $errno=0;$errstr='';
    $fp = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok'=>false,'error'=>"connect failed: $errstr ($errno)"];
    stream_set_timeout($fp, 30);

    try {
        [$code,] = ec_smtp_read($fp);
        if ($code !== 220) throw new Exception("greeting: $code");
        $ehlo = 'ehlo ' . (gethostname() ?: 'localhost');
        [$code,$resp] = ec_smtp_cmd($fp, $ehlo);
        if ($code !== 250) throw new Exception("EHLO: $code");

        if ($sec === 'tls') {
            [$code,] = ec_smtp_cmd($fp, 'STARTTLS');
            if ($code !== 220) throw new Exception("STARTTLS: $code");
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT))
                throw new Exception('TLS negotiation failed');
            [$code,$resp] = ec_smtp_cmd($fp, $ehlo);
            if ($code !== 250) throw new Exception("EHLO(tls): $code");
        }

        // AUTH (LOGIN preferred; fall back to PLAIN)
        if (!empty($s['user'])) {
            [$code,] = ec_smtp_cmd($fp, 'AUTH LOGIN');
            if ($code === 334) {
                [$code,] = ec_smtp_cmd($fp, base64_encode($s['user']));
                if ($code !== 334) throw new Exception("AUTH user: $code");
                [$code,] = ec_smtp_cmd($fp, base64_encode($s['pass'] ?? ''));
                if ($code !== 235) throw new Exception("AUTH pass rejected: $code");
            } else {
                $token = base64_encode("\0" . $s['user'] . "\0" . ($s['pass'] ?? ''));
                [$code,] = ec_smtp_cmd($fp, 'AUTH PLAIN ' . $token);
                if ($code !== 235) throw new Exception("AUTH PLAIN rejected: $code");
            }
        }

        [$code,] = ec_smtp_cmd($fp, 'MAIL FROM:<' . $envelopeFrom . '>');
        if ($code !== 250) throw new Exception("MAIL FROM: $code");
        [$code,] = ec_smtp_cmd($fp, 'RCPT TO:<' . $rcpt . '>');
        if ($code !== 250 && $code !== 251) throw new Exception("RCPT TO: $code");
        [$code,] = ec_smtp_cmd($fp, 'DATA');
        if ($code !== 354) throw new Exception("DATA: $code");

        // dot-stuff and ensure CRLF line endings
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $rawData);
        $body = preg_replace('/^\./m', '..', $body);
        fwrite($fp, $body . "\r\n.\r\n");
        [$code,] = ec_smtp_read($fp);
        if ($code !== 250) throw new Exception("message not accepted: $code");

        ec_smtp_cmd($fp, 'QUIT');
        fclose($fp);
        return ['ok'=>true,'error'=>''];
    } catch (Throwable $e) {
        @fclose($fp);
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}
