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

namespace PhpUintTest\Unit\Support\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolInterface;
use PhpUintTest\TestCase;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Neuron\Skill\SkillFrontmatterParser;
use Swoolefy\Support\Neuron\Skill\SkillLoader;
use Swoolefy\Support\Neuron\Skill\SkillNotFoundException;
use Swoolefy\Support\Neuron\Skill\SkillToolFactory;
use Swoolefy\Support\Workflow\State\WorkflowState;
use InvalidArgumentException;

/**
 * 本地 SKILL.md 加载与 Tool 挂载（无需外网 LLM）。
 *
 * ## 运行
 * ```bash
 * ./vendor/bin/phpunit PhpUintTest/Unit/Support/Neuron/SkillModuleTest.php
 * ```
 */
final class SkillModuleTest extends TestCase
{
    private string $skillsRoot;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        SkillLoader::clearCache();
        $this->skillsRoot = dirname(__DIR__, 4) . '/Test/Skills';
        $this->tmpRoot = sys_get_temp_dir() . '/swoolefy_skill_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        SkillLoader::clearCache();
        $this->removeDir($this->tmpRoot);
        parent::tearDown();
    }

    public function testFrontmatterParserExtractsYamlAndBody(): void
    {
        $raw = <<<'MD'
---
name: weather-ops
description: How to use weather tools
extra: 1
---

# Weather Ops

Call get_weather.
MD;

        $parsed = SkillFrontmatterParser::parse($raw);

        $this->assertSame('weather-ops', $parsed['frontmatter']['name'] ?? null);
        $this->assertSame('How to use weather tools', $parsed['frontmatter']['description'] ?? null);
        $this->assertSame(1, $parsed['frontmatter']['extra'] ?? null);
        $this->assertTrue(str_contains($parsed['content'], '# Weather Ops'));
        $this->assertTrue(str_contains($parsed['content'], 'Call get_weather'));
        $this->assertFalse(str_contains($parsed['content'], 'name: weather-ops'));
    }

    public function testFrontmatterParserWithoutDelimiterReturnsFullBody(): void
    {
        $parsed = SkillFrontmatterParser::parse("# Plain\n\nNo frontmatter.");

        $this->assertSame([], $parsed['frontmatter']);
        $this->assertSame("# Plain\n\nNo frontmatter.", $parsed['content']);
    }

    public function testFrontmatterParserMissingCloseThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('closing ---');
        SkillFrontmatterParser::parse("---\nname: broken\n# no close");
    }

    public function testScanFindsDemoSkillsByName(): void
    {
        $loader = new SkillLoader([$this->skillsRoot]);
        $index = $loader->scan();

        $this->assertArrayHasKey('weather-ops', $index);
        $this->assertArrayHasKey('tool-calling', $index);
        $this->assertSame('weather-ops', $index['weather-ops']->name);
        $this->assertNotSame('', $index['weather-ops']->description);
        $this->assertTrue(str_contains($index['weather-ops']->content, 'get_weather'));
    }

    public function testLoadByNameAndCache(): void
    {
        $loader = new SkillLoader([$this->skillsRoot]);
        $a = $loader->load('weather-ops');
        $b = $loader->load('weather-ops');

        $this->assertSame($a, $b, 'file cache should return same instance');
        $this->assertSame('weather-ops', $a->name);
        $this->assertTrue(is_file($a->path));
    }

    public function testLoadMissingSkillThrows(): void
    {
        $loader = new SkillLoader([$this->skillsRoot]);

        try {
            $loader->load('does-not-exist');
            $this->assertTrue(false, 'expected SkillNotFoundException');
        } catch (SkillNotFoundException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'does-not-exist'));
            $this->assertTrue(str_contains($e->getMessage(), $this->skillsRoot));
        }
    }

    public function testLoadEmptyNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SkillLoader([$this->skillsRoot]))->load('  ');
    }

    public function testFallbackNameFromDirectoryWhenFrontmatterOmitsName(): void
    {
        $dir = $this->tmpRoot . '/from-dir';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\ndescription: Dir name fallback\n---\n\n# Body\n");

        $skill = (new SkillLoader([$this->tmpRoot]))->load('from-dir');
        $this->assertSame('from-dir', $skill->name);
        $this->assertSame('Dir name fallback', $skill->description);
    }

    public function testSkillToolFactoryInvokeReturnsMarkdownBody(): void
    {
        $skill = (new SkillLoader([$this->skillsRoot]))->load('weather-ops');
        $tool = SkillToolFactory::make($skill);

        $this->assertSame('skill_weather_ops', $tool->getName());
        $this->assertTrue(str_contains($tool->getDescription(), 'weather tools'));

        $tool->execute();
        $result = (string) $tool->getResult();

        $this->assertTrue(str_contains($result, '# Weather Ops'));
        $this->assertTrue(str_contains($result, 'get_weather'));
        $this->assertFalse(str_contains($result, 'name: weather-ops'));
    }

    public function testAvailableSkillsPromptListsNamesNotBodies(): void
    {
        $skills = (new SkillLoader([$this->skillsRoot]))->loadMany(['weather-ops', 'tool-calling']);
        $prompt = SkillToolFactory::availableSkillsPrompt($skills);

        $this->assertTrue(str_contains($prompt, '<AVAILABLE-SKILLS>'));
        $this->assertTrue(str_contains($prompt, 'weather-ops'));
        $this->assertTrue(str_contains($prompt, 'skill_weather_ops'));
        $this->assertTrue(str_contains($prompt, 'Call the matching skill_*'));
        $this->assertFalse(str_contains($prompt, 'Known demo cities'));
    }

    public function testAvailableSkillsPromptInlineDoesNotGuideSkillToolCall(): void
    {
        $skills = (new SkillLoader([$this->skillsRoot]))->loadMany(['weather-ops']);
        $prompt = SkillToolFactory::availableSkillsPrompt($skills, SkillToolFactory::MODE_INLINE);

        $this->assertTrue(str_contains($prompt, 'weather-ops'));
        $this->assertTrue(str_contains($prompt, 'already inlined'));
        $this->assertFalse(str_contains($prompt, 'tool: skill_weather_ops'));
        $this->assertFalse(str_contains($prompt, 'Call the matching skill_*'));
    }

    public function testInlineSkillsPromptWrapsMarkdownBody(): void
    {
        $skills = (new SkillLoader([$this->skillsRoot]))->loadMany(['weather-ops']);
        $prompt = SkillToolFactory::inlineSkillsPrompt($skills);

        $this->assertTrue(str_contains($prompt, '<SKILL name="weather-ops">'));
        $this->assertTrue(str_contains($prompt, '# Weather Ops'));
        $this->assertTrue(str_contains($prompt, 'Known demo cities'));
        $this->assertTrue(str_contains($prompt, '</SKILL>'));
        $this->assertFalse(str_contains($prompt, 'name: weather-ops'));
    }

    public function testNormalizeSkillsModeRejectsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skillsMode must be one of');
        SkillToolFactory::normalizeMode('prompt');
    }

    public function testNeuronFactoryAttachesSkillToolsAndPrompt(): void
    {
        $agent = $this->bootAgentWithSkills([
            'skills' => ['weather-ops'],
            'skillsPrompt' => true,
        ]);

        $names = $this->toolNames($agent);
        $this->assertTrue(in_array('skill_weather_ops', $names, true), 'skill tool attached');

        $instructions = $agent->resolveInstructions();
        $this->assertTrue(str_contains($instructions, '<AVAILABLE-SKILLS>'));
        $this->assertTrue(str_contains($instructions, 'weather-ops'));
        $this->assertFalse(str_contains($instructions, 'Known demo cities'), 'full skill body must not dump into instructions');
        $this->assertFalse(str_contains($instructions, '<SKILL name='));
    }

    public function testNeuronFactoryDefaultSkillsModeIsTool(): void
    {
        $agent = $this->bootAgentWithSkills([
            'skills' => ['weather-ops'],
        ]);

        $this->assertTrue(in_array('skill_weather_ops', $this->toolNames($agent), true));
        $this->assertFalse(str_contains($agent->resolveInstructions(), 'Known demo cities'));
    }

    public function testNeuronFactoryInlineModeInjectsBodyWithoutSkillTools(): void
    {
        $agent = $this->bootAgentWithSkills([
            'skills' => ['weather-ops'],
            'skillsMode' => 'inline',
            'skillsPrompt' => true,
        ]);

        $names = $this->toolNames($agent);
        $this->assertFalse(in_array('skill_weather_ops', $names, true), 'inline must not attach skill_*');
        foreach ($names as $name) {
            $this->assertFalse(str_starts_with($name, 'skill_'), 'tools must not include skill_*');
        }

        $instructions = $agent->resolveInstructions();
        $this->assertTrue(str_contains($instructions, '<SKILL name="weather-ops">'));
        $this->assertTrue(str_contains($instructions, 'Known demo cities'));
        $this->assertTrue(str_contains($instructions, 'already inlined'));
        $this->assertFalse(str_contains($instructions, 'Call the matching skill_*'));
    }

    public function testNeuronFactoryBothModeInjectsBodyAndKeepsSkillTools(): void
    {
        $agent = $this->bootAgentWithSkills([
            'skills' => ['weather-ops'],
            'skillsMode' => 'both',
            'skillsPrompt' => true,
        ]);

        $this->assertTrue(in_array('skill_weather_ops', $this->toolNames($agent), true));

        $instructions = $agent->resolveInstructions();
        $this->assertTrue(str_contains($instructions, '<SKILL name="weather-ops">'));
        $this->assertTrue(str_contains($instructions, 'Known demo cities'));
        $this->assertTrue(str_contains($instructions, 'skill_weather_ops'));
        $this->assertTrue(str_contains($instructions, 'duplicate tokens'));
    }

    public function testNeuronFactoryInvalidSkillsModeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skillsMode must be one of');
        $this->bootAgentWithSkills([
            'skills' => ['weather-ops'],
            'skillsMode' => 'prompt',
        ]);
    }

    public function testNeuronFactoryMissingSkillThrows(): void
    {
        $this->expectException(SkillNotFoundException::class);
        $this->bootAgentWithSkills([
            'skills' => ['missing-skill-xyz'],
        ]);
    }

    /**
     * @param array<string, mixed> $agentOptions
     */
    private function bootAgentWithSkills(array $agentOptions): Agent
    {
        $agentClass = new class extends Agent {
            protected function provider(): AIProviderInterface
            {
                return FakeAIProvider::make(new AssistantMessage('ok'));
            }
        };

        $factory = new NeuronFactory(
            config: NeuronAiConfig::fromArray([
                'capability' => ['enabled' => false],
                'skills' => [
                    'paths' => [$this->skillsRoot],
                ],
            ]),
        );

        return $factory->create($agentClass::class, new WorkflowState(), array_merge([
            'capabilityEnabled' => false,
        ], $agentOptions));
    }

    /**
     * @return list<string>
     */
    private function toolNames(Agent $agent): array
    {
        return array_map(
            static fn (ToolInterface $tool): string => $tool->getName(),
            $agent->getTools(),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
