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

With `--hyva-cms` a third file joins each pair, see [Hyva CMS content](#hyva-cms-content).

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

Hyva CMS stores its own component tree beside the native row, plus a per-entity Tailwind CSS delta the
storefront needs to render it. Neither travels with the `.html` and `.json` above, and Hyva's own Transfer
Center carries no CSS at all, so a promoted page renders unstyled until someone republishes it by hand.

`--hyva-cms` on either command adds a third file per entity, `%%IDENTIFIER%%---%%STORES%%.hyva.json`,
holding `draft_content`, `published_content`, every `{theme, edition, css}` row, the two liveview flags,
and a diagnostic `references` list.

```
php bin/magento cms:dump:data   --type=all -i contact-us,home-hero --hyva-cms
php bin/magento cms:import:data --type=all -i contact-us,home-hero --hyva-cms
```

The flag defaults to off. Off is byte identical to previous behaviour, and on an install without Hyva CMS
both commands warn and carry on with the native export or import.

Watch out for:

- **The `.html` is written empty for a Hyva entity**, because the component tree is what renders. Import
  mirrors the source, so promoting a Hyva page clears the native content on the target.
- **`cms:dump:data -r` without `--hyva-cms` deletes your `.hyva.json` files.**
- **An unrestricted import overwrites live content for every entity with a sidecar.** Restrict with `-i`.
- **Avoid `.html` inside a block identifier.** The block export writes it verbatim, unlike the page export.
- Stored CSS is a delta against that theme's compiled `styles.css`, so source and target must run the same
  theme build. The importer warns when the theme is missing on the target.
- A missing block, menu or instance component renders as nothing, silently. The importer warns per miss.
- Treat these files as code. Content and CSS reach the storefront unescaped, as the `.html` always has.

Templates, snippets, instance components, attribute content and version history are not exported.
Templates and snippets are copy on insert, so a page never references one. The rest are separate content
roots.

## Hyva menus

A Menu Builder menu has no native CMS row behind it, so it exports as one file rather than the html, json
and hyva.json trio: `var/sync_cms_data/cms/menus/%%IDENTIFIER%%---%%STORES%%.json`, holding the whole
record plus its CSS rows and the same diagnostic `references` list.

```
php bin/magento cms:dump:data   --type=menu -i main-nav
php bin/magento cms:import:data --type=menu -i main-nav
```

`--type=menu` needs `hyva-themes/commerce-module-menu-builder`; without it both commands warn and do
nothing. `--hyva-cms` has no meaning here, because the Hyva content is the whole entity.

Watch out for:

- **`--type=all` does not include menus.** Ask for them explicitly.
- **Category links export as `url_path`, not as an entity id**, so they resolve on the target. That covers
  a `category` link and `hyva_menu_category_tree`. A path the target does not have is reported and left
  alone, so the link renders as nothing until the category exists.
- A menu matches by identifier within its own store scope, so a re-import updates rather than duplicates.
- Pointing a storefront at the menu is separate configuration, `design/header/topmenu_identifier`.
