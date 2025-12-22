<?php

namespace App\Console\Commands;

use App\Models\Keyword;
use App\Models\KeywordCluster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ExportTrainingData extends Command
{
    protected $signature = 'keyword:export-training-data
                            {--output=training_data.json : Output file path}
                            {--min-cluster-size=3 : Minimum keywords per cluster}
                            {--format=json : Output format (json or csv)}';

    protected $description = 'Export keyword clusters as training data for model training';

    public function handle(): int
    {
        $this->info('Exporting keyword cluster data for training...');

        $minClusterSize = (int) $this->option('min-cluster-size');
        $outputPath = $this->option('output');
        $format = $this->option('format');

        $clusters = KeywordCluster::with('keywords')
            ->has('keywords', '>=', $minClusterSize)
            ->get();

        if ($clusters->isEmpty()) {
            $this->error('No clusters found with minimum size of ' . $minClusterSize);
            return Command::FAILURE;
        }

        $this->info("Found {$clusters->count()} clusters");

        $trainingPairs = $this->generateTrainingPairs($clusters);

        $this->info("Generated " . count($trainingPairs) . " training pairs");

        if ($format === 'csv') {
            $this->saveAsCsv($trainingPairs, $outputPath);
        } else {
            $this->saveAsJson($trainingPairs, $outputPath);
        }

        $this->info("Training data exported to: {$outputPath}");
        $this->info("File size: " . $this->formatBytes(filesize($outputPath)));

        $this->displayStatistics($trainingPairs, $clusters);

        return Command::SUCCESS;
    }

    protected function generateTrainingPairs($clusters): array
    {
        $pairs = [];
        $allKeywords = [];

        foreach ($clusters as $cluster) {
            $keywords = $cluster->keywords->pluck('keyword')->toArray();
            $allKeywords[] = $keywords;

            for ($i = 0; $i < count($keywords); $i++) {
                for ($j = $i + 1; $j < count($keywords); $j++) {
                    $pairs[] = [
                        'keyword1' => $keywords[$i],
                        'keyword2' => $keywords[$j],
                        'similarity' => 0.9,
                        'source' => 'same_cluster',
                        'cluster_id' => $cluster->id,
                    ];
                }
            }
        }

        $negativePairs = $this->generateNegativePairs($allKeywords, count($pairs));
        $pairs = array_merge($pairs, $negativePairs);

        return $pairs;
    }

    protected function generateNegativePairs(array $allKeywords, int $positiveCount): array
    {
        $negativePairs = [];
        $maxNegativePairs = min($positiveCount, 10000);

        for ($i = 0; $i < count($allKeywords); $i++) {
            for ($j = $i + 1; $j < count($allKeywords) && count($negativePairs) < $maxNegativePairs; $j++) {
                $cluster1Keywords = $allKeywords[$i];
                $cluster2Keywords = $allKeywords[$j];

                $samplesPerPair = min(3, min(count($cluster1Keywords), count($cluster2Keywords)));

                for ($k = 0; $k < $samplesPerPair && count($negativePairs) < $maxNegativePairs; $k++) {
                    if (!empty($cluster1Keywords) && !empty($cluster2Keywords)) {
                        $keyword1 = $cluster1Keywords[array_rand($cluster1Keywords)];
                        $keyword2 = $cluster2Keywords[array_rand($cluster2Keywords)];

                        if ($keyword1 !== $keyword2) {
                            $negativePairs[] = [
                                'keyword1' => $keyword1,
                                'keyword2' => $keyword2,
                                'similarity' => 0.2,
                                'source' => 'different_cluster',
                                'cluster_id' => null,
                            ];
                        }
                    }
                }
            }
        }

        return $negativePairs;
    }

    protected function saveAsJson(array $pairs, string $outputPath): void
    {
        $data = [
            'metadata' => [
                'exported_at' => now()->toIso8601String(),
                'total_pairs' => count($pairs),
                'positive_pairs' => count(array_filter($pairs, fn($p) => $p['similarity'] >= 0.8)),
                'negative_pairs' => count(array_filter($pairs, fn($p) => $p['similarity'] < 0.5)),
            ],
            'pairs' => $pairs,
        ];

        File::put($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function saveAsCsv(array $pairs, string $outputPath): void
    {
        $file = fopen($outputPath, 'w');

        fputcsv($file, ['keyword1', 'keyword2', 'similarity', 'source']);

        foreach ($pairs as $pair) {
            fputcsv($file, [
                $pair['keyword1'],
                $pair['keyword2'],
                $pair['similarity'],
                $pair['source'],
            ]);
        }

        fclose($file);
    }

    protected function displayStatistics(array $pairs, $clusters): void
    {
        $positivePairs = array_filter($pairs, fn($p) => $p['similarity'] >= 0.8);
        $negativePairs = array_filter($pairs, fn($p) => $p['similarity'] < 0.5);

        $this->newLine();
        $this->info('=== Statistics ===');
        $this->line("Total clusters: {$clusters->count()}");
        $this->line("Total keywords: " . Keyword::whereNotNull('keyword_cluster_id')->count());
        $this->line("Total pairs: " . count($pairs));
        $this->line("Positive pairs (same cluster): " . count($positivePairs));
        $this->line("Negative pairs (different clusters): " . count($negativePairs));
        $this->line("Balance ratio: " . round(count($positivePairs) / max(count($negativePairs), 1), 2));
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
