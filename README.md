# [NEOSidekick](https://neosidekick.com/)

## Revolutionize how you write copy

AI is the game changer in content marketing. 
Use the innovative writing assistant now, directly in your Neos CMS!

## Installation

`NEOSidekick.AiAssistant` is available via packagist. `"neosidekick/ai-assistant" : "~1.0"` to the require section of the composer.json or run:

```bash
composer require neosidekick/ai-assistant
```

We use semantic-versioning so every breaking change will increase the major-version number.

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

### Page-specific AI briefings

You can add the mixin `NEOSidekick.AiAssistant:Mixin.AiPageBriefing` to any Document NodeType to allow editors to fine-tine the NEOSidekick AI Assistant behavior, 
or you can build your own based on the [NEOSidekick YAML API](https://neosidekick.com/en/product/features/build-your-own-ai#page-specific-briefings).

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
```

Of course, you can also define the privilege for any
other role that you are using, for example `Neos.Neos:Administrator`.
