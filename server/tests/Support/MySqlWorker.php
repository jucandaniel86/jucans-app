<?php

namespace Tests\Support;

use RuntimeException;

class MySqlWorker
{
    private $process;

    private array $pipes = [];

    private string $buffer = '';

    public array $events = [];

    public function __construct(array $payload)
    {
        $this->process = proc_open([PHP_BINARY, __DIR__.'/mysql-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, dirname(__DIR__, 2));
        if (! is_resource($this->process)) {
            throw new RuntimeException('Cannot start isolated MySQL worker.');
        }
        fwrite($this->pipes[0], json_encode($payload)."\n");
        fclose($this->pipes[0]);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    public function collect(float $seconds): array
    {
        $until = microtime(true) + $seconds;
        do {
            $this->buffer .= stream_get_contents($this->pipes[1]);
            while (($newline = strpos($this->buffer, "\n")) !== false) {
                $line = substr($this->buffer, 0, $newline);
                $this->buffer = substr($this->buffer, $newline + 1);
                if ($line !== '') {
                    $this->events[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                }
            }
            if (microtime(true) < $until) {
                usleep(10000);
            }
        } while (microtime(true) < $until);

        return $this->events;
    }

    public function waitFor(string $name, float $timeout = 8): array
    {
        $until = microtime(true) + $timeout;
        do {
            foreach ($this->collect(0.02) as $event) {
                if ($event['event'] === 'fatal') {
                    throw new RuntimeException('Worker bootstrap failed: '.$event['class']);
                }
                if ($event['event'] === $name) {
                    return $event;
                }
            }
        } while (microtime(true) < $until);
        throw new RuntimeException('Timed out waiting for worker '.$name.'; no credentials/SQL logged.');
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
            }
            foreach ([1, 2] as $pipe) {
                if (is_resource($this->pipes[$pipe])) {
                    fclose($this->pipes[$pipe]);
                }
            }
            proc_close($this->process);
        }
    }
}
