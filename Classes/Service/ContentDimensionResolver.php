<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\EelHelper\TranslationHelper;
use NEOSidekick\AiAssistant\Exception\InvalidContentDimensionsException;

/**
 * Turns the content dimensions an API caller asks for into the dimensions a content context is
 * created with, and is the one place that decides which dimensions the agent API accepts.
 *
 * Neos 8 does not check context dimensions against the configuration. An unknown value or a
 * combination the constraints forbid is written as node data no editor can ever open, and a
 * shortened fallback chain hides content that shines through. So every endpoint of the agent API
 * resolves its dimensions here first: each named dimension must be a configured preset and is
 * replaced by that preset's full fallback chain, every dimension that is not named gets its
 * default preset, and anything else is an error for the caller. Nothing is silently replaced.
 *
 * @Flow\Scope("singleton")
 */
class ContentDimensionResolver
{
    private const SYNC_TRANSLATION_STRATEGY = 'sync';
    private const MAX_PRESETS_IN_MESSAGE = 30;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @param mixed $requested The caller's dimensions, e.g. `{"language": ["en_UK"]}`; null and `[]` name none
     * @param string $fieldName How the error messages name the argument
     * @return array<string, array<int, string>> The fallback chain of every configured dimension
     * @throws InvalidContentDimensionsException
     */
    public function resolve($requested, string $fieldName = 'dimensions'): array
    {
        $configuration = $this->getConfiguration();
        $requestedValues = $this->parseRequested($requested, $fieldName);

        $resolved = [];
        $presetKeys = [];
        foreach ($requestedValues as $dimensionName => $values) {
            if (!isset($configuration[$dimensionName])) {
                throw new InvalidContentDimensionsException($configuration === []
                    ? sprintf('This site has no content dimensions, but "%s" names "%s". Omit "%s".', $fieldName, $dimensionName, $fieldName)
                    : sprintf(
                        '"%s" is not a content dimension of this site. Configured dimensions: %s.',
                        $dimensionName,
                        implode(', ', array_keys($configuration))
                    ), 1764230401);
            }

            $presetKey = $this->findPresetKey($configuration[$dimensionName]['presets'], $values);
            if ($presetKey === null) {
                throw new InvalidContentDimensionsException(sprintf(
                    '%s is not a configured preset of the "%s" dimension. Use the values of one of: %s.',
                    json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $dimensionName,
                    $this->describePresets($configuration[$dimensionName]['presets'])
                ), 1764230402);
            }

            $presetKeys[$dimensionName] = $presetKey;
            $resolved[$dimensionName] = $configuration[$dimensionName]['presets'][$presetKey]['values'];
        }

        $defaultedDimensionNames = [];
        $dimensions = [];
        foreach ($configuration as $dimensionName => $dimension) {
            if (!isset($resolved[$dimensionName])) {
                $defaultedDimensionNames[] = $dimensionName;
                $presetKeys[$dimensionName] = $dimension['defaultPreset'];
                $resolved[$dimensionName] = $dimension['presets'][$dimension['defaultPreset']]['values'];
            }
            // configuration order, whatever order the caller used
            $dimensions[$dimensionName] = $resolved[$dimensionName];
        }

        // Only a combination the caller chose is checked. Naming no dimension is the site's own
        // default, which is not for this API to refuse.
        if ($requestedValues !== []) {
            $this->assertCombinationIsAllowed($presetKeys, $defaultedDimensionNames);
        }

        return $dimensions;
    }

    /**
     * Why nothing may be written into these dimensions, or null when writing is fine.
     *
     * Sitegeist.LostInTranslation re-translates a sync preset from the default preset each time a
     * node is published there, which overwrites the node in the sync language: a change written
     * there is lost. Reading stays allowed.
     *
     * @param array<string, array<int, string>> $dimensions Dimensions as returned by resolve()
     */
    public function findSyncPresetWriteRefusal(array $dimensions): ?string
    {
        foreach ($this->getConfiguration() as $dimensionName => $dimension) {
            $presetKey = $this->findPresetKey($dimension['presets'], $dimensions[$dimensionName] ?? []);
            if ($presetKey === null || !$this->isSyncPreset($dimension, $presetKey)) {
                continue;
            }

            $defaultPresetKey = $dimension['defaultPreset'];

            return sprintf(
                'Not applied: %1$s "%2$s" is a sync preset. It is re-translated automatically from %1$s "%3$s" each time a page '
                . 'is published there, so a change made in "%2$s" would be overwritten. Make the change in %1$s "%3$s" instead '
                . '(dimensions %4$s) and say that "%2$s" follows automatically once that page is published.',
                $dimensionName,
                $presetKey,
                $defaultPresetKey,
                json_encode(
                    [$dimensionName => $dimension['presets'][$defaultPresetKey]['values']],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        }

        return null;
    }

    /**
     * The content dimensions of this installation as an API caller needs them to choose one:
     * every preset with its label, its fallback chain, whether it is kept in sync and what it
     * cannot be combined with. Empty when the site has no content dimensions.
     *
     * @return array<string, array{label: string, defaultPreset: string, presets: array<string, array<string, mixed>>}>
     */
    public function describeConfiguration(): array
    {
        $description = [];
        foreach ($this->getConfiguration() as $dimensionName => $dimension) {
            $presets = [];
            foreach ($dimension['presets'] as $presetKey => $preset) {
                $presets[$presetKey] = [
                    'label' => $this->translateLabel($preset['label'], (string)$presetKey),
                    'values' => $preset['values'],
                    'sync' => $this->isSyncPreset($dimension, (string)$presetKey),
                ];
                if ($preset['constraints'] !== []) {
                    $presets[$presetKey]['constraints'] = $preset['constraints'];
                }
            }

            $description[$dimensionName] = [
                'label' => $this->translateLabel($dimension['label'], $dimensionName),
                'defaultPreset' => $dimension['defaultPreset'],
                'presets' => $presets,
            ];
        }

        return $description;
    }

    /**
     * The configured dimensions, reduced to presets that have values. A preset a site unset in
     * its settings (`preset: ~`) stays in the configuration as null and is left out here.
     *
     * @return array<string, array{label: mixed, defaultPreset: string, presets: array<string, array{label: mixed, values: array<int, string>, constraints: array<string, array<string, bool>>, translationStrategy: mixed}>}>
     */
    private function getConfiguration(): array
    {
        $configuration = [];
        foreach ($this->contentDimensionPresetSource->getAllPresets() as $dimensionName => $dimension) {
            $presets = [];
            foreach (is_array($dimension['presets'] ?? null) ? $dimension['presets'] : [] as $presetKey => $preset) {
                $values = is_array($preset) && is_array($preset['values'] ?? null) ? array_values($preset['values']) : [];
                if ($values === []) {
                    continue;
                }
                $presets[(string)$presetKey] = [
                    'label' => $preset['label'] ?? null,
                    'values' => array_map('strval', $values),
                    'constraints' => $this->parseConstraints($preset['constraints'] ?? null),
                    'translationStrategy' => $preset['options']['translationStrategy'] ?? null,
                ];
            }

            $defaultPresetKey = (string)($dimension['defaultPreset'] ?? '');
            if (!isset($presets[$defaultPresetKey])) {
                continue;
            }

            $configuration[(string)$dimensionName] = [
                'label' => $dimension['label'] ?? null,
                'defaultPreset' => $defaultPresetKey,
                'presets' => $presets,
            ];
        }

        return $configuration;
    }

    /**
     * @param mixed $requested
     * @return array<string, array<int, string>>
     * @throws InvalidContentDimensionsException
     */
    private function parseRequested($requested, string $fieldName): array
    {
        if ($requested === null || $requested === []) {
            return [];
        }
        if (!is_array($requested) || array_is_list($requested)) {
            throw new InvalidContentDimensionsException(
                sprintf('"%s" must be an object such as {"language": ["en"]}.', $fieldName),
                1764230403
            );
        }

        $requestedValues = [];
        foreach ($requested as $dimensionName => $values) {
            if ($values === []) {
                // named without a value: treated like a dimension that is not named
                continue;
            }
            if (!is_array($values) || !array_is_list($values)) {
                throw new InvalidContentDimensionsException(sprintf(
                    'In "%s", "%s" must be a list with the values of a preset, e.g. {"%s": ["en"]}.',
                    $fieldName,
                    $dimensionName,
                    $dimensionName
                ), 1764230404);
            }
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') {
                    throw new InvalidContentDimensionsException(sprintf(
                        'In "%s", the values of "%s" must be non-empty strings, e.g. {"%s": ["en"]}.',
                        $fieldName,
                        $dimensionName,
                        $dimensionName
                    ), 1764230405);
                }
            }

            $requestedValues[(string)$dimensionName] = array_map('trim', $values);
        }

        return $requestedValues;
    }

    /**
     * The preset a list of values refers to: the preset with exactly these values, else the preset
     * that starts with the first value, else a preset key given as the only value. The values are
     * tried before the key because callers send what tool results show them, which are values,
     * and a key may equal the first value of another preset.
     *
     * @param array<string, array{values: array<int, string>}> $presets
     * @param array<int, string> $values
     */
    private function findPresetKey(array $presets, array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        foreach ($presets as $presetKey => $preset) {
            if ($preset['values'] === $values) {
                return (string)$presetKey;
            }
        }
        foreach ($presets as $presetKey => $preset) {
            if ($preset['values'][0] === $values[0]) {
                return (string)$presetKey;
            }
        }

        return count($values) === 1 && isset($presets[$values[0]]) ? $values[0] : null;
    }

    /**
     * @param array<string, string> $presetKeys The preset of every configured dimension
     * @param array<int, string> $defaultedDimensionNames Dimensions the caller did not name
     * @throws InvalidContentDimensionsException
     */
    private function assertCombinationIsAllowed(array $presetKeys, array $defaultedDimensionNames): void
    {
        if ($this->contentDimensionPresetSource->isPresetCombinationAllowedByConstraints($presetKeys)) {
            return;
        }

        // Neos only answers for the whole combination; checking the pairs names the one to change.
        $dimensionNames = array_keys($presetKeys);
        foreach ($dimensionNames as $index => $dimensionName) {
            foreach (array_slice($dimensionNames, $index + 1) as $otherDimensionName) {
                $pair = [
                    $dimensionName => $presetKeys[$dimensionName],
                    $otherDimensionName => $presetKeys[$otherDimensionName],
                ];
                if ($this->contentDimensionPresetSource->isPresetCombinationAllowedByConstraints($pair)) {
                    continue;
                }

                $defaulted = array_values(array_intersect([$dimensionName, $otherDimensionName], $defaultedDimensionNames));

                throw new InvalidContentDimensionsException(sprintf(
                    'This site does not allow %s "%s" together with %s "%s".%s Choose an allowed combination.',
                    $dimensionName,
                    $presetKeys[$dimensionName],
                    $otherDimensionName,
                    $presetKeys[$otherDimensionName],
                    $defaulted === [] ? '' : sprintf(
                        ' "%s" was not named, so its default preset was used.',
                        implode('" and "', $defaulted)
                    )
                ), 1764230406);
            }
        }

        throw new InvalidContentDimensionsException(
            'This site does not allow this combination of content dimensions. Choose an allowed combination.',
            1764230407
        );
    }

    /**
     * The default preset is the source of the translation and never a target, whatever its own
     * strategy says.
     *
     * @param array{defaultPreset: string, presets: array<string, array{translationStrategy: mixed}>} $dimension
     */
    private function isSyncPreset(array $dimension, string $presetKey): bool
    {
        return $presetKey !== $dimension['defaultPreset']
            && ($dimension['presets'][$presetKey]['translationStrategy'] ?? null) === self::SYNC_TRANSLATION_STRATEGY;
    }

    /**
     * @param mixed $constraints
     * @return array<string, array<string, bool>> `{<otherDimension>: {<presetKey | "*">: allowed}}`
     */
    private function parseConstraints($constraints): array
    {
        $parsedConstraints = [];
        foreach (is_array($constraints) ? $constraints : [] as $dimensionName => $rules) {
            $parsedRules = [];
            foreach (is_array($rules) ? $rules : [] as $presetKey => $isAllowed) {
                if (is_bool($isAllowed)) {
                    $parsedRules[(string)$presetKey] = $isAllowed;
                }
            }
            if ($parsedRules !== []) {
                $parsedConstraints[(string)$dimensionName] = $parsedRules;
            }
        }

        return $parsedConstraints;
    }

    /**
     * @param array<string, array{label: mixed, values: array<int, string>}> $presets
     */
    private function describePresets(array $presets): string
    {
        $descriptions = [];
        foreach (array_slice($presets, 0, self::MAX_PRESETS_IN_MESSAGE, true) as $presetKey => $preset) {
            $descriptions[] = sprintf(
                '%s ("%s")',
                json_encode($preset['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->translateLabel($preset['label'], (string)$presetKey)
            );
        }
        if (count($presets) > self::MAX_PRESETS_IN_MESSAGE) {
            $descriptions[] = sprintf('%d more', count($presets) - self::MAX_PRESETS_IN_MESSAGE);
        }

        return implode(', ', $descriptions);
    }

    /**
     * Labels may be XLIFF ids (`Vendor.Site:Main:language`); a caller should get the label the
     * editor sees in the dimension switcher.
     *
     * @param mixed $label
     */
    private function translateLabel($label, string $fallback): string
    {
        if (!is_string($label) || trim($label) === '') {
            return $fallback;
        }
        if (preg_match('/^[\w.]+:[\w.\/]+:.+$/', $label) !== 1) {
            return $label;
        }

        try {
            $translatedLabel = (new TranslationHelper())->translate($label);
        } catch (\Throwable $exception) {
            return $label;
        }

        return is_string($translatedLabel) && $translatedLabel !== '' ? $translatedLabel : $label;
    }
}
