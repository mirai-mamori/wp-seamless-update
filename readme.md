# WP Seamless Update

**Contributors:** hitomi  
**Tags:** theme, update, seamless  
**Requires at least:** 5.0  
**Tested up to:** 6.8  
**Requires PHP:** 7.0  
**License:** GPLv3
**License URI:** http://www.gnu.org/licenses/gpl-3.0.html  

Implements seamless updates for a selected theme using partial file updates based on an internal version.

## Description

WP Seamless Update allows theme developers to implement seamless updates using partial file updates based on an internal version tracking system.

### Features
* Target specific theme for seamless updates
* Configure update source URL
* Keep backups of previous theme versions
* Manual update check option
* Status monitoring

## Installation

1. Upload the plugin files to the `/wp-content/plugins/wp-seamless-update` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->Seamless Update screen to configure the plugin

## Frequently Asked Questions

### How do I configure the update server?

The server needs to provide a JSON file that follows this format:

```json
{
  "display_version": "x.x.x",
  "internal_version": "x.x.x",
  "package_url": "https://yourdomain.com/x.x.x.zip",
  "package_hash": "HASH-SHA1-HEX-VALUE",
  "files": [
    "style.css",
    "functions.php"
  ]
}
```

## Changelog

### 1.0.0
* Initial release

## Translations

* English - default
* 简体中文 (Chinese Simplified) - by Hitomi

If you want to help translate the plugin into your language, please have a look at the readme.txt file which explains how to do this.
