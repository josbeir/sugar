<?php
declare(strict_types=1);

use Sugar\Core\Parser\Lexer;
use Sugar\Core\Parser\Parser;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Run parser benchmark for multiple template scenarios.
 *
 * Usage:
 * php benchmark/parser.php [iterations] [warmup]
 * php benchmark/parser.php --iterations=20000 --warmup=2000 --samples=7 --json=benchmark/latest.json
 * php benchmark/parser.php --compare=benchmark/latest.json
 * php benchmark/parser.php --reference-dom
 */
final class ParserBenchmark
{
    /**
     * @param array<string> $arguments
     */
    public function run(array $arguments): void
    {
        $options = $this->parseOptions($arguments);
        $iterations = $options['iterations'];
        $warmup = $options['warmup'];
        $samples = $options['samples'];
        $jsonPath = $options['json'];
        $comparePath = $options['compare'];
        $referenceDom = $options['referenceDom'];
        $effectiveComparePath = $comparePath ?? $jsonPath;

        $parser = new Parser();
        $cases = $this->cases();
        $results = [];

        foreach ($cases as $name => $template) {
            $this->printProgress(sprintf('Warming up %s (%d iterations)', $name, $warmup));
            $this->warmup($parser, $template, $warmup);
            $this->printProgress(sprintf('Measuring %s (%d samples × %d iterations)', $name, $samples, $iterations));
            $results[$name] = $this->measureCase($parser, $template, $iterations, $samples, $name);
        }

        $domReferenceResults = [];
        if ($referenceDom) {
            $domReferenceResults = $this->runDomReferenceBenchmark($iterations, $warmup, $samples);
        }

        $this->printProgress('Measurements complete. Building report...');

        $baseline = $results['simple-html'] ?? null;
        $comparison = $effectiveComparePath !== null ? $this->loadComparison($effectiveComparePath) : null;

        echo "Parser benchmark\n";
        echo 'PHP: ' . PHP_VERSION . "\n";
        echo sprintf('Iterations: %d%s', $iterations, PHP_EOL);
        echo "Warmup: {$warmup}\n\n";
        if ($comparison !== null && $effectiveComparePath !== null) {
            echo "Comparing against: {$effectiveComparePath}\n\n";
        }

        echo str_pad('Case', 26)
            . str_pad('Len', 8)
            . str_pad('med µs', 12)
            . str_pad('p95 µs', 12)
            . str_pad('tok µs', 10)
            . str_pad('parse µs', 12)
            . str_pad('peak KB', 10)
            . str_pad('p95 KB', 10)
            . str_pad('ops/s', 14)
            . str_pad('vs base', 10)
            . "vs prev\n";
        echo str_repeat('-', 134) . "\n";

        foreach ($results as $name => $result) {
            $vsBase = $this->formatVsBase($result['medianUs'], $baseline['medianUs'] ?? null);
            $vsPrevious = $this->formatVsPrevious($name, $result['medianUs'], $comparison);

            echo str_pad($name, 26)
                . str_pad((string)$result['length'], 8)
                . str_pad(number_format($result['medianUs'], 2), 12)
                . str_pad(number_format($result['p95Us'], 2), 12)
                . str_pad(number_format($result['medianTokenizeUs'], 2), 10)
                . str_pad(number_format($result['medianParseUs'], 2), 12)
                . str_pad(number_format($result['medianPeakMemoryKb'], 2), 10)
                . str_pad(number_format($result['p95PeakMemoryKb'], 2), 10)
                . str_pad(number_format($result['medianOpsPerSecond'], 0), 14)
                . str_pad($vsBase, 10)
                . $vsPrevious
                . "\n";
        }

        if ($referenceDom) {
            echo "\nDOM reference benchmark (compile phase)\n";
            echo str_pad('Case', 26)
                . str_pad('Len', 8)
                . str_pad('med µs', 12)
                . str_pad('p95 µs', 12)
                . str_pad('peak KB', 10)
                . str_pad('p95 KB', 10)
                . "ops/s\n";
            echo str_repeat('-', 88) . "\n";

            foreach ($domReferenceResults as $name => $result) {
                echo str_pad($name, 26)
                    . str_pad((string)$result['length'], 8)
                    . str_pad(number_format($result['medianUs'], 2), 12)
                    . str_pad(number_format($result['p95Us'], 2), 12)
                    . str_pad(number_format($result['medianPeakMemoryKb'], 2), 10)
                    . str_pad(number_format($result['p95PeakMemoryKb'], 2), 10)
                    . number_format($result['medianOpsPerSecond'], 0)
                    . "\n";
            }

            $this->printSideBySideTable($results, $domReferenceResults);
        }

        if ($jsonPath !== null) {
            $this->writeJson(
                $jsonPath,
                [
                    'generatedAt' => date(DATE_ATOM),
                    'phpVersion' => PHP_VERSION,
                    'iterations' => $iterations,
                    'warmup' => $warmup,
                    'samples' => $samples,
                    'results' => $results,
                    'domReferenceResults' => $domReferenceResults,
                ],
            );
            echo "\nSaved JSON results to {$jsonPath}\n";
        }
    }

    /**
     * @return array{length: int, medianSeconds: float, medianUs: float, p95Us: float, medianOpsPerSecond: float, medianTokenizeUs: float, medianParseUs: float, medianPeakMemoryKb: float, p95PeakMemoryKb: float, sampleSeconds: array<int, float>}
     */
    private function measureCase(Parser $parser, string $template, int $iterations, int $samples, string $caseName): array
    {
        $sampleSeconds = [];
        $sampleTokenizeUs = [];
        $sampleParseUs = [];
        $samplePeakMemoryKb = [];

        for ($sample = 0; $sample < $samples; $sample++) {
            $this->printProgress(sprintf('  %s sample %d/%d', $caseName, $sample + 1, $samples));
            $sampleResult = $this->measureOnce($parser, $template, $iterations);
            $sampleSeconds[] = $sampleResult['seconds'];
            $sampleTokenizeUs[] = $sampleResult['tokenizeUs'];
            $sampleParseUs[] = $sampleResult['parseUs'];
            $samplePeakMemoryKb[] = $sampleResult['peakMemoryKb'];
        }

        $sorted = $sampleSeconds;
        sort($sorted, SORT_NUMERIC);

        $medianSeconds = $this->percentile($sorted, 50.0);
        $p95Seconds = $this->percentile($sorted, 95.0);
        $medianUs = $iterations > 0 ? $medianSeconds * 1_000_000 / $iterations : 0.0;
        $p95Us = $iterations > 0 ? $p95Seconds * 1_000_000 / $iterations : 0.0;
        $medianOpsPerSecond = $medianSeconds > 0.0 ? $iterations / $medianSeconds : 0.0;
        sort($sampleTokenizeUs, SORT_NUMERIC);
        sort($sampleParseUs, SORT_NUMERIC);
        sort($samplePeakMemoryKb, SORT_NUMERIC);
        $medianTokenizeUs = $this->percentile($sampleTokenizeUs, 50.0);
        $medianParseUs = $this->percentile($sampleParseUs, 50.0);
        $medianPeakMemoryKb = $this->percentile($samplePeakMemoryKb, 50.0);
        $p95PeakMemoryKb = $this->percentile($samplePeakMemoryKb, 95.0);

        return [
            'length' => strlen($template),
            'medianSeconds' => $medianSeconds,
            'medianUs' => $medianUs,
            'p95Us' => $p95Us,
            'medianOpsPerSecond' => $medianOpsPerSecond,
            'medianTokenizeUs' => $medianTokenizeUs,
            'medianParseUs' => $medianParseUs,
            'medianPeakMemoryKb' => $medianPeakMemoryKb,
            'p95PeakMemoryKb' => $p95PeakMemoryKb,
            'sampleSeconds' => $sampleSeconds,
        ];
    }

    /**
     * @return array{seconds: float, tokenizeUs: float, parseUs: float, peakMemoryKb: float}
     */
    private function measureOnce(Parser $parser, string $template, int $iterations): array
    {
        $totalNanoseconds = 0;
        $tokenizeNanoseconds = 0;
        $parseNanoseconds = 0;
        $baselineUsage = memory_get_usage(false);
        $baselinePeak = memory_get_peak_usage(false);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
            $baselinePeak = memory_get_peak_usage(false);
        }

        $lexer = new Lexer();

        for ($index = 0; $index < $iterations; $index++) {
            $totalStart = hrtime(true);

            // Measure tokenization (Lexer phase)
            $phaseStart = hrtime(true);
            $tokens = $lexer->tokenize($template);
            $tokenizeNanoseconds += hrtime(true) - $phaseStart;

            // Measure parsing (Parser phase)
            $phaseStart = hrtime(true);
            $parser->parseTokens($tokens);
            $parseNanoseconds += hrtime(true) - $phaseStart;

            $totalNanoseconds += hrtime(true) - $totalStart;
        }

        $tokenizeUs = $iterations > 0 ? $tokenizeNanoseconds / 1000 / $iterations : 0.0;
        $parseUs = $iterations > 0 ? $parseNanoseconds / 1000 / $iterations : 0.0;
        $peakMemoryBytes = max(0, memory_get_peak_usage(false) - $baselinePeak);
        if ($peakMemoryBytes === 0) {
            $peakMemoryBytes = max(0, memory_get_usage(false) - $baselineUsage);
        }

        $peakMemoryKb = $peakMemoryBytes / 1024;

        return [
            'seconds' => $totalNanoseconds / 1_000_000_000,
            'tokenizeUs' => $tokenizeUs,
            'parseUs' => $parseUs,
            'peakMemoryKb' => $peakMemoryKb,
        ];
    }

    private function warmup(Parser $parser, string $template, int $warmup): void
    {
        for ($index = 0; $index < $warmup; $index++) {
            $parser->parse($template);
        }
    }

    /**
     * @param array<int, float> $sortedValues
     */
    private function percentile(array $sortedValues, float $percentile): float
    {
        if ($sortedValues === []) {
            return 0.0;
        }

        $count = count($sortedValues);
        if ($count === 1) {
            return $sortedValues[0];
        }

        $rank = $percentile / 100.0 * ($count - 1);
        $lower = (int)floor($rank);
        $upper = (int)ceil($rank);
        if ($lower === $upper) {
            return $sortedValues[$lower];
        }

        $weight = $rank - $lower;

        return $sortedValues[$lower] + ($sortedValues[$upper] - $sortedValues[$lower]) * $weight;
    }

    /**
     * @return array<string, string>
     */
    private function cases(): array
    {
        return [
            'simple-html' => '<div class="card"><h1>Hello</h1><p>World</p></div>',
            'outputs-and-attrs' => '<a href="<?= $url ?>" title="<?= $title ?>"><?= $label ?></a>',
            'nested-structure' => '<ul><li s:foreach="$items as $item"><span><?= $item ?></span></li></ul>',
            'raw-region' => '<section><div s:raw><span s:if="$flag"><?= $danger ?></span>{{ token }}</div></section>',
            'fragment-raw' => '<s-template s:raw><?= $value ?></s-template><p>after</p>',
            'component-heavy' => '<s-card s:bind="$props"><div s:slot="header"><?= $title ?></div><p><?= $body ?></p></s-card>',
        ];
    }

    /**
     * @return array<string, array{length: int, medianUs: float, p95Us: float, medianOpsPerSecond: float, medianPeakMemoryKb: float, p95PeakMemoryKb: float}>
     */
    private function runDomReferenceBenchmark(int $iterations, int $warmup, int $samples): array
    {
        $cases = $this->domReferenceCases();
        $results = [];

        foreach ($cases as $name => $template) {
            $this->printProgress(sprintf('Warming up DOM %s (%d iterations)', $name, $warmup));
            for ($index = 0; $index < $warmup; $index++) {
                $this->compileWithDomReference($template);
            }

            $this->printProgress(sprintf('Measuring DOM %s (%d samples × %d iterations)', $name, $samples, $iterations));
            $sampleSeconds = [];
            $samplePeakMemoryKb = [];
            for ($sample = 0; $sample < $samples; $sample++) {
                $this->printProgress(sprintf('  DOM %s sample %d/%d', $name, $sample + 1, $samples));
                $sampleResult = $this->measureDomReferenceOnce($template, $iterations);
                $sampleSeconds[] = $sampleResult['seconds'];
                $samplePeakMemoryKb[] = $sampleResult['peakMemoryKb'];
            }

            sort($sampleSeconds, SORT_NUMERIC);
            sort($samplePeakMemoryKb, SORT_NUMERIC);
            $medianSeconds = $this->percentile($sampleSeconds, 50.0);
            $p95Seconds = $this->percentile($sampleSeconds, 95.0);
            $medianUs = $iterations > 0 ? $medianSeconds * 1_000_000 / $iterations : 0.0;
            $p95Us = $iterations > 0 ? $p95Seconds * 1_000_000 / $iterations : 0.0;
            $medianOpsPerSecond = $medianSeconds > 0.0 ? $iterations / $medianSeconds : 0.0;

            $results[$name] = [
                'length' => strlen($template),
                'medianUs' => $medianUs,
                'p95Us' => $p95Us,
                'medianOpsPerSecond' => $medianOpsPerSecond,
                'medianPeakMemoryKb' => $this->percentile($samplePeakMemoryKb, 50.0),
                'p95PeakMemoryKb' => $this->percentile($samplePeakMemoryKb, 95.0),
            ];
        }

        return $results;
    }

    /**
     * @return array{seconds: float, peakMemoryKb: float}
     */
    private function measureDomReferenceOnce(string $template, int $iterations): array
    {
        $totalNanoseconds = 0;
        $baselineUsage = memory_get_usage(false);
        $baselinePeak = memory_get_peak_usage(false);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
            $baselinePeak = memory_get_peak_usage(false);
        }

        for ($index = 0; $index < $iterations; $index++) {
            $startTime = hrtime(true);
            $this->compileWithDomReference($template);
            $totalNanoseconds += hrtime(true) - $startTime;
        }

        $peakMemoryBytes = max(0, memory_get_peak_usage(false) - $baselinePeak);
        if ($peakMemoryBytes === 0) {
            $peakMemoryBytes = max(0, memory_get_usage(false) - $baselineUsage);
        }

        return [
            'seconds' => $totalNanoseconds / 1_000_000_000,
            'peakMemoryKb' => $peakMemoryBytes / 1024,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function domReferenceCases(): array
    {
        return [
            'simple-html' => '<div class="card"><h1>Hello</h1><p>World</p></div>',
            'outputs-and-attrs' => '<a :href="$url" :title="$title">{{ $label }}</a>',
            'nested-structure' => '<ul><li v-for="$items as $item"><span>{{ $item }}</span></li></ul>',
        ];
    }

    /**
     * @param array<string, array{medianUs?: float, medianPeakMemoryKb?: float}> $parserResults
     * @param array<string, array{medianUs: float, medianPeakMemoryKb: float}> $domResults
     */
    private function printSideBySideTable(array $parserResults, array $domResults): void
    {
        $this->printProgress('Building side-by-side parser vs DOM comparison...');

        echo "\nSide-by-side comparison (common cases)\n";
        echo str_pad('Case', 26)
            . str_pad('parser µs', 12)
            . str_pad('dom µs', 12)
            . str_pad('parser KB', 12)
            . str_pad('dom KB', 12)
            . str_pad('parser vs dom', 16)
            . str_pad('time ratio', 12)
            . "mem ratio\n";
        echo str_repeat('-', 116) . "\n";

        foreach ($domResults as $name => $domResult) {
            if (!isset($parserResults[$name])) {
                continue;
            }

            if (!is_array($parserResults[$name])) {
                continue;
            }

            $parserResult = $parserResults[$name];
            $parserUs = is_numeric($parserResult['medianUs'] ?? null) ? (float)$parserResult['medianUs'] : 0.0;
            $domUs = (float)$domResult['medianUs'];
            $parserKb = is_numeric($parserResult['medianPeakMemoryKb'] ?? null)
                ? (float)$parserResult['medianPeakMemoryKb']
                : 0.0;
            $domKb = (float)$domResult['medianPeakMemoryKb'];

            $delta = 'n/a';
            if ($domUs > 0.0) {
                $delta = sprintf('%+.1f%%', (($parserUs / $domUs) - 1.0) * 100.0);
            }

            $timeRatio = $domUs > 0.0 ? sprintf('%.2fx', $parserUs / $domUs) : 'n/a';
            $memoryRatio = $domKb > 0.0 ? sprintf('%.2fx', $parserKb / $domKb) : 'n/a';

            echo str_pad($name, 26)
                . str_pad(number_format($parserUs, 2), 12)
                . str_pad(number_format($domUs, 2), 12)
                . str_pad(number_format($parserKb, 2), 12)
                . str_pad(number_format($domKb, 2), 12)
                . str_pad($delta, 16)
                . str_pad($timeRatio, 12)
                . $memoryRatio
                . "\n";
        }
    }

    private function compileWithDomReference(string $htmlTemplate): string
    {
        $phpPlaceholders = [];

        $document = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="__root__">' . $htmlTemplate . '</div>';
        $flags = $this->domLoadFlags();
        $document->loadHTML('<?xml version="1.0" encoding="utf-8" ?>' . $wrapped, $flags);
        $xpath = new DOMXPath($document);

        $this->domProcessLoops($document, $xpath, $phpPlaceholders);
        $this->domProcessConditionals($document, $xpath, $phpPlaceholders);
        $this->domProcessDynamicAttributes($xpath, $phpPlaceholders);
        $this->domProcessInterpolations($xpath, $phpPlaceholders);

        $rootQuery = $xpath->query('//*[@id="__root__"]');
        if ($rootQuery === false) {
            return '';
        }

        $root = $rootQuery->item(0);
        if (!$root instanceof DOMElement) {
            return '';
        }

        $output = '';
        foreach ($root->childNodes as $node) {
            $output .= $document->saveHTML($node);
        }

        foreach ($phpPlaceholders as $placeholder => $php) {
            $output = str_replace($placeholder, $php, $output);
        }

        return $output;
    }

    private function domLoadFlags(): int
    {
        $flags = 0;
        $constants = [
            'LIBXML_HTML_NOIMPLIED',
            'LIBXML_NOBLANKS',
            'LIBXML_NOCDATA',
            'LIBXML_NONET',
            'LIBXML_NOXMLDECL',
            'LIBXML_NSCLEAN',
            'LIBXML_PARSEHUGE',
        ];

        foreach ($constants as $constant) {
            if (defined($constant)) {
                $flags |= constant($constant);
            }
        }

        return $flags;
    }

    /**
     * @param array<string, string> $phpPlaceholders
     */
    private function domProcessLoops(DOMDocument $document, DOMXPath $xpath, array &$phpPlaceholders): void
    {
        $nodes = $xpath->query('//*[@v-foreach or @v-for]');
        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (!$node->parentNode instanceof DOMNode) {
                continue;
            }

            $directive = $node->getAttribute('v-for');
            if ($directive === '') {
                $directive = $node->getAttribute('v-foreach');
            }

            $node->removeAttribute('v-for');
            $node->removeAttribute('v-foreach');

            $openPlaceholder = $this->domInjectPhp(
                sprintf('<?php foreach(%s) { ?>', $directive),
                $phpPlaceholders,
            );
            $closePlaceholder = $this->domInjectPhp('<?php } ?>', $phpPlaceholders);

            $node->parentNode->insertBefore($document->createTextNode($openPlaceholder), $node);
            $node->parentNode->insertBefore($document->createTextNode($closePlaceholder), $node->nextSibling);
        }
    }

    /**
     * @param array<string, string> $phpPlaceholders
     */
    private function domProcessConditionals(DOMDocument $document, DOMXPath $xpath, array &$phpPlaceholders): void
    {
        $nodes = $xpath->query('//*[@v-if]');
        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (!$node->hasAttribute('v-if')) {
                continue;
            }

            $chain = [];
            $current = $node;
            while (
                $current instanceof DOMElement
                && ($current->hasAttribute('v-if')
                    || $current->hasAttribute('v-else-if')
                    || $current->hasAttribute('v-else'))
            ) {
                $chain[] = $current;
                $current = $current->nextSibling;

                while ($current instanceof DOMText && trim($current->nodeValue ?? '') === '') {
                    $current = $current->nextSibling;
                }
            }

            foreach ($chain as $element) {
                if (!$element->parentNode instanceof DOMNode) {
                    continue;
                }

                if ($element->hasAttribute('v-if')) {
                    $expr = $element->getAttribute('v-if');
                    $element->removeAttribute('v-if');
                    $php = sprintf('<?php if(%s) { ?>', $expr);
                } elseif ($element->hasAttribute('v-else-if')) {
                    $expr = $element->getAttribute('v-else-if');
                    $element->removeAttribute('v-else-if');
                    $php = sprintf('<?php } elseif(%s) { ?>', $expr);
                } else {
                    $element->removeAttribute('v-else');
                    $php = '<?php } else { ?>';
                }

                $placeholder = $this->domInjectPhp($php, $phpPlaceholders);
                $element->parentNode->insertBefore($document->createTextNode($placeholder), $element);
            }

            $last = end($chain);
            if (!$last instanceof DOMElement) {
                continue;
            }

            if (!$last->parentNode instanceof DOMNode) {
                continue;
            }

            $closePlaceholder = $this->domInjectPhp('<?php } ?>', $phpPlaceholders);
            $last->parentNode->insertBefore($document->createTextNode($closePlaceholder), $last->nextSibling);
        }
    }

    /**
     * @param array<string, string> $phpPlaceholders
     */
    private function domProcessDynamicAttributes(DOMXPath $xpath, array &$phpPlaceholders): void
    {
        $nodes = $xpath->query('//*[@*[starts-with(name(), ":")]]');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (!$node->hasAttributes()) {
                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attr) {
                if (!$attr instanceof DOMAttr) {
                    continue;
                }

                if (!str_starts_with($attr->name, ':')) {
                    continue;
                }

                $realName = substr($attr->name, 1);
                $php = $this->domInjectPhp('<?= ' . $attr->value . ' ?>', $phpPlaceholders);
                $node->setAttribute($realName, $php);
                $node->removeAttributeNode($attr);
            }
        }
    }

    /**
     * @param array<string, string> $phpPlaceholders
     */
    private function domProcessInterpolations(DOMXPath $xpath, array &$phpPlaceholders): void
    {
        $textNodes = $xpath->query('//text()[contains(., "{{")]');
        if ($textNodes === false) {
            return;
        }

        foreach ($textNodes as $textNode) {
            if (!$textNode instanceof DOMText) {
                continue;
            }

            $newContent = preg_replace_callback(
                '/{{\s*(.*?)\s*}}/s',
                function (array $matches) use (&$phpPlaceholders): string {
                    $expression = $matches[1];

                    return $this->domInjectPhp('<?= ' . $expression . ' ?>', $phpPlaceholders);
                },
                $textNode->nodeValue ?? '',
            );

            if (is_string($newContent)) {
                $textNode->nodeValue = $newContent;
            }
        }
    }

    /**
     * @param array<string, string> $phpPlaceholders
     */
    private function domInjectPhp(string $code, array &$phpPlaceholders): string
    {
        $key = sprintf('--==PHP_CHUNK_%d_%s==--', count($phpPlaceholders), md5($code));
        $phpPlaceholders[$key] = $code;

        return $key;
    }

    private function parsePositiveInt(?string $value, int $defaultValue): int
    {
        if ($value === null || $value === '') {
            return $defaultValue;
        }

        if (!ctype_digit($value)) {
            return $defaultValue;
        }

        $parsedValue = (int)$value;
        if ($parsedValue <= 0) {
            return $defaultValue;
        }

        return $parsedValue;
    }

    /**
     * @param array<string> $arguments
     * @return array{iterations: int, warmup: int, samples: int, json: string|null, compare: string|null, referenceDom: bool}
     */
    private function parseOptions(array $arguments): array
    {
        $iterations = $this->parsePositiveInt($arguments[1] ?? null, 20000);
        $warmup = $this->parsePositiveInt($arguments[2] ?? null, 2000);
        $samples = 7;
        $jsonPath = 'benchmark/latest.json';
        $comparePath = null;
        $referenceDom = false;

        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                continue;
            }

            if (str_starts_with($argument, '--iterations=')) {
                $iterations = $this->parsePositiveInt(substr($argument, 13), $iterations);
                continue;
            }

            if (str_starts_with($argument, '--warmup=')) {
                $warmup = $this->parsePositiveInt(substr($argument, 9), $warmup);
                continue;
            }

            if (str_starts_with($argument, '--samples=')) {
                $samples = $this->parsePositiveInt(substr($argument, 10), $samples);
                continue;
            }

            if (str_starts_with($argument, '--json=')) {
                $jsonPath = trim(substr($argument, 7));
                continue;
            }

            if (str_starts_with($argument, '--compare=')) {
                $comparePath = trim(substr($argument, 10));
                continue;
            }

            if ($argument === '--reference-dom') {
                $referenceDom = true;
            }
        }

        return [
            'iterations' => $iterations,
            'warmup' => $warmup,
            'samples' => $samples,
            'json' => $jsonPath !== '' ? $jsonPath : null,
            'compare' => $comparePath !== '' ? $comparePath : null,
            'referenceDom' => $referenceDom,
        ];
    }

    private function formatVsBase(float $value, ?float $baseline): string
    {
        if ($baseline === null || $baseline <= 0.0) {
            return 'n/a';
        }

        $deltaPercent = (($value / $baseline) - 1.0) * 100.0;

        return sprintf('%+.1f%%', $deltaPercent);
    }

    /**
     * @param array<string, mixed>|null $comparison
     */
    private function formatVsPrevious(string $case, float $medianUs, ?array $comparison): string
    {
        if ($comparison === null) {
            return 'n/a';
        }

        $results = $comparison['results'] ?? null;
        if (!is_array($results) || !isset($results[$case]) || !is_array($results[$case])) {
            return 'n/a';
        }

        $previousMedian = $results[$case]['medianUs'] ?? null;
        if (!is_numeric($previousMedian) || (float)$previousMedian <= 0.0) {
            return 'n/a';
        }

        $deltaPercent = (($medianUs / (float)$previousMedian) - 1.0) * 100.0;

        return sprintf('%+.1f%%', $deltaPercent);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadComparison(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        file_put_contents($path, $encoded . "\n");
    }

    private function printProgress(string $message): void
    {
        echo '[progress] ' . $message . "\n";
    }
}

$benchmark = new ParserBenchmark();
$arguments = [];
$argumentsRaw = $_SERVER['argv'] ?? [];
if (is_array($argumentsRaw)) {
    foreach ($argumentsRaw as $argument) {
        if (!is_scalar($argument)) {
            continue;
        }

        $arguments[] = (string)$argument;
    }
}

$benchmark->run($arguments);
