<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Ollama;

/**
 * The camelCase spelling of Ollama's model options, and the map to the wire.
 *
 * Port of `OllamaCamelCaseOptions` from `types.ts`, together with the
 * `mapping` table `OllamaEmbeddings._convertOptions` carries. TypeScript has the
 * interface; PHP has no structural types, so the one thing that survives as
 * code is the camel -> snake table.
 */
final class OllamaCamelCaseOptions
{
    /** @var array<string, string> */
    public const WIRE_NAMES = [
        'embeddingOnly' => 'embedding_only',
        'frequencyPenalty' => 'frequency_penalty',
        'keepAlive' => 'keep_alive',
        'logitsAll' => 'logits_all',
        'lowVram' => 'low_vram',
        'mainGpu' => 'main_gpu',
        'mirostat' => 'mirostat',
        'mirostatEta' => 'mirostat_eta',
        'mirostatTau' => 'mirostat_tau',
        'numBatch' => 'num_batch',
        'numCtx' => 'num_ctx',
        'numGpu' => 'num_gpu',
        'numKeep' => 'num_keep',
        'numPredict' => 'num_predict',
        'numThread' => 'num_thread',
        'penalizeNewline' => 'penalize_newline',
        'presencePenalty' => 'presence_penalty',
        'repeatLastN' => 'repeat_last_n',
        'repeatPenalty' => 'repeat_penalty',
        'temperature' => 'temperature',
        'stop' => 'stop',
        'tfsZ' => 'tfs_z',
        'topK' => 'top_k',
        'topP' => 'top_p',
        'typicalP' => 'typical_p',
        'useMlock' => 'use_mlock',
        'useMmap' => 'use_mmap',
        'vocabOnly' => 'vocab_only',
        'f16Kv' => 'f16_kv',
        'numa' => 'numa',
        'seed' => 'seed',
    ];

    private function __construct()
    {
    }

    /**
     * Convert camelCase option keys to the snake_case Ollama actually reads.
     *
     * A key not in the table passes through unchanged, so a caller can still
     * hand over an option this table has not heard of (or one already spelled
     * as the wire spells it).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function toWire(array $options): array
    {
        $converted = [];
        foreach ($options as $key => $value) {
            $converted[self::WIRE_NAMES[$key] ?? $key] = $value;
        }

        return $converted;
    }
}
