--TEST--
swoole_thread/server: report and replace a worker that exits during replacement bootstrap
--SKIPIF--
<?php
require __DIR__ . '/../../include/skipif.inc';
skip_if_nts();
?>
--FILE--
<?php
require __DIR__ . '/../../include/bootstrap.php';

use Swoole\Thread;

const CODE = 235;
$port = get_constant_port(__FILE__);

$server = new Swoole\Http\Server('127.0.0.1', $port, SWOOLE_THREAD);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
    'log_level' => SWOOLE_LOG_ERROR,
    'enable_coroutine' => false,
    'init_arguments' => function () {
        global $queue, $starts, $errors;
        $queue = new Thread\Queue();
        $starts = new Thread\Atomic(0);
        $errors = new Thread\Atomic(0);
        return [$queue, $starts, $errors];
    },
]);
$server->on('WorkerStart', function (Swoole\Server $server, int $workerId): void {
    [$queue, $starts] = Thread::getArguments();
    $count = $starts->add();
    if ($count === 2) {
        swoole_implicit_fn('bailout', CODE);
    }
    if ($count === 1 || $count === 3) {
        $queue->push(true, Thread\Queue::NOTIFY_ONE);
    }
});
$server->on('WorkerError', function (
    Swoole\Server $server,
    int $workerId,
    int $workerPid,
    int $status,
    int $signal
): void {
    [, , $errors] = Thread::getArguments();
    if ($status === CODE && $signal === 0) {
        $errors->add();
    }
});
$server->on('Request', function (Swoole\Http\Request $request, Swoole\Http\Response $response): void {
    swoole_implicit_fn('bailout', CODE);
});
$server->addProcess(new Swoole\Process(function () use ($server, $port): void {
    [$queue, $starts, $errors] = Thread::getArguments();
    $queue->pop(-1);

    Assert::false(@file_get_contents('http://127.0.0.1:' . $port));
    // A regression stops replacement at this boundary, so the test times out instead of reaching its assertions.
    $queue->pop(-1);

    Assert::same($starts->get(), 3);
    Assert::same($errors->get(), 2);
    echo "DONE\n";
    $server->shutdown();
}));
$server->start();
?>
--EXPECT--
DONE
