<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * The providers the PUBLIC customer chat may use. The base URL is part of this catalogue, in code: a key is
 * only ever sent to the host listed here (the one exception is the test mode, see AssistantConfig::apiBase()).
 * "kind" says which client speaks to it: `anthropic` = AnthropicModel (Messages API), `openai` =
 * OpenAiCompatibleModel (chat.completions with function calling).
 *
 * Prices live in Pricing (with the date they were read). Facts below were read from the providers' own
 * documentation on 2026-10-12; the `dataNote` is orientation for the owner, not legal advice - the data-processing
 * agreement and the region must be checked with the provider before real traffic (docs/CLIENT_ASSISTANT.md).
 */
final class Providers
{
    /**
     * id => label, kind, base (no trailing slash), default model, suggested models, token limit parameter,
     * and whether the provider is known to reject `store`/reasoning parameters (we send them only where listed).
     */
    public const CATALOG = [
        'anthropic' => [
            'label' => 'Anthropic (Claude)', 'kind' => 'anthropic', 'base' => 'https://api.anthropic.com',
            'model' => 'claude-haiku-5-5', 'models' => ['claude-haiku-5-5', 'claude-sonnet-5-5'],
            'tokenParam' => 'max_tokens', 'dataNote' => 'US; DPA + SCC; EU residency not offered by default',
        ],
        'openai' => [
            'label' => 'OpenAI', 'kind' => 'openai', 'base' => 'https://api.openai.com/v1',
            'model' => 'gpt-4.1-nano', 'models' => ['gpt-4.1-nano', 'gpt-5-nano', 'gpt-4.1-mini', 'gpt-5-mini', 'gpt-4o-mini'],
            'tokenParam' => 'max_completion_tokens', 'dataNote' => 'DPA; EU data residency only for a Project created with region Europe',
        ],
        'gemini' => [
            'label' => 'Google Gemini (OpenAI-compatible)', 'kind' => 'openai', 'base' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'model' => 'gemini-3.5-flash-lite', 'models' => ['gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-2.5-flash'],
            'tokenParam' => 'max_tokens', 'dataNote' => 'USE A PAID KEY: free-tier content may be used by Google to improve its products',
        ],
        'mistral' => [
            'label' => 'Mistral AI', 'kind' => 'openai', 'base' => 'https://api.mistral.ai/v1',
            'model' => 'mistral-small-latest', 'models' => ['mistral-small-latest', 'ministral-8b-latest'],
            'tokenParam' => 'max_tokens', 'dataNote' => 'EU company; data hosted in the EU by default; DPA available',
        ],
        'deepseek' => [
            'label' => 'DeepSeek', 'kind' => 'openai', 'base' => 'https://api.deepseek.com',
            'model' => 'deepseek-flash', 'models' => ['deepseek-flash'],
            'tokenParam' => 'max_tokens', 'dataNote' => 'servers in China: not suitable for customer names / phones under GDPR without a transfer basis',
        ],
        'groq' => [
            'label' => 'Groq (Llama / Qwen)', 'kind' => 'openai', 'base' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile', 'models' => ['llama-3.3-70b-versatile'],
            'tokenParam' => 'max_tokens', 'dataNote' => 'US; price not in our table: the estimate is priced high',
        ],
    ];

    /** @return string[] ids a customer chat provider may be set to (not 'off', not 'scripted') */
    public static function ids(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function has(string $id): bool
    {
        return isset(self::CATALOG[$id]);
    }

    /** @return array<string, mixed> */
    public static function get(string $id): array
    {
        return self::CATALOG[$id] ?? self::CATALOG['anthropic'];
    }

    /** For the administration page: nothing secret, the base is shown so the owner sees where a key goes. */
    public static function publicList(): array
    {
        $out = [];

        foreach (self::CATALOG as $id => $p) {
            $out[] = ['id' => $id, 'label' => $p['label'], 'base' => $p['base'], 'model' => $p['model'], 'models' => $p['models'], 'dataNote' => $p['dataNote']];
        }

        return $out;
    }

    /** A model id that is safe to put in a request: the provider's own naming, no spaces or shell/URL characters. */
    public static function validModel(string $provider, string $id): bool
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-:\/]{1,80}$/', $id)) {
            return false;
        }

        $claude = str_starts_with($id, 'claude-');

        return $provider === 'anthropic' ? $claude : !$claude;
    }
}
