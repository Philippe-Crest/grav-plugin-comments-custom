# Grav Comments Plugin (Community-Maintained Fork)

**Community-maintained fork notice**

This repository is an **unofficial fork** of the deprecated Grav **Comments** plugin, maintained independently.
This fork improves **a posteriori comment management in the Grav Admin** while preserving:
- frontend behavior,
- existing YAML storage format,
- compatibility with Comments v1.2.8 data layout.

---

## Project scope and intent

This repository is a **community-maintained fork** of the deprecated Grav Comments plugin.

I am not a professional developer, and this fork does not aim to take over
maintenance of the original plugin or to provide a full-featured replacement.

**Important (scope):** This is an **Admin-only** fork. It **does not change anything on the frontend** (templates, rendering, forms, routes behavior beyond what upstream already does). Installing this fork will **not** make comments appear on your pages if they were not already working. It only adds **post-moderation tools in the Grav Admin** (trash/restore, pagination, counters, permission).

The scope is intentionally limited:
- provide **minimal Admin-side improvements** for a posteriori comment management,
- help existing Grav sites address practical **a posteriori moderation** needs without changing frontend behavior,
- preserve the existing YAML data format and backward compatibility at the data level,
- avoid introducing new concepts, dependencies, or breaking changes.

This project exists to address a specific practical need and should be viewed
as a lightweight, best-effort community contribution.

## Installation (this fork)

The upstream command below:
```bash
bin/gpm install comments
```
installs the **deprecated upstream** plugin from the Grav package repository, not this fork.

To install this fork, use one of the following:
1. Download a release ZIP from this repository and extract it into `user/plugins/comments/` (replacing the existing plugin), or
2. Clone this repository into `user/plugins/comments/`:
```bash
git clone https://github.com/Philippe-Crest/grav-plugin-comments-custom.git user/plugins/comments
```

---

## Fork-specific additions (Admin only)
- Non-destructive deletion (Trash) and restore
- Trash tab + pagination ("Load more") for Active and Trash lists
- Single Admin permission: `admin.comments`
- Localized Admin messages (fr/en/es where available)

Explicitly out of scope: a priori moderation, comment editing, frontend changes, storage format changes.

---

# Grav Comments Plugin

The **Comments Plugin** for [Grav](http://github.com/getgrav/grav) adds the ability to add comments to pages, and moderate them.

**Fork note:** Upstream moderation features are not developed further here. This fork focuses on **a posteriori** comment management in the Grav Admin (Trash/Restore), without changing frontend rendering.

# Installation

The Comments plugin is easy to install with GPM.

```
$ bin/gpm install comments
```

Or clone from GitHub and put in the `user/plugins/comments` folder.

# Usage

Add `{% include 'partials/comments.html.twig' with {'page': page} %}` to the template file where you want to add comments.

For example, in Antimatter, in `templates/item.html.twig`:

```twig
{% embed 'partials/base.html.twig' %}

    {% block content %}
        {% if config.plugins.breadcrumbs.enabled %}
            {% include 'partials/breadcrumbs.html.twig' %}
        {% endif %}

        <div class="blog-content-item grid pure-g-r">
            <div id="item" class="block pure-u-2-3">
                {% include 'partials/blog_item.html.twig' with {'blog':page.parent, 'truncate':false} %}
            </div>
            <div id="sidebar" class="block size-1-3 pure-u-1-3">
                {% include 'partials/sidebar.html.twig' with {'blog':page.parent} %}
            </div>
        </div>

        {% include 'partials/comments.html.twig' with {'page': page} %}
    {% endblock %}

{% endembed %}
```

The comment form will appear on the blog post items matching the enabled routes.

To set the enabled routes, create a `user/config/plugins/comments.yaml` file, copy in it the contents of `user/plugins/comments/comments.yaml` and edit the `enable_on_routes` and `disable_on_routes` options according to your needs.

`enable_on_routes` controls **where comments are enabled** (upstream behavior). It does not change your theme/templates. If comments are not displayed, ensure your page template includes comments and that the upstream Comments plugin is correctly configured.

> Make sure you configured the "Email from" and "Email to" email addresses in the Email plugin with your email address!

# Enabling Recaptcha

The plugin comes with Recaptcha integration. To make it work, create a `user/config/plugins/comments.yaml` file, copy in it the contents of `user/plugins/comments/comments.yaml` and uncomment the captcha form field and the captcha validation process.
Make sure you add your own Recaptcha `site` and `secret` keys too.

# Where are the comments stored?

In the `user/data/comments` folder. They're organized by page route, so every page with a comment has a corresponding file. This enables a quick load of all the page comments.

# Visualize comments

When the plugin is installed and enabled, the `Comments` menu will appear in the Admin Plugin. From there you can see all the comments made in the last 7 days.

Further improvements to the comments visualization will be added in the next releases.

# Email notifications

The plugin interacts with the Email plugin to send emails upon receiving a comment. Configure the Email plugin correctly, setting its "Email from" and "Email to" email addresses.

# Things still missing

- Allow to delete comments from the Admin Plugin
- Ability to see all comments of a page in the Admin Plugin
- Ability to reply to a comment from the Admin Plugin
- Auto-fill the comment form when a user is logged in
