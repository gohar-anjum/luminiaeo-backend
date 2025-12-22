<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateKeywordDataset extends Command
{
    protected $signature = 'dataset:keywords {pairs=50000}';
    protected $description = 'Generate 10,000 topics and create keyword→topic training dataset';

    private array $noise = [
        '', '', '',
        'best', 'top', 'guide', 'latest', 'cheap', 'free', 'online'
    ];

    private array $keywordModifiers = [
        '', '', '',
        'best', 'top', 'cheap', 'affordable', 'ai', 'online', 'free',
        'local', 'near me', 'solution', 'services', 'platform'
    ];

    public function handle()
    {
        $pairs = (int)$this->argument('pairs');

        $topics = $this->generateTopics(10000);

        $dataset = [];

        for ($i = 0; $i < $pairs; $i++) {

            $topic = $topics[array_rand($topics)];

            $keyword = trim(
                implode(' ', [
                    $this->noise[array_rand($this->noise)],
                    $this->keywordModifiers[array_rand($this->keywordModifiers)],
                    $topic
                ])
            );

            $dataset[] = [
                'keyword' => strtolower($keyword),
                'topic'   => $topic,
            ];
        }

        $path = storage_path("app/dataset_{$pairs}_pairs.json");
        file_put_contents($path, json_encode($dataset, JSON_PRETTY_PRINT));

        $this->info("✔ Generated 10,000 topics");
        $this->info("✔ Generated {$pairs} keyword pairs");
        $this->info("Output: {$path}");
    }

    private function generateTopics(int $count): array
    {
        $industries = [
            'fintech', 'banking', 'insurance', 'healthcare', 'medical', 'dental',
            'construction', 'real estate', 'automotive', 'transportation',
            'education', 'edtech', 'gaming', 'travel', 'hospitality', 'retail',
            'ecommerce', 'fashion', 'manufacturing', 'logistics', 'legal',
            'cybersecurity', 'food', 'fitness', 'telecom', 'energy', 'ai',
            'robotics', 'cloud', 'devops', 'blockchain', 'crypto', 'marketing',
            'advertising', 'hr', 'recruitment', 'analytics', 'biotech',
            'agritech', 'home services', 'plumbing', 'electrical', 'cleaning',
            'photography', 'events', 'saas', 'mobility', 'pet care'
        ];

        $functions = [
            'automation', 'analytics', 'monitoring', 'management', 'tracking',
            'reporting', 'optimization', 'intelligence', 'prediction', 'compliance',
            'risk analysis', 'fraud detection', 'lead generation',
            'customer engagement', 'workflow orchestration', 'performance analysis',
            'identity verification', 'scheduling', 'inventory control',
            'supply chain visibility', 'content moderation', 'image recognition',
            'NLP classification', 'recommendation engine', 'data enrichment',
            'data labeling', 'process mining'
        ];

        $solutionNouns = [
            'platform', 'system', 'software', 'tool', 'dashboard', 'suite',
            'framework', 'microservice', 'API', 'AI model', 'bot', 'pipeline',
            'application', 'engine', 'infrastructure', 'portal', 'workspace'
        ];

        $intents = ['informational', 'commercial', 'transactional', 'navigational'];

        $locations = [
            '', '', '',
            'usa', 'uk', 'europe', 'canada', 'australia', 'asia', 'remote',
            'local businesses', 'small businesses', 'enterprise teams'
        ];

        $longTailAddons = [
            '', '', '',
            'for startups', 'for enterprises', 'for SMBs',
            'for ecommerce stores', 'using AI', 'with automation',
            'for real estate agents', 'for Shopify stores',
            'for small clinics', 'for logistics companies',
            'with predictive analytics'
        ];

        $topics = [];
        $attempts = 0;
        $maxAttempts = $count * 5;

        while (count($topics) < $count && $attempts < $maxAttempts) {

            $industry  = $industries[array_rand($industries)];
            $function  = $functions[array_rand($functions)];
            $noun      = $solutionNouns[array_rand($solutionNouns)];
            $intent    = $intents[array_rand($intents)];
            $location  = $locations[array_rand($locations)];
            $addon     = $longTailAddons[array_rand($longTailAddons)];

            $forms = [
                "{$industry} {$function} {$noun}",
                "{$industry} {$noun} for {$function}",
                "{$function} {$noun} for {$industry}",
                "{$intent} {$industry} {$function} {$noun}",
                "{$industry} {$function} {$noun} {$location}",
                "{$industry} {$function} {$noun} {$addon}",
            ];

            $topic = trim(preg_replace('/\s+/', ' ', $forms[array_rand($forms)]));

            $topics[$topic] = true;
            $attempts++;
        }

        return array_keys($topics);
    }
}
