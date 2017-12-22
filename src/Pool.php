<?php

namespace Spatie\Async;

use ArrayAccess;
use Spatie\Async\Runtime\ParentRuntime;

class Pool implements ArrayAccess
{
    protected $concurrency = 20;
    protected $tasksPerProcess = 1;
    protected $timeout = 300;

    /** @var \Spatie\Async\ParallelProcess[] */
    protected $queue = [];
    /** @var \Spatie\Async\ParallelProcess[] */
    protected $inProgress = [];
    /** @var \Spatie\Async\ParallelProcess[] */
    protected $finished = [];
    /** @var \Spatie\Async\ParallelProcess[] */
    protected $failed = [];

    protected $results = [];

    public function __construct()
    {
        $this->registerListener();
    }

    /**
     * @return static
     */
    public static function create()
    {
        return new static();
    }

    public function concurrency(int $concurrency): self
    {
        $this->concurrency = $concurrency;

        return $this;
    }

    public function tasksPerProcess(int $tasksPerProcess): self
    {
        $this->tasksPerProcess = $tasksPerProcess;

        return $this;
    }

    public function timeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function notify(): void
    {
        if (count($this->inProgress) >= $this->concurrency) {
            return;
        }

        $process = array_shift($this->queue);

        if (! $process) {
            return;
        }

        $this->putInProgress($process);
    }

    public function add($process): ParallelProcess
    {
        if (! $process instanceof ParallelProcess) {
            $process = ParentRuntime::createChildProcess($process);
        }

        $this->putInQueue($process);

        return $process;
    }

    public function wait(): array
    {
        while ($this->inProgress) {
            foreach ($this->inProgress as $process) {
                if ($process->executionTime() > $this->timeout) {
                    $this->markAsTimeout($process);
                }
            }

            if (! $this->inProgress) {
                break;
            }

            usleep(50000);
        }

        return $this->results;
    }

    public function putInQueue(ParallelProcess $process): void
    {
        $this->queue[$process->id()] = $process;

        $this->notify();
    }

    public function putInProgress(ParallelProcess $process): void
    {
        $process->process()->setTimeout($this->timeout);

        $process->start();

        unset($this->queue[$process->id()]);

        $this->inProgress[$process->pid()] = $process;
    }

    public function markAsFinished(ParallelProcess $process): void
    {
        $this->results[] = $process->triggerSuccess();

        unset($this->inProgress[$process->pid()]);

        $this->finished[$process->pid()] = $process;

        $this->notify();
    }

    public function markAsTimeout(ParallelProcess $process): void
    {
        $process->triggerTimeout();

        unset($this->inProgress[$process->pid()]);

        $this->failed[$process->pid()] = $process;

        $this->notify();
    }

    public function markAsFailed(ParallelProcess $process): void
    {
        $process->triggerError();

        unset($this->inProgress[$process->pid()]);

        $this->failed[$process->pid()] = $process;

        $this->notify();
    }

    public function offsetExists($offset)
    {
        // TODO

        return false;
    }

    public function offsetGet($offset)
    {
        // TODO
    }

    public function offsetSet($offset, $value)
    {
        $this->add($value);
    }

    public function offsetUnset($offset)
    {
        // TODO
    }

    /**
     * @return \Spatie\Async\ParallelProcess[]
     */
    public function getFinished(): array
    {
        return $this->finished;
    }

    /**
     * @return \Spatie\Async\ParallelProcess[]
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    protected function registerListener(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGCHLD, function ($signo, $status) {
            while (true) {
                $pid = pcntl_waitpid(-1, $processState, WNOHANG | WUNTRACED);

                if ($pid <= 0) {
                    break;
                }

                $process = $this->inProgress[$pid] ?? null;

                if (! $process) {
                    continue;
                }

                if ($status['status'] === 0) {
                    $this->markAsFinished($process);

                    continue;
                }

                $this->markAsFailed($process);
            }
        });
    }
}
