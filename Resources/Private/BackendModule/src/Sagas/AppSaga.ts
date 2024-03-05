import BackendService from "../Service/BackendService";
import { put, select, takeLatest, takeEvery } from "redux-saga/effects";
import {
    addItem,
    getItems,
    getModuleConfiguration,
    isAppStarted, resetItems, setItemGenerating, setItemPersisted, setItemPersisting,
    setLoading,
    setStarted, updateItemPropertyValue, hasUnpersistedItem, getItem, setErrorMessage
} from "../Store/AppSlice";
import BackendAssetModuleResultDtoInterface from "../Model/BackendAssetModuleResultDtoInterface";
import AssetDtoInterface from "../Model/AssetDtoInterface";
import {ExternalService} from "../Service/ExternalService";
import {PayloadAction} from "@reduxjs/toolkit";
import StateInterface from "../Store/StateInterface";
import TranslationService from "../Service/TranslationService";
import AiAssistantError from "../Service/AiAssistantError";

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
    const response = yield backend.getAssetsThatNeedProcessing(configuration)
    yield put(resetItems())
    for (let item of response) {
        yield put(addItem(item))
    }
    yield put(setLoading({loading: false}))
}

export function* watchStartModule() {
    yield takeLatest('app/startModule', startModuleSaga)
}

function* saveAllAndFetchNextSaga() {
    const assets = yield select(getItems)
    const assetsWithSetPropertyValue = Object.keys(assets).map((key: string): BackendAssetModuleResultDtoInterface => {
        const asset: AssetDtoInterface = assets[key]
        return {
            assetIdentifier: asset.assetIdentifier,
            filename: asset.filename,
            thumbnailUri: asset.thumbnailUri,
            fullsizeUri: asset.fullsizeUri,
            propertyName: asset.propertyName,
            propertyValue: asset.propertyValue
        }
    }).filter((asset: BackendAssetModuleResultDtoInterface) => {
        return asset.propertyValue.length > 0
    })

    if (assetsWithSetPropertyValue.length > 0) {
        for (let asset of assetsWithSetPropertyValue) {
            yield put(setItemPersisting({ identifier: asset.assetIdentifier, persisting: true }))
        }

        yield BackendService.getInstance().persistAssets(assetsWithSetPropertyValue)
        yield fetchNewPage()
    }
}

export function* watchSaveAllAndFetchNext() {
    yield takeLatest('app/saveAllAndFetchNext', saveAllAndFetchNextSaga)
}

function* addItemSaga({ payload: item }: PayloadAction<BackendAssetModuleResultDtoInterface>) {
    yield put(setItemGenerating({ identifier: item.assetIdentifier, generating: true }))
    const language = yield select((state: StateInterface) => state.app.moduleConfiguration.language)
    const externalService = ExternalService.getInstance()
    const translationService = TranslationService.getInstance()
    try {
        const response = yield externalService.generate('alt_tag_generator', language, [
            {
                identifier: 'url',
                value: [
                    item.fullsizeUri,
                    item.thumbnailUri
                ]
            }
        ])
        yield put(updateItemPropertyValue({ identifier: item.assetIdentifier, propertyValue: response }))
    } catch (e) {
        if (e instanceof AiAssistantError) {
            yield put(setErrorMessage(translationService.translate('NEOSidekick.AiAssistant:Error:' + e.code, e.message, {0: e.externalMessage})))
        }
        // todo catch other errors with a more generic message?
    }
    yield put(setItemGenerating({ identifier: item.assetIdentifier, generating: false }))
}

export function* watchAddItem() {
    yield takeEvery('app/addItem', addItemSaga)
}

function* persistOneItemSaga({ payload: id}: PayloadAction<string>) {
    const item = yield select(state => getItem(state, id))
    yield put(setItemPersisting({ identifier: item.assetIdentifier, persisting: true}))
    const backend = BackendService.getInstance()
    const response = yield backend.persistAssets([item])
    yield put(setItemPersisted({ identifier: item.assetIdentifier, persisted: true}))
    yield put(setItemPersisting({ identifier: item.assetIdentifier, persisting: false}))
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
