# AI-Canvas

Give an AI agent a controlled sandbox to vibe-code landing pages on a block-theme site.

Each canvas is a normal WordPress page (or post) whose body is a trio of files the plugin owns — `index.html`, `style.css`, `script.js` — stored under `wp-content/uploads/ai-canvas/{post_id}/`. On the front end the page renders as: **theme header template part → your files → theme footer template part**. An external agent (Claude Code, Claude Desktop, any MCP client) writes those files, and only those files, through an MCP endpoint the plugin exposes — plus upload/search access to the Media Library.

No blocks, no editor round-trips. The files are the canonical source; writes are live immediately.

## ⚠️ Read this first

**AI-Canvas deliberately trusts AI output.** Whatever the agent writes into `index.html` / `script.js` is served to visitors unsanitized and runs same-origin — it can read the DOM, make credentialed REST requests, and act with the session of any logged-in viewer. This is cross-site scripting by design.

**Use it only on development sites or sites where you would happily hand the agent an admin account.** Do not run it on a site with real users or real customer data.

## Requirements

- WordPress **6.9+** (the Abilities API ships in core; the plugin deactivates its MCP surface without it)
- A **block theme** (uses `register_block_template()` and the theme's `header`/`footer` template parts)
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) **≥ 0.6.1** (0.6.0 has a fatal packaging bug; older versions break Claude Code tool listing). Without it, abilities still register but there is no MCP endpoint.

## Setup

1. Activate `mcp-adapter` and `ai-canvas`.
2. Create an Application Password for a user who can publish pages and upload files:
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
| `create-canvas` | Create a published page/post, scaffold its file set, assign the Canvas template | publish capability for the post type |
| `list-canvases` | All canvases with IDs, URLs, file mtimes | `edit_pages` |
| `read-file` / `write-file` | Read/overwrite one of `html` \| `css` \| `js` for a canvas (2 MB cap) | `edit_post` on the target |
| `upload-media` | Sideload a file into the Media Library from a URL or base64 | `upload_files` |
| `list-media` | Search the Media Library, get URLs to reference | `upload_files` |

The tool contract has no path parameters at all — files are addressed by post ID plus a fixed enum, so the agent cannot write anywhere else on the filesystem.

## How rendering works

- The plugin registers an "AI Canvas" block template (`ai-canvas//canvas`): the theme's header template part, an internal dynamic block that echoes `index.html`, the footer template part. It shows up in the editor's Template panel like any other template.
- In wp-admin, canvas pages don't present a blank editor: a pinned notice plus a locked "AI-controlled canvas" placeholder card explain that the content lives in the file set and is edited through the connected agent.
- `style.css` and `script.js` are enqueued on canvas pages with `filemtime()` cache-busting.
- `index.html` is read server-side and never web-served; only the CSS/JS need to be publicly readable from `uploads/`.
- After each write the plugin bumps the page's Batcache version key and purges Pressable's edge cache when present, and fires `ai_canvas_after_write` for anything host-specific.

## Not in v1 (on purpose)

Output sanitization, draft/preview, write history or rollback, classic themes, an embedded chat UI, settings screens.
