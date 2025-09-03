# Composer Dependency Age

[![GitHub License](https://img.shields.io/github/license/konradmichalik/composer-dependency-age)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://php.net)
[![Composer Version](https://img.shields.io/badge/composer-%5E2.0-blue)](https://getcomposer.org)

A Composer plugin that analyzes the age of your project dependencies and provides neutral categorization to help you understand your dependency landscape. No risk assessment - just clear, objective information about when your dependencies were last released.

## ✨ Features

- **Neutral Age Analysis** - Categorizes dependencies as Current, Medium, or Old based on release dates
- **Multiple Output Formats** - CLI table, JSON for automation, GitHub-formatted for PRs
- **Flexible Filtering** - Analyze all dependencies or focus on direct ones only
- **Smart Caching** - Caches Packagist API responses with configurable TTL for better performance
- **CI/CD Ready** - Perfect for automated dependency auditing in your build pipelines
- **Highly Configurable** - Customize thresholds, ignore lists, and output preferences

## 🚀 Installation

Install the plugin globally or per project via Composer:

```bash
composer require konradmichalik/composer-dependency-age
```

The plugin integrates automatically with Composer and adds the `dependency-age` command.

## 📊 Usage

### Basic Analysis
```bash
composer dependency-age
```

### Focus on Direct Dependencies
```bash
composer dependency-age --direct
```

### JSON Output for CI/CD
```bash
composer dependency-age --format json
```

### GitHub-Formatted Output
```bash
composer dependency-age --format github
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
      "cache_ttl": 86400
    }
  }
}
```

## 📈 Age Categories

| Category | Emoji | Timeframe | Description |
|----------|-------|-----------|-------------|
| Current | ✅ | ≤ 6 months | Recently released dependencies |
| Medium | ⚠️ | ≤ 12 months | Moderately aged dependencies |
| Old | ❗ | > 12 months | Dependencies released over a year ago |
| Unknown | ❓ | - | Dependencies without release date information |

## 💡 Output Formats

### CLI Table (Default)
Human-readable table with colors, symbols, and summary statistics. Perfect for manual review and development workflow.

### JSON Format
Machine-readable output ideal for:
- CI/CD pipeline integration
- Custom reporting tools
- Automated dependency monitoring
- Data analysis workflows

### GitHub Format
Markdown-optimized output designed for:
- Pull request comments
- Issue tracking
- GitHub Actions integration
- Team communication

## 🔧 Integration

### Automatic Analysis
The plugin automatically runs after `composer install` and `composer update` operations, providing immediate feedback on your dependency landscape.

### CI/CD Pipeline
```bash
# Check dependency ages in CI
composer dependency-age --format json > dependency-report.json

# Focus on direct dependencies only
composer dependency-age --direct --no-colors
```

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## ⭐ License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.
