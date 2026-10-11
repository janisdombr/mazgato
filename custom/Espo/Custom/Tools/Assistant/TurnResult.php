<?php

namespace Espo\Custom\Tools\Assistant;

/** How one question ended. */
final class TurnResult
{
    /** answered | step_cap | cut_off | refused | provider_error | deadline | budget */
    public string $stopped = 'answered';
    public string $text = '';
    public string $errorCode = '';
    public int $steps = 0;
    public int $tokensIn = 0;
    public int $tokensOut = 0;
    public int $cacheRead = 0;
    public int $cacheWrite = 0;
    public float $costUsd = 0.0;
    /** @var array<int, array<string, mixed>> {name, input, chars, error?} per tool call, in order */
    public array $tools = [];
    /** @var array<int, array<string, mixed>> */
    public array $drafts = [];
    /** @var string[] */
    public array $unsourced = [];
    /** @var string[] models that answered some step */
    public array $models = [];
}
