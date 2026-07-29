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

namespace Swoolefy\Support\Neuron\Skill;

use InvalidArgumentException;

/**
 * 扫描本地 Skill 根目录并按名称加载 SKILL.md。
 *
 * 目录约定：
 *   {root}/{skill-name}/SKILL.md
 *
 * 默认根目录（{@see defaultRoots()}）：
 *   - APP_PATH/Skills（若已定义）
 *   - ROOT_PATH/Skills（若已定义且与 APP_PATH 不同）
 *
 * 进程内按文件绝对路径缓存解析结果；clearCache() 供单测重置。
 */
final class SkillLoader
{
    /** @var array<string, SkillDefinition> realpath => definition */
    private static array $fileCache = [];

    /** @var list<string> */
    private readonly array $roots;

    /** @var array<string, SkillDefinition>|null name => definition（当前 roots 扫描索引） */
    private ?array $index = null;

    /**
     * @param list<string> $roots Skill 根目录；空则使用 {@see defaultRoots()}
     */
    public function __construct(array $roots = [])
    {
        $normalized = [];
        foreach (($roots === [] ? self::defaultRoots() : $roots) as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $real = realpath($root);
            $path = $real !== false ? $real : rtrim($root, DIRECTORY_SEPARATOR);
            if (!in_array($path, $normalized, true)) {
                $normalized[] = $path;
            }
        }

        $this->roots = $normalized;
    }

    /**
     * @return list<string>
     */
    public static function defaultRoots(): array
    {
        $roots = [];
        if (defined('APP_PATH') && is_string(APP_PATH) && APP_PATH !== '') {
            $roots[] = APP_PATH . DIRECTORY_SEPARATOR . 'Skills';
        }
        if (defined('ROOT_PATH') && is_string(ROOT_PATH) && ROOT_PATH !== '') {
            $rootSkills = ROOT_PATH . DIRECTORY_SEPARATOR . 'Skills';
            if (!in_array($rootSkills, $roots, true)) {
                $roots[] = $rootSkills;
            }
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    public static function clearCache(): void
    {
        self::$fileCache = [];
    }

    /**
     * 扫描全部 roots，返回 name => SkillDefinition。
     *
     * 同名 skill：先扫描到的根目录优先（APP_PATH 先于 ROOT_PATH）。
     *
     * @return array<string, SkillDefinition>
     */
    public function scan(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        foreach ($this->roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $entries = scandir($root);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $dir = $root . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($dir)) {
                    continue;
                }
                $skillFile = $dir . DIRECTORY_SEPARATOR . 'SKILL.md';
                if (!is_file($skillFile)) {
                    continue;
                }

                $definition = $this->loadFile($skillFile, $entry);
                if (!isset($index[$definition->name])) {
                    $index[$definition->name] = $definition;
                }
            }
        }

        $this->index = $index;

        return $this->index;
    }

    public function has(string $name): bool
    {
        return isset($this->scan()[$name]);
    }

    /**
     * 按名称加载；未找到时抛 {@see SkillNotFoundException}。
     */
    public function load(string $name): SkillDefinition
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Skill name must not be empty');
        }

        $index = $this->scan();
        if (!isset($index[$name])) {
            throw new SkillNotFoundException($name, $this->roots);
        }

        return $index[$name];
    }

    /**
     * @param list<string> $names
     *
     * @return list<SkillDefinition>
     */
    public function loadMany(array $names): array
    {
        $skills = [];
        foreach ($names as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $skills[] = $this->load(trim($name));
        }

        return $skills;
    }

    private function loadFile(string $path, string $fallbackName): SkillDefinition
    {
        $real = realpath($path);
        $cacheKey = $real !== false ? $real : $path;

        if (isset(self::$fileCache[$cacheKey])) {
            return self::$fileCache[$cacheKey];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException('Unable to read SKILL.md: ' . $path);
        }

        $parsed = SkillFrontmatterParser::parse($raw);
        $frontmatter = $parsed['frontmatter'];
        $content = $parsed['content'];

        $name = $frontmatter['name'] ?? $fallbackName;
        if (!is_string($name) || trim($name) === '') {
            $name = $fallbackName;
        } else {
            $name = trim($name);
        }

        $description = $frontmatter['description'] ?? '';
        if (!is_string($description)) {
            $description = is_scalar($description) ? (string) $description : '';
        }

        $metadata = $frontmatter;
        unset($metadata['name'], $metadata['description']);

        $definition = new SkillDefinition(
            name: $name,
            description: trim($description),
            content: $content,
            path: $cacheKey,
            metadata: $metadata,
        );

        self::$fileCache[$cacheKey] = $definition;

        return $definition;
    }
}
