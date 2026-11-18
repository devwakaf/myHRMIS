<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Formula;

/**
 * Kahn topological sort with a stable tie-break and cycle detection that
 * reports one cycle path (spec-04 §3.5). Replaces a fixed row order with silent
 * zeros for forward references.
 */
final class DependencyGraph
{
    /** @var array<string, list<string>> node => nodes it depends on */
    private array $deps = [];

    /** @var array<string, string> node => sort key for the tie-break */
    private array $keys = [];

    public function addNode(string $node, string $sortKey): void
    {
        $this->deps[$node] ??= [];
        $this->keys[$node] = $sortKey;
    }

    public function hasNode(string $node): bool
    {
        return array_key_exists($node, $this->deps);
    }

    /** $node depends on $dependency (edge dependency → node). Unknown dependencies are ignored. */
    public function addEdge(string $node, string $dependency): void
    {
        if (! in_array($dependency, $this->deps[$node] ?? [], true)) {
            $this->deps[$node][] = $dependency;
        }
    }

    /**
     * @return list<string>
     */
    public function dependenciesOf(string $node): array
    {
        return array_values(array_filter($this->deps[$node] ?? [], fn (string $d) => array_key_exists($d, $this->deps)));
    }

    /**
     * Evaluation order. Throws FormulaError('cycle') with one cycle path.
     *
     * @return list<string>
     */
    public function sort(): array
    {
        $indegree = [];
        $dependants = [];
        foreach (array_keys($this->deps) as $node) {
            $indegree[$node] = 0;
        }
        foreach (array_keys($this->deps) as $node) {
            foreach ($this->dependenciesOf($node) as $dep) {
                $indegree[$node]++;
                $dependants[$dep][] = $node;
            }
        }

        $ready = array_keys(array_filter($indegree, fn (int $d) => $d === 0));
        $order = [];
        while ($ready !== []) {
            usort($ready, fn (string $a, string $b) => strcmp($this->keys[$a] ?? $a, $this->keys[$b] ?? $b));
            $node = array_shift($ready);
            $order[] = $node;
            foreach ($dependants[$node] ?? [] as $next) {
                if (--$indegree[$next] === 0) {
                    $ready[] = $next;
                }
            }
        }

        if (count($order) < count($this->deps)) {
            $remaining = array_values(array_diff(array_keys($this->deps), $order));
            throw FormulaError::of('cycle', ['path' => implode(' → ', $this->findCycle($remaining))]);
        }

        return $order;
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function findCycle(array $candidates): array
    {
        sort($candidates);
        foreach ($candidates as $start) {
            $path = $this->walk($start, [$start], $candidates);
            if ($path !== null) {
                return $path;
            }
        }

        return $candidates;
    }

    /**
     * Depth-first walk along dependencies until it returns to a node on the path.
     *
     * @param  list<string>  $path
     * @param  list<string>  $candidates
     * @return list<string>|null
     */
    private function walk(string $node, array $path, array $candidates): ?array
    {
        foreach ($this->dependenciesOf($node) as $dep) {
            if (! in_array($dep, $candidates, true)) {
                continue;
            }
            $at = array_search($dep, $path, true);
            if ($at !== false) {
                return [...array_slice($path, (int) $at), $dep];
            }
            $found = $this->walk($dep, [...$path, $dep], $candidates);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
