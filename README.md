# CMS Import/Export Tool
A tool to manage CMS content (both blocks &amp; pages) being imported/exported between environments using the repository. This tool comes handy for build and maintenance projects.

## Installation
Using composer:
```
composer2 require rocketweb/module-cms-import-export
```

Once the tool is installed, we have two workflows, depending on what we are trying to do.

> Ideas for using this:
> - allowing FED team to create a CMS block/page in admin but then modify the HTML content using proper IDEs that allow auto-complete & code-styling
> - allowing simpler deployments since there is no manual copy/paste of CMS data needed
> - allowing the client to modify staging content and having it ready for deployment
> - having an easy way to sync up production env to staging/dev/local by exporting on production and importing on 
  staging/dev/local

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
```

This command works by using files in `var/sync_cms_data/cms/` path. As you can see from the options, we need to define:
- type - which can be CMS block, CMS page or both - **required**
- identifier - either a CMS block or CMS page identifier - **optional**

With the combination of these two, we can **import**:
- all CMS content (using --type=all)
- all CMS pages (using --type=page)
- all CMS blocks (using --type=block)
- specific CMS page or pages (using --type=page --identifier=about-us.html,homepage-new)
- specific CMS block or blocks (using --type=block --identifier=who-are-we,homepage-carousel)

Once you execute the command, the content will be created/updated in Magento Admin. 
By executing `php bin/magento cache:flush` you should be able to see the updated CMS content on frontend also!
