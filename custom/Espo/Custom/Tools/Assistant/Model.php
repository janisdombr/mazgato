<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * One call to a language model: the whole conversation so far in, one reply out. The loop
 * knows only this interface; AnthropicModel talks HTTPS, ScriptedModel (tests) plays a script
 * and never touches the network.
 *
 * Messages use the Messages-API shape: [{role: user|assistant, content: [block, ...]}], with
 * blocks {type:text,text} | {type:tool_use,id,name,input} | {type:tool_result,tool_use_id,content,is_error}.
 */
interface Model
{
    /**
     * @param array<int, array<string, mixed>> $system  system blocks: [{type:text, text, cache?:bool}]
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools    [{name, description, input_schema}]
     * @throws ModelError
     */
    public function respond(array $system, array $messages, array $tools, float $timeout): Reply;

    /** The model id that answers (for the journal). */
    public function name(): string;
}
