<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * neuron_ai.php → ai_model_providers 的 Provider 别名常量。
 */
final class NeuronAiProviderName
{
    public const ANTHROPIC = 'anthropic';

    public const OPENAI = 'openai';

    public const OPENAI_RESPONSES = 'openai_responses';

    public const OPENAILIKE = 'openailike';

    public const DEEPSEEK = 'deepseek';

    public const GEMINI = 'gemini';

    public const MISTRAL = 'mistral';

    public const OLLAMA = 'ollama';
}
