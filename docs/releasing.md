# Releasing to WordPress.org

The directory serves the plugin from Subversion, not from this repository.
`https://plugins.svn.wordpress.org/citecue-ai-auto-fix` has three folders and
they mean different things:

| Folder | Contents | Installed on a site? |
|---|---|---|
| `trunk/` | the current source | no — the directory installs from a tag |
| `tags/<version>/` | one frozen copy per release | yes, the one named by `Stable tag` |
| `assets/` | banner, icon, screenshots | no — directory page art only |

`Stable tag:` in `readme.txt` is what decides which tag is served. Trunk is
not it; a release that updates trunk and forgets the tag serves the old
version, and one that tags without moving `Stable tag` serves nothing new.

## Before you start

`bin/build-plugin-zip.sh <ref>` refuses to build when the three version
strings disagree (`citecue.php` header, `CITECUE_VERSION`, `readme.txt`
`Stable tag`), so run it first — it is the cheapest check that a release is
coherent, and it prints exactly what will ship.

## Publishing a version

```bash
# 1. A working copy. Empty folders, so this is fast.
svn checkout https://plugins.svn.wordpress.org/citecue-ai-auto-fix /tmp/citecue-svn

# 2. Trunk, from the tag being released — never from the working tree.
#    `git archive` honours .gitattributes export-ignore, so tests, CI config
#    and Composer files cannot be swept in by accident.
rm -rf /tmp/citecue-svn/trunk/*
git archive --format=tar v1.2.0 | tar -x -C /tmp/citecue-svn/trunk

# 3. Directory page art. Only when it has changed.
cp .wordpress-org/* /tmp/citecue-svn/assets/

# 4. Stage, then freeze a tag from trunk.
cd /tmp/citecue-svn
svn add --force trunk assets
svn copy trunk tags/1.2.0

# 5. One commit for both. Prompts for the wordpress.org password.
svn commit -m "Release 1.2.0" --username citecue
```

Commit trunk and the tag together. A trunk-only commit points `Stable tag` at
a tag that does not exist yet, and the directory serves an error to everyone
updating in the window between the two.

`svn` is not installed on macOS by default: `brew install subversion`.

## Regenerating the directory art

`assets-src/build-assets.sh` rebuilds `.wordpress-org/` from
`assets-src/banner.html` and `.wordpress-org/icon.svg`. It needs headless
Chrome and the brand fonts from the citecue2 site repository — see the
script's header for the two overrides.

Both folders are `export-ignore`d, and `bin/build-plugin-zip.sh` fails if
either ever appears inside the archive: they belong beside the plugin in SVN,
never inside it.
