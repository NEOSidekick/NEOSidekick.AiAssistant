import {put, select, takeEvery} from "redux-saga/effects";
import {setErrorMessage, setItemState, updateItemProperty} from "../Store/AppSlice";
import {ExternalService} from "../Service/ExternalService";
import {PayloadAction} from "@reduxjs/toolkit";
import StateInterface from "../Store/StateInterface";
import TranslationService from "../Service/TranslationService";
import AiAssistantError from "../Service/AiAssistantError";
import {ModuleItem} from "../Model/ModuleItem";
import {StatefulModuleItem} from "../Model/StatefulModuleItem";
import {ModuleConfiguration} from "../Model/ModuleConfiguration";
import {ListItemState} from "../Enums/ListItemState";

function* generateItemSaga({ payload: item }: PayloadAction<StatefulModuleItem>) {
    yield put(setItemState({ identifier: item.identifier, state: ListItemState.Generating }))
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
            yield put(setItemState({ identifier: item.identifier, state: ListItemState.Generated }))
        } catch (e) {
            if (e instanceof AiAssistantError) {
                yield put(setErrorMessage(translationService.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage})))
            }
            yield put(setItemState({ identifier: item.identifier, state: ListItemState.GeneratingError }))
            // todo catch other errors with a more generic message?
        }
    })
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
