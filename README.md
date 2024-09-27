# [NEOSidekick](https://neosidekick.com/) - Your Personal Writing Assistant for Neos

Create content drafts faster, brainstorm new ideas, and turn thoughts into brilliant text. 
Based on the latest findings in artificial intelligence.

## Installation

`NEOSidekick.AiAssistant` is available via Packagist. Add `"neosidekick/ai-assistant" : "^2.2"` to the require section of the composer.json or run:

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

### Page-specific AI briefings

By default, we add the mixin `NEOSidekick.AiAssistant:Mixin.AiPageBriefing` to the Neos.Neos:Document NodeType to allow editors to fine-tune the NEOSidekick AI Assistant behavior. 
Advanced users can also build their own based on the [NEOSidekick YAML API](https://neosidekick.com/en/product/features/build-your-own-ai#page-specific-briefings).

![AiPageBriefing.png](docs%2FAiPageBriefing.png)

### Image Description Generator

Image alternative texts are essential for SEO and accessibility. With good image descriptions, you can help Google and screen readers to understand your images.

[Read the tutorial](https://neosidekick.com/en/product/features/image-description-generator) on how create dozens of image descriptions in no time.

![Alternate-Image-Text-Generator.png](docs%2FAlternate-Image-Text-Generator.png)

### SEO Title and Meta Description Generator

We have designed a two-step process to help you create great SEO titles and meta descriptions, that are both search engine friendly and engaging for your readers.
We designed a special backend module just to ease the to make it fast and efficient.

In the first step, we look at the pages and suggest a likely focus keyword for this page.
![Focus-Keyword-Generator.gif](docs%2FFocus-Keyword-Generator.gif)

Next we create SEO titles and meta description optimized for the given focus keyword.
![SEO-Title-and-Meta-Description-Generator.gif](docs%2FSEO-Title-and-Meta-Description-Generator.gif)

# License

You can use it for free with our [free and paid plans](https://neosidekick.com/preise). You are not allowed to modify, reuse or resell this code. For additional feature wishes, write us an email to [support@neosidekick.com](mailto:support@neosidekick.com).
