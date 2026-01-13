# Changelog

This file documents notable changes in this community-maintained fork of the Grav Comments plugin.

The upstream plugin (Comments v1.2.8) has been deprecated. This fork focuses on improving **a posteriori** comment management in the Grav Admin while preserving frontend behavior and the existing YAML storage format.

---

## [1.2.8-dvst.1] - 2026-01-13

### Added
- Admin: non-destructive comment deletion using a Trash workflow.
  - Comments are moved to `user/data/comments-trash/<lang>/<route>.yaml`.
  - Restore from Trash is supported (idempotent; no duplication).
- Admin: Trash tab in the Comments Admin interface.
- Admin: pagination ("Load more") for both Active comments and Trash.
- Admin: reliable counters (shown vs total) for large comment sets.
- Admin: unified permission model using a single permission `admin.comments`.
- Admin: localized messages for key actions (fr / en / es, where available).

### Changed
- Admin: internal computed comment identifier (CID) remains in server logic
  but is no longer displayed in the Admin UI.

### Security
- Admin: strict nonce verification for destructive actions.
- Admin: controlled relative paths (`relPath`) to prevent path traversal.
- Admin: hardened AJAX pagination with authorization checks and JSON error handling.

---

### Notes
- No changes were made to public frontend rendering.
- No changes were made to the existing YAML comment data format.
- This release is intended for manual installation (community fork, not distributed via GPM).

# v1.2.8
## 09/10/2020

1. [](#improved)
    * Improved some translations
1. [](#bugfix)
    * Fix for PHP 7.4 [#81](https://github.com/getgrav/grav-plugin-comments/issues/81)
    * Fix for allowing path in form submission [#86](https://github.com/getgrav/grav-plugin-comments/issues/86) [#80](https://github.com/getgrav/grav-plugin-comments/issues/80)
    
# v1.2.7
## 05/12/2017

1. [](#improved)
    * Added Japanese translation
    * Move captcha over email [#45](https://github.com/getgrav/grav-plugin-comments/issues/45)
1. [](#bugfix)
    * Fix comment form processing
    * Fix issue with scope for autofilled values

# v1.2.6
## 01/09/2017

1. [](#improved)
    * Use existing `Utils::startsWith()` method
1. [](#bugfix)
    * Fix [#41](https://github.com/getgrav/grav-plugin-comments/issues/41) using Comments in a Gantry-powered theme did not escape the comment form token correctly

# v1.2.5
## 09/16/2016

1. [](#bugfix)
    * Fix [#37](https://github.com/getgrav/grav-plugin-comments/issues/37) showing comments older than one week in the "latest comments" view

# v1.2.4
## 09/15/2016

1. [](#bugfix)
    * Fix missing Twig template error if route is excluded but twig is loaded

# v1.2.3
## 09/15/2016

1. [](#improved)
    * Added Croatian translation
1. [](#bugfix)
    * Fix [#35](https://github.com/getgrav/grav-plugin-comments/issues/35) Allow comments to work fine on Form 2.0 too

# v1.2.2
## 08/12/2016

1. [](#improved)
    * Added Romanian translation
1. [](#bugfix)
    * Fix issue in storing comments cache when cache is enabled [#33](https://github.com/getgrav/grav-plugin-comments/issues/33)

# v1.2.1
## 07/19/2016

1. [](#bugfix)
    * Check if Login plugin is installed before checking for user object [#28](https://github.com/getgrav/grav-plugin-comments/issues/28)

# v1.2.0
## 07/14/2016

1. [](#improved)
    * Prevent a missing template problem on ignored routes
    * Allow to translate the comments form
    * Added spanish and brazilian portuguese translations
    * Enhanced german, russian and french translations
    * Added cache for comments
    * Handle logged in users by not requiring username/email
    * Reset the comments form after a comment is submitted

# v1.1.4
## 02/05/2016

1. [](#improved)
    * Added german and polish
    * Avoid listening on onTwigTemplatePaths if not enabled

# v1.1.3
## 01/06/2016

1. [](#improved)
    * Disable captcha by default, added instructions on how to enable it
1. [](#bugfix)
    * Increase priority for onPageInitialized in the comments plugin over the form plugin one to prevent an issue when saving comments

# v1.1.2
## 12/11/2015

1. [](#improved)
    Fix double escaping comments text and author

# v1.1.1
## 12/11/2015

1. [](#improved)
    * Drop the autofocus on the comment form
1. [](#bugfix)
    * Fix double encoding (#12)

# v1.1.0
## 11/24/2015

1. [](#new)
    * Added french (@codebee-fr) and russian (@joomline) languages
    * Takes advantage of the new nonce support provided by the Form plugin
1. [](#improved)
    * Use date instead of gmdate to respect the server local time (thanks @bovisp)
    * Now works with multilang (thanks @bovisp)


# v1.0.2
## 11/13/2015

1. [](#improved)
    * Use nonce
1. [](#improved)
    * Changed form action to work with multilang

# v1.0.1
## 11/11/2015

1. [](#improved)
    * Use onAdminMenu instead of the deprecated onAdminTemplateNavPluginHook
1. [](#bugfix)
    * Fix error when user/data/comments does not exist


# v1.0.0
## 10/21/2015

1. [](#new)
    * Initial Release
