# AI-Canvas

Give an AI agent a controlled sandbox to vibe-code landing pages on a block-theme site.

Each canvas is a normal WordPress page (or post) whose body is a trio of files the plugin owns — `index.html`, `style.css`, `script.js` — stored under `wp-content/uploads/ai-canvas/{post_id}/`. On the front end the page renders on one of two templates: **theme** (theme header template part → your files → theme footer template part) or **blank** (your files alone — the canvas supplies its own header and footer). An external agent (Claude Code, Claude Desktop, any MCP client) writes those files, and only those files, through an MCP endpoint the plugin exposes — plus upload/search access to the Media Library.

No blocks, no editor round-trips. The files are the canonical source; writes are live immediately, and every write retains the file's previous version so the last change can be rolled back with a single call.

## ⚠️ Read this first

**AI-Canvas deliberately trusts AI output.** Whatever the agent writes into `index.html` / `script.js` is served to visitors unsanitized and runs same-origin — it can read the DOM, make credentialed REST requests, and act with the session of any logged-in viewer. This is cross-site scripting by design.

**Use it only on development sites or sites where you would happily hand the agent an admin account.** Do not run it on a site with real users or real customer data.

## The companion Claude Code plugin

This WordPress plugin is the capability surface: the MCP endpoint, the file jail, the rendering. What the agent *should do* with those capabilities lives in the companion **[ai-canvas Claude Code plugin](https://github.com/a8cteam51/claude-code-plugins/tree/trunk/plugins/ai-canvas)**, and you want both halves:

- Its **setup skill** walks a non-technical site owner through the entire connection — plugin installs, a dedicated Editor user, the Application Password — one wp-admin step at a time, then automatically verifies the site (endpoint, auth-header passthrough, capabilities including `unfiltered_html`) and registers the MCP server in Claude Code.
- Its **vibe skill** is the guardrail set this plugin can't enforce from the server side: CSS scoping so canvas styles never bleed into the theme's header/footer, performance rules (right-sized image variants, explicit dimensions, fold-aware lazy-loading, IntersectionObserver instead of layout-reading scroll handlers), read-before-write and rollback discipline, verification of the live page in a real browser via Claude in Chrome, and plain-language reporting for non-technical users.

Without the skills, any MCP client can still connect and write files — but a generic agent tends to produce exactly the pages you don't want: theme-bleeding selectors, full-size hero images, scroll-handler jank, and no verification beyond "the write returned 200".

```bash
/plugin marketplace add a8cteam51/claude-code-plugins
/plugin install ai-canvas@a8cteam51-claude-code-plugins
```

## Requirements

- WordPress **6.9+** (the Abilities API ships in core; the plugin deactivates its MCP surface without it)
- A **block theme** (uses `register_block_template()` and the theme's `header`/`footer` template parts)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) **≥ 0.6.1** (0.6.0 has a fatal packaging bug; older versions break Claude Code tool listing). Without it, abilities still register but there is no MCP endpoint.

## Setup

**Recommended:** install the [companion Claude Code plugin](#the-companion-claude-code-plugin) and say `set up AI-Canvas on https://your-site.tld` — the setup skill runs the whole flow below interactively, including the verification checks.

**Manual:**

1. Activate `mcp-adapter` and `ai-canvas`.
2. Create an Application Password for a user who can publish pages and upload files (an Editor — see [capabilities](#mcp-tools)):
   ```bash
   wp user application-password create <user> "ai-canvas" --porcelain
   ```
3. Connect Claude Code directly over HTTP:
   ```bash
   claude mcp add ai-canvas https://your-site.tld/wp-json/ai-canvas/mcp \
     -s user -t http -H "Authorization: Basic $(echo -n 'user:app-password' | base64)"
   ```
   Application Passwords require HTTPS (or `WP_ENVIRONMENT_TYPE=local`, e.g. WordPress Studio). If your host strips the `Authorization` header, or you need OAuth-style auth, use the official proxy instead: [`@automattic/mcp-wordpress-remote`](https://github.com/Automattic/mcp-wordpress-remote).
4. Tell the agent to go wild:
   > Create a canvas called "Spring Launch" and build me a landing page for…

## MCP tools

| Tool | Does | Requires |
|---|---|---|
| `create-canvas` | Create a published page/post, scaffold its file set, assign the `theme` (default) or `blank` template | `edit_others_posts` + publish capability for the post type + `unfiltered_html` |
| `list-canvases` | Canvases the caller can edit, with IDs, URLs, file mtimes | `edit_others_posts`, filtered per-post by `edit_post` |
| `read-file` | Read one of `html` \| `css` \| `js` for a canvas | `edit_others_posts` + `edit_post` on the target |
| `write-file` | Overwrite one file (2 MB cap); the outgoing contents become the file's retained previous version. `html` writes carrying inline JavaScript are rejected | `edit_others_posts` + `edit_post` on the target + `unfiltered_html` |
| `rollback-file` | Swap a file with its retained previous version — one slot per file, so calling it again undoes the rollback | `edit_others_posts` + `edit_post` on the target + `unfiltered_html` |
| `upload-media` | Sideload a file into the Media Library from a URL or base64 (site upload limit applies); returns image dimensions and the generated smaller sizes | `edit_others_posts` + `upload_files` |
| `list-media` | Search the Media Library; results include URLs, image dimensions, and generated sizes | `edit_others_posts` + `upload_files` |

Every tool requires `edit_others_posts` — the capability that separates an Editor from an Author across core roles — and the MCP endpoint enforces it too, so **Subscribers, Contributors and Authors cannot reach the server at all**, not even to list tool names. That bar deliberately sits above `upload_files`, so the Media Library tools are never exposed to an Author-level credential.

Writing canvas content is additionally writing unsanitized same-origin HTML, so it demands the capability WordPress already reserves for exactly that: `unfiltered_html`. In practice that means **Editor or Administrator on a single site, super admins only on multisite**, and no one when `DISALLOW_UNFILTERED_HTML` is defined. Because `edit_others_posts` survives on multisite where `unfiltered_html` narrows to super admins, Editors there keep the read-only tools while writes correctly do not.

The tool contract has no path parameters at all — files are addressed by post ID plus a fixed enum, so the agent cannot write anywhere else on the filesystem. Permanently deleting a canvas post removes its file set (retained previous versions included); uninstalling the plugin removes all of them.

Note that the Application Password itself is **not** scoped to these tools. WordPress cannot scope one: the credential you create during setup authenticates the whole REST API, so anything holding it is an Editor on that site, not a caller limited to seven tools. Treat it accordingly.

### One script per page

`index.html` may not carry inline JavaScript. `write-file` rejects an `html` write containing a `<script>` tag, an inline event-handler attribute (`onclick`, `onerror`, and similar), or a `javascript:` URL, and tells the agent to put the behaviour in `script.js` — which is already enqueued on every canvas page.

This is a rejection gate, not a sanitizer: nothing is silently rewritten, and it does not make canvas output safe (`script.js` is still arbitrary same-origin JavaScript). What it buys is that all of a page's behaviour lives in one reviewable file. The patterns are deliberately broad, so markup that merely *looks* like inline JS — an `on…=` sequence inside an attribute value, say — is rejected too; entity-escaped samples such as `&lt;script&gt;` pass untouched.

### Write log

Every write and rollback appends an entry to the `_ai_canvas_write_log` post meta — timestamp, user ID, action, file, byte count, and a SHA-256 of the contents — capped at the 50 most recent, and bumps `post_modified` so the change surfaces in admin listings and in any activity log watching `save_post`. The log records metadata and hashes, not content, so it is an audit trail rather than a second undo. `ai_canvas_after_write` receives the entry as its second argument.

### Rollback semantics

- Every `write-file` whose contents differ from the current file retains the outgoing version as that file's **single previous version** (`.{filename}.prev` alongside the live file). An identical write doesn't consume the slot.
- `rollback-file` **swaps** current and previous, so it is its own undo: roll back to inspect, roll back again to return. The swap replaces the live file via `rename()`, so visitors never see a missing asset mid-swap.
- One slot per file means undo reaches back exactly one write per file; deeper history is deliberately out of scope (see [Not in v1](#not-in-v1-on-purpose)).
- A file that has never been overwritten has no previous version; `rollback-file` returns a clear error rather than guessing.
- Rollback purges the same page caches a write does and fires the same `ai_canvas_after_write` action.

### Media dimensions

`upload-media` and `list-media` return each image's pixel dimensions plus every generated intermediate size (name, URL, width, height). That exists so agents can reference a right-sized variant with explicit `width`/`height` attributes instead of dropping a full-size original into a 600px column — the single most common way AI-built pages balloon. The [vibe skill](#the-companion-claude-code-plugin) instructs agents to use it that way.

## How rendering works

- The plugin registers two block templates: "AI Canvas" (`ai-canvas//canvas`) — the theme's header template part, an internal dynamic block that echoes `index.html`, the footer template part — and "AI Canvas (Blank)" (`ai-canvas//canvas-blank`), which is just the canvas block, so the canvas controls the whole page (`wp_head`/`wp_footer` still fire, so the CSS/JS enqueues work). Both show up in the editor's Template panel like any other template.
- In wp-admin, canvas pages don't present a blank editor: a pinned notice plus a locked "AI-controlled canvas" placeholder card explain that the content lives in the file set and is edited through the connected agent.
- `style.css` and `script.js` are enqueued on canvas pages with `filemtime()` cache-busting, so an agent's write (or rollback) is visible on the next reload.
- `index.html` is read server-side and never web-served; only the CSS/JS need to be publicly readable from `uploads/`.
- After each write or rollback the plugin bumps the page's Batcache version key and purges Pressable's edge cache when present, and fires `ai_canvas_after_write` for anything host-specific.

## Not in v1 (on purpose)

Output sanitization (beyond the inline-JS rejection rule above), draft/preview, restorable write history beyond the single per-file rollback slot, classic themes, an embedded chat UI, settings screens.

## Changelog

### Unreleased

- Every ability, and the MCP endpoint itself, now requires `edit_others_posts`. Authors and Contributors can no longer reach the server at all; this closes Author-level access to `upload-media` and `list-media`.
- `write-file` rejects inline JavaScript in `html` — `<script>` tags, event-handler attributes, and `javascript:` URLs. Page behaviour belongs in `script.js`.
- Writes and rollbacks are now recorded: a capped `_ai_canvas_write_log` post meta entry (user, action, file, bytes, SHA-256) plus a `post_modified` bump. `ai_canvas_after_write` now receives that entry as a second argument.
- `list-media` `limit` is clamped to 1–100 in both the schema and the query; a negative value previously validated and returned every attachment on the site.

### 0.2.0

- `rollback-file` tool: every write retains one previous version per file; rollback swaps it live and is its own undo. Cleanup on post deletion and uninstall covers the retained versions.
- `upload-media` / `list-media` now return image pixel dimensions and all generated intermediate sizes, so agents can reference right-sized variants.

### 0.1.0

- Initial release: canvas templates, file jail, MCP endpoint, Media Library tools, cache purging, editor lockout card.
