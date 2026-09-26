<?php

declare(strict_types=1);

// Bounded loopback-only responder for the delivered HTTP helper example.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error ?? 'Could not bind loopback fixture.');
    exit(1);
}
$address = stream_socket_get_name($server, false);
if ($address === false) {
    exit(1);
}
fwrite(STDOUT, $address . "\n");
fflush(STDOUT);
for ($request = 0; $request < 2; ++$request) {
    $client = stream_socket_accept($server, 5);
    if ($client === false) {
        exit(1);
    }
    stream_set_timeout($client, 5);
    while (($line = fgets($client)) !== false && $line !== "\r\n") {
        // Consume the bounded test request's headers.
    }
    fwrite($client, "HTTP/1.1 200 OK\r\nX-Feature-Fixture: captured\r\nX-Request: $request\r\nContent-Length: 12\r\nConnection: close\r\n\r\nfixture-body");
    fclose($client);
}
fclose($server);
