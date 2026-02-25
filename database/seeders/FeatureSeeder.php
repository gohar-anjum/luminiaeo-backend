<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Domain\Billing\Models\Feature;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            [
                'key' => 'keyword_ideas',
                'name' => 'Keyword Ideas',
                'credit_cost' => 2,
            ],
            [
                'key' => 'faq_generator',
                'name' => 'FAQ Generator',
                'credit_cost' => 6,
            ],
            [
                'key' => 'backlink_feature',
                'name' => 'Backlink Feature',
                'credit_cost' => 8,
            ],
            [
                'key' => 'citation_feature',
                'name' => 'Citation Feature',
                'credit_cost' => 25,
            ],
            [
                'key' => 'semantic_score_checker',
                'name' => 'Semantic Score Checker',
                'credit_cost' => 1,
            ],
            [
                'key' => 'semantic_content_generator',
                'name' => 'Semantic Content Generator',
                'credit_cost' => 4,
            ],
            [
                'key' => 'keyword_clustering',
                'name' => 'Keyword Clustering',
                'credit_cost' => 4,
            ],
            [
                'key' => 'meta_tag_optimizer',
                'name' => 'Meta Tag Optimizer',
                'credit_cost' => 4,
            ],
        ];

        foreach ($features as $feature) {
            Feature::updateOrCreate(
                ['key' => $feature['key']],
                [
                    'name' => $feature['name'],
                    'credit_cost' => $feature['credit_cost'],
                    'is_active' => true,
                ]
            );
        }
    }
}
