<?php

declare (strict_types=1);
namespace danog\MadelineProto\Loop;

trait InternalLoop
{
    protected \danog\MadelineProto\MTProto $API;
    public function __construct(\danog\MadelineProto\MTProto $API)
    {
        $this->API = $API;
    }
}
