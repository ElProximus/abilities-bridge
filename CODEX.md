# CODEX.md - Codex Notes

## Project

Abilities Bridge is a WordPress plugin that connects AI clients to WordPress
Abilities through an admin chat interface and MCP endpoints. It includes OAuth,
permission controls, activity logging, memory storage, model/provider settings,
and integrations with provider plugins.

## Orientation

- Start with `README.md` for the current product overview, installation notes,
  security model, and testing commands.
- Main plugin file: `abilities-bridge.php`.
- Core classes live under `includes/`.
- Admin UI code lives under `admin/`.
- WordPress.org release packaging may involve separate SVN/build artifacts;
  keep source repo changes distinct from release output.

## Workflow Notes

- Commit and push only when explicitly asked.
- Prefer WordPress-native APIs, sanitization, escaping, nonces, and capability
  checks.
- Be careful around OAuth, tokens, permissions, memory storage, and any tool that
  can execute actions on a WordPress site.
- When pushing to the WordPress.org repo, update the banner image if it contains
  the version number. The visible banner version must match the plugin release
  version.
- Check the working tree before editing; as of this note there may be unrelated
  untracked local files such as `Formulae` and `Searching`.

## Testing

- If dependencies are installed, run PHPUnit with `vendor/bin/phpunit`.
- For admin or MCP changes, also verify the relevant WordPress admin screens or
  MCP flows manually when possible.

## Persistent Notes

- This file is a living memory aid for Codex sessions.
- Update it when we learn durable project facts, setup details, or recurring
  gotchas.
- Keep it concise; detailed implementation notes belong near the code or in
  focused docs.

## Release Hold (2026-07-04)

- DO NOT push to GitHub and DO NOT touch the WordPress.org SVN until Joe says
  he is ready for an update. Current work is being rolled into several local
  commits for the v1.3.3 release; commit locally only.
