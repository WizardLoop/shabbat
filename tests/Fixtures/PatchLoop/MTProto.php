<?php

declare (strict_types=1);
namespace danog\MadelineProto;

class MTProto
{
    public array $feeders = [];
    public array $updaters = [];
    public array $authorization = ['user' => ['bot' => true]];
    public object $loginState;
    public int $calls = 0;
    public int $reports = 0;
    public ?\Closure $onCall = null;
    public function __construct(public string $scenario)
    {
        $this->loginState = new class
        {
            public function subscribe(object $listener): void
            {
            }
        };
    }
    public function loadUpdateState(): object
    {
        return $this->state();
    }
    public function loadChannelState(int $id): object
    {
        return $this->state();
    }
    private function state(): object
    {
        return new class
        {
            public function pts(): int
            {
                return 2;
            }
            public function date(): int
            {
                return 100;
            }
            public function qts(): int
            {
                return 0;
            }
            public function update(array $data): void
            {
            }
        };
    }
    public function logger(string $message, mixed $level = null): void
    {
    }
    public function report(string $message): void
    {
        $this->reports++;
    }
    public function methodCallAsyncRead(string $method, array $args, ?int $dc = null): array
    {
        $this->calls++;
        if ($this->calls === 1) {
            $this->onCall?->__invoke();
            if ($this->scenario === 'direct' || $this->scenario === 'shutdown-direct') {
                throw new \Amp\TimeoutException('fixture direct timeout');
            }
            if ($this->scenario === 'wrapped' || $this->scenario === 'shutdown-wrapped') {
                $pending = new \Amp\DeferredFuture();
                $keepAlive = \Revolt\EventLoop::repeat(1.0, static function (): void {
                });
                try {
                    $pending->getFuture()->await(new \Amp\TimeoutCancellation(0.001, 'fixture timeout'));
                } finally {
                    \Revolt\EventLoop::cancel($keepAlive);
                }
                throw new \LogicException('Timeout cancellation unexpectedly completed.');
            }
            if ($this->scenario === 'intentional' || $this->scenario === 'shutdown-intentional') {
                $cancel = new \Amp\DeferredCancellation();
                $cancel->cancel(new \RuntimeException('fixture deliberate cancellation'));
                (new \Amp\DeferredFuture())->getFuture()->await($cancel->getCancellation());
                throw new \LogicException('Deliberate cancellation unexpectedly completed.');
            }
        }
        return $method === 'updates.getChannelDifference' ? ['_' => 'updates.channelDifferenceEmpty', 'pts' => 3, 'timeout' => 10.0] : ['_' => 'updates.differenceEmpty', 'date' => 100, 'seq' => 1];
    }
}
