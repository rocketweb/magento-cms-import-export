# CMS Import/Export Tool
A tool to manage CMS content (both blocks &amp; pages) being imported/exported between environments using the repository. This tool comes handy for build and maintenance projects.

> Ideas for using this:
> - allowing FED team to create a CMS block/page in admin but then modify the HTML content using proper IDEs that allow auto-complete & code-styling
> - allowing simpler deployments since there is no manual copy/paste of CMS data needed
> - allowing the client to modify staging content and having it ready for deployment
> - having an easy way to sync up production env to staging/dev/local by exporting on production and importing on 
  staging/dev/local

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
-a, --importAll                Flag to import all files
-r, --removeAll                Flag to remove all existing data
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
    - %%IDENTIFIER%%.html => contains the block HTML
    - %%IDENTIFIER%%.json => contains title, is_active, stores information
- pages
    - %%IDENTIFIER%%.html => contains the page HTML
    - %%IDENTIFIER%%.json => contains title, is_ative, page_layou, content_heading
```

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
-s, --store[STORE_CODE]        Store code to process only pages/blocks specific to this store
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

Native CMS content is the HTML in `cms_page.content` and `cms_block.content`. Hyva CMS keeps a component tree of its
own alongside that row, plus a per-entity Tailwind CSS delta the storefront needs in order to render it. The optional
`--hyva-cms` flag makes both travel through the repository with the files above.

Hyva's own Transfer Center carries no CSS at all. Its ZIP holds `manifest.json`, a per-entity `settings.json` and
`content.json`, an optional `translations.csv`, `images/**` and `media.json`, and nothing else. An entity imported
through it has no CSS until a human opens it in the Liveview Editor and publishes. There is no bulk recompile to fall
back on either, `Hyva_CmsTailwindRecompile` is not installed. Carrying the CSS ourselves is what lets a page promoted
through git render on arrival.

The flag exists on both commands. It has no shortcut letter, deliberately, because `-h` is help.

```
php bin/magento cms:dump:data --type=all -i rw-ie-fixture-page,rw-ie-fixture-block --hyva-cms
php bin/magento cms:import:data --type=all -i rw-ie-fixture-page,rw-ie-fixture-block --hyva-cms
```

> `--hyva-cms` defaults to off, and off is byte identical to the previous behaviour. Without the flag neither command
reads or writes anything belonging to Hyva CMS. On an install where Hyva CMS is absent the flag is a no-op: both
commands print a warning and carry on with the native export or import.

### The sibling file

Hyva CMS data lands in a third file, beside the `.html` and `.json` the commands already write:

```
var/sync_cms_data/cms/pages/
- rw-ie-fixture-page---_all_.html       => the native page HTML
- rw-ie-fixture-page---_all_.json       => title, is_active, page_layout, stores
- rw-ie-fixture-page---_all_.hyva.json  => Hyva CMS content and its Tailwind CSS
```

The name is `<identifier>---<storecodes>.hyva.json`, using the same identifier and store code suffix as the other two
files. Store 0, meaning All Store Views, renders as `_all_`.

A sibling file rather than extra keys inside the existing `.json`, for two reasons. An older build of this extension
ignores a file it knows nothing about, where extra keys in a file it does know would be read, not round-tripped, and
silently dropped on the next export. And it keeps the diff of the page HTML apart from the diff of the component tree,
which is what this module is for.

The payload carries:

| Key | Contents |
| --- | --- |
| `is_liveview_enabled` | whether the entity renders through the Liveview Editor |
| `is_tailwindcss_jit_enabled` | whether per-entity Tailwind CSS is compiled for it |
| `draft_content` | the component tree as last saved |
| `published_content` | the component tree the storefront renders |
| `tailwindcss` | a list of `{theme, edition, css}` rows |
| `references` | the cross-entity identifiers the content depends on |

`references` is diagnostic only. Nothing reads it back on import: the importer re-derives the references from the
content it actually imported and checks those, so a hand edited file cannot lie about its own dependencies.

### Which Tailwind CSS travels

Hyva CMS writes several `*_tailwindcss` tables. Only the ones a storefront request reads are exported.

| Table | Travels | Why |
| --- | --- | --- |
| `hyva_commerce_cms_page_tailwindcss` | yes | read on the storefront at `magento-cms/Plugin/Model/Page.php:176` |
| `hyva_commerce_cms_block_tailwindcss` | yes | read at `magento-cms/Plugin/Model/Block.php:168`, for the block itself and for any block a page embeds |
| `hyva_commerce_cms_menu_tailwindcss` | not yet | needed as soon as a page embeds a menu, read at `menu-builder/src/Block/Widget/Menu.php:99`. See the scope section below |
| `hyva_cms_snippet_tailwindcss` | no | no storefront reader, it feeds the editor preview iframe |
| `hyva_cms_template_tailwindcss` | no | preview iframe, plus one config driven attribute fallback that page content cannot reach |
| `hyva_commerce_product_attribute_tailwindcss` | no | belongs to an attribute content export |
| `hyva_commerce_category_attribute_tailwindcss` | no | same |

Every `(theme, edition)` row travels, not only the one for the theme the entity's stores happen to use. The compiler
writes a row per Hyva capable theme regardless of store assignment: the fixture page is on All Store Views and still
carries rows for both `frontend/Cenveo/cms` and `frontend/Cenveo/marketplace`.

### The target theme has to match

Stored CSS is a delta against that theme's compiled `styles.css`, not a standalone stylesheet. It is only correct
while the target theme build matches the source. That makes the flag safe for promoting one codebase between
environments, which is what it is for, and unsafe for moving content between unrelated projects. The importer warns
about it on every run.

### Reference warnings

A component that names something the target install does not have renders as a silent blank.
`liveview-editor/Block/Element.php` `renderComponentNotFound()` returns an empty string outside the editor preview,
with no exception and nothing in the log. An `is_active = 0` instance component fails in exactly the same way.

The importer checks `cms_block`, `menu` and `instance_component` references, warns once per miss, and prints a run
total at the end so a warning cannot scroll past unnoticed in a long import. It never fails the import. A partial
import a human can fix beats an all or nothing one.

### Two things to watch out for

**`cms:dump:data -r` without `--hyva-cms` deletes your `.hyva.json` files.** `-r` clears the whole sync directory, and
a dump without the flag never writes them back. Without `-r` they survive byte identical. The database is untouched
either way. Anyone who dumps without the flag after having dumped with it loses the sidecars from the working tree.

**An unrestricted `--hyva-cms` import overwrites live Hyva CMS content for every entity that has a sidecar.** Restrict
it with `-i` unless you genuinely mean to promote everything:

```
php bin/magento cms:import:data --type=all -i rw-ie-fixture-page,rw-ie-fixture-block --hyva-cms
```

That applies two sidecars. This applies every one in the sync directory:

```
php bin/magento cms:import:data --type=all --importAll --hyva-cms
```

### Deleting Hyva CMS content

The Hyva CSS tables carry a foreign key to `cms_page` and `cms_block`, not to the Hyva content rows. Deleting a Hyva
content row therefore does not cascade its CSS. The two have to be handled explicitly.

### What `--hyva-cms` deliberately leaves out

These were considered and left out on purpose. They are not oversights.

**Menus.** `hyva_commerce_cms_menu` is a separate content root with no `cms_page` anchor, and it ships in a different
composer package. Its CSS has to travel as soon as a page embeds a `hyva_menu_widget`. The collector already records
the reference and the importer already warns on it, so the gap is visible rather than silent.

**Templates and snippets.** Both are copy on insert.
`liveview-editor/Magewire/Traits/Component.php:657` `pasteComponents()` calls `regenerateUids()` and splices the
subtree straight into the content. Loading a template and pasting from the clipboard are the same code path, and
nothing records where the components came from. So a page never references a template or a snippet at render time,
and the page's own CSS row already covers their components, because the compiler is fed the whole component tree.
Their CSS tables feed the editor preview only.

**Instance components.** A genuine live reference, and worth a later phase. Their classes compile into the host
page's CSS row, so an imported page keeps its styling even when the definition is missing: it renders blank exactly
where the component was. The same coupling cuts the other way, editing a definition leaves every host page's CSS
stale until that page is saved again.

**Product and category attribute content.** Separate content roots. Their `store_content` is keyed by store id inside
the JSON rather than by a store table, so they need their own export shape.

**Version history.** `hyva_commerce_cms_page_version_history` and its block counterpart hold per-environment editing
history, not content. Carrying them would overwrite the target's own history.

**A Transfer Center handler.** `ContentTypeHandlerInterface` is `@api` and genuinely open, and
`Hyva\MenuBuilder\Model\ImportExport\MenuHandler` proves a third-party package can register one. But the Transfer
Center has no server-side ZIP and no CLI: assembly and extraction happen in the browser with a bundled `fflate.js`,
and the server side is a handful of adminhtml AJAX endpoints behind an admin session. A handler therefore buys a CLI
tool nothing. Worth adding later if these types should also appear in the admin UI.
