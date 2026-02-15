# Benchmarks

## Parser benchmark

Run:

```bash
php benchmark/parser.php
```

By default, results are written to `benchmark/latest.json` (and compared against it when it already exists).

Default positional arguments are still supported:

```bash
php benchmark/parser.php <iterations> <warmup>
```

Example:

```bash
php benchmark/parser.php 50000 5000
```

### Improved output

The benchmark now reports per case:

- Median microseconds per operation (`med µs`)
- 95th percentile microseconds per operation (`p95 µs`)
- Median tokenize time per operation (`tok µs`)
- Median parse time per operation (`parse µs`)
- Median incremental peak memory per operation sample (`peak KB`)
- 95th percentile incremental peak memory (`p95 KB`)
- Median operations per second (`ops/s`)
- Relative delta vs baseline case (`vs base`, baseline = `simple-html`)
- Optional relative delta vs previous JSON run (`vs prev`)

During execution, progress lines are printed for warmup and each sample so long runs show visible activity.

### Useful flags

```bash
php benchmark/parser.php --iterations=50000 --warmup=5000 --samples=9
```

Save JSON results:

```bash
php benchmark/parser.php --json=benchmark/latest.json
```

When `--json` points to an existing file, the benchmark automatically uses it as `vs prev` baseline.

Compare against previous run:

```bash
php benchmark/parser.php --compare=benchmark/latest.json
```

Run DOM reference comparison mode:

```bash
php benchmark/parser.php --reference-dom
```

In `--reference-dom` mode the benchmark also prints:

- A DOM reference compile-phase table (`simple-html`, `outputs-and-attrs`, `nested-structure`)
- A side-by-side comparison table with:
	- parser vs dom delta (`parser vs dom`)
	- time ratio (`time ratio`)
	- memory ratio (`mem ratio`)

Combined example:

```bash
php benchmark/parser.php --iterations=50000 --warmup=5000 --samples=9 --reference-dom --json=benchmark/latest.json
```
