type Translate = (label: string) => string;

/**
 * The content dimension configuration as the Neos UI holds it (`cr.contentDimensions.byName`),
 * with dimension and preset labels translated: they may be XLIFF ids, and the assistant should
 * get the labels the editor sees in the dimension switcher.
 */
export function translateContentDimensionLabels(contentDimensions: unknown, translate: Translate): unknown {
    if (!isRecord(contentDimensions)) {
        return null;
    }

    const translateLabel = (configuration: Record<string, unknown>): Record<string, unknown> => {
        if (typeof configuration.label !== 'string') {
            return configuration;
        }
        try {
            return {...configuration, label: translate(configuration.label)};
        } catch {
            return configuration;
        }
    };

    return mapRecords(contentDimensions, dimension => {
        const translatedDimension = translateLabel(dimension);
        return isRecord(dimension.presets)
            ? {...translatedDimension, presets: mapRecords(dimension.presets, translateLabel)}
            : translatedDimension;
    });
}

function mapRecords(records: Record<string, unknown>, map: (record: Record<string, unknown>) => unknown): Record<string, unknown> {
    return Object.fromEntries(Object.entries(records).map(([key, value]) => [key, isRecord(value) ? map(value) : value]));
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
