<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * Neuron AI 模型相关环境变量名常量（供 neuron_ai.php 与 env() 使用）。
 */
final class NeuronAiModelEnv
{
    public const ANTHROPIC_API_KEY = 'ANTHROPIC_API_KEY';

    public const ANTHROPIC_MODEL = 'ANTHROPIC_MODEL';

    public const OPENAI_API_KEY = 'OPENAI_API_KEY';

    public const OPENAI_MODEL = 'OPENAI_MODEL';

    public const OPENAI_RESPONSES_MODEL = 'OPENAI_RESPONSES_MODEL';

    public const OPENAILIKE_API_KEY = 'OPENAILIKE_API_KEY';

    public const OPENAILIKE_MODEL = 'OPENAILIKE_MODEL';

    public const OPENAILIKE_BASE_URI = 'OPENAILIKE_BASE_URI';

    public const DEEPSEEK_API_KEY = 'DEEPSEEK_API_KEY';

    public const DEEPSEEK_MODEL = 'DEEPSEEK_MODEL';

    public const GEMINI_API_KEY = 'GEMINI_API_KEY';

    public const GEMINI_MODEL = 'GEMINI_MODEL';

    public const MISTRAL_API_KEY = 'MISTRAL_API_KEY';

    public const MISTRAL_MODEL = 'MISTRAL_MODEL';

    public const OLLAMA_URL = 'OLLAMA_URL';

    public const OLLAMA_MODEL = 'OLLAMA_MODEL';
}
