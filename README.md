# [NEOSidekick](https://neosidekick.com/) - Your Personal Writing Assistant for Neos

Create content drafts faster, brainstorm new ideas, and turn thoughts into brilliant text. 
Based on the latest findings in artificial intelligence.

## Installation

`NEOSidekick.AiAssistant` is available via Packagist. Add the version matching your Neos version (see the compatibility table below) to the require section of the composer.json — e.g. `"neosidekick/ai-assistant": "^4.0"` for Neos 9 — or run:

```bash
composer require neosidekick/ai-assistant
```

We use semantic versioning, so every breaking change will increase the major version number.

### Neos compatibility

| Plugin version | Neos    |
|----------------|---------|
| 2.x            | 8.3     |
| 3.x            | 8.3/8.4 |
| 4.x            | 9.0/9.1 |

No configuration beyond this README is needed on Neos 9 — the plugin adapts to your
content dimensions and site configuration automatically. Two Neos-9-specific notes:

- **Content repository**: the plugin operates on a single content repository, `default`
  by default. If your document nodes live in a different one, set:

  ```yaml
  NEOSidekick:
    AiAssistant:
      contentRepositoryId: 'default'
  ```

- **Upgrading a Neos 8.3 site**: per-dimension-value options like Sitegeist.LostInTranslation's
  `translationStrategy` must move with your dimension configuration into the content
  repository registry, e.g.:

  ```yaml
  Neos:
    ContentRepositoryRegistry:
      contentRepositories:
        default:
          contentDimensions:
            language:
              values:
                en:
                  options:
                    translationStrategy: 'sync'
  ```

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
