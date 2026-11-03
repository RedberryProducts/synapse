# Profile settings handoff

The README, Composer presentation fields, support and contribution guides, issue forms, and pull-request template are supplied by this PR. The changes below need an authorized repository or account owner after review and approval. They are not applied by merging the files.

## GitHub repository settings

The description, website, and topics were already populated when checked on 2 November 2026. Review the proposed values below instead of treating them as missing fields.

**About description**

```text
A development dashboard for Laravel AI agents. Chat with your agents, inspect tool calls, and test approval flows. Built and maintained by Redberry, an official Laravel Premier Partner.
```

**Website**

```text
https://redberry.international/open-source/synapse/
```

**Topics**

```text
laravel, laravel-ai, php, ai-agents, agent-development, developer-tools, debugging, dashboard, tool-calling, human-in-the-loop, redberry
```

Use this exact topic list from the agreed profile document.

**Social preview:** upload [synapse-social-1280x640.png](branding/synapse-social-1280x640.png) in the repository’s Social preview setting. It preserves the complete supplied artwork with black side margins. The depicted interface and metrics are illustrative.

**Repository tabs and sidebar:** an owner should decide whether Wiki, Projects, Discussions, Packages, and Deployments provide maintained destinations. Do not enable new channels solely for branding.

## Shared account branding

The prepared [Redberry avatar](branding/redberry-avatar-256.png) is available for the Packagist maintainer’s Gravatar or the GitHub organization, subject to the respective account owner’s approval. It affects other packages and repositories. Review the organization’s description, website, social links, and pinned repositories with its owner.

Composer’s `authors` entry is attribution. It does not change Packagist maintainers, account access, or Gravatar settings. Retain the package name, vendor, ownership, and MIT license.

## Policies and channels

GitHub private vulnerability reporting is enabled, verified on 3 November 2026. Security reports go through [Report a vulnerability](https://github.com/RedberryProducts/synapse/security/advisories/new), following [SECURITY.md](../SECURITY.md). No separate security email or response-time commitment is published.

Ordinary bugs and feature requests use GitHub Issues. No code of conduct, support email, chat, forum, wiki, IRC channel, or funding programme is added by this refresh. Leave corresponding fields absent unless the responsible Redberry owners confirm a maintained policy or destination.

The commercial AI-development link belongs in the README and support guidance. Funding fields and GitHub’s sponsor button require a real financial-support programme.

## Future release footer

Follow the [release checklist](RELEASE.md#5-changelog) for the maintained footer and instructions for future release notes.

## Review, merge, and verify

1. Review the technical copy and run `composer validate --strict` and `composer check` in a configured development environment. Follow the existing [release checklist](RELEASE.md) if preparing a release.
2. Merge only after the copy and technical review are complete. The README’s canonical upstream image URL will become available when its asset reaches `main`; the image itself is reviewable in this PR.
3. Apply the approved repository settings and social preview using an authorized account.
4. Check GitHub and Packagist rendering, images, badges, and section links. Packagist has one imported README, not a separate long description to edit by hand.
5. Verify Packagist automatic updates. An authorized maintainer should publish the next appropriate tag through the established release process, then verify its homepage, description, keywords, author role, and support links. Do not retag an existing version to change historical metadata.

The shared README’s tracking links use `utm_source=github`; Packagist imports the same links, so they cannot distinguish the two traffic origins reliably. Generated versions, archives, statistics, advisories, and dependency information remain platform-managed.
