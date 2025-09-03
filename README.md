<div align="center">

# Composer Dependency Age

[![Coverage](https://img.shields.io/coverallsCoverage/github/jackd248/composer-dependency-age?logo=coveralls)](https://coveralls.io/github/jackd248/composer-dependency-age)
[![CGL](https://img.shields.io/github/actions/workflow/status/jackd248/composer-dependency-age/cgl.yml?label=cgl&logo=github)](https://github.com/jackd248/composer-dependency-age/actions/workflows/cgl.yml)
[![Tests](https://img.shields.io/github/actions/workflow/status/jackd248/composer-dependency-age/tests.yml?label=tests&logo=github)](https://github.com/jackd248/composer-dependency-age/actions/workflows/tests.yml)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/composer-dependency-age/php?logo=php)](https://packagist.org/packages/konradmichalik/composer-dependency-age)

</div>

A Composer plugin that analyzes the age of your project dependencies and provides neutral categorization to help you understand your dependency landscape. No risk assessment - just clear, objective information about when your dependencies were last released.

## ✨ Features

- **Neutral Age Analysis** - Categorizes dependencies as Current, Medium, or Old based on release dates
- **Multiple Output Formats** - CLI table, JSON for automation, GitHub-formatted for PRs
- **Flexible Filtering** - Analyze all dependencies or focus on direct ones only
- **Smart Caching** - Caches Packagist API responses with configurable TTL for better performance
- **CI/CD Ready** - Perfect for automated dependency auditing in your build pipelines
- **Highly Configurable** - Customize thresholds, ignore lists, and output preferences

## 🔥 Installation

```bash
composer require konradmichalik/composer-dependency-age --dev
```

## 📊 Usage

### Command
```bash
composer dependency-age
```

### Automatic Analysis
The plugin automatically runs after `composer install` and `composer update` operations, providing immediate feedback on your dependency landscape.

```shell
$ composer install
...
Dependency age ~ // 21.9 years in total (9 months average per package). Use composer dependency-age for full details.
...
```

## 📝 Configuration

### Command Line Options

| Option | Description | Default |
|--------|-------------|---------|
| `--format` | Output format: cli, json, github | cli |
| `--direct` | Show only direct dependencies | false |
| `--no-dev` | Exclude development dependencies | false |
| `--no-colors` | Disable color output | false |
| `--no-cache` | Disable caching | false |
| `--offline` | Use cached data only | false |
| `--ignore` | Comma-separated packages to ignore | - |
| `--thresholds` | Custom age thresholds (years) | current=0.5,medium=1.0,old=2.0 |

### Configuration via composer.json

```json
{
  "extra": {
    "dependency-age": {
      "thresholds": {
        "current": 0.5,
        "medium": 1.0,
        "old": 2.0
      },
      "ignore": ["psr/log", "psr/container"],
      "output_format": "cli",
      "include_dev": false,
      "cache_ttl": 86400,
      "event_integration": true,
      "event_operations": ["install", "update"],
      "event_analysis_limit": 10
    }
  }
}
```

#### Event Integration Options

| Option | Description | Default | Example |
|--------|-------------|---------|---------|
| `event_integration` | Enable/disable automatic analysis after composer operations | `true` | `false` |
| `event_operations` | Which operations trigger analysis | `["install", "update"]` | `["install"]` |
| `event_analysis_limit` | Maximum packages to analyze in quick mode | `10` | `20` |

**To disable composer hooks entirely:**
```json
{
  "extra": {
    "dependency-age": {
      "event_integration": false
    }
  }
}
```

**To run analysis only after install (not update):**
```json
{
  "extra": {
    "dependency-age": {
      "event_operations": ["install"]
    }
  }
}
```

## 📈 Age Categories

| Category | Sign | Timeframe | Description |
|----------|------|-----------|-------------|
| Current | ✓    | ≤ 6 months | Recently released dependencies |
| Medium | ~️   | ≤ 12 months | Moderately aged dependencies |
| Old | !    | > 12 months | Dependencies released over a year ago |
| Unknown | ?    | - | Dependencies without release date information |

## 🧑‍💻 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## ⭐ License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.
