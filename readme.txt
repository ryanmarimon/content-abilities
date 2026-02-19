Prerequisites                                                                                                                                                                               
                                                            
  1. WordPress 6.9+ — The Abilities API shipped as a feature plugin for this version. Your site must be running 6.9 or later.                                                                 
  2. Abilities API plugin — Provides the wp_register_ability() framework that everything builds on. Install and activate it.                                                                  
  3. MCP Adapter plugin — Bridges the Abilities API to the Model Context Protocol so Claude (or any MCP client) can discover and call abilities over a standard transport. Install and
  activate it.
  4. Content Abilities plugin — THIS ONE. Copy the folder:
  wp-content/plugins/content-abilities/
  └── content-abilities.php    (single file, ~500 lines)
  4. Activate it. It auto-registers the content category and all 9 abilities on the existing hooks.

  Plugin dependency chain

  Abilities API (core framework)
      └── MCP Adapter (exposes abilities to MCP clients)
      └── Content Abilities (registers the 9 content management abilities)

  Content Abilities has a built-in guard — if wp_register_ability doesn't exist, it silently bails, so activation order doesn't matter as long as the Abilities API is active.

  What the Content Abilities plugin provides

  ┌────────────────────────────┬─────────────────────────────────────────────────────────────────────────┐
  │          Ability           │                              What it does                               │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/list-posts         │ Search/filter posts & pages with pagination                             │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/get-post           │ Full post details (title, content, status, categories, tags, permalink) │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/get-post-blocks    │ Parse post content into structured block JSON                           │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/list-categories    │ All categories                                                          │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/list-tags          │ All tags                                                                │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/create-post        │ Create post or page with block content                                  │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/update-post        │ Update any post/page fields                                             │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/delete-post        │ Trash or permanently delete                                             │
  ├────────────────────────────┼─────────────────────────────────────────────────────────────────────────┤
  │ content/update-post-blocks │ Block-level insert/remove/replace by index                              │
  └────────────────────────────┴─────────────────────────────────────────────────────────────────────────┘

  Connecting Claude Code or Other AI Agent

  Once all three plugins are active, configure Agent with the MCP server endpoint for your production site. The MCP Adapter plugin provides the transport — Claude Code discovers all available abilities automatically via mcp-adapter/discover-abilities.
