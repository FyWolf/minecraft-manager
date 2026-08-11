# Minecraft Manager

Worlds, configuration, server versions and mod/modpack installs for Minecraft servers on
Pelican Panel — with every feature gated by the server's egg.

A Vanilla server gets worlds, configuration and version switching, and no mod browser at
all. A Paper server additionally gets a **plugin** browser that installs into `plugins/`.
A Fabric or Forge server gets a **mod** browser and modpack installs, pointed at `mods/`.
An egg nobody has mapped shows nothing rather than a broken page.

**Author:** FyWolf · **Requires:** Pelican Panel · Filament v4 · PHP 8.2+

## Status

Feature-complete and **not yet tested against a live panel.** Every page is written, every
import resolves against a real panel's autoloader, and the `.properties` parser has 41
passing round-trip tests — but nothing here has been exercised against a running Wings
daemon. Treat the first install as a shakedown, on a server you do not mind breaking.

| Feature | State |
|---|---|
| Per-egg capability profiles, admin UI, egg auto-detection | built |
| Worlds — list, archive, restore, switch active, delete/reset | built |
| Configuration — typed `server.properties` editor, EULA | built |
| Mod / plugin browser (Modrinth), with dependency resolution | built |
| Mod / plugin browser (CurseForge) | built |
| Version switching (jar swap + reinstall) | built |
| Modpack installs (queued, with progress) | built |

## Developing

Two checks, both runnable without a panel install (the second needs one):

```
php tests/PropertiesFileTest.php                  # 41 round-trip assertions
php tests/verify-imports.php   /path/to/panel     # every `use` resolves
php tests/verify-overrides.php /path/to/panel     # no narrowed inherited methods
```

**Run all three before installing a build on a panel.** Each catches a failure mode that
`php -l` cannot see, and both of the last two catch faults that are invisible until a
panel boots:

- A **mistyped namespace** fails silently. `PluginService` catches the exception, flips the
  plugin to Errored and moves on, so the symptom is a plugin that simply does not appear.
- **Narrowing an inherited method's visibility** — `protected function getFormActions()`
  over a trait's `public` one — is a fatal at *class-load* time. In a panel that is boot,
  so it does not break one page: it takes the entire panel down, and the error page itself
  fails to render (`No hint path defined for [filament]`) because Filament never got far
  enough to register its view namespace. `verify-overrides.php` deliberately does not
  autoload plugin classes, since doing so would hit the very fatal it reports.

## Releasing

Automatic. Merging to `main` cuts a release — there is nothing to run by hand and the
version is never edited manually.

The bump level comes from the commit messages since the last tag:

| Commit prefix | Bump |
|---|---|
| `feat!:` or a `BREAKING CHANGE` body | major |
| `feat:` | minor |
| anything else (`fix:`, `chore:`, `docs:`, …) | patch |

The workflow then writes the new version into `plugin.json` and `updater.json`, commits it
as `github-actions[bot]` with `[skip ci]`, tags `v<version>`, builds `minecraft-manager.zip`
and publishes the release. Tests run first and block the release if they fail.

Both URLs — `plugin.json.update_url` and `updater.json.download_url` — are rewritten from
the repository context on every release rather than trusted from the file, so they stay
correct whichever organisation this lives under and cannot silently point a customer's
panel at another repository's releases after a rename or fork.

To force a specific version (the first release, or a correction), run the workflow
manually from the Actions tab and give it an explicit version.

Note the loop that isn't: the bot's own bump commit pushes to `main`, but pushes made with
`GITHUB_TOKEN` do not trigger workflows, and the `[skip ci]` marker guards the case where
that token is ever swapped for a PAT.

---

## Why the gating is a database table

The obvious approach — check the egg's `features` array for `mods` or `plugins` — does not
work on a stock panel. The panel only registers five feature ids (`eula`, `java_version`,
`gsl_token`, `pid_limit`, `steam_disk_space`), so an egg never carries `mods` unless an
administrator hand-edits it. Tag matching alone is not enough either: tags are free-form,
`neoforge` contains `forge` as a substring, and Purpur eggs carry the `paper` tag.

So availability comes from a **capability profile** mapped to an egg, resolved in this
order:

1. **Explicit** — a profile an administrator mapped to this egg. Always wins.
2. **Inherited** — the profile mapped to the egg's parent (`config_from`), which covers a
   customised copy of a stock egg.
3. **Detected** — guessed from the egg's tags, features and startup variables. Never
   written to the database, so it cannot fight an administrator's choice later.
4. **Nothing** — no pages, no navigation entries, no errors.

## Installation

1. Install the plugin from the panel (Admin → Plugins) or drop this folder into
   `plugins/minecraft-manager` and run the installer.
2. Migrations create `mc_capability_profiles`, `egg_mc_capability_profile` and
   `mc_pack_installs`, and the seeder maps the stock Minecraft eggs automatically.
3. **After importing new eggs**, run `php artisan minecraft-manager:sync-profiles` — eggs
   are imported from the `pelican-eggs` organisation *after* the panel is set up, so eggs
   added later are not mapped by the install-time seeder.
4. Review the mapping at **Admin → Minecraft Profiles**. The "Unmapped eggs" tab lists
   Minecraft eggs with no profile and offers a one-click mapping with a detected default.

### Optional: CurseForge

Modrinth works with no configuration. CurseForge requires an API key, which you request at
[console.curseforge.com](https://console.curseforge.com).

Paste it into **Admin → Plugins → Minecraft Manager → Settings** and press **Test key**.
Until a key is present, no CurseForge UI appears anywhere — nothing is broken, there is
simply one provider instead of two.

Note that some CurseForge authors opt out of third-party distribution. Those files have no
download URL through the API, and no client can install them automatically. The plugin
detects this *before* a modpack install starts, tells you how many files are affected, and
links you to download them by hand.

### Optional but recommended: a modpack queue

A large modpack install occupies a queue worker for several minutes. On the default queue
that blocks every other panel job — backups, webhooks, SFTP revocation. Set a dedicated
queue name in the plugin settings and run a worker for it:

```
php artisan queue:work --queue=mcm-packs
```

Schedule the stale-install reaper too, so a worker restarted mid-install cannot leave a
server permanently locked out of further installs:

```
php artisan minecraft-manager:prune-installs
```

## Permissions

Every action is gated on the subuser permissions a player already understands — no new
permission types are introduced.

| To do this | A subuser needs |
|---|---|
| See worlds, browse mods | `file.read` |
| Install a mod, plugin or modpack | `file.create` |
| Edit configuration | `file.read-content` + `file.update` |
| Archive or restore a world | `file.archive` |
| Delete or reset a world | `file.delete` |
| Change the server version (jar) | `file.update` + `startup.update` |
| Change the server version (reinstall) | `startup.update` + `settings.reinstall` |

Operations that Minecraft cannot survive having done underneath it — switching the active
world, restoring a world, swapping the server jar, installing a modpack — **require the
server to be stopped**. This is not a courtesy check: Minecraft rewrites `server.properties`
on shutdown and would silently discard an active-world change, and overwriting region
files under a running server corrupts them.

## Uninstalling

Uninstalling a plugin rolls back its migrations, so **the egg-to-profile mapping is
dropped**. Export it first from Admin → Minecraft Profiles if you intend to reinstall.

Worlds, configuration files and installed mods live on the game server and are untouched.

## Relationship to `minecraft-modrinth`

Both can be installed at once — the page slugs do not collide — but you will get two
"Mods" entries in the sidebar. This plugin does not disable the other one; that is a
decision for an administrator, not for a plugin to make on their behalf.

## License

GPL-3.0. See `LICENSE`.
