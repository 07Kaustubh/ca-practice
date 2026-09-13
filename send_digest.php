<?php
// Sends the escalation digest and VERIFIES every SMTP response code.
// Printing "sent" without reading the server's reply is how silent failures ship.
$to   = getenv('CA_EMAIL');
$host = getenv('CA_SMTP_HOST') ?: 'mailpit';
$port = (int)(getenv('CA_SMTP_PORT') ?: 1025);
if (!$to) { fwrite(STDERR, "DIGEST FAIL: CA_EMAIL not set in container env\n"); exit(3); }
$msg = @file_get_contents('/tmp/digest.eml');
if ($msg === false) { fwrite(STDERR, "DIGEST FAIL: /tmp/digest.eml missing\n"); exit(3); }

$fp = @fsockopen($host, $port, $errno, $errstr, 10);
if (!$fp) { fwrite(STDERR, "DIGEST FAIL: cannot connect to $host:$port - $errstr\n"); exit(3); }
stream_set_timeout($fp, 10);

function expect($fp, $want, $what) {
    $line = fgets($fp, 2048);
    if ($line === false) { fwrite(STDERR, "DIGEST FAIL: no reply to $what\n"); exit(3); }
    $code = (int)substr(trim($line), 0, 3);
    if ($code !== $want) {
        fwrite(STDERR, "DIGEST FAIL: $what returned '".trim($line)."' (wanted $want)\n");
        exit(3);
    }
    return $code;
}
expect($fp, 220, 'banner');
fwrite($fp, "EHLO ca-reminders\r\n");
// EHLO emits multiple 250- lines; drain to the final 250<space>
do { $l = fgets($fp, 2048); } while ($l !== false && preg_match('/^250-/', $l));
if (!$l || (int)substr(trim($l),0,3) !== 250) { fwrite(STDERR,"DIGEST FAIL: EHLO rejected\n"); exit(3); }
fwrite($fp, "MAIL FROM:<$to>\r\n"); expect($fp, 250, 'MAIL FROM');
fwrite($fp, "RCPT TO:<$to>\r\n");   expect($fp, 250, 'RCPT TO');
fwrite($fp, "DATA\r\n");            expect($fp, 354, 'DATA');
fwrite($fp, $msg . "\r\n.\r\n");    expect($fp, 250, 'message body');
fwrite($fp, "QUIT\r\n"); fclose($fp);
echo "digest DELIVERED to $to (all SMTP codes verified)\n";
