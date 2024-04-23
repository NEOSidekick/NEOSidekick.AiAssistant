/*
 * This file is copied from Neos.Neos.Ui
 */
const getTranslationAddress = function (id, packageKey, sourceName) {
    if (id && id.indexOf(':') !== -1) {
        return id.split(':');
    }

    return [packageKey, sourceName, id];
};

/**
 * This code is taken from the Ember version with minor adjustments. Possibly refactor it later
 * as its style is not superb.
 */
const substitutePlaceholders = function (textWithPlaceholders, parameters) {
    const result = [];
    let startOfPlaceholder;
    let offset = 0;
    while ((startOfPlaceholder = textWithPlaceholders.indexOf('{', offset)) !== -1) {
        const endOfPlaceholder = textWithPlaceholders.indexOf('}', offset);
        const startOfNextPlaceholder = textWithPlaceholders.indexOf('{', startOfPlaceholder + 1);

        if (endOfPlaceholder === -1 || (startOfPlaceholder + 1) >= endOfPlaceholder || (startOfNextPlaceholder !== -1 && startOfNextPlaceholder < endOfPlaceholder)) {
            break;
        }

        const contentBetweenBrackets = textWithPlaceholders.substr(startOfPlaceholder + 1, endOfPlaceholder - startOfPlaceholder - 1);
        const placeholderElements = contentBetweenBrackets.replace(' ', '').split(',');

        const valueIndex = placeholderElements[0];
        const value = parameters[valueIndex];
        if (typeof value === 'undefined') {
            break;
        }

        let formattedPlaceholder;
        if (typeof placeholderElements[1] === 'undefined') {
            // No formatter defined, just string-cast the value
            formattedPlaceholder = parameters[valueIndex];
        } else {
            break;
        }

        result.push(textWithPlaceholders.substr(offset, startOfPlaceholder - offset));
        result.push(formattedPlaceholder);

        offset = endOfPlaceholder + 1;
    }

    result.push(textWithPlaceholders.substr(offset));

    return result.join('');
};

const getPluralForm = (translation, quantity = 0) => {
    const translationHasPlurals = translation instanceof Object;

    // no defined quantity or less than one returns singular
    if (translationHasPlurals && (!quantity || quantity <= 1)) {
        return translation[0];
    }

    if (translationHasPlurals && quantity > 1) {
        return translation[1] ? translation[1] : translation[0];
    }

    return translation;
};

export default class TranslationService {
    private static instance: TranslationService|null = null;
    private translations: object;

    public static getInstance(): TranslationService
    {
        if (!TranslationService.instance) {
            TranslationService.instance = new TranslationService()
        }
        return TranslationService.instance
    }

    public configure = (translations: object): void => {
        this.translations = translations
    }

    // eslint-disable-next-line max-params
    public translate(idOrig, fallbackOrig, params = {}, packageKeyOrig = 'Neos.Neos', sourceNameOrig = 'Main', quantity = 0) {
        const fallback = fallbackOrig || idOrig;
        const [packageKey, sourceName, id] = getTranslationAddress(idOrig, packageKeyOrig, sourceNameOrig);
        let translation = [packageKey, sourceName, id]
            // Replace all dots with underscores
            .map(s => s ? s.replace(/\./g, '_') : '')
            // Traverse through translations and find us a fitting one
            .reduce((prev, cur) => (prev ? prev[cur] || '' : ''), this.translations);

        translation = getPluralForm(translation, quantity);
        if (translation && translation.length) {
            if (Object.keys(params).length) {
                return substitutePlaceholders(translation, params);
            }
            return translation;
        }

        return fallback;
    }
}
