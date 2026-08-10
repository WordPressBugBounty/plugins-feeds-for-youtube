# SmashBalloon Customizer Library

A comprehensive PHP library for building customizable social media feed interfaces for WordPress plugins. This library provides the foundation for creating rich, interactive feed builders with preview capabilities for various SmashBalloon social media plugins.

## Overview

The SmashBalloon Customizer is a shared library used across multiple SmashBalloon WordPress plugins to provide a unified feed customization experience. It includes a visual builder interface, feed management system, and preview functionality.

## Branch Structure

This repository maintains three distinct branches, each serving a different version of the customizer for specific plugin implementations:

### `master` Branch (v1.x)
- **Version:** 1.7.0
- **Namespace:** `Smashballoon\Customizer\`
- **Purpose:** Legacy customizer implementation for older plugin versions
- **Structure:** PHP-based customizer with traditional control system
- **Key Features:**
  - Builder customizer interface
  - Feed management (Builder, Locator, Processor, Saver)
  - Extensive control library (20+ control types)
  - Template system for builder screens
  - Cache management system
  - Proxy provider for API integrations

### `v2` Branch
- **Version:** 2.1.0
- **Namespace:** `Smashballoon\Customizer\V2\`
- **Purpose:** React-based customizer for Reviews and modern plugin implementations
- **Structure:** React application with PHP backend services
- **Key Features:**
  - Full React-based UI (`sb-common/sb-customizer/src/`)
  - Collections management system
  - Forms integration for user submissions
  - Support for multiple review sources (Facebook, Google, TripAdvisor, etc.)
  - Advanced moderation capabilities
  - Import/Export functionality
  - Modern component-based architecture

### `v3` Branch
- **Version:** 2.0.0
- **Namespace:** `Smashballoon\Customizer\V3\`
- **Purpose:** Specialized implementation for TikTok Feeds plugin
- **Structure:** React application optimized for video feed management
- **Key Features:**
  - TikTok-specific feed components
  - Video player integration
  - Gallery and carousel layouts
  - Specialized templates for video content
  - WPCode integration for snippets
  - Streamlined settings management

## Installation

### Via Composer

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:awesomemotive/sb-customizer.git"
        }
    ],
    "require": {
        "smashballoon/customizer": "^1.7.0"
    }
}
```

For specific versions:
- Master branch: `"smashballoon/customizer": "^1.7.0"`
- V2 branch: `"smashballoon/customizer": "dev-v2"`
- V3 branch: `"smashballoon/customizer": "dev-v3"`

## Requirements

- PHP >= 7.4
- WordPress 5.0+
- Node.js 14+ (for v2 and v3 branches)
- Composer

## Dependencies

### Core Dependencies (All Versions)
- `smashballoon/stubs`: SmashBalloon WordPress stubs library
- `php-di/php-di`: Dependency injection container (master and v2)

### Development Dependencies
- React 17+ (v2 and v3 branches)
- Webpack for bundling
- Various WordPress-specific libraries

## Architecture

### Master Branch Structure
```
app/
├── Controls/          # UI control components
├── Cache/            # Feed caching system
├── Tabs/             # Tab management
└── *.php             # Core service classes
```

### V2/V3 Branch Structure
```
app/V2/ or app/V3/    # PHP backend services
sb-common/sb-customizer/
├── src/
│   ├── Components/   # React components
│   ├── Screens/      # Application screens
│   └── Utils/        # Utility functions
└── public/           # Static assets
```

## Key Components

### Feed Management
- **Feed_Builder**: Core feed construction logic
- **Feed_Locator**: Feed discovery and location services
- **Feed_Saver**: Persistence layer for feed configurations
- **Feed_Processor**: Feed data processing pipeline

### UI Controls (Master Branch)
Extensive library of customizable controls including:
- Color pickers
- Toggle switches
- Image choosers
- Date pickers
- Select dropdowns
- Custom view controls

### React Components (V2/V3)
Modern component library featuring:
- Preview panels with live updates
- Drag-and-drop interfaces
- Modal systems
- Template selectors
- Source managers

## Usage in Plugins

This library is designed to be integrated into SmashBalloon WordPress plugins. Each plugin will:

1. Include the library via Composer
2. Bootstrap the customizer service
3. Register plugin-specific configurations
4. Extend base classes as needed

Example integration:
```php
use Smashballoon\Customizer\Container;
use Smashballoon\Customizer\Feed_Builder;

$container = new Container();
$builder = new Feed_Builder($container);
```

## Development

### Building Assets (V2/V3)

```bash
# Install dependencies
npm install

# Development build
npm run start

# Production build
npm run build
```

### Testing

Run PHP tests:
```bash
composer test
```

## Contributing

This is a private repository maintained by the SmashBalloon team. For questions or issues, please contact the development team.

## License

Proprietary - SmashBalloon/Awesome Motive Inc.

## Support

For technical support and documentation, contact the SmashBalloon development team or refer to internal documentation resources.
