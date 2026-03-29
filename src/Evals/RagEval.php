<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Evals;

use Moneo\LaravelRag\Evals\Metrics\ContextRecallMetric;
use Moneo\LaravelRag\Evals\Metrics\FaithfulnessMetric;
use Moneo\LaravelRag\Evals\Metrics\MetricContract;
use Moneo\LaravelRag\Evals\Metrics\RelevancyMetric;
use Moneo\LaravelRag\Pipeline\RagPipeline;

class RagEval
{
    protected ?RagPipeline $pipeline = null;

    /** @var array<int, array{question: string, expected: string}> */
    protected array $cases = [];

    /** @var array<int, MetricContract> */
    protected array $metrics = [];

    /**
     * Create a new evaluation suite.
     */
    public static function suite(): static
    {
        $instance = new static;

        // Register default metrics
        $instance->metrics = [
            new FaithfulnessMetric,
            new RelevancyMetric,
            new ContextRecallMetric,
        ];

        return $instance;
    }

    /**
     * Set the RAG pipeline to evaluate.
     *
     * @return $this
     */
    public function using(RagPipeline $pipeline): static
    {
        $this->pipeline = $pipeline;

        return $this;
    }

    /**
     * Add a test case.
     *
     * @param  string  $question  The question to ask
     * @param  string  $expected  The expected/reference answer
     * @return $this
     */
    public function add(string $question, string $expected): static
    {
        $this->cases[] = [
            'question' => $question,
            'expected' => $expected,
        ];

        return $this;
    }

    /**
     * Load test cases from a JSON file.
     *
     * @param  string  $path  Path to JSON file with cases
     * @return $this
     */
    public function loadFromFile(string $path): static
    {
        $data = json_decode(file_get_contents($path), true);

        foreach ($data['cases'] ?? $data as $case) {
            $this->add($case['question'], $case['expected']);
        }

        return $this;
    }

    /**
     * Add a custom metric.
     *
     * @return $this
     */
    public function withMetric(MetricContract $metric): static
    {
        $this->metrics[] = $metric;

        return $this;
    }

    /**
     * Run the evaluation suite.
     */
    public function run(): EvalReport
    {
        if (!$this->pipeline instanceof \Moneo\LaravelRag\Pipeline\RagPipeline) {
            throw new \RuntimeException('No pipeline set. Call using() before run().');
        }

        $startTime = microtime(true);
        $results = [];
        $metricTotals = [];

        foreach ($this->metrics as $metric) {
            $metricTotals[$metric->name()] = 0.0;
        }

        foreach ($this->cases as $case) {
            $caseStart = microtime(true);

            // Run the pipeline
            $ragResult = $this->pipeline->ask($case['question']);
            $context = $ragResult->chunks->map(fn (array $c): string => $c['content'] ?? '')->implode("\n\n");

            // Evaluate each metric
            $scores = [];
            foreach ($this->metrics as $metric) {
                $score = $metric->evaluate(
                    question: $case['question'],
                    answer: $ragResult->answer,
                    expected: $case['expected'],
                    context: $context,
                );
                $scores[$metric->name()] = $score;
                $metricTotals[$metric->name()] += $score;
            }

            $latencyMs = (microtime(true) - $caseStart) * 1000;

            $results[] = [
                'question' => $case['question'],
                'expected' => $case['expected'],
                'answer' => $ragResult->answer,
                'scores' => $scores,
                'latency_ms' => $latencyMs,
            ];
        }

        // Calculate averages
        $caseCount = max(count($this->cases), 1);
        $averageScores = array_map(fn (float $total): float => $total / $caseCount, $metricTotals);

        $totalTimeMs = (microtime(true) - $startTime) * 1000;

        return new EvalReport(
            results: $results,
            averageScores: $averageScores,
            totalTimeMs: $totalTimeMs,
        );
    }
}
