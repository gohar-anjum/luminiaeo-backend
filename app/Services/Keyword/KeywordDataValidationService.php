<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\DTOs\SerpKeywordDataDTO;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class KeywordDataValidationService
{
    public function validate($data): bool
    {
        if ($data instanceof KeywordDataDTO || $data instanceof SerpKeywordDataDTO) {
            $data = $data->toArray();
        }

        $validator = Validator::make($data, [
            'keyword' => ['required', 'string', 'max:255', 'min:1'],
            'search_volume' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'competition' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'cpc' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'difficulty' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'language_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'location_code' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            Log::warning('Keyword data validation failed', [
                'errors' => $validator->errors()->toArray(),
                'data' => $data,
            ]);
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return true;
    }

    public function validateBatch(array $keywords): array
    {
        $validated = [];
        $errors = [];

        foreach ($keywords as $index => $keyword) {
            try {
                $this->validate($keyword);
                $validated[] = $keyword;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $errors[$index] = $e->errors();
                Log::warning('Keyword validation failed in batch', [
                    'index' => $index,
                    'errors' => $e->errors(),
                ]);
            }
        }

        if (!empty($errors)) {
            Log::warning('Some keywords failed validation in batch', [
                'total' => count($keywords),
                'valid' => count($validated),
                'invalid' => count($errors),
            ]);
        }

        return $validated;
    }

    public function sanitize(array $data): array
    {
        $sanitized = $data;

        if (isset($sanitized['keyword'])) {
            $sanitized['keyword'] = trim($sanitized['keyword']);
        }

        if (isset($sanitized['competition']) && ($sanitized['competition'] < 0 || $sanitized['competition'] > 1)) {
            $sanitized['competition'] = max(0, min(1, $sanitized['competition']));
        }

        if (isset($sanitized['difficulty']) && ($sanitized['difficulty'] < 0 || $sanitized['difficulty'] > 100)) {
            $sanitized['difficulty'] = max(0, min(100, $sanitized['difficulty']));
        }

        if (isset($sanitized['search_volume']) && $sanitized['search_volume'] < 0) {
            $sanitized['search_volume'] = 0;
        }

        if (isset($sanitized['cpc']) && $sanitized['cpc'] < 0) {
            $sanitized['cpc'] = 0;
        }

        return $sanitized;
    }

    public function isCacheValid($cache): bool
    {
        if (!$cache) {
            return false;
        }

        if ($cache->isExpired()) {
            return false;
        }

        if (empty($cache->keyword)) {
            return false;
        }

        return true;
    }

    public function compareData(array $oldData, array $newData): array
    {
        $differences = [];

        $fieldsToCompare = ['search_volume', 'competition', 'cpc', 'difficulty'];

        foreach ($fieldsToCompare as $field) {
            $oldValue = $oldData[$field] ?? null;
            $newValue = $newData[$field] ?? null;

            if ($oldValue !== $newValue) {
                $differences[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $differences;
    }
}
