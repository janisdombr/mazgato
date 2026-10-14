<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\Core\Utils\Log;

/** The one place that picks the client for a configured provider (Providers::CATALOG `kind`). */
final class ModelFactory
{
    public static function make(AssistantConfig $cfg, Log $log): Model
    {
        $provider = $cfg->provider();
        $kind = Providers::get($provider)['kind'];
        $build = fn(string $id, bool $main) => $kind === 'openai'
            ? new OpenAiCompatibleModel($cfg, $id, $log, $provider)
            : new AnthropicModel($cfg, $id, $log, $main);

        return new FallbackModel(
            $build($cfg->model(), true),
            $cfg->fallbackModel() !== $cfg->model() ? $build($cfg->fallbackModel(), false) : null
        );
    }
}
