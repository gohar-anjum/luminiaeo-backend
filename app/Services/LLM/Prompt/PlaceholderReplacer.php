<?php

namespace App\Services\LLM\Prompt;

class PlaceholderReplacer
{
    public function replace(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace(
                [
                    "{{ $key }}",
                    '{' . $key . '}',
                    '{{' . $key . '}}',
                ],
                $value,
                $template
            );
        }

        return $template;
    }
}
