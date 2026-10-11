<?php

namespace Espo\Custom\Tools\Assistant;

/** The main model, and ONE attempt with the cheaper one when the main is overloaded or rate-limited. */
class FallbackModel implements Model
{
    private string $last;

    public function __construct(private Model $main, private ?Model $fallback)
    {
        $this->last = $main->name();
    }

    public function name(): string
    {
        return $this->last;
    }

    public function respond(array $system, array $messages, array $tools, float $timeout): Reply
    {
        $t0 = microtime(true);

        try {
            $this->last = $this->main->name();

            return $this->main->respond($system, $messages, $tools, $timeout);
        } catch (ModelOverloaded $e) {
            if (!$this->fallback) {
                throw $e;
            }
        }

        $left = $timeout - (microtime(true) - $t0);

        if ($left < 3) {
            throw new ModelOverloaded('The provider is overloaded and there is no time left to retry.', 'overloaded');
        }

        $this->last = $this->fallback->name();

        return $this->fallback->respond($system, $this->withoutThinking($messages), $tools, $left);
    }

    /**
     * `thinking` / `redacted_thinking` blocks belong to the model that wrote them (the signature is bound to
     * it): the main model gets them back verbatim inside its own tool loop, but a DIFFERENT model must not be
     * sent them - the API would answer 400. The cheaper model starts from the plain text / tool_use /
     * tool_result history, which is all it needs.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function withoutThinking(array $messages): array
    {
        foreach ($messages as &$m) {
            if (($m['role'] ?? '') !== 'assistant' || !is_array($m['content'] ?? null)) {
                continue;
            }

            $kept = array_values(array_filter(
                $m['content'],
                fn($b) => !in_array(is_array($b) ? ($b['type'] ?? '') : '', ['thinking', 'redacted_thinking'], true)
            ));

            // never leave an assistant turn empty (the API refuses it)
            $m['content'] = $kept ?: [['type' => 'text', 'text' => '(…)']];
        }

        return $messages;
    }
}
