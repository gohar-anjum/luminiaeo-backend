<?php

namespace App\Services\LLM\Prompt;

class PromptLoader
{
    protected static array $cache = [];

    public function load(string $name): array
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $segments = str_replace('.', '/', $name);

        $legacyBase = resource_path("llm/prompts/{$segments}");
        if (is_dir($legacyBase)) {
            $system = file_exists("{$legacyBase}/system.txt") ? trim((string) file_get_contents("{$legacyBase}/system.txt")) : '';
            $user = file_exists("{$legacyBase}/user.txt") ? trim((string) file_get_contents("{$legacyBase}/user.txt")) : '';

            return self::$cache[$name] = [
                'system' => $system,
                'user' => $user,
            ];
        }

        $markdownPath = resource_path("prompts/{$segments}.md");
        if (file_exists($markdownPath)) {
            $contents = trim((string) file_get_contents($markdownPath));
            $parsed = $this->parseMarkdownPrompt($contents);

            return self::$cache[$name] = $parsed;
        }

        return self::$cache[$name] = [
            'system' => '',
            'user' => '',
        ];
    }

    protected function parseMarkdownPrompt(string $contents): array
    {
        $system = '';
        $user = '';

        if (preg_match('/^System:\s*(.*?)(?:^User:|\z)/ims', $contents, $matches)) {
            $system = trim($matches[1]);
        }

        if (preg_match('/^User:\s*(.*)$/ims', $contents, $matches)) {
            $user = trim($matches[1]);
        }

        return [
            'system' => $system,
            'user' => $user,
        ];
    }
}
