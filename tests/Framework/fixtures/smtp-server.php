<?php

// A one-connection SMTP server for MailerTest. Prints its port, then records
// every command and every DATA block it receives to the file in $argv[1].

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
fflush(STDOUT);

$conn = stream_socket_accept($server, 10);
$log = [];
$reply = static fn(string $line) => fwrite($conn, $line . "\r\n");
$reply('220 test');
while (($line = fgets($conn)) !== false) {
    $line = rtrim($line, "\r\n");
    $log[] = ['cmd' => $line];
    $verb = strtoupper(substr($line, 0, 4));
    if ($verb === 'DATA') {
        $reply('354 go');
        $data = '';
        while (($d = fgets($conn)) !== false && $d !== ".\r\n") {
            $data .= $d;
        }
        $log[] = ['data' => $data];
        $reply('250 queued');
    } elseif ($verb === 'QUIT') {
        $reply('221 bye');
        break;
    } else {
        $reply('250 ok');
    }
}
file_put_contents($argv[1], json_encode($log));
