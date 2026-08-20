--TEST--
swoole_http_server: upload reserved header
--SKIPIF--
<?php require __DIR__ . '/../include/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/../include/bootstrap.php';

function build_multipart_request(string $boundary, string $body, string $probe): string
{
    return implode("\r\n", [
        'POST / HTTP/1.1',
        'Host: 127.0.0.1',
        'Connection: close',
        'X-Probe-File: ' . $probe,
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Content-Length: ' . strlen($body),
        '',
        $body,
    ]);
}

function send_raw_request(ProcessManager $pm, string $request): array
{
    $sock = stream_socket_client("tcp://127.0.0.1:{$pm->getFreePort()}");
    fwrite($sock, $request);
    stream_set_chunk_size($sock, 2 * 1024 * 1024);
    $response = fread($sock, 2 * 1024 * 1024);
    fclose($sock);

    return explode("\r\n\r\n", $response, 2);
}

function build_file_body(string $boundary, string $content, string $probe, bool $includeName = true): string
{
    $disposition = 'Content-Disposition: form-data; ';
    if ($includeName) {
        $disposition .= 'name="file"; ';
    }
    $disposition .= 'filename="test.txt"';

    return implode("\r\n", [
        '--' . $boundary,
        $disposition,
        'Swoole-Upload-File: ' . $probe,
        'Content-Type: text/plain',
        '',
        $content,
        '--' . $boundary . '--',
        '',
    ]);
}

function run_upload_reserved_header(int $mode): void
{
    $pm = new ProcessManager;
    $uploadDir = sys_get_temp_dir() . '/swoole-upload-' . getmypid() . '-' . $mode;
    mkdir_if_not_exists($uploadDir);

    $pm->parentFunc = function () use ($pm, $uploadDir) {
        $directProbe = tempnam(sys_get_temp_dir(), 'swoole-upload-probe-');
        file_put_contents($directProbe, 'probe');
        $directContent = 'direct upload body';
        $boundary = '------------------------d3f990cdce762596';
        $body = build_file_body($boundary, $directContent, $directProbe);
        [, $responseBody] = send_raw_request($pm, build_multipart_request($boundary, $body, $directProbe));
        $json = json_decode($responseBody, true);
        Assert::true(is_array($json));
        Assert::true($json['has_file']);
        Assert::same($json['md5'], md5($directContent));
        Assert::false($json['tmp_name_is_probe']);
        Assert::false($json['probe_uploaded']);
        Assert::true(file_exists($directProbe));
        unlink($directProbe);

        $preprocessedProbe = tempnam(sys_get_temp_dir(), 'swoole-upload-probe-');
        file_put_contents($preprocessedProbe, 'probe');
        $preprocessedContent = str_repeat('A', 80 * 1024);
        $body = build_file_body($boundary, $preprocessedContent, $preprocessedProbe);
        [, $responseBody] = send_raw_request($pm, build_multipart_request($boundary, $body, $preprocessedProbe));
        $json = json_decode($responseBody, true);
        Assert::true(is_array($json));
        Assert::true($json['has_file']);
        Assert::same($json['md5'], md5($preprocessedContent));
        Assert::false($json['tmp_name_is_probe']);
        Assert::false($json['probe_uploaded']);
        Assert::same($json['file_count'], 1);
        Assert::true(file_exists($preprocessedProbe));
        unlink($preprocessedProbe);

        $badProbe = tempnam(sys_get_temp_dir(), 'swoole-upload-probe-');
        file_put_contents($badProbe, 'probe');
        $body = build_file_body($boundary, str_repeat('B', 80 * 1024), $badProbe, false);
        [$responseHeader] = send_raw_request($pm, build_multipart_request($boundary, $body, $badProbe));
        Assert::contains($responseHeader, '400 Bad Request');
        Assert::true(file_exists($badProbe));
        unlink($badProbe);
        // The malformed marker path is rejected before the 400 response, and one worker serializes earlier cleanup.
        Assert::same(glob($uploadDir . '/swoole.upfile.*'), []);

        $body = str_repeat('C', 80 * 1024);
        [$responseHeader] = send_raw_request($pm, implode("\r\n", [
            'POST / HTTP/1.1',
            'Host: 127.0.0.1',
            'Connection: close',
            'Content-Type: multipart/form-data',
            'Content-Length: ' . strlen($body),
            '',
            $body,
        ]));
        Assert::contains($responseHeader, '400 Bad Request');
        Assert::same(glob($uploadDir . '/swoole.upfile.*'), []);

        $pm->kill();
        rmdir($uploadDir);
    };

    $pm->childFunc = function () use ($pm, $uploadDir, $mode) {
        $http = new Swoole\Http\Server('127.0.0.1', $pm->getFreePort(), $mode);
        $http->set([
            'log_file' => '/dev/null',
            'worker_num' => 1,
            'package_max_length' => 64 * 1024,
            'upload_max_filesize' => 1024 * 1024,
            'upload_tmp_dir' => $uploadDir,
        ]);
        $http->on('workerStart', function () use ($pm) {
            $pm->wakeup();
        });
        $http->on('request', function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
            $probe = $request->header['x-probe-file'] ?? '';
            $file = $request->files['file'] ?? null;
            $tmpName = $file['tmp_name'] ?? '';
            $response->end(json_encode([
                'has_file' => is_array($file),
                'md5' => is_file($tmpName) ? md5_file($tmpName) : '',
                'tmp_name_is_probe' => $tmpName === $probe,
                'probe_uploaded' => is_uploaded_file($probe),
                'file_count' => count($request->files ?? []),
            ]));
        });
        $http->start();
    };

    $pm->childFirst();
    $pm->run();
}

foreach ([SWOOLE_BASE, SWOOLE_PROCESS] as $mode) {
    run_upload_reserved_header($mode);
}
?>
--EXPECT--
