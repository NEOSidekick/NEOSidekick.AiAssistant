# [NEOSidekick](https://neosidekick.com/) - Your Personal Writing Assistant for Neos

Create content drafts faster, brainstorm new ideas, and turn thoughts into brilliant text. 
Based on the latest findings in artificial intelligence.

## Installation

`NEOSidekick.AiAssistant` is available via Packagist. Add `"neosidekick/ai-assistant" : "^2.5"` to the require section of the composer.json or run:

```bash
composer require neosidekick/ai-assistant
```

We use semantic versioning, so every breaking change will increase the major version number.

## Configuration

### API Key

You can use the free version, or [get more features with a license](https://www.neosidekick.com/en/pricing).
To configure your license key, add the following to your Settings.yaml:

```yaml
NEOSidekick:
  AiAssistant:
    apikey: 'your-api-key-here'
```

### Content Language

If you're using content dimensions in your Neos setup, we will retrieve the content language 
from the currently active content dimension. However, if you are not using this feature of Neos, 
you need to define the default content language in the configuration, like this:

```yaml
NEOSidekick:
  AiAssistant:
    defaultLanguage: 'en'
```

English (`en`) is configured out of the box. Supported languages are:

* English `en`
* English (US) `en_US`
* English (Australia) `en_AU`
* English (UK) `en_UK`
* French `fr`
* French (Belgium) `fr_BE`
* French (Switzerland) `fr_CH`
* French (France) `fr_FR`
* French (Canada) `fr_CA`
* German `de`
* German (Austria) `de_AT`
* German (Germany) `de_DE`
* German (Switzerland) `de_CH`
* Italian `it`
* Italian (Italy) `it_IT`
* Italian (Switzerland) `it_CH`
* Spanish `es`
* Spanish (Spain) `es_ES`
* Spanish (Mexico) `es_MX`
* Spanish (Argentina) `es_AR`

### Permissions

By default, every editor can use the assistant.
However, if you want to restrict the access to certain roles,
you can copy this configuration into your site package.
It will give you an additional role `AiAssistantEditor`.

```yaml
roles:
  'Neos.Neos:AbstractEditor':
    privileges:
      - privilegeTarget: NEOSidekick.AiAssistant:CanUse
        permission: ABSTAIN

  'NEOSidekick.AiAssistant:AiAssistantEditor':
    description: Grants access to the NEOSidekick AiAssistant sidebar
    privileges:
      - privilegeTarget: NEOSidekick.AiAssistant:CanUse
        permission: GRANT

  'Neos.Neos:Administrator':
    privileges:
      - privilegeTarget: NEOSidekick.AiAssistant:CanUse
        permission: GRANT
```

Of course, you can also define the privilege for any
other role that you are using for example `Neos.Neos:Administrator`.

### Signing key of this installation

This installation identifies itself to NEOSidekick with an RSA key pair. It lives in one
row of the table `neosidekick_aiassistant_domain_model_agentsigningkeyrecord`, created
by `./flow doctrine:migrate`, shared by every application node and unaffected by
deployments. The key pair is generated and registered automatically the first time an
editor authorizes the assistant, so there is normally nothing to do. **A database dump
contains the private key**: treat dumps as secret and regenerate the key after handing
one out.

**Regenerate key** (Neos backend → *NEOSidekick* module → *Configuration*; administrators
only, privilege target `NEOSidekick.AiAssistant:ManageSigningKey`) replaces the key as
soon as NEOSidekick confirms the new one. The previous key stops being accepted, while
editors and connected tools keep working because open sessions renew themselves. Use it
after a possible key exposure, after handing out a dump, or after restoring a database
backup that predates an earlier regeneration.

| The panel reports… | What happened | What to do |
|---|---|---|
| Regeneration incomplete | NEOSidekick did not confirm the new key; the current key stays in use | Press **Regenerate key** again. The same pending key is re-sent, no third key is created. |
| `chain_domain_mismatch`, with a registered domain that differs from this site's | This is a copy of another installation's database (a staging copy of production, for example) | Tick **Enrol this installation as a new one, with its own key**, then **Regenerate key**. The copy gets its own identity; the original keeps working; only tools connected to the copy must be reconnected. |
| `chain_domain_mismatch`, and this *same* installation moved to another domain (including `www` vs. bare host) | NEOSidekick still knows the installation under the old domain | Press **Re-register under the new domain**. Identity and connected tools are kept. **Never on a copy**: it revokes the original's key, which is what the confirmation dialog warns about. |
| Key pair unusable | The stored halves do not belong together, or one is not a readable PEM (hand edit, partial restore) | Restore the signing-key row from a backup to keep this installation's identity, or tick the re-enrolment checkbox and regenerate to start as a new installation (every connected tool must be reconnected). A regeneration never re-enrols on its own. |

After a row delete or a restore that predates the current key, editors whose session is
bound to the old key see the chat frame retry until an administrator regenerates the key
or someone authorizes the assistant once.

On the shell: `./flow agentkey:show` prints the key and its registration state,
`./flow agentkey:push` re-sends it, `./flow agentkey:generate --force` regenerates
(`--relabel` is the "Re-register under the new domain" equivalent, same warning). The
shell-only fallback for re-enrolment is `DELETE FROM
neosidekick_aiassistant_domain_model_agentsigningkeyrecord;` followed by
`./flow agentkey:generate`.

### Reverse proxy / headless setups (Zebra, Next.js)

If you run Neos headless behind a frontend proxy — for example a
[Zebra](https://github.com/networkteam/zebra) Next.js frontend that renders the site
and proxies backend requests to Neos — the proxy **must forward the `/neosidekick/`
URL prefix** to the Neos backend. All of this package's routes live under that prefix:

- `/neosidekick/api/*` — the internal Agent API consumed by the NEOSidekick platform
- `/neosidekick/agent/*` — the agent authorization flow (chat sidebar consent)
- `/neosidekick/aiassistant/*` — the backend UI service

If the proxy only forwards `/neos/` (and assets), every `/neosidekick/*` request is
served by the frontend instead of Neos and returns the frontend's own 404, so the
Agent API and the chat authorization silently break.

With Next.js this means adding the prefix to your `middleware.ts` matcher, next to the
existing `/neos/` entry, for example:

```ts
export const config = {
    matcher: [
        '/neos/:path*',
        '/neosidekick/:path*', // forward the AiAssistant routes to Neos
        // …your other proxied paths (assets, etc.)
    ],
};
```

**Public host / trusted proxies.** The plugin reports the site's public domain to the
NEOSidekick platform so the authorization popup and platform callbacks target the
correct URL. It first uses the matching Neos `Domain` record for the request; if none
matches it falls back to the active request's base URI. For that fallback to yield the
public host (and not the internal upstream host the proxy connects to), configure
Flow's [trusted proxies](https://flowframework.readthedocs.io/en/stable/TheDefinitiveGuide/PartV/Http.html#trusted-proxies)
so the forwarded `Host`/`Proto` headers are honoured, **or** add a Neos `Domain`
record for each public host you serve the backend on.

### Page-specific AI briefings

By default, we add the mixin `NEOSidekick.AiAssistant:Mixin.AiPageBriefing` to the Neos.Neos:Document NodeType to allow editors to fine-tune the NEOSidekick AI Assistant behavior. 
Advanced users can also build their own based on the [NEOSidekick YAML API](https://neosidekick.com/en/product/features/build-your-own-ai#page-specific-briefings).

![AiPageBriefing.png](docs%2FAiPageBriefing.png)

### ImageAltTextEditor and ImageTitleEditor

With a few simple YAML configurations, you can configure NodeType properties for image alternative text and image titles. In addition to AI generation, it also offers the option to configure classic fallbacks:
- If no text is set, use the title or description of the asset
- If that is not set, use the file name

This allows you to easily implement the same behavior in the editor and frontend. And my personal favorite: every time the image changes or is set, it automatically generates new text.

![Image-AltText-Editor.gif](docs%2FImage-AltText-Editor.gif)

[Read the docs](https://neosidekick.com/en/developer-guide/image-description-generator)

### Image Description Generator

With this tool, you can create image descriptions for the media browser and save them in the title or description field of the media asses. These help you better search for images in the media browser
and can be used as fallback alternative text for an image. They are optimized as image alternative texts for SEO and accessibility.

[Read the tutorial](https://neosidekick.com/en/product/features/image-description-generator) on how create dozens of image descriptions in no time, and use them as image fallback alternative texts.

![Alternate-Image-Text-Generator.png](docs%2FAlternate-Image-Text-Generator.png)

### SEO Title and Meta Description Generator

We have designed a two-step process to help you create great SEO titles and meta descriptions, that are both search engine friendly and engaging for your readers.
We designed a special backend module just to ease the to make it fast and efficient.

In the first step, we look at the pages and suggest a likely focus keyword for this page.

![Focus-Keyword-Generator.gif](docs%2FFocus-Keyword-Generator.gif)

Next we create SEO titles and meta description optimized for the given focus keyword.

![SEO-Title-and-Meta-Description-Generator.gif](docs%2FSEO-Title-and-Meta-Description-Generator.gif)

### SEO Image Alternative Text Generator

Images boost user engagement, but search engines can't interpret visuals—they rely on alt text. 

The [Image Description Generator](#image-description-generator) helps you create effective descriptions for your media. This improves the searchability of extensive media libraries and by serving as a [fallback alternative text for images](https://neosidekick.com/en/product/features/image-description-generator) it makes your site more accessible. To craft SEO-optimized alt texts, it's crucial to understand the page content and context. The same image can need different descriptions depending on how it's used. Since Neos NodeTypes do not automatically link image attributes to alt text properties, you must [configure these details for NEOSidekick in YAML](https://neosidekick.com/produkt/features/property-text-generieren#alt-tags). After this setup, you can generate SEO-friendly alt texts directly in the Neos content editor. Here, you can also systematically apply these alt texts on bulk.

NEOSidekick can identify the most relevant pages for you and provide suggestions for each image title and alternative text of these pages.

![SEO-Image-Alternative-Text-Generator.gif](docs%2FSEO-Image-Alternative-Text-Generator.gif)


# License

You can use it for free with our [free and paid plans](https://neosidekick.com/preise). You are not allowed to modify, reuse or resell this code. For additional feature wishes, write us an email to [support@neosidekick.com](mailto:support@neosidekick.com).
