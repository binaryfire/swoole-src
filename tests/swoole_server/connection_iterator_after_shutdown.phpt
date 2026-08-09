--TEST--
swoole_server: connection iterators remain safe after shutdown
--SKIPIF--
<?php require __DIR__ . '/../include/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/../include/bootstrap.php';

use Swoole\Server;
use SwooleTest\ProcessManager;

function testIteratorAfterShutdown(int $mode): void
{
    $pm = new ProcessManager;
    $pm->initRandomData(1);
    $pm->parentFunc = static function () use ($pm): void {
        Co\run(static function () use ($pm): void {
            $client = new Co\Client(SWOOLE_SOCK_TCP);
            $client->set(['timeout' => 3]);
            Assert::true($client->connect('127.0.0.1', $pm->getFreePort()));
            $data = $pm->getRandomData();
            Assert::greaterThan($client->send($data), 0);
            Assert::same($client->recv(), $data);
            $client->close();
        });
        $pm->wait(-1);
    };
    $pm->childFunc = static function () use ($pm, $mode): void {
        $server = new Server('127.0.0.1', $pm->getFreePort(), $mode);
        $server->set([
            'worker_num' => 1,
            'log_file' => '/dev/null',
        ]);
        $server->on('start', static function () use ($pm): void {
            $pm->wakeup();
        });
        $server->on('receive', static function (Server $server, int $fd, int $reactorId, string $data): void {
            Assert::true($server->send($fd, $data));
        });
        $server->on('close', static function (Server $server): void {
            $server->shutdown();
        });
        $server->on('shutdown', static function () use ($pm): void {
            $pm->wakeup();
        });

        Assert::true($server->start());
        Assert::same(iterator_to_array($server->connections), []);
        Assert::same(iterator_to_array($server->ports[0]->connections), []);
    };
    $pm->childFirst();
    $pm->run();
    $pm->expectExitCode(0);
}

testIteratorAfterShutdown(SWOOLE_BASE);
testIteratorAfterShutdown(SWOOLE_PROCESS);

echo "DONE\n";
?>
--EXPECT--
DONE
