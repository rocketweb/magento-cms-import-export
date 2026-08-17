# CMS Import/Export Tool
A tool to manage CMS content (both blocks &amp; pages) being imported/exported between environments using the repository. This tool comes handy for build and maintenance projects.

> Ideas for using this:
> - allowing FED team to create a CMS block/page in admin but then modify the HTML content using proper IDEs that allow auto-complete & code-styling
> - allowing simpler deployments since there is no manual copy/paste of CMS data needed
> - allowing the client to modify staging content and having it ready for deployment
> - having an easy way to sync up production env to staging/dev/local by exporting on production and importing on 
  staging/dev/local

## Requirements

PHP 8.1 or newer. Hyva CMS support is optional: the `--hyva-cms` flag needs `hyva-themes/commerce-module-cms`, and
without it both commands behave exactly as they always have.

## Installation
Using composer:
```
composer require rocketweb/module-cms-import-export
```

Then enable module:
```
bin/magento module:enable RocketWeb_CmsImportExport
```


Once the tool is installed, we have two workflows, depending on what we are trying to do.

## Export

Usage:
```
php bin/magento cms:dump:data [options]

Description:
Dumps cms pages/blocks to var/sync_cms_data for further import

Options:
-t, --type=TYPE                Which type are we dumping - block/page/all
-i, --identifier[=IDENTIFIER]  identifier to process (one or CSV list)
-r, --removeAll                Flag to remove all existing data
    --hyva-cms                 Also dump Hyva CMS content and its per-entity Tailwind CSS
```

As you can see from the options, we need to define:
- type - which can be CMS block, CMS page or both - **required**
- identifier - either a CMS block or CMS page identifier - **optional**

With the combination of these two, we can **export**:
- all CMS content (using --type=all)
- all CMS pages (using --type=page)
- all CMS blocks (using --type=block)
- specific CMS page or pages (using --type=page --identifier=about-us.html,no-route)
- specific CMS block or blocks (using --type=block --identifier=who-are-we,homepage-carousel)

> CMS Page identifier is **Url Key**! Because of that, it can have **.html** suffix - it depends on what is set in the 
Magento Admin CMS Edit Page. _Use the actual value from CMS Edit Page - Url Key_!If the CMS Page Url Key has **.html** suffix, then the file **%%IDENTIFIER%%** will be: **url_key_html.html** (but for export or import, you still use the value from Url Key)

Once you execute the command, you will get the following folder structure:

```
var/sync_cms_data/cms/
- blocks
    - %%IDENTIFIER%%---%%STORES%%.html => contains the block HTML
    - %%IDENTIFIER%%---%%STORES%%.json => contains title, is_active, stores information
- pages
    - %%IDENTIFIER%%---%%STORES%%.html => contains the page HTML
    - %%IDENTIFIER%%---%%STORES%%.json => contains title, is_active, page_layout, content_heading
```

Every file name carries the store codes the entity is assigned to, joined with `---`. An entity on All Store Views
renders as `_all_`, so `about-us` on All Store Views becomes `about-us---_all_.html`. A `/` inside an identifier
becomes `---` and a `.html` suffix becomes `_html`.

With `--hyva-cms` a third file joins each pair. See [Hyva CMS content](#hyva-cms-content) below.

You can modify the HTML directly in your editor which should give you more flexibility.

When you are done, commit the files (html & json) to the repository.

## Import

```
Usage:
php bin/magento cms:import:data [options]

Description:
Import cms pages/blocks from var/sync_cms_data

Options:
-t, --type=TYPE                Which type are we importing - block/page/all
-i, --identifier[=IDENTIFIER]  identifier to process (one or CSV list)
-a, --importAll                Flag to import all files
-s, --store[=STORE]            Store code to process only pages/blocks specific to this store
    --hyva-cms                 Also import Hyva CMS content and its per-entity Tailwind CSS
```

This command works by using files in `var/sync_cms_data/cms/` path. As you can see from the options, we need to define:
- type - which can be CMS block or CMS page - **required**
- identifier - either a CMS block or CMS page identifier - **optional**
There are optional parameters:
- importAll - when identifiers not specified we'll import all blocks or pages
- store - store code (like default) to import block(s)/pages(s) only for specific store
With the combination of these two, we can **import**:
- all CMS pages (using --type=page and importAll)
- all CMS blocks (using --type=block and importAll)
- specific CMS page or pages (using --type=page --identifier=about-us,homepage-new)
- specific CMS block or blocks (using --type=block --identifier=who-are-we,homepage-carousel)
- specific CMS page by store (using --type=page --identifier=about-us-default --store=default)
Once you execute the command, the content will be created/updated in Magento Admin. 
By executing `php bin/magento cache:flush` you should be able to see the updated CMS content on frontend also!

## Hyva CMS content

Hyva CMS keeps its own component tree beside the native `cms_page` / `cms_block` row, plus a per-entity
Tailwind CSS delta the storefront needs to render it. `--hyva-cms` makes both travel with the files above.

It exists because Hyva's own Transfer Center carries no CSS. An entity imported through it renders unstyled
until a human opens the Liveview Editor and publishes.

```
php bin/magento cms:dump:data   --type=all -i contact-us,home-hero --hyva-cms
php bin/magento cms:import:data --type=all -i contact-us,home-hero --hyva-cms
```

The flag defaults to off, and off is byte identical to previous behaviour. On an install without Hyva CMS
both commands print a warning and carry on with the native export or import.

### The sibling file

A third file joins the pair, named the same way: `<identifier>---<storecodes>.hyva.json`.

| Key | Contents |
| --- | --- |
| `is_liveview_enabled` | whether the entity renders through the Liveview Editor |
| `is_tailwindcss_jit_enabled` | whether per-entity Tailwind CSS is compiled for it |
| `draft_content` | the component tree as last saved |
| `published_content` | the component tree the storefront renders |
| `tailwindcss` | a list of `{theme, edition, css}` rows, every theme and edition |
| `references` | the cross-entity identifiers the content depends on, diagnostic only |

A separate file rather than extra keys in the `.json`, so an older build of this extension ignores it
instead of silently dropping keys it does not understand on the next export.

**The `.html` is written empty for a Hyva entity.** The component tree is what renders, so the native
content column is either already empty or stale legacy markup. The file still exists because the importer
discovers entities by it. Import mirrors the source, so promoting a Hyva page clears the native content on
the target.

### Which Tailwind CSS travels

Only the tables a storefront request reads:

| Table | Travels |
| --- | --- |
| `hyva_commerce_cms_page_tailwindcss` | yes |
| `hyva_commerce_cms_block_tailwindcss` | yes, for the block and for any block a page embeds |
| `hyva_commerce_cms_menu_tailwindcss` | not yet, needed once a page embeds a menu |
| `hyva_cms_snippet_tailwindcss`, `hyva_cms_template_tailwindcss` | no, editor preview only |
| `hyva_commerce_product_attribute_tailwindcss`, `..._category_...` | no, separate content root |

Stored CSS is a delta against that theme's compiled `styles.css`, so it is only correct while the target
theme build matches the source. Safe for promoting one codebase between environments, unsafe between
unrelated projects. The importer warns on every run, and again if the theme is not registered on the target.

### Things to watch out for

- **Treat these files as code.** Content and CSS reach the storefront unescaped, exactly as the `.html`
  sibling always has.
- **`cms:dump:data -r` without `--hyva-cms` deletes your `.hyva.json` files.** `-r` clears the sync
  directory and an off-path dump never writes them back.
- **An unrestricted `--hyva-cms` import overwrites live content for every entity with a sidecar.** Restrict
  with `-i` unless you mean to promote everything.
- **Avoid `.html` inside a block identifier.** The block export writes the identifier verbatim, unlike the
  page export which converts it to `_html`. `--hyva-cms` handles it, the older `.json` path does not.
- A missing reference renders as nothing at all, with no exception and no log entry. The importer checks
  `cms_block`, `menu` and `instance_component`, warns per miss, and prints a run total.
- The CSS tables key on `cms_page` / `cms_block`, not on the Hyva rows, so deleting a Hyva content row does
  not cascade its CSS.

### Deliberately not covered

Menus, templates and snippets, instance components, product and category attribute content, version
history, and a Transfer Center handler.

Templates and snippets are copy on insert, so a page never references one and its own CSS row already
covers their components. The rest are separate content roots. Menus and instance components are real live
references, so the collector records them and the importer warns when they are missing.
