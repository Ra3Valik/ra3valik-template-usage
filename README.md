# Ra3valik Template Usage Scanner (Site Editor)

Finds unused `wp_template` items and marks them as "Not in use".
Respects template hierarchy: `page-{slug|ID}`, `single`, `single-{post_type}`, `single-{post_type}-{ID|slug}`, taxonomy templates (`taxonomy-`, `category-`, `tag-`).

## Installation
1. Copy the folder `ra3valik-template-usage` into `wp-content/plugins/`.
2. Activate **ra3valik – Template Usage Scanner (Site Editor)** in wp-admin → Plugins.
    - Or use as MU-plugin: copy `ra3valik-template-usage.php` to `wp-content/mu-plugins/`.

## Usage
- wp-admin → Appearance → **Template Usage**
- Click **Scan now** to mark unused templates.
- **Clear all “Not in use” badges** to remove flags/prefixes.
- Set the **Unused title prefix** in settings.

## Notes
- Flag meta: `_ra3valik_not_in_use`
- Default prefix: `🗑 Not in use — `
- Version: 1.2.0
