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

You can use the free version, or [get more features with a license](https://www.neosidekick.com/en/pricing).
To configure your license key, add the following to your Settings.yaml:

```yaml
NEOSidekick:
  AiAssistant:
    apikey: 'your-api-key-here'
```

# Permissions

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
