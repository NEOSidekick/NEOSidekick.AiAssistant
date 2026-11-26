import {describe, expect, it} from 'vitest';
import {translateContentDimensionLabels} from './contentDimensions';

describe('translateContentDimensionLabels', () => {
    const translations: Record<string, string> = {
        'Vendor.Site:Main:dimension.language': 'Sprachen',
        'Vendor.Site:Main:language.de': 'Deutsch',
    };
    const translate = (label: string): string => translations[label] ?? label;

    it('translates dimension and preset labels and keeps the rest of the configuration', () => {
        const contentDimensions = {
            language: {
                label: 'Vendor.Site:Main:dimension.language',
                defaultPreset: 'de',
                presets: {
                    de: {label: 'Vendor.Site:Main:language.de', values: ['de'], uriSegment: 'de'},
                    en: {label: 'English', values: ['en'], options: {translationStrategy: 'sync'}},
                    removed: null,
                },
            },
        };

        expect(translateContentDimensionLabels(contentDimensions, translate)).toEqual({
            language: {
                label: 'Sprachen',
                defaultPreset: 'de',
                presets: {
                    de: {label: 'Deutsch', values: ['de'], uriSegment: 'de'},
                    en: {label: 'English', values: ['en'], options: {translationStrategy: 'sync'}},
                    removed: null,
                },
            },
        });
    });

    it('keeps a label the registry cannot handle', () => {
        const failingTranslate = (): string => {
            throw new Error('Malformed translation id');
        };

        expect(translateContentDimensionLabels({language: {label: 'Sprache: alle', presets: {}}}, failingTranslate))
            .toEqual({language: {label: 'Sprache: alle', presets: {}}});
    });

    it('reports no configuration for installations without content dimensions', () => {
        expect(translateContentDimensionLabels(undefined, translate)).toBeNull();
        expect(translateContentDimensionLabels([], translate)).toBeNull();
    });
});
