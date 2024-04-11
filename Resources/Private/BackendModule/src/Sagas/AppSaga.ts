import BackendService from "../Service/BackendService";
import {put, select, takeEvery, takeLatest} from "redux-saga/effects";
import {
    addItem,
    getItem,
    getItems,
    getModuleConfiguration,
    hasUnpersistedItem,
    isAppStarted,
    resetItems,
    setErrorMessage,
    setItemGenerating,
    setItemPersisted,
    setItemPersisting,
    setLoading,
    setModuleConfiguration,
    setStarted,
    updateItemProperty
} from "../Store/AppSlice";
import {ExternalService} from "../Service/ExternalService";
import {PayloadAction} from "@reduxjs/toolkit";
import StateInterface from "../Store/StateInterface";
import TranslationService from "../Service/TranslationService";
import AiAssistantError from "../Service/AiAssistantError";
import {ModuleItem} from "../Model/ModuleItem";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";
import {ResultCollection} from "../Model/ResultCollection";
import {ModuleConfiguration} from "../Model/ModuleConfiguration";

function* startModuleSaga() {
    const isStarted = yield select(isAppStarted)

    if (isStarted) {
        return
    }

    yield fetchNewPage()
}

function* fetchNewPage() {
    yield put(setStarted(true))
    yield put(setLoading({loading: true}))
    const configuration = yield select(getModuleConfiguration)
    const backend: BackendService = BackendService.getInstance()
    try {
        const response: ResultCollection = yield backend.getItemsThatNeedProcessing(configuration)
        yield put(resetItems())
        yield put(setModuleConfiguration({ moduleConfiguration: { firstResult: response.nextFirstResult }}))
        for (let item of response.items) {
            yield put(addItem(item))
        }
        yield put(setLoading({loading: false}))
    } catch (e) {
        if (e instanceof AiAssistantError) {
            const translationService = TranslationService.getInstance()
            yield put(setErrorMessage(translationService.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage})))
        }
        yield put(setLoading({loading: false}))
    }
}

export function* watchStartModule() {
    yield takeLatest('app/startModule', startModuleSaga)
}

function* saveAllAndFetchNextSaga() {
    const items: StatefulModuleItem[] = yield select(getItems)
    const persistableItems: ModuleItem[] = Object.keys(items).map((key: string): ModuleItem => {
        const item: ModuleItem = items[key]
        return {
            identifier: item.identifier,
            // Asset
            filename: item.filename,
            thumbnailUri: item.thumbnailUri,
            fullsizeUri: item.fullsizeUri,
            propertyName: item.propertyName,
            propertyValue: item.propertyValue,
            // Focus Keyword
            pageTitle: item.pageTitle,
            publicUri: item.publicUri,
            nodeContextPath: item.nodeContextPath,
            focusKeyword: item.focusKeyword,
            language: item.language
        }
    }).filter(filterItemsFromBackend)

    if (persistableItems.length > 0) {
        for (let persistableItem of persistableItems) {
            yield put(setItemPersisting({ identifier: persistableItem.identifier, persisting: true }))
        }

        yield BackendService.getInstance().persistItems(persistableItems)
        yield fetchNewPage()
    }
}

function *filterItemsFromBackend(item: ModuleItem) {
    const scope = yield select(state => state.app.scope)
    switch (scope) {
        case 'altTextGeneratorModule': return item.propertyValue.length > 0
        case 'focusKeywordGeneratorModule': return item.focusKeyword.length > 0
    }
}

export function* watchSaveAllAndFetchNext() {
    yield takeLatest('app/saveAllAndFetchNext', saveAllAndFetchNextSaga)
}

function* generateItemSaga({ payload: item }: PayloadAction<StatefulModuleItem>) {
    yield put(setItemGenerating({ identifier: item.identifier, generating: true }))
    const language = yield getLanguageDependingOnScope(item)
    const externalService = ExternalService.getInstance()
    const translationService = TranslationService.getInstance()
    const itemGenerationConfig = yield getItemGenerationConfigDependingOnScope(item)

    yield* Object.keys(itemGenerationConfig).map(yield function* (propertyName: string) {
        const sidekickConfiguration = itemGenerationConfig[propertyName]
        try {
            const propertyValue = yield externalService.generate(sidekickConfiguration.module, language, sidekickConfiguration.userInput)
            console.log(propertyValue)
            yield put(updateItemProperty({ identifier: item.identifier, propertyName, propertyValue }))
        } catch (e) {
            if (e instanceof AiAssistantError) {
                yield put(setErrorMessage(translationService.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage})))
            }
            // todo catch other errors with a more generic message?
        }
    })
    yield put(setItemGenerating({ identifier: item.identifier, generating: false }))
}

function *autogenerateItemSaga(action: PayloadAction<StatefulModuleItem>) {
    const { payload: item } = action
    if (yield shouldGenerateAutomaticallyDependingOnScope(item)) {
        yield generateItemSaga(action)
    }
}

function *shouldGenerateAutomaticallyDependingOnScope(item: ModuleItem) {
    const scope = yield select(state => state.app.scope)
    const configuration: ModuleConfiguration = yield select(state => state.app.moduleConfiguration)
    switch (scope) {
        case 'altTextGeneratorModule': return true
        case 'focusKeywordGeneratorModule':
            return (
                (item.focusKeyword === '' && configuration.generateEmptyFocusKeywords) ||
                (item.focusKeyword !== '' && configuration.regenerateExistingFocusKeywords)
            )
    }
}

function *getLanguageDependingOnScope(item: ModuleItem) {
    const scope = yield select(state => state.app.scope)
    switch (scope) {
        case 'altTextGeneratorModule': return yield select((state: StateInterface) => state.app.moduleConfiguration.language)
        case 'focusKeywordGeneratorModule': return item.language
    }
}

function *getItemGenerationConfigDependingOnScope(item: ModuleItem) {
    const scope = yield select(state => state.app.scope)
    switch (scope) {
        case 'altTextGeneratorModule':
            let configuration = {}
            configuration[item.propertyName] = {
                'module': 'alt_tag_generator',
                'userInput': [
                    {
                        identifier: 'url',
                        value: [
                            item.fullsizeUri,
                            item.thumbnailUri
                        ]
                    }
                ]
            }
            return configuration
        case 'focusKeywordGeneratorModule': return {
            'focusKeyword': {
                'module': 'free_conversation',
                'userInput': [
                    {
                        identifier: 'content',
                        value: 'Answer with: example.'
                    }
                ]
            }
        }
    }
}

function* postprocessItemDependingOnScopeSaga(action: PayloadAction<StatefulModuleItem>) {
    const { payload: item } = action
    const scope = yield select(state => state.app.scope)
    switch (scope) {
        case 'altTextGeneratorModule': return
        case 'focusKeywordGeneratorModule':
            const pageContentResponse = yield fetch(item.publicUri)
            const pageContent = yield pageContentResponse.text()
            yield put(updateItemProperty({ identifier: item.identifier, propertyName: 'pageContent', propertyValue: pageContent}))
            return
    }
}

export function* watchAddItem() {
    yield takeEvery('app/addItem', postprocessItemDependingOnScopeSaga)
    yield takeEvery('app/addItem', autogenerateItemSaga)
}

export function* watchGenerateItem() {
    yield takeEvery('app/generateItem', generateItemSaga)
}


function* persistOneItemSaga({ payload: id}: PayloadAction<string>) {
    const item = yield select(state => getItem(state, id))
    yield put(setItemPersisting({ identifier: item.identifier, persisting: true}))
    const backend = BackendService.getInstance()
    try {
        yield backend.persistItems([item])
        yield put(setItemPersisted({identifier: item.identifier, persisted: true}))
        yield put(setItemPersisting({identifier: item.identifier, persisting: false}))
    } catch (e) {
        if (e instanceof AiAssistantError) {
            const translationService = TranslationService.getInstance()
            yield put(setErrorMessage(translationService.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage})))
        }
        yield put(setItemPersisting({identifier: item.identifier, persisting: false}))
    }
}

export function* watchPersistOneItem() {
    yield takeEvery('app/persistOneItem', persistOneItemSaga)
}

function* fetchNewPageAfterLastItemIsPersistedSaga(){
    const stateHasUnpersistedItem = yield select(hasUnpersistedItem)
    if (!stateHasUnpersistedItem) {
        yield fetchNewPage()
    }
}

export function* watchSetPersisted() {
    yield takeLatest('app/setItemPersisted', fetchNewPageAfterLastItemIsPersistedSaga)
}
