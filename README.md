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

To have access to Sidekick, you need to define your api key in the configuration:

```yaml
Neos:
  Neos:
    Ui:
      frontendConfiguration:
        NEOSidekick:
          AiAssistant:
            apikey: 'your-api-key-here'
```
